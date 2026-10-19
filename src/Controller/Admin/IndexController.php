<?php
namespace BatchValues\Controller\Admin;

use BatchValues\Job\ApplyChangeSet;
use BatchValues\Stdlib\ChangeLog;
use BatchValues\Stdlib\Operations;
use BatchValues\Stdlib\Planner;
use BatchValues\Stdlib\Processor;
use Interop\Container\ContainerInterface;
use Laminas\Form\Element\Csrf;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

class IndexController extends AbstractActionController
{
    /**
     * @var ContainerInterface
     */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    // ------------------------------------------------------------------
    // Log
    // ------------------------------------------------------------------

    public function browseAction()
    {
        $log = $this->log();
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 25;
        $this->paginator($log->countSets(), $page, $perPage);
        return new ViewModel([
            'sets' => $log->browseSets($page, $perPage),
        ]);
    }

    public function showAction()
    {
        $log = $this->log();
        $id = (int) $this->params('id');
        $set = $log->getSet($id);
        if (!$set) {
            return $this->notFoundAction();
        }
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $perPage = 50;
        $counts = $log->countChangesByOutcome($id);
        $effectiveCount = ($counts['added'] ?? 0) + ($counts['removed'] ?? 0) + ($counts['changed'] ?? 0);
        $skippedCount = array_sum(array_filter($counts, function ($n, $o) {
            return strpos($o, 'skipped') === 0;
        }, ARRAY_FILTER_USE_BOTH));
        // By default the rows that changed something, not pages of "skipped".
        $outcome = $this->params()->fromQuery('outcome') ?: ($effectiveCount ? 'effective' : 'all');
        $total = $outcome === 'all' ? array_sum($counts)
            : ($outcome === 'effective' ? $effectiveCount : ($outcome === 'skipped' ? $skippedCount : ($counts[$outcome] ?? 0)));
        $this->paginator($total, $page, $perPage);
        $changes = $log->browseChanges($id, $outcome, $page, $perPage);
        // Every field the run touched is shown as two columns, before and
        // after (0.8.0). The order of each field was kept with the change;
        // the values the run did not touch are read as they are now.
        $live = $this->liveValuesOf($changes, $set['resource_type'] ?: 'items');
        return new ViewModel([
            'live' => $live,
            'current' => $this->legacyFieldsOf($changes, $set['resource_type'] ?: 'items'),
            'set' => $set,
            'counts' => $counts,
            'effectiveCount' => $effectiveCount,
            'skippedCount' => $skippedCount,
            'outcome' => $outcome,
            'changes' => $changes,
            'reversible' => $log->countReversible($id),
            'cancelSets' => $log->getCancelSets($id),
            'reversedSet' => $set['reverses_id'] ? $log->getSet((int) $set['reverses_id']) : null,
            'display' => ($op = $this->services->get(Operations::class)->get($set['operation']))
                ? $op->describe($set['params'], []) : [],
            'statsNow' => !empty($set['params']['preview_stats']) && !empty($set['selection']['ids'])
                ? $this->planner()->setResourceType($set['resource_type'] ?: 'items')->propertyStats($set['selection']['ids'], array_keys($set['params']['preview_stats']), [])
                : [],
            'csrf' => $this->csrfElement(),
        ]);
    }

    public function progressAction()
    {
        $set = $this->log()->getSet((int) $this->params('id'));
        if (!$set) {
            return new JsonModel(['error' => 'not found']);
        }
        return new JsonModel([
            'status' => $set['status'],
            'total' => (int) $set['total'],
            'processed' => (int) $set['processed'],
            'changed' => (int) $set['changed'],
            'skipped' => (int) $set['skipped'],
            'failed' => (int) $set['failed'],
        ]);
    }

    // ------------------------------------------------------------------
    // Step 1: selection
    // ------------------------------------------------------------------

    public function newAction()
    {
        $request = $this->getRequest();
        $params = $request->isPost() ? $this->params()->fromPost() : $this->params()->fromQuery();
        $resourceType = $this->resourceTypeParam($params['resource_type'] ?? $this->params()->fromQuery('resource_type'));
        $selection = null;

        if ($request->isPost()) {
            $post = $params;
            if (!isset($post['batchvalues_from_browse']) && (isset($post['resource_ids']) || isset($post['resource_id_list']))) {
                // Handed over by another screen or module (see README, "Opening
                // Batch Values from another module"). resource_id_list is one
                // field holding every id, so a long list is not cut short by
                // PHP's max_input_vars.
                $ids = array_merge(
                    (array) ($post['resource_ids'] ?? []),
                    preg_split('/[^0-9]+/', (string) ($post['resource_id_list'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)
                );
                $selection = ['mode' => 'ids', 'ids' => $ids];
            } elseif (isset($post['batchvalues_from_browse'])) {
                // From a browse page (batch actions). Its search is kept either
                // way, so "Change selection" can go back to it.
                $browseQuery = json_decode($post['query'] ?? '[]', true) ?: [];
                if ($post['batchvalues_from_browse'] === 'all') {
                    $selection = ['mode' => 'query', 'query' => $browseQuery];
                } else {
                    $selection = ['mode' => 'ids', 'ids' => $post['resource_ids'] ?? []];
                }
                $selection['browse_query'] = $browseQuery;
            } elseif (isset($post['pick'])) {
                // Quick choice by resource template and/or item set (items).
                $resourceType = 'items';
                $query = [];
                $templates = array_values(array_filter(array_map('intval', (array) ($post['pick_template'] ?? []))));
                $itemSets = array_values(array_filter(array_map('intval', (array) ($post['pick_item_set'] ?? []))));
                if ($templates) {
                    $query['resource_template_id'] = $templates;
                }
                if ($itemSets) {
                    $query['item_set_id'] = $itemSets;
                }
                if ($query) {
                    $selection = ['mode' => 'query', 'query' => $query, 'browse_query' => $query];
                } else {
                    $this->messenger()->addWarning('Choose a template or an item set.'); // @translate
                }
            } else {
                // "Other ways" on this page.
                $ids = preg_split('/[^0-9]+/', (string) ($post['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $searchUrl = trim((string) ($post['search_url'] ?? ''));
                if ($searchUrl !== '') {
                    $path = (string) parse_url($searchUrl, PHP_URL_PATH);
                    if (preg_match('~/admin/item-set(/|$)~', $path)) {
                        $resourceType = 'item_sets';
                    } elseif (preg_match('~/admin/media(/|$)~', $path)) {
                        $resourceType = 'media';
                    }
                    $queryString = parse_url($searchUrl, PHP_URL_QUERY);
                    if ($queryString === null && strpos($searchUrl, '=') !== false) {
                        $queryString = ltrim($searchUrl, '?');
                    }
                    parse_str((string) $queryString, $query);
                    $selection = ['mode' => 'query', 'query' => $query, 'browse_query' => $query];
                } elseif ($ids) {
                    $selection = ['mode' => 'ids', 'ids' => $ids];
                }
            }
        } elseif ($this->params()->fromQuery('from_search')) {
            $query = $this->params()->fromQuery();
            unset($query['from_search'], $query['term'], $query['operation'], $query['return_url'], $query['return_label'], $query['resource_type']);
            $selection = ['mode' => 'query', 'query' => $query, 'browse_query' => $query];
        }

        if ($selection) {
            $ids = $this->planner()->setResourceType($resourceType)->resolveSelection($selection);
            if (!$ids) {
                $this->messenger()->addWarning('Nothing matches this selection.'); // @translate
            } else {
                if ($selection['mode'] === 'ids') {
                    $selection['ids'] = $ids;
                }
                $handover = $this->handoverParams($params);
                $token = $this->storeState([
                    'resource_type' => $resourceType,
                    'selection' => $selection,
                    'ids' => $ids,
                    'return' => $handover['return'],
                ]);
                $query = ['token' => $token];
                if ($handover['operation']) {
                    $query['operation'] = $handover['operation'];
                }
                if ($handover['property']) {
                    $query['property'] = $handover['property']->getId();
                }
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => $query]);
            }
        }

        return new ViewModel([
            'templates' => $this->services->get('Omeka\Connection')->fetchAllAssociative(
                'SELECT rt.id, rt.label, COUNT(i.id) AS items FROM resource_template rt'
                . ' LEFT JOIN resource r ON r.resource_template_id = rt.id LEFT JOIN item i ON i.id = r.id'
                . ' GROUP BY rt.id, rt.label ORDER BY rt.label'
            ),
            'itemSets' => $this->services->get('Omeka\Connection')->fetchAllAssociative(
                'SELECT r.id, r.title, (SELECT COUNT(*) FROM item_item_set iis WHERE iis.item_set_id = r.id) AS items'
                . ' FROM resource r JOIN item_set s ON s.id = r.id ORDER BY r.title'
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Step 2: operation, property, values
    // ------------------------------------------------------------------

    public function configureAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $exclude = (int) $this->params()->fromQuery('exclude');
        if ($exclude && count($state['ids']) > 1 && in_array($exclude, $state['ids'], true)) {
            // Taking one item out turns the selection into a fixed list.
            $ids = array_values(array_diff($state['ids'], [$exclude]));
            $selection = $state['selection'];
            $selection['mode'] = 'ids';
            $selection['ids'] = $ids;
            unset($selection['query']);
            $selection['excluded'] = array_values(array_unique(array_merge($selection['excluded'] ?? [], [$exclude])));
            $this->updateState($token, ['ids' => $ids, 'selection' => $selection, 'pending' => null]);
            $query = $this->params()->fromQuery();
            unset($query['exclude']);
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => $query]);
        }
        $ids = $state['ids'];
        $resourceType = $state['resource_type'] ?? 'items';
        $planner = $this->planner()->setResourceType($resourceType);
        $operations = $this->services->get(Operations::class)->forResourceType($resourceType);
        $operation = $operations[(string) $this->params()->fromQuery('operation')] ?? null;
        $allProperties = $this->params()->fromQuery('property') === 'all' && $operation && $operation->allowsAllProperties();
        $property = $allProperties ? null : $this->propertyFromParam($this->params()->fromQuery('property'));

        $view = new ViewModel([
            'token' => $token,
            'state' => $state,
            'resourceType' => $resourceType,
            'count' => count($ids),
            'templateCounts' => $this->templateCounts($ids),
            'previewItems' => $this->previewItems($ids, 200, $resourceType),
            'browseUrl' => $this->browseUrl($state),
            'propertyOptions' => $this->propertyOptions($ids),
            'operations' => $operations,
            'menu' => $this->services->get(Operations::class)->menu($resourceType),
            'operation' => $operation,
            'property' => $property,
            'allProperties' => $allProperties,
            'csrf' => $this->csrfElement(),
        ]);

        if ($operation && (!$operation->needsProperty() || $property || $allProperties)) {
            $ctx = $this->ctx($resourceType, $property, $ids, $allProperties);
            $vars = $operation->formVars($ctx);
            $vars['allProperties'] = $allProperties;
            // Values chosen in advance (the "Fix" of the data type check).
            $vars['presetTypes'] = array_values(array_filter(array_map('strval', (array) $this->params()->fromQuery('preset_types', [])), 'strlen'));
            // One option only (the "Fix" of the duplicates check).
            $vars['presetOnly'] = (string) $this->params()->fromQuery('only');
            $vars['presetKeepCopy'] = $this->params()->fromQuery('keep_copy') === 'last' ? 'last' : 'first';
            $vars['presetKeepIds'] = $state['preset_keep_ids'] ?? [];
            // Exact values chosen by a check (the "Fix" of the links check).
            $vars['presetValueIds'] = $state['preset_value_ids'] ?? [];
            if ($allProperties) {
                $vars['distinct'] = [];
                $vars['required'] = [];
                $vars['matchTypes'] = $this->typesInSelection($ids);
            } elseif (!empty($vars['picker'])) {
                $vars['distinct'] = $planner->distinctValues($ids, $property->getId());
                $vars['required'] = $this->requiredTemplateLabels($property->getId());
            }
            if ($property) {
                // What the field holds now in the selection, shown before any
                // choice is made.
                $distinct = $vars['distinct'] ?? $planner->distinctValues($ids, $property->getId());
                $now = $planner->propertyStats($ids, [$property->getId()], []);
                $vars['fieldNow'] = ['distinct' => $distinct, 'stats' => $now[$property->getId()] ?? null, 'count' => count($ids)];
            }
            $view->setVariable('opVars', $vars);
            $view->setVariable('ready', true);
        }
        return $view;
    }

    /**
     * Values of the selection whose data type is not the one their template
     * sets, per property: what "Change to the data type of the template"
     * can fix.
     */
    public function checkAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $planner = $this->planner()->setResourceType($resourceType);
        $mismatches = $planner->typeMismatches($state['ids']);
        // The values themselves, so what is found can be judged (some may be
        // on purpose) before anything is converted.
        foreach ($mismatches as $propertyId => &$m) {
            foreach ($m['templates'] as $templateId => &$t) {
                $t['sample'] = $planner->mismatchRows($state['ids'], (int) $propertyId, (int) $templateId, array_keys($t['found']), 50);
            }
            unset($t);
        }
        unset($m);
        return new ViewModel([
            'token' => $token,
            'resourceType' => $resourceType,
            'count' => count($state['ids']),
            'mismatches' => $mismatches,
        ]);
    }

    /**
     * "Fix" on the check page: a new selection with only the resources that
     * hold such values, and the conversion ready with those values chosen.
     */
    public function checkFixAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $propertyId = (int) $this->params()->fromQuery('property');
        $templateId = (int) $this->params()->fromQuery('template');
        $types = array_values(array_filter(array_map('strval', (array) $this->params()->fromQuery('types', [])), 'strlen'));
        $resourceType = $state['resource_type'] ?? 'items';
        $found = $this->planner()->setResourceType($resourceType)->mismatchRows($state['ids'], $propertyId, $templateId, $types, 0);
        if (!$found['resource_ids']) {
            $this->messenger()->addWarning('Nothing to fix any more.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'check'], ['query' => ['token' => $token]]);
        }
        $newToken = $this->storeState([
            'resource_type' => $resourceType,
            'selection' => ['mode' => 'ids', 'ids' => $found['resource_ids']],
            'ids' => $found['resource_ids'],
            'return' => [
                'url' => $this->url()->fromRoute('admin/batch-values', ['action' => 'check'], ['query' => ['token' => $token]]),
                'label' => $this->translate('Back to the data type check'),
            ],
        ]);
        return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => [
            'token' => $newToken,
            'operation' => 'convert',
            'property' => $propertyId,
            'preset_types' => $types,
        ]]);
    }

    /**
     * The same value entered more than once in one field of one resource.
     * Without a selection: every resource of the chosen type.
     */
    /**
     * "Links to check" (0.9.0): an item linked to itself, and links that go
     * one way only in a field that is used both ways elsewhere. Nothing is
     * changed here.
     */
    public function linksAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        if ($token === '') {
            $resourceType = $this->resourceTypeParam($this->params()->fromQuery('resource_type'));
            $selection = ['mode' => 'query', 'query' => []];
            $ids = $this->planner()->setResourceType($resourceType)->resolveSelection($selection);
            if (!$ids) {
                $this->messenger()->addWarning('Nothing matches this selection.'); // @translate
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
            }
            $selection['browse_query'] = [];
            $token = $this->storeState(['resource_type' => $resourceType, 'selection' => $selection, 'ids' => $ids, 'return' => null]);
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'links'], ['query' => ['token' => $token]]);
        }
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $issues = $this->planner()->setResourceType($resourceType)->linkIssues($state['ids']);
        $thumbIds = [];
        foreach ($issues as $issue) {
            foreach ($issue['self'] as $row) {
                $thumbIds[$row['resource_id']] = true;
            }
            foreach ($issue['one_way'] as $row) {
                $thumbIds[$row['resource_id']] = true;
                $thumbIds[$row['target_id']] = true;
            }
        }
        return new ViewModel([
            'token' => $token,
            'resourceType' => $resourceType,
            'count' => count($state['ids']),
            'issues' => $issues,
            'thumbIds' => array_keys($thumbIds),
        ]);
    }

    /**
     * Accession numbers of the selection: numbers of a run that were never
     * used, values that fit no known shape, and spellings that look like a
     * slip (0.12.0). Nothing is changed here.
     */
    /** The accession-number shapes set in Configure (1.0.0). */
    private function accessionShapes(): array
    {
        $text = (string) $this->services->get('Omeka\Settings')->get('batchvalues_accession_shapes', '');
        return \BatchValues\Stdlib\Planner::parseAccessionShapes($text)[0];
    }

    public function accessionAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        if ($token === '') {
            $resourceType = $this->resourceTypeParam($this->params()->fromQuery('resource_type'));
            $selection = ['mode' => 'query', 'query' => []];
            $ids = $this->planner()->setResourceType($resourceType)->resolveSelection($selection);
            if (!$ids) {
                $this->messenger()->addWarning('Nothing matches this selection.'); // @translate
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
            }
            $selection['browse_query'] = [];
            $token = $this->storeState(['resource_type' => $resourceType, 'selection' => $selection, 'ids' => $ids, 'return' => null]);
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'accession'], ['query' => ['token' => $token]]);
        }
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $settings = $this->services->get('Omeka\Settings');
        $api = $this->services->get('Omeka\ApiManager');
        // The field to read: the one asked for on this page, else the one set
        // in Configure.
        $property = null;
        $asked = $this->params()->fromQuery('property');
        $term = $asked !== null && $asked !== ''
            ? null
            : (string) $settings->get('batchvalues_accession_property', '');
        try {
            if ($asked !== null && $asked !== '') {
                $property = $api->read('properties', (int) $asked)->getContent();
            } elseif ($term !== '') {
                $found = $api->search('properties', ['term' => $term])->getContent();
                $property = $found ? $found[0] : null;
            }
        } catch (\Exception $e) {
            $property = null;
        }
        $years = array_values(array_filter(array_map('trim', preg_split(
            '/[\s,;]+/',
            (string) $settings->get('batchvalues_accession_placeholder_years', '')
        )), 'strlen'));
        $issues = $property
            ? $this->planner()->setResourceType($resourceType)->setAccessionShapes($this->accessionShapes())->accessionIssues($state['ids'], $property->id(), $years)
            : null;
        // Every item this page shows a link to, so each one can be named and
        // shown with its picture (0.12.2).
        $thumbIds = [];
        if ($issues) {
            foreach (array_slice($issues['shared'], 0, 50) as $row) {
                foreach ($row['resources'] as $id) {
                    $thumbIds[$id] = true;
                }
            }
            foreach ($issues['unparsed'] as $row) {
                $thumbIds[$row['resource_id']] = true;
            }
            foreach (['case_twins', 'rare_kinds'] as $part) {
                foreach ($issues[$part] as $row) {
                    foreach ($row['where'] ?? [] as $one) {
                        $thumbIds[$one['resource_id']] = true;
                    }
                }
            }
            foreach ($issues['groups'] as $group) {
                foreach ($group['gaps'] as $gap) {
                    foreach (['under', 'over'] as $side) {
                        if (!empty($gap[$side]['resource_id'])) {
                            $thumbIds[$gap[$side]['resource_id']] = true;
                        }
                    }
                }
            }
            foreach ($issues['zeros'] as $row) {
                $thumbIds[$row['resource_id']] = true;
            }
        }
        $titles = [];
        if ($thumbIds) {
            $rows = $this->services->get('Omeka\Connection')->fetchAllAssociative(
                'SELECT id, title FROM `resource` WHERE id IN (' . implode(',', array_map('intval', array_keys($thumbIds))) . ')'
            );
            foreach ($rows as $row) {
                $titles[(int) $row['id']] = $row['title'];
            }
        }
        return new ViewModel([
            'titles' => $titles,
            'token' => $token,
            'resourceType' => $resourceType,
            'count' => count($state['ids']),
            'property' => $property,
            'propertyTerm' => $term,
            'propertyOptions' => $this->propertyOptions($state['ids']),
            'years' => $years,
            'shapes' => $this->accessionShapes(),
            'issues' => $issues,
            'thumbIds' => array_keys($thumbIds),
        ]);
    }

    /**
     * Every number of one run, in order, with the item each one is on.
     *
     * The page itself carries only the shape of each run. A run can hold
     * hundreds of numbers and the archive has hundreds of runs, so the list
     * is fetched when the run is pressed, not written into the page (0.12.3).
     */
    public function accessionRunAction()
    {
        $state = $this->loadState((string) $this->params()->fromQuery('token'));
        if (!$state) {
            return new JsonModel(['error' => 'This selection has expired. Please select the items again.']); // @translate
        }
        $key = (string) $this->params()->fromQuery('key');
        $propertyId = (int) $this->params()->fromQuery('property');
        if ($key === '' || !$propertyId) {
            return new JsonModel(['rows' => []]);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $settings = $this->services->get('Omeka\Settings');
        $years = array_values(array_filter(array_map('trim', preg_split(
            '/[\s,;]+/',
            (string) $settings->get('batchvalues_accession_placeholder_years', '')
        )), 'strlen'));
        $issues = $this->planner()->setResourceType($resourceType)->setAccessionShapes($this->accessionShapes())
            ->accessionIssues($state['ids'], $propertyId, $years, 0, $key);
        $width = 4;
        foreach ($issues['groups'] as $group) {
            if ($group['key'] === $key) {
                $width = (int) $group['width'];
                break;
            }
        }
        $titles = [];
        $thumbs = [];
        $ids = [];
        foreach ($issues['members'] as $row) {
            $ids[(int) $row['resource_id']] = true;
        }
        if ($ids) {
            // The picture and the name as Omeka itself shows them, so the
            // list reads like the item browse page (0.12.4).
            try {
                foreach ($this->services->get('Omeka\ApiManager')
                    ->search($resourceType, ['id' => array_keys($ids)])->getContent() as $resource) {
                    $titles[$resource->id()] = $resource->displayTitle();
                    $thumbs[$resource->id()] = $resource->thumbnailDisplayUrl('square');
                }
            } catch (\Throwable $e) {
                // A picture is not worth failing the list for.
            }
        }
        $controller = \BatchValues\Stdlib\Planner::RESOURCE_TYPES[$resourceType][1] ?? 'item';
        $rows = [];
        foreach ($issues['members'] as $row) {
            $id = (int) $row['resource_id'];
            $rows[] = [
                'serial' => str_pad((string) $row['serial'], $width, '0', STR_PAD_LEFT),
                'value' => $row['value'],
                'id' => $id,
                'title' => $titles[$id] ?? '',
                'thumb' => $thumbs[$id] ?? null,
                'url' => $this->url()->fromRoute('admin/id', ['controller' => $controller, 'id' => $id]),
            ];
        }
        $serials = [];
        foreach ($issues['members'] as $row) {
            $serials[(int) $row['serial']] = true;
        }
        return new JsonModel(['rows' => $rows, 'values' => count($rows), 'numbers' => count($serials)]);
    }

    /**
     * "Fix" on the links page: a selection of the items that link to
     * themselves in that field, with "Remove values" ready on exactly those
     * values.
     */
    public function linksFixAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $propertyId = (int) $this->params()->fromQuery('property');
        $resourceType = $state['resource_type'] ?? 'items';
        $issues = $this->planner()->setResourceType($resourceType)->linkIssues($state['ids'], $propertyId ?: null, 0);
        $rows = $issues[$propertyId]['self'] ?? [];
        if (!$propertyId || !$rows) {
            $this->messenger()->addWarning('Nothing to fix any more.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'links'], ['query' => ['token' => $token]]);
        }
        $resourceIds = array_values(array_unique(array_column($rows, 'resource_id')));
        $newToken = $this->storeState([
            'resource_type' => $resourceType,
            'selection' => ['mode' => 'ids', 'ids' => $resourceIds],
            'ids' => $resourceIds,
            'return' => [
                'url' => $this->url()->fromRoute('admin/batch-values', ['action' => 'links'], ['query' => ['token' => $token]]),
                'label' => $this->translate('Back to the check of links'),
            ],
            'preset_value_ids' => array_values(array_unique(array_column($rows, 'value_id'))),
        ]);
        return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => [
            'token' => $newToken,
            'operation' => 'remove',
            'property' => $propertyId,
        ]]);
    }

    public function duplicatesAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        if ($token === '') {
            $resourceType = $this->resourceTypeParam($this->params()->fromQuery('resource_type'));
            $selection = ['mode' => 'query', 'query' => []];
            $ids = $this->planner()->setResourceType($resourceType)->resolveSelection($selection);
            if (!$ids) {
                $this->messenger()->addWarning('Nothing matches this selection.'); // @translate
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
            }
            $selection['browse_query'] = [];
            $token = $this->storeState(['resource_type' => $resourceType, 'selection' => $selection, 'ids' => $ids, 'return' => null]);
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'duplicates'], ['query' => ['token' => $token]]);
        }
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $planner = $this->planner()->setResourceType($resourceType);
        $duplicates = $planner->duplicates($state['ids']);
        // The whole field of the items shown, in its order, so the copy to
        // remove can be chosen knowingly.
        foreach ($duplicates as $propertyId => &$d) {
            $shown = array_values(array_unique(array_column($d['rows'], 'resource_id')));
            $d['fields'] = $shown ? $planner->valuesByResource($shown, (int) $propertyId) : [];
            $d['titles'] = array_column($d['rows'], 'title', 'resource_id');
        }
        unset($d);
        return new ViewModel([
            'token' => $token,
            'resourceType' => $resourceType,
            'count' => count($state['ids']),
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * "Fix" on the duplicates page: a new selection with only the resources
     * that hold a value twice in this field, and "Clean up" ready with only
     * "The same value entered twice" ticked.
     */
    public function duplicatesFixAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $request = $this->getRequest();
        $input = $request->isPost() ? $this->params()->fromPost() : $this->params()->fromQuery();
        $propertyId = (int) ($input['property'] ?? 0);
        // Copies chosen one by one on the check page: value ids to keep.
        $keepIds = [];
        foreach ((array) ($input['keep'] ?? []) as $groups) {
            foreach ((array) $groups as $valueId) {
                if ((int) $valueId > 0) {
                    $keepIds[] = (int) $valueId;
                }
            }
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $found = $this->planner()->setResourceType($resourceType)->duplicates($state['ids'], $propertyId, 0);
        $resourceIds = $found[$propertyId]['resource_ids'] ?? [];
        if (!$propertyId || !$resourceIds) {
            $this->messenger()->addWarning('Nothing to fix any more.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'duplicates'], ['query' => ['token' => $token]]);
        }
        $newToken = $this->storeState([
            'resource_type' => $resourceType,
            'selection' => ['mode' => 'ids', 'ids' => $resourceIds],
            'ids' => $resourceIds,
            'return' => [
                'url' => $this->url()->fromRoute('admin/batch-values', ['action' => 'duplicates'], ['query' => ['token' => $token]]),
                'label' => $this->translate('Back to the check of values entered twice'),
            ],
            'preset_keep_ids' => array_values(array_unique($keepIds)),
        ]);
        return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => [
            'token' => $newToken,
            'operation' => 'clean',
            'property' => $propertyId,
            'only' => 'duplicates,resource_type',
            'keep_copy' => ($input['keep_copy'] ?? 'first') === 'last' ? 'last' : 'first',
        ]]);
    }

    /**
     * What "Clean up" would fix, in every field of the selection (0.6.5).
     * Without a selection: every resource of the chosen type.
     */
    public function cleanupAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        if ($token === '') {
            $resourceType = $this->resourceTypeParam($this->params()->fromQuery('resource_type'));
            $selection = ['mode' => 'query', 'query' => []];
            $ids = $this->planner()->setResourceType($resourceType)->resolveSelection($selection);
            if (!$ids) {
                $this->messenger()->addWarning('Nothing matches this selection.'); // @translate
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
            }
            $selection['browse_query'] = [];
            $token = $this->storeState(['resource_type' => $resourceType, 'selection' => $selection, 'ids' => $ids, 'return' => null]);
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'cleanup'], ['query' => ['token' => $token]]);
        }
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $this->planner()->setResourceType($resourceType);
        $found = $this->cleanupScan($state['ids'], null, 10);
        $titles = [];
        $sampleIds = [];
        foreach ($found as $f) {
            foreach ($f['issues'] as $issue) {
                foreach ($issue['samples'] as $sample) {
                    $sampleIds[(int) $sample['resource_id']] = true;
                }
            }
        }
        if ($sampleIds) {
            $titles = $this->services->get('Omeka\Connection')->fetchAllKeyValue(
                'SELECT id, title FROM resource WHERE id IN (?)',
                [array_keys($sampleIds)],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            );
        }
        return new ViewModel([
            'token' => $token,
            'resourceType' => $resourceType,
            'count' => count($state['ids']),
            'found' => $found,
            'titles' => $titles,
        ]);
    }

    /**
     * "Fix" on the clean-up check: only the resources concerned, and Clean up
     * with only the options found ticked. property=all: every field.
     */
    public function cleanupFixAction()
    {
        $token = (string) $this->params()->fromQuery('token');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select the items again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $all = $this->params()->fromQuery('property') === 'all';
        $propertyId = $all ? null : (int) $this->params()->fromQuery('property');
        $resourceType = $state['resource_type'] ?? 'items';
        $this->planner()->setResourceType($resourceType);
        $found = $this->cleanupScan($state['ids'], $propertyId, 0);
        $resourceIds = [];
        $options = [];
        foreach ($found as $f) {
            foreach ($f['issues'] as $option => $issue) {
                $options[$option] = true;
                $resourceIds += array_flip($issue['resource_ids']);
            }
        }
        if (!$resourceIds || (!$all && !$propertyId)) {
            $this->messenger()->addWarning('Nothing to fix any more.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'cleanup'], ['query' => ['token' => $token]]);
        }
        $resourceIds = array_keys($resourceIds);
        sort($resourceIds);
        $newToken = $this->storeState([
            'resource_type' => $resourceType,
            'selection' => ['mode' => 'ids', 'ids' => $resourceIds],
            'ids' => $resourceIds,
            'return' => [
                'url' => $this->url()->fromRoute('admin/batch-values', ['action' => 'cleanup'], ['query' => ['token' => $token]]),
                'label' => $this->translate('Back to the clean-up check'),
            ],
        ]);
        return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => [
            'token' => $newToken,
            'operation' => 'clean',
            'property' => $all ? 'all' : $propertyId,
            'only' => implode(',', array_keys($options)),
        ]]);
    }

    /**
     * Every value of the resources, one field at a time, through the checks
     * of "Clean up".
     *
     * @return array property id => ['label', 'term', 'values' => int,
     *   'issues' => [option => ['values' => int, 'resource_ids' => int[],
     *   'samples' => [['resource_id', 'row']]]]]
     */
    protected function cleanupScan(array $ids, ?int $propertyId, int $sampleLimit): array
    {
        /** @var \BatchValues\Operation\CleanValues $clean */
        $clean = $this->services->get(Operations::class)->get('clean');
        $connection = $this->services->get('Omeka\Connection');
        $result = [];
        $add = function (int $pid, string $option, array $row, int $n = 1) use (&$result, $sampleLimit) {
            if (!isset($result[$pid]['issues'][$option])) {
                $result[$pid]['issues'][$option] = ['values' => 0, 'resource_ids' => [], 'samples' => []];
            }
            $issue = &$result[$pid]['issues'][$option];
            $issue['values'] += $n;
            $issue['resource_ids'][(int) $row['resource_id']] = true;
            if (count($issue['samples']) < $sampleLimit) {
                $issue['samples'][] = ['resource_id' => (int) $row['resource_id'], 'row' => $row];
            }
        };
        foreach (array_chunk($ids, 500) as $chunk) {
            $sql = 'SELECT id, resource_id, property_id, value_resource_id, value_annotation_id, type, lang, value, uri, is_public'
                . ' FROM value WHERE resource_id IN (?)' . ($propertyId ? ' AND property_id = ?' : '')
                . ' ORDER BY resource_id, property_id, id';
            $rows = $connection->fetchAllAssociative(
                $sql,
                $propertyId ? [$chunk, $propertyId] : [$chunk],
                $propertyId ? [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT] : [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            );
            $fields = [];
            foreach ($rows as $row) {
                $pid = (int) $row['property_id'];
                $result[$pid]['values'] = ($result[$pid]['values'] ?? 0) + 1;
                foreach ($clean->issues($row) as $option) {
                    $add($pid, $option, $row);
                }
                $fields[(int) $row['resource_id'] . ':' . $pid][] = $row;
            }
            // Values entered twice, exactly or apart from upper and lower case.
            foreach ($fields as $list) {
                if (count($list) < 2) {
                    continue;
                }
                $pid = (int) $list[0]['property_id'];
                $exact = [];
                $folded = [];
                foreach ($list as $row) {
                    $key = Planner::pickKey($row);
                    $exact[$key][] = $row;
                    $folded[mb_strtolower(Planner::rowKey($row, true)) . '|' . (int) (bool) $row['is_public']][$key] = $row;
                }
                foreach ($exact as $copies) {
                    if (count($copies) > 1) {
                        $add($pid, 'duplicates', $copies[1], count($copies) - 1);
                    }
                }
                foreach ($folded as $variants) {
                    if (count($variants) > 1) {
                        $variants = array_values($variants);
                        $add($pid, 'duplicates_case', $variants[1], count($variants) - 1);
                    }
                }
            }
        }
        $out = [];
        foreach ($result as $pid => $r) {
            if (empty($r['issues'])) {
                continue;
            }
            $meta = $connection->fetchAssociative(
                "SELECT p.label, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?",
                [$pid]
            ) ?: ['label' => '#' . $pid, 'term' => ''];
            $issues = [];
            // Same order as the options of Clean up.
            foreach (['trim', 'duplicates', 'empty', 'collapse', 'invisible', 'nbsp', 'duplicates_case', 'mojibake', 'resource_type', 'nulls'] as $option) {
                if (isset($r['issues'][$option])) {
                    $issue = $r['issues'][$option];
                    $issue['resource_ids'] = array_keys($issue['resource_ids']);
                    $issues[$option] = $issue;
                }
            }
            $out[$pid] = ['label' => $meta['label'], 'term' => $meta['term'], 'values' => $r['values'], 'issues' => $issues];
        }
        uasort($out, function ($a, $b) {
            return array_sum(array_column($b['issues'], 'values')) <=> array_sum(array_column($a['issues'], 'values'));
        });
        return $out;
    }

    // ------------------------------------------------------------------
    // Step 3: preview (nothing is written)
    // ------------------------------------------------------------------

    public function previewAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        $post = $this->params()->fromPost();
        $token = (string) ($post['token'] ?? '');
        $state = $this->loadState($token);
        if (!$state) {
            $this->messenger()->addError('This selection has expired. Please select again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        if (!$this->checkCsrf($post)) {
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => ['token' => $token]]);
        }
        $resourceType = $state['resource_type'] ?? 'items';
        $planner = $this->planner()->setResourceType($resourceType);
        $operation = $this->services->get(Operations::class)->forResourceType($resourceType)[(string) ($post['operation'] ?? '')] ?? null;
        $allProperties = ($post['property'] ?? null) === 'all' && $operation && $operation->allowsAllProperties();
        $property = $allProperties ? null : $this->propertyFromParam($post['property'] ?? null);
        if (!$operation || ($operation->needsProperty() && !$property && !$allProperties)) {
            $this->messenger()->addError('Choose an operation and a property.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => ['token' => $token]]);
        }
        $backQuery = array_filter(['token' => $token, 'operation' => $operation->name(), 'property' => $allProperties ? 'all' : ($property ? $property->getId() : null)]);
        $ids = $state['ids'];
        $ctx = $this->ctx($resourceType, $property, $ids, $allProperties);
        $parsed = $operation->parse($post, $ctx);
        if ($parsed['errors']) {
            foreach ($parsed['errors'] as $error) {
                $this->messenger()->addError($error);
            }
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => $backQuery]);
        }
        $params = $parsed['params'];
        if ($allProperties) {
            $params['all_properties'] = true;
        }

        $plan = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $plan += $operation->planAll($chunk, $params, $ctx);
        }
        $summary = Planner::summarize($plan);
        $stats = $planner->propertyStats($ids, array_filter([
            $property ? $property->getId() : null,
            $params['target_property_id'] ?? null,
        ]), $plan);
        // Kept with the change set, so its page can show the numbers as they
        // were previewed next to the numbers of today.
        $params['preview_stats'] = $stats;
        $this->updateState($token, [
            'pending' => [
                'operation' => $operation->name(),
                'property_id' => $property ? $property->getId() : null,
                'params' => $params,
            ],
        ]);

        // A sample for the table: changed ones first, then the others.
        // A sample for the table: up to 100 that change, then a few of the
        // skipped ones (one of each reason first), not pages of "not present".
        $sample = [];
        $skipped = [];
        $reasons = [];
        foreach ($plan as $id => $entry) {
            if (in_array($entry['outcome'], ['added', 'removed', 'changed'], true)) {
                if (count($sample) < 100) {
                    $sample[$id] = $entry;
                }
            } elseif (!isset($reasons[$entry['outcome']])) {
                $reasons[$entry['outcome']] = true;
                $skipped = [$id => $entry] + $skipped;
            } elseif (count($skipped) < 10) {
                $skipped[$id] = $entry;
            }
        }
        $sample += array_slice($skipped, 0, 10, true);
        $info = $planner->resourceInfo(array_keys($sample));
        // What each example resource holds now in the involved properties,
        // to show the field before and after, not only the change.
        $fieldProperties = array_values(array_filter([
            $property ? $property->getId() : null,
            $params['target_property_id'] ?? null,
        ]));
        $current = [];
        foreach ($fieldProperties as $pid) {
            $current[$pid] = $planner->valuesByResource(array_keys($sample), (int) $pid);
        }
        $thumbs = [];
        foreach ($this->previewItems(array_keys($sample), 100, $resourceType) as $resource) {
            $thumbs[$resource->id()] = $resource->thumbnailDisplayUrl('square');
        }
        // Work that cannot be cancelled: every resource it touches is listed,
        // not only examples.
        $fullList = [];
        if (!$operation->reversible()) {
            $effective = array_filter($plan, function ($entry) {
                return in_array($entry['outcome'], ['added', 'removed', 'changed'], true);
            });
            $listIds = array_slice(array_keys($effective), 0, 5000);
            $listInfo = $planner->resourceInfo($listIds);
            foreach ($listIds as $id) {
                $fullList[] = ['id' => $id, 'title' => $listInfo[$id]['title'] ?? null] + $plan[$id];
            }
        }
        $rows = [];
        foreach ($sample as $id => $entry) {
            $rows[] = ['id' => $id, 'title' => $info[$id]['title'] ?? null, 'thumb' => $thumbs[$id] ?? null] + $entry;
        }

        return new ViewModel([
            'token' => $token,
            'resourceType' => $resourceType,
            'operation' => $operation,
            'fullList' => $fullList,
            'fullCount' => $operation->reversible() ? 0 : count(array_filter($plan, function ($e) { return in_array($e['outcome'], ['added', 'removed', 'changed'], true); })),
            'allProperties' => $allProperties,
            'property' => $property,
            'groups' => $property ? $planner->templateGroups($ids, $property->getId()) : [],
            'params' => $params,
            'display' => $operation->describe($params, $ctx),
            'summary' => $summary,
            'stats' => $stats,
            'sample' => $rows,
            'current' => $current,
            'fieldProperties' => $fieldProperties,
            'count' => count($ids),
            'backQuery' => $backQuery,
            'syncLimit' => $operation->syncLimit($this->syncLimit()),
            'csrf' => $this->csrfElement(),
        ]);
    }

    // ------------------------------------------------------------------
    // Step 4: apply
    // ------------------------------------------------------------------

    public function applyAction()
    {
        $post = $this->params()->fromPost();
        $token = (string) ($post['token'] ?? '');
        $state = $this->loadState($token);
        if (!$this->getRequest()->isPost() || !$state || empty($state['pending'])) {
            $this->messenger()->addError('Nothing to apply. Please start again.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'new']);
        }
        if (!$this->checkCsrf($post)) {
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'configure'], ['query' => ['token' => $token]]);
        }
        $pending = $state['pending'];
        $property = $pending['property_id'] ? $this->propertyFromParam($pending['property_id']) : null;
        if ($pending['property_id'] && !$property) {
            return $this->notFoundAction();
        }
        $selection = $state['selection'];
        $selection['ids'] = $state['ids'];
        if (!empty($state['return'])) {
            $selection['return'] = $state['return'];
        }

        $setId = $this->log()->createSet([
            'operation' => $pending['operation'],
            'resource_type' => $state['resource_type'] ?? 'items',
            'property_id' => $property ? $property->getId() : null,
            'property_term' => $property ? $property->getVocabulary()->getPrefix() . ':' . $property->getLocalName() : null,
            'property_label' => $property ? $property->getLabel() : (!empty($pending['params']['all_properties']) ? 'All properties' : null), // @translate
            'params' => $pending['params'],
            'selection' => $selection,
            'total' => count($state['ids']),
        ]);
        // The same preview cannot be applied twice.
        $this->updateState($token, ['pending' => null]);

        $operation = $this->services->get(Operations::class)->get($pending['operation']);
        $this->dispatchRun($setId, count($state['ids']), $operation ? $operation->syncLimit($this->syncLimit()) : null);
        return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $setId]);
    }

    // ------------------------------------------------------------------
    // Cancel
    // ------------------------------------------------------------------

    public function cancelAction()
    {
        $log = $this->log();
        $id = (int) $this->params('id');
        $set = $log->getSet($id);
        if (!$set) {
            return $this->notFoundAction();
        }
        if ($set['status'] === 'running') {
            $this->messenger()->addWarning('This change set is still running.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $id]);
        }
        // A save made by a person on Omeka's own form is only recorded here,
        // never undone here (0.11.0).
        if ($set['operation'] === \BatchValues\Module::FORM_EDIT) {
            $this->messenger()->addWarning('This run was a save made on the resource\'s own form. It is recorded here, but undoing it belongs to the archive\'s history.'); // @translate
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $id]);
        }
        $post = $this->params()->fromPost();
        $changeIds = isset($post['change_ids']) ? array_map('intval', (array) $post['change_ids']) : null;
        $singleId = $post['change_id'] ?? $this->params()->fromQuery('change_id');
        if ($singleId) {
            $changeIds = [(int) $singleId];
        }
        $reversible = $log->getReversibleChanges($id, $changeIds);

        if ($this->getRequest()->isPost() && !empty($post['confirm'])) {
            if (!$this->checkCsrf($post)) {
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $id]);
            }
            if (!$reversible) {
                $this->messenger()->addWarning('Nothing left to cancel.'); // @translate
                return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $id]);
            }
            $cancelId = $log->createSet([
                'operation' => 'cancel',
                'resource_type' => $set['resource_type'] ?: 'items',
                'property_id' => $set['property_id'],
                'property_term' => $set['property_term'],
                'property_label' => $set['property_label'],
                'reverses_id' => $id,
                'params' => ['change_ids' => $changeIds],
                'selection' => ['ids' => array_values(array_unique(array_map('intval', array_column($reversible, 'resource_id'))))],
                'total' => count($reversible),
            ]);
            $this->dispatchRun($cancelId, count($reversible));
            return $this->redirect()->toRoute('admin/batch-values', ['action' => 'show', 'id' => $cancelId]);
        }

        $shown = array_slice($reversible, 0, 100);
        return new ViewModel([
            'set' => $set,
            'changes' => $shown,
            'live' => $this->liveValuesOf($shown, $set['resource_type'] ?: 'items'),
            'current' => $this->legacyFieldsOf($shown, $set['resource_type'] ?: 'items'),
            'total' => count($reversible),
            'changeIds' => $changeIds,
            'csrf' => $this->csrfElement(),
        ]);
    }

    // ------------------------------------------------------------------
    // Quick look at one resource (popup)
    // ------------------------------------------------------------------

    public function peekAction()
    {
        $resourceType = $this->resourceTypeParam($this->params()->fromQuery('resource_type'));
        $id = (int) $this->params('id');
        try {
            $resource = $this->services->get('Omeka\ApiManager')->read($resourceType, $id)->getContent();
        } catch (\Exception $e) {
            $resource = null;
        }
        $view = new ViewModel(['resource' => $resource, 'id' => $id]);
        $view->setTerminal(true);
        return $view;
    }

    // ------------------------------------------------------------------
    // Resource picker (for resource data types)
    // ------------------------------------------------------------------

    public function searchResourceAction()
    {
        $type = (string) $this->params()->fromQuery('type', 'resource:item');
        $q = trim((string) $this->params()->fromQuery('q', ''));
        $map = [
            'resource:item' => ['items'],
            'resource:itemset' => ['item_sets'],
            'resource:media' => ['media'],
            'resource' => ['items', 'item_sets', 'media'],
        ];
        $resourceNames = $map[$type] ?? ['items'];
        $api = $this->services->get('Omeka\ApiManager');
        $results = [];
        foreach ($resourceNames as $resourceName) {
            $query = ['per_page' => 20, 'sort_by' => 'title'];
            if (ctype_digit($q)) {
                $query['id'] = (int) $q;
            } elseif ($q !== '') {
                $query['fulltext_search'] = $q;
                unset($query['sort_by']);
            }
            try {
                foreach ($api->search($resourceName, $query)->getContent() as $resource) {
                    $results[] = [
                        'id' => $resource->id(),
                        'title' => $resource->displayTitle(),
                        'type' => $resource->resourceName(),
                        'url' => $resource->adminUrl(),
                    ];
                }
            } catch (\Exception $e) {
                // Ignore a resource type that cannot be searched.
            }
        }
        return new JsonModel(['results' => $results]);
    }

    // ------------------------------------------------------------------
    // Order of a field's values, for the add and edit forms (0.10.0)
    // ------------------------------------------------------------------

    /**
     * Put a list of texts in order and say where each one goes.
     *
     * The buttons on Omeka's own add and edit form send what is shown on the
     * screen and get back the new order. The work is done here, with the same
     * collator as the "Sort the values" operation, so that sorting a field in
     * the form and sorting it in a batch give the same order.
     */
    public function sortOrderAction()
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = $this->params()->fromPost();
        }
        $texts = isset($data['texts']) && is_array($data['texts']) ? array_values($data['texts']) : [];
        // A field on a form never holds thousands of values; a list longer
        // than this is not a form and is not sorted here.
        if (count($texts) > 2000) {
            return new JsonModel(['order' => []]);
        }
        $texts = array_map(function ($text) {
            return mb_substr((string) $text, 0, 2000);
        }, $texts);
        $langs = isset($data['langs']) && is_array($data['langs']) ? array_values($data['langs']) : [];
        $desc = ($data['direction'] ?? 'asc') === 'desc';
        $langFirst = [];
        if (isset($data['lang_first']) && is_array($data['lang_first'])) {
            $langFirst = array_values(array_filter(array_map('trim', $data['lang_first']), 'strlen'));
        }
        $collator = class_exists('Collator') ? new \Collator('th_TH') : null;
        if ($collator) {
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        $langRank = array_flip(array_map('strtolower', $langFirst));
        $order = array_keys($texts);
        usort($order, function ($a, $b) use ($texts, $langs, $collator, $desc, $langRank) {
            if ($langRank) {
                $ra = $langRank[strtolower((string) ($langs[$a] ?? ''))] ?? count($langRank);
                $rb = $langRank[strtolower((string) ($langs[$b] ?? ''))] ?? count($langRank);
                if ($ra !== $rb) {
                    return $ra <=> $rb;
                }
            }
            $ta = (string) $texts[$a];
            $tb = (string) $texts[$b];
            $c = $collator ? $collator->compare($ta, $tb) : strnatcasecmp($ta, $tb);
            if ($desc) {
                $c = -$c;
            }
            return $c ?: ($a <=> $b);
        });
        return new JsonModel(['order' => $order]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function dispatchRun(int $setId, int $count, ?int $limit = null): void
    {
        if ($count <= ($limit ?? $this->syncLimit())) {
            $this->services->get(Processor::class)->run($setId);
            $set = $this->log()->getSet($setId);
            if ($set['status'] === 'failed') {
                $this->messenger()->addError(new Message('The run failed: %s', $set['message'])); // @translate
            } else {
                $this->messenger()->addSuccess('Done.'); // @translate
            }
            return;
        }
        $job = $this->jobDispatcher()->dispatch(ApplyChangeSet::class, ['change_set_id' => $setId]);
        $this->log()->updateSet($setId, ['job_id' => $job->getId()]);
        $this->messenger()->addSuccess('The run was started in the background. This page shows its progress.'); // @translate
    }

    /**
     * Optional parameters another screen may pass with a selection:
     * term (property term or id), operation (add|remove), return_url (a path
     * inside this Omeka, starting with "/") and return_label.
     */
    protected function handoverParams(array $params): array
    {
        $property = $this->propertyFromParam($params['term'] ?? null);
        $operation = $this->services->get(Operations::class)->get($params['operation'] ?? null) ? (string) $params['operation'] : null;
        $return = null;
        $url = (string) ($params['return_url'] ?? '');
        // Only a local path: never an absolute or protocol-relative address.
        if ($url !== '' && $url[0] === '/' && (strlen($url) < 2 || ($url[1] !== '/' && $url[1] !== '\\'))) {
            $label = trim((string) ($params['return_label'] ?? ''));
            $return = ['url' => $url, 'label' => $label !== '' ? mb_substr($label, 0, 120) : null];
        }
        return ['property' => $property, 'operation' => $operation, 'return' => $return];
    }

    protected function resourceTypeParam($value): string
    {
        return isset(Planner::RESOURCE_TYPES[(string) $value]) ? (string) $value : 'items';
    }

    protected function ctx(string $resourceType, $property, array $ids, bool $allProperties = false): array
    {
        return [
            'resource_type' => $resourceType,
            'property_id' => $property ? $property->getId() : null,
            'term' => $property ? $property->getVocabulary()->getPrefix() . ':' . $property->getLocalName() : null,
            'ids' => $ids,
            'all_properties' => $allProperties,
        ];
    }

    /**
     * Data types of the values of the selection, with counts.
     */
    protected function typesInSelection(array $ids): array
    {
        $types = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->services->get('Omeka\Connection')->fetchAllKeyValue(
                'SELECT type, COUNT(*) FROM value WHERE resource_id IN (?) GROUP BY type',
                [$chunk],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $type => $n) {
                $types[$type] = ($types[$type] ?? 0) + (int) $n;
            }
        }
        arsort($types);
        return $types;
    }

    protected function syncLimit(): int
    {
        $config = $this->services->get('Config');
        $default = (int) ($config['batchvalues']['sync_limit'] ?? 50);
        return max(1, (int) $this->services->get('Omeka\Settings')->get('batchvalues_sync_limit', $default));
    }

    protected function log(): ChangeLog
    {
        return $this->services->get(ChangeLog::class);
    }

    protected function planner(): Planner
    {
        return $this->services->get(Planner::class);
    }

    /**
     * @return \Omeka\Entity\Property|null
     */
    protected function propertyFromParam($param)
    {
        if (!$param) {
            return null;
        }
        $em = $this->services->get('Omeka\EntityManager');
        if (is_numeric($param)) {
            return $em->find(\Omeka\Entity\Property::class, (int) $param);
        }
        if (is_string($param) && strpos($param, ':') !== false) {
            [$prefix, $localName] = explode(':', $param, 2);
            return $em->createQuery(
                'SELECT p FROM Omeka\Entity\Property p JOIN p.vocabulary v WHERE v.prefix = :prefix AND p.localName = :localName'
            )->setParameters(['prefix' => $prefix, 'localName' => $localName])->getOneOrNullResult();
        }
        return null;
    }

    /**
     * The first items of the selection, for thumbnails and titles.
     */
    protected function previewItems(array $ids, int $limit, string $resourceType = 'items'): array
    {
        $slice = array_slice($ids, 0, $limit);
        if (!$slice) {
            return [];
        }
        $items = $this->services->get('Omeka\ApiManager')->search($resourceType, ['id' => $slice])->getContent();
        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id()] = $item;
        }
        $result = [];
        foreach ($slice as $id) {
            if (isset($byId[$id])) {
                $result[] = $byId[$id];
            }
        }
        return $result;
    }

    /**
     * Where "Change selection" goes: back to the items list the selection came
     * from, with its search, or to the items list itself.
     */
    protected function browseUrl(array $state): string
    {
        $query = $state['selection']['browse_query'] ?? null;
        if (!$query && ($state['selection']['mode'] ?? null) === 'query') {
            $query = $state['selection']['query'] ?? [];
        }
        $controller = Planner::RESOURCE_TYPES[$state['resource_type'] ?? 'items'][1] ?? 'item';
        return $this->url()->fromRoute('admin/default', ['controller' => $controller, 'action' => 'browse'], ['query' => $query ?: []]);
    }

    /**
     * Property menu: first the properties of the templates the selected items
     * use (with the template's own labels), then the other vocabularies, minus
     * the ones hidden in the module settings.
     */
    /**
     * For runs made before 0.8.0, which kept no order: the fields those
     * changes touched, as they are now, so the two columns can be rebuilt.
     *
     * @return array [property id => [resource id => rows]]
     */
    protected function legacyFieldsOf(array $changes, string $resourceType): array
    {
        $wanted = [];
        $resourceIds = [];
        foreach ($changes as $change) {
            if (!empty($change['fields']) || empty($change['values'])) {
                continue;
            }
            foreach (\BatchValues\Stdlib\Processor::pairsOf($change) as $pair) {
                foreach (['before', 'after'] as $side) {
                    if (!empty($pair[$side]['property_id'])) {
                        $wanted[(int) $pair[$side]['property_id']] = true;
                        $resourceIds[(int) $change['resource_id']] = true;
                    }
                }
            }
        }
        if (!$wanted) {
            return [];
        }
        $planner = $this->planner()->setResourceType($resourceType);
        $rows = $planner->valuesByResource(array_keys($resourceIds), array_keys($wanted));
        $byProperty = [];
        foreach ($rows as $resourceId => $list) {
            foreach ($list as $row) {
                $byProperty[(int) $row['property_id']][(int) $resourceId][] = $row;
            }
        }
        return $byProperty;
    }

    /**
     * The values named in the kept order of these changes, as they are now
     * (0.8.0): the two columns show the values the run did not touch from
     * the base, not from a copy in the log.
     *
     * @return array [value id => row]
     */
    protected function liveValuesOf(array $changes, string $resourceType): array
    {
        $valueIds = [];
        foreach ($changes as $change) {
            foreach ((array) ($change['fields'] ?? []) as $order) {
                foreach (['before', 'after'] as $side) {
                    foreach ((array) ($order[$side] ?? []) as $valueId) {
                        $valueIds[(int) $valueId] = true;
                    }
                }
            }
        }
        return $valueIds
            ? $this->planner()->setResourceType($resourceType)->valuesByIds(array_keys($valueIds))
            : [];
    }

    protected function propertyOptions(array $ids): array
    {
        $connection = $this->services->get('Omeka\Connection');
        $templateIds = [];
        foreach ($this->planner()->resourceInfo($ids) as $info) {
            if ($info['template_id']) {
                $templateIds[$info['template_id']] = true;
            }
        }
        $inTemplates = [];
        if ($templateIds) {
            $rows = $connection->fetchAllAssociative(
                'SELECT rtp.property_id, rtp.alternate_label, rt.label AS template_label, p.label, p.local_name, v.prefix'
                . ' FROM resource_template_property rtp'
                . ' JOIN resource_template rt ON rt.id = rtp.resource_template_id'
                . ' JOIN property p ON p.id = rtp.property_id JOIN vocabulary v ON v.id = p.vocabulary_id'
                . ' WHERE rtp.resource_template_id IN (?) ORDER BY rt.label, rtp.position',
                [array_keys($templateIds)],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $id = (int) $row['property_id'];
                if (!isset($inTemplates[$id])) {
                    $inTemplates[$id] = [
                        'id' => $id,
                        'term' => $row['prefix'] . ':' . $row['local_name'],
                        'label' => $row['alternate_label'] ?: $row['label'],
                        'templates' => [],
                    ];
                }
                $inTemplates[$id]['templates'][] = $row['template_label'];
            }
        }
        // Fields the selection has values in, so they are found without
        // scrolling the whole vocabularies.
        $used = [];
        if ($ids) {
            $rows = $connection->fetchAllAssociative(
                'SELECT DISTINCT property_id FROM value WHERE resource_id IN (?)',
                [array_map('intval', $ids)],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $used[(int) $row['property_id']] = true;
            }
        }
        $hidden = array_map('intval', (array) $this->services->get('Omeka\Settings')->get('batchvalues_hidden_vocabularies', []));
        $sql = 'SELECT p.id, p.label, p.local_name, v.prefix, v.label AS vocabulary FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id';
        $params = [];
        $types = [];
        if ($hidden) {
            $sql .= ' WHERE v.id NOT IN (?)';
            $params[] = $hidden;
            $types[] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        }
        $sql .= ' ORDER BY v.label, p.label';
        $vocabularies = [];
        $withValues = [];
        foreach ($connection->fetchAllAssociative($sql, $params, $types) as $row) {
            $id = (int) $row['id'];
            if (isset($inTemplates[$id])) {
                continue;
            }
            $option = [
                'id' => $id,
                'term' => $row['prefix'] . ':' . $row['local_name'],
                'label' => $row['label'],
            ];
            if (isset($used[$id])) {
                $withValues[] = $option;
                continue;
            }
            $vocabularies[$row['vocabulary']][] = $option;
        }
        return [
            'template' => array_values($inTemplates),
            'with_values' => $withValues,
            'vocabularies' => $vocabularies,
        ];
    }

    protected function templateCounts(array $ids): array
    {
        $counts = [];
        foreach ($this->planner()->resourceInfo($ids) as $info) {
            $key = $info['template_id'] ?: 0;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $labels = [];
        if ($counts) {
            $rows = [];
            foreach ($this->services->get('Omeka\Connection')->fetchAllAssociative('SELECT id, label FROM resource_template') as $row) {
                $rows[(int) $row['id']] = $row['label'];
            }
            foreach ($counts as $templateId => $count) {
                $labels[] = [
                    'label' => $templateId ? ($rows[$templateId] ?? '#' . $templateId) : null,
                    'count' => $count,
                ];
            }
        }
        usort($labels, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        return $labels;
    }

    protected function requiredTemplateLabels(int $propertyId): array
    {
        return $this->services->get('Omeka\Connection')->fetchFirstColumn(
            'SELECT rt.label FROM resource_template_property rtp JOIN resource_template rt ON rt.id = rtp.resource_template_id'
            . ' WHERE rtp.property_id = ? AND rtp.is_required = 1 ORDER BY rt.label',
            [$propertyId]
        );
    }

    protected function csrfElement(): Csrf
    {
        $csrf = new Csrf('batchvalues_csrf', ['csrf_options' => ['timeout' => 3600]]);
        return $csrf;
    }

    protected function checkCsrf(array $post): bool
    {
        $csrf = $this->csrfElement();
        $validator = $csrf->getCsrfValidator();
        if (!$validator->isValid($post['batchvalues_csrf'] ?? '')) {
            $this->messenger()->addError('The form expired. Please submit it again.'); // @translate
            return false;
        }
        return true;
    }

    protected function session(): SessionContainer
    {
        return new SessionContainer('BatchValues');
    }

    protected function storeState(array $state): string
    {
        $session = $this->session();
        $states = $session->states ?? [];
        // Keep only the most recent selections.
        uasort($states, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        $states = array_slice($states, 0, 9, true);
        $token = bin2hex(random_bytes(8));
        $state['time'] = time();
        $states[$token] = $state;
        $session->states = $states;
        return $token;
    }

    protected function loadState(string $token): ?array
    {
        $states = $this->session()->states ?? [];
        return $states[$token] ?? null;
    }

    protected function updateState(string $token, array $data): void
    {
        $session = $this->session();
        $states = $session->states ?? [];
        if (isset($states[$token])) {
            $states[$token] = array_merge($states[$token], $data);
            $session->states = $states;
        }
    }
}
