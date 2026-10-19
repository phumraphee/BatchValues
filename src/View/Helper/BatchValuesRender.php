<?php
namespace BatchValues\View\Helper;

use Interop\Container\ContainerInterface;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Entity\Value;

/**
 * Render a value snapshot (as stored in the log) with its data type's own
 * renderer, falling back to plain text when that is not possible (e.g. the
 * data type module was uninstalled or the linked resource was deleted).
 */
class BatchValuesRender extends AbstractHelper
{
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function __invoke(array $snapshot, bool $withMeta = true): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $html = null;
        try {
            $em = $this->services->get('Omeka\EntityManager');
            $dataTypes = $this->services->get('Omeka\DataTypeManager');
            if ($dataTypes->has((string) $snapshot['type'])) {
                $value = new Value;
                $value->setType((string) $snapshot['type']);
                $value->setValue($snapshot['value'] ?? null);
                $value->setUri($snapshot['uri'] ?? null);
                $value->setLang($snapshot['lang'] ?? null);
                $value->setIsPublic(!empty($snapshot['is_public']));
                if (!empty($snapshot['property_id'])) {
                    $property = $em->find(\Omeka\Entity\Property::class, (int) $snapshot['property_id']);
                    if ($property) {
                        $value->setProperty($property);
                    }
                }
                $ok = true;
                if (!empty($snapshot['value_resource_id'])) {
                    $resource = $em->find(\Omeka\Entity\Resource::class, (int) $snapshot['value_resource_id']);
                    if ($resource) {
                        $value->setValueResource($resource);
                    } else {
                        $ok = false;
                    }
                }
                if ($ok) {
                    // The data type's own renderer, called directly: this value
                    // is not attached to a resource, and listeners of
                    // "rep.value.html" may expect one (and throw).
                    $representation = new ValueRepresentation($value, $this->services);
                    $html = (string) $dataTypes->get((string) $snapshot['type'])->render($view, $representation, []);
                }
            }
        } catch (\Throwable $e) {
            $html = null;
        }
        if ($html === null || trim(strip_tags($html)) === '') {
            $parts = [];
            if (!empty($snapshot['value_resource_id'])) {
                $parts[] = '#' . (int) $snapshot['value_resource_id'];
            }
            if (isset($snapshot['value']) && $snapshot['value'] !== '') {
                $parts[] = $escape($snapshot['value']);
            }
            if (!empty($snapshot['uri'])) {
                $parts[] = '&lt;' . $escape($snapshot['uri']) . '&gt;';
            }
            $html = implode(' ', $parts) ?: '<em>' . $escape($view->translate('[empty]')) . '</em>';
        }
        if (!$withMeta) {
            return '<span class="bv-value">' . $html . '</span>';
        }
        $meta = [];
        $meta[] = $escape($this->typeLabel((string) $snapshot['type']));
        if (!empty($snapshot['lang'])) {
            $meta[] = $escape($snapshot['lang']);
        }
        if (isset($snapshot['is_public']) && !$snapshot['is_public']) {
            $meta[] = $escape($view->translate('private'));
        }
        return '<span class="bv-value">' . $html . '</span> <span class="bv-value-meta">' . implode(' · ', $meta) . '</span>';
    }

    /**
     * Render a stored value by id with its data type's renderer.
     */
    public function valueById(int $valueId): string
    {
        $escape = $this->getView()->plugin('escapeHtml');
        try {
            $value = $this->services->get('Omeka\EntityManager')->find(Value::class, $valueId);
            if ($value) {
                $html = (string) (new ValueRepresentation($value, $this->services))->asHtml();
                if (trim(strip_tags($html)) !== '' || strpos($html, '<img') !== false) {
                    return $html;
                }
                return '<em>' . $escape($this->getView()->translate('[empty]')) . '</em>';
            }
        } catch (\Throwable $e) {
        }
        return '<em>' . $escape($this->getView()->translate('[value not found]')) . '</em>';
    }

    public function typeLabel(string $type): string
    {
        $dataTypes = $this->services->get('Omeka\DataTypeManager');
        if (!$dataTypes->has($type)) {
            return $type;
        }
        $label = $this->getView()->translate($dataTypes->get($type)->getLabel());
        return $type === 'resource' ? $this->getView()->translate('Omeka resource') : $label;
    }

    /**
     * Before → after of value pairs.
     */
    public function pairsHtml(array $pairs, bool $showProperty = false): string
    {
        if ($pairs && count(array_filter($pairs, function ($p) { return ($p['reason'] ?? null) === 'sort'; })) === count($pairs)) {
            // Sorted, possibly in several properties (all properties).
            $byProperty = [];
            foreach ($pairs as $pair) {
                $byProperty[(int) (($pair['after'] ?? $pair['before'])['property_id'])][] = $pair;
            }
            if (count($byProperty) === 1 && !$showProperty) {
                return $this->sortedHtml($pairs);
            }
            $html = '';
            foreach ($byProperty as $propertyId => $list) {
                $html .= '<div class="bv-value-meta">' . $this->getView()->escapeHtml($this->propertyLabelOf($propertyId)) . '</div>' . $this->sortedHtml($list);
            }
            return $html;
        }
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $html = '';
        // Values taken off and put back only to keep the order (split): the
        // same content, so they are not shown as changes.
        $order = array_filter($pairs, function ($p) {
            return ($p['reason'] ?? null) === 'order';
        });
        if ($order) {
            $pairs = array_filter($pairs, function ($p) {
                return ($p['reason'] ?? null) !== 'order';
            });
        }
        $t = function ($text) use ($view) {
            return $view->translate($text);
        };
        foreach ($pairs as $pair) {
            $before = $pair['before'] ?? null;
            $after = $pair['after'] ?? null;
            $property = '';
            if ($showProperty) {
                $property = ' <span class="bv-value-meta">' . $escape($this->termOf((int) (($after ?? $before)['property_id']))) . '</span>';
            }
            if ($before && $after && (int) $before['property_id'] !== (int) $after['property_id']) {
                // Moved: same value, other property. Say so in one line.
                $html .= $this->lineHtml('moved', $t('Moved'), $this->__invoke($after) . $this->noteBadge($after)
                    . '<div class="bv-what">' . $escape($this->propertyLabelOf((int) $before['property_id']))
                    . ' <span class="bv-arrow">→</span> ' . $escape($this->propertyLabelOf((int) $after['property_id'])) . '</div>');
                continue;
            }
            if ($before && $after) {
                $html .= $this->lineHtml('changed', $t('Changed'), $this->editHtml($before, $after) . $property);
            } elseif ($after) {
                $html .= $this->lineHtml('added', $t('Added'), $this->__invoke($after) . $this->noteBadge($after) . $property);
            } elseif ($before) {
                $reasons = [
                    'merged' => 'the same value is already there, so this one is removed rather than duplicated', // @translate
                    'joined' => 'joined into the value above', // @translate
                ];
                $why = isset($reasons[$pair['reason'] ?? ''])
                    ? '<div class="bv-what">' . $escape($t($reasons[$pair['reason']])) . '</div>'
                    : '';
                if ($showProperty) {
                    $property = ' <span class="bv-value-meta">' . $escape($this->termOf((int) $before['property_id'])) . '</span>';
                }
                $html .= $this->lineHtml('removed', $t('Removed'), '<span class="bv-struck">' . $this->__invoke($before) . $this->noteBadge($before) . '</span>' . $property . $why);
            }
        }
        if ($order) {
            $html .= '<div class="bv-muted">' . $escape(sprintf($view->translate('%d later value(s) taken off and put back after the new ones, to keep the order; they do not change.'), count($order) / 2)) . '</div>';
        }
        return $html;
    }

    /**
     * One field of one resource in two columns (0.8.0): the whole field as
     * it was before the run on the left and as it is after on the right,
     * values in their order and numbered, each change in its own place.
     * Runs of more than five values the run did not touch are folded away.
     *
     * The same display is used on the preview page (where the left column is
     * the field as it is now and the right one is what the run will make of
     * it) and on the history page.
     *
     * @param array $rows Planner rows of the field before the run.
     * @param array $pairs Pairs of the change row.
     * @param int $propertyId
     * @param array $opts allowed: data types of the template · before_label,
     *   after_label: the two column headings.
     */
    public function gridHtml(array $rows, array $pairs, int $propertyId, array $opts = []): string
    {
        if ($pairs && count(array_filter($pairs, function ($p) { return ($p['reason'] ?? null) === 'sort'; })) === count($pairs)) {
            // Sorting: the same values, in another order. Place by place.
            $was = array_values(array_filter(array_column($pairs, 'before')));
            $will = array_values(array_filter(array_column($pairs, 'after')));
            $lines = [];
            for ($i = 0, $n = max(count($was), count($will)); $i < $n; ++$i) {
                $b = $was[$i] ?? null;
                $a = $will[$i] ?? null;
                $lines[] = [
                    'before' => $b,
                    'after' => $a,
                    'kind' => $b && $a && ($b['value_id'] ?? 0) === ($a['value_id'] ?? -1) ? 'same' : 'reordered',
                ];
            }
            return $this->gridLinesHtml($lines, $opts);
        }
        return $this->gridLinesHtml($this->gridLines($rows, $pairs, $propertyId), $opts);
    }

    /**
     * Line by line, what the two columns hold: ['before' => snapshot|null,
     * 'after' => snapshot|null, 'kind' => same|changed|removed|moved|added].
     */
    public function gridLines(array $rows, array $pairs, int $propertyId): array
    {
        $byId = [];
        foreach ($pairs as $pair) {
            if (!empty($pair['value_id']) && ($pair['before'] ?? null) !== null) {
                $byId[(int) $pair['value_id']] = $pair;
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            $snapshot = \BatchValues\Stdlib\Planner::snapshotFromRow($row);
            $pair = $byId[(int) $row['id']] ?? null;
            if ($pair === null) {
                $lines[] = ['before' => $snapshot, 'after' => $snapshot, 'kind' => 'same'];
                continue;
            }
            if (($pair['reason'] ?? null) === 'order') {
                // Taken off and put back further down only to keep the order.
                continue;
            }
            $after = $pair['after'] ?? null;
            if ($after === null) {
                $lines[] = ['before' => $pair['before'], 'after' => null, 'kind' => 'removed', 'reason' => $pair['reason'] ?? null];
            } elseif ((int) ($after['property_id'] ?? 0) !== $propertyId) {
                $lines[] = ['before' => $pair['before'], 'after' => null, 'kind' => 'moved', 'to' => (int) $after['property_id']];
            } else {
                $lines[] = ['before' => $pair['before'], 'after' => $after, 'kind' => 'changed'];
            }
        }
        foreach ($pairs as $pair) {
            $after = $pair['after'] ?? null;
            $before = $pair['before'] ?? null;
            if ($after === null || (int) ($after['property_id'] ?? 0) !== $propertyId) {
                continue;
            }
            if ($before !== null && (int) ($before['property_id'] ?? 0) === $propertyId) {
                // Changed in place: already on its line above.
                continue;
            }
            if (($pair['reason'] ?? null) === 'order') {
                $lines[] = ['before' => $after, 'after' => $after, 'kind' => 'same'];
                continue;
            }
            $line = ['before' => null, 'after' => $after, 'kind' => 'added', 'reason' => $pair['reason'] ?? null];
            if ($before !== null) {
                // Came from another property (copy, move).
                $line['from'] = (int) ($before['property_id'] ?? 0);
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * The two columns of one field of a run that already happened (0.8.0),
     * from the order kept in the log: the ids of the field before and after
     * the run. Values the run changed or removed are shown as the log kept
     * them; values it did not touch are read as they are now.
     *
     * @param array $order ['before' => [value id, …], 'after' => [value id, …]]
     * @param array $pairs Pairs of the change row.
     * @param int $propertyId
     * @param array $live [value id => Planner row] of the values still there.
     */
    /**
     * The two columns of every field one logged change touched (0.8.0), with
     * a heading per field when it touched more than one. Null when the change
     * is not about values (files, template, owner, visibility of a resource),
     * so the caller shows its own display.
     *
     * @param array $change A row of the log.
     * @param array $live [value id => row] for runs that kept the order.
     * @param array $current [property id => [resource id => rows]] for runs
     *   made before 0.8.0.
     */
    public function gridsOf(array $change, array $live = [], array $current = []): ?string
    {
        $escape = $this->getView()->plugin('escapeHtml');
        if (isset($change['resource']) || isset($change['values']['resource'])) {
            return null;
        }
        $pairs = \BatchValues\Stdlib\Processor::pairsOf($change);
        if (!$pairs) {
            return null;
        }
        $resourceId = (int) ($change['resource_id'] ?? 0);
        $fields = $change['fields'] ?? null;
        if (!$fields) {
            // Before 0.8.0 the order was not kept: rebuild it.
            $properties = [];
            foreach ($pairs as $pair) {
                foreach (['before', 'after'] as $side) {
                    if (!empty($pair[$side]['property_id'])) {
                        $properties[(int) $pair[$side]['property_id']] = true;
                    }
                }
            }
            if (!$properties) {
                return null;
            }
            $html = '';
            foreach (array_keys($properties) as $propertyId) {
                $html .= '<div class="bv-field-grid">'
                    . (count($properties) > 1 ? $this->fieldHeadHtml($propertyId) : '')
                    . $this->gridFromPairs($current[$propertyId][$resourceId] ?? [], $pairs, $propertyId)
                    . '</div>';
            }
            return $html . '<div class="bv-muted bv-rebuilt-note">'
                . $escape($this->getView()->translate('This run is older than the two columns: it did not keep the order of the field, so the left column is rebuilt from the log and from the field as it is now.'))
                . '</div>';
        }
        $html = '';
        foreach ($fields as $propertyId => $order) {
            $html .= '<div class="bv-field-grid">'
                . (count($fields) > 1 ? $this->fieldHeadHtml((int) $propertyId) : '')
                . $this->gridFromLog($order, $pairs, (int) $propertyId, $live)
                . '</div>';
        }
        return $html;
    }

    protected function fieldHeadHtml(int $propertyId): string
    {
        $escape = $this->getView()->plugin('escapeHtml');
        return '<div class="bv-field-head"><strong>' . $escape($this->propertyLabel($propertyId)) . '</strong> '
            . '<span class="bv-value-meta">' . $escape($this->propertyTerm($propertyId)) . '</span></div>';
    }

    /**
     * The same two columns for a run made before 0.8.0, which did not keep
     * the order of the field (0.8.0): the right column is the field as it is
     * now, the left one is rebuilt from it and from the log — the values the
     * run removed are put back in their place, the ones it added are taken
     * out again. Omeka keeps the values of a field in the order of their id,
     * so the places are right as long as nothing else changed the field since
     * that run.
     *
     * @param array $rows Planner rows of the field as it is now.
     * @param array $pairs Pairs of the change row.
     */
    public function gridFromPairs(array $rows, array $pairs, int $propertyId, array $opts = []): string
    {
        $key = function (?array $s) {
            return $s === null ? null : implode('|', [
                $s['type'] ?? '', $s['value'] ?? '', $s['uri'] ?? '',
                $s['value_resource_id'] ?? '', $s['lang'] ?? '', (int) (bool) ($s['is_public'] ?? true),
            ]);
        };
        $byId = [];
        $incoming = [];
        $gone = [];
        foreach ($pairs as $pair) {
            $before = $pair['before'] ?? null;
            $after = $pair['after'] ?? null;
            $fromHere = $before !== null && (int) ($before['property_id'] ?? 0) === $propertyId;
            $toHere = $after !== null && (int) ($after['property_id'] ?? 0) === $propertyId;
            if ($fromHere && $toHere) {
                if (!empty($pair['value_id'])) {
                    $byId[(int) $pair['value_id']] = $pair;
                }
                continue;
            }
            if ($toHere) {
                $incoming[] = $pair;
            }
            if ($fromHere) {
                $gone[] = $pair;
            }
        }
        // Values that came in are matched to what is in the field now by
        // their content, the last ones first: they were appended.
        $taken = [];
        $lines = [];
        $seen = [];
        foreach (array_reverse($rows) as $row) {
            $snapshot = \BatchValues\Stdlib\Planner::snapshotFromRow($row);
            $id = (int) $row['id'];
            $pair = $byId[$id] ?? null;
            if ($pair !== null) {
                $seen[$id] = true;
                $lines[] = ['id' => $id, 'before' => $pair['before'], 'after' => $snapshot, 'kind' => 'changed'];
                continue;
            }
            $match = null;
            foreach ($incoming as $i => $candidate) {
                if (!isset($taken[$i]) && $key($candidate['after'] ?? null) === $key($snapshot)) {
                    $match = $candidate;
                    $taken[$i] = true;
                    break;
                }
            }
            if ($match !== null) {
                $line = ['id' => $id, 'before' => null, 'after' => $snapshot, 'kind' => 'added', 'reason' => $match['reason'] ?? null];
                if (($match['before'] ?? null) !== null) {
                    $line['from'] = (int) ($match['before']['property_id'] ?? 0);
                }
                if (($match['reason'] ?? null) === 'order') {
                    $line['kind'] = 'same';
                    $line['before'] = $snapshot;
                }
                $lines[] = $line;
                continue;
            }
            $lines[] = ['id' => $id, 'before' => $snapshot, 'after' => $snapshot, 'kind' => 'same'];
        }
        // Values this run changed that are not in the field any more: they
        // cannot be placed in the two columns, so they are listed under them.
        $orphans = [];
        foreach ($byId as $id => $pair) {
            if (!isset($seen[$id])) {
                $orphans[] = $pair;
            }
        }
        foreach ($gone as $pair) {
            $after = $pair['after'] ?? null;
            $to = $after !== null ? (int) ($after['property_id'] ?? 0) : 0;
            $lines[] = [
                'id' => (int) ($pair['value_id'] ?? 0),
                'before' => $pair['before'],
                'after' => null,
                'kind' => $to ? 'moved' : 'removed',
                'to' => $to,
                'reason' => $pair['reason'] ?? null,
            ];
        }
        usort($lines, function ($a, $b) {
            return $a['id'] <=> $b['id'];
        });
        $html = $this->gridLinesHtml($lines, $opts + ['before_label' => 'Before (rebuilt)', 'after_label' => 'Now']);
        if ($orphans) {
            $escape = $this->getView()->plugin('escapeHtml');
            $html .= '<div class="bv-muted bv-rebuilt-note">'
                . $escape(sprintf(
                    $this->getView()->translate('This run also changed %d value(s) that are not in the field any more; this is what it did to them:'),
                    count($orphans)
                )) . '</div>' . $this->pairsHtml($orphans);
        }
        return $html;
    }

    public function gridFromLog(array $order, array $pairs, int $propertyId, array $live, array $opts = []): string
    {
        $before = array_map('intval', $order['before'] ?? []);
        $after = array_map('intval', $order['after'] ?? []);
        $byId = [];
        $reordering = $pairs && count(array_filter($pairs, function ($p) {
            return in_array($p['reason'] ?? null, ['sort', 'order'], true);
        })) === count($pairs);
        foreach ($pairs as $pair) {
            if (!empty($pair['value_id'])) {
                $byId[(int) $pair['value_id']] = $pair;
            }
        }
        $newPairs = [];
        foreach ($pairs as $pair) {
            $a = $pair['after'] ?? null;
            // Pairs that put a new value in this field: values added, values
            // that came from another field, and values taken off and put back
            // (they come back with a new id).
            if ($a !== null && (int) ($a['property_id'] ?? 0) === $propertyId
                && (empty($pair['value_id']) || !in_array((int) $pair['value_id'], $after, true))
            ) {
                $newPairs[] = $pair;
            }
        }
        $inAfter = array_flip($after);
        $inBefore = array_flip($before);
        $snapshot = function (int $id, ?array $fallback) use ($live, $propertyId) {
            if (isset($live[$id])) {
                return \BatchValues\Stdlib\Planner::snapshotFromRow($live[$id]);
            }
            // The run did not touch this value and it is not there any more:
            // something else removed it since.
            return $fallback ?: ['value_id' => $id, 'property_id' => $propertyId, 'type' => 'literal', 'value' => null, 'bv_missing' => true];
        };
        if ($reordering) {
            // Sorting takes the values off and puts them back, so they come
            // back with new ids: place by place, not id by id.
            //
            // The left column is what the log kept, not what that id holds
            // today: Omeka reuses the value rows of the whole resource when it
            // saves, so an id from before the run can hold something else now
            // (0.11.0). The live value is only a fallback, for a run written
            // before the snapshots were kept.
            $lines = [];
            $wasSnapshot = function (int $id) use ($byId, $snapshot) {
                $kept = $byId[$id]['before'] ?? null;
                return $kept !== null ? $kept : $snapshot($id, null);
            };
            $key = function (?array $s) {
                return $s === null ? null : implode('|', [
                    $s['type'] ?? '', $s['value'] ?? '', $s['uri'] ?? '',
                    $s['value_resource_id'] ?? '', $s['lang'] ?? '', (int) (bool) ($s['is_public'] ?? true),
                ]);
            };
            for ($k = 0, $n = max(count($before), count($after)); $k < $n; ++$k) {
                $b = isset($before[$k]) ? $wasSnapshot($before[$k]) : null;
                $a = isset($after[$k]) ? $snapshot($after[$k], null) : null;
                $lines[] = ['before' => $b, 'after' => $a, 'kind' => $key($b) === $key($a) ? 'same' : 'reordered'];
            }
            return $this->gridLinesHtml($lines, $opts);
        }
        $lines = [];
        $i = $j = 0;
        while ($i < count($before) || $j < count($after)) {
            $b = $before[$i] ?? null;
            $a = $after[$j] ?? null;
            if ($b !== null && $a !== null && $b === $a) {
                $pair = $byId[$b] ?? null;
                $has = $pair && ($pair['before'] ?? null) !== null && ($pair['after'] ?? null) !== null;
                $lines[] = $has
                    ? ['before' => $pair['before'], 'after' => $pair['after'], 'kind' => 'changed']
                    : ['before' => $snapshot($b, null), 'after' => $snapshot($b, null), 'kind' => 'same', 'gone' => !isset($live[$b])];
                ++$i;
                ++$j;
                continue;
            }
            if ($b !== null && !isset($inAfter[$b])) {
                $pair = $byId[$b] ?? null;
                if ($pair && ($pair['reason'] ?? null) === 'order') {
                    // Taken off and put back further down, to keep the order.
                    $lines[] = ['before' => $snapshot($b, $pair['before'] ?? null), 'after' => null, 'kind' => 'same'];
                    ++$i;
                    continue;
                }
                $to = $pair && ($pair['after'] ?? null) !== null ? (int) $pair['after']['property_id'] : 0;
                $lines[] = [
                    'before' => $snapshot($b, $pair['before'] ?? null),
                    'after' => null,
                    'kind' => $to && $to !== $propertyId ? 'moved' : 'removed',
                    'to' => $to,
                    'reason' => $pair['reason'] ?? null,
                ];
                ++$i;
                continue;
            }
            if ($a !== null && !isset($inBefore[$a])) {
                $pair = array_shift($newPairs);
                $line = [
                    'before' => null,
                    'after' => $snapshot($a, $pair['after'] ?? null),
                    'kind' => ($pair['reason'] ?? null) === 'order' ? 'same' : 'added',
                    'gone' => !isset($live[$a]),
                ];
                if ($pair && ($pair['before'] ?? null) !== null) {
                    $line['from'] = (int) ($pair['before']['property_id'] ?? 0);
                }
                $lines[] = $line;
                ++$j;
                continue;
            }
            // Both are there but not in the same place: the order changed.
            $lines[] = ['before' => $snapshot((int) $b, null), 'after' => $snapshot((int) $a, null), 'kind' => 'reordered'];
            ++$i;
            ++$j;
        }
        return $this->gridLinesHtml($lines, $opts);
    }

    /**
     * The two columns of one field, from the lines of gridLines().
     */
    public function gridLinesHtml(array $lines, array $opts = []): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $allowed = $opts['allowed'] ?? [];
        $fold = (int) ($opts['fold'] ?? 5);
        $before = $after = 0;
        foreach ($lines as $line) {
            $before += $line['before'] !== null ? 1 : 0;
            $after += $line['after'] !== null ? 1 : 0;
        }
        $n = ['before' => 0, 'after' => 0];
        $sideLabel = [
            'before' => $translate((string) ($opts['before_label'] ?? 'Before')),
            'after' => $translate((string) ($opts['after_label'] ?? 'After')),
        ];
        $cell = function (?array $snapshot, string $side, string $kind, array $line) use (&$n, $escape, $translate, $allowed, $view, $sideLabel) {
            if ($snapshot === null) {
                return '<div class="bv-cell bv-cell-none"></div>';
            }
            ++$n[$side];
            $mark = $side === 'before'
                ? ($kind === 'removed' || $kind === 'moved' ? 'out' : ($kind === 'changed' ? 'was' : ($kind === 'reordered' ? 'moved-line' : 'same')))
                : ($kind === 'added' ? 'in' : ($kind === 'changed' ? 'now' : ($kind === 'reordered' ? 'moved-line' : 'same')));
            // On a narrow screen the two columns are stacked, so each cell
            // says which side it is.
            $caption = '<span class="bv-side">' . $escape($sideLabel[$side]) . '</span>';
            if (!empty($snapshot['bv_missing'])) {
                return '<div class="bv-cell bv-cell-gone">' . $caption . '<span class="bv-n">' . (++$n[$side]) . '</span>'
                    . '<div class="bv-cell-body"><em class="bv-muted">' . $escape($translate('this value is not there any more (removed after this run)')) . '</em></div></div>';
            }
            $body = '';
            if ($kind === 'changed') {
                [$b, $a] = $this->editCells($line['before'], $line['after']);
                $body = $side === 'before' ? $b : $a;
                // Only spaces or characters that cannot be seen: say it in
                // words, on the "after" side (0.9.2).
                $said = $side === 'after'
                    ? self::invisibleChange($line['before']['value'] ?? null, $line['after']['value'] ?? null)
                    : null;
                if ($said) {
                    $body .= '<div class="bv-invisible-note">' . $escape($translate('Only what cannot be seen changed:')) . ' ' . $escape($translate($said)) . '</div>';
                }
            } else {
                $body = $this->__invoke($snapshot) . $this->noteBadge($snapshot);
            }
            if ($mark === 'out') {
                $body = '<span class="bv-struck">' . $body . '</span>';
            }
            $note = '';
            if ($side === 'after' && $kind === 'added' && !empty($line['from'])) {
                $note = '<div class="bv-what">' . $escape(sprintf($translate('came from %s'), $this->propertyLabel((int) $line['from']))) . '</div>';
            } elseif ($side === 'before' && $kind === 'moved') {
                $note = '<div class="bv-what">' . $escape(sprintf($translate('moved to %s'), $this->propertyLabel((int) $line['to']))) . '</div>';
            } elseif ($side === 'before' && $kind === 'removed' && ($line['reason'] ?? null)) {
                $reasons = [
                    'merged' => 'the same value is already there, so this one is removed rather than duplicated', // @translate
                    'joined' => 'joined into the value above', // @translate
                ];
                if (isset($reasons[$line['reason']])) {
                    $note = '<div class="bv-what">' . $escape($translate($reasons[$line['reason']])) . '</div>';
                }
            } elseif ($side === 'before' && $kind === 'same' && $allowed && !in_array((string) ($snapshot['type'] ?? ''), $allowed, true)) {
                $note = ' <span class="bv-flag" title="' . $escape(sprintf($translate('The template expects: %s'), implode(', ', array_map([$this, 'typeLabel'], $allowed)))) . '">'
                    . $escape($translate('not the template\'s data type')) . '</span>';
            }
            return '<div class="bv-cell bv-cell-' . $mark . '">' . $caption . '<span class="bv-n">' . $n[$side] . '</span>'
                . '<div class="bv-cell-body">' . $body . $note . '</div></div>';
        };
        $row = function (array $line) use ($cell) {
            return '<div class="bv-grid-row bv-row-' . $line['kind'] . '">'
                . $cell($line['before'], 'before', $line['kind'], $line)
                . $cell($line['after'], 'after', $line['kind'], $line) . '</div>';
        };
        $html = '<div class="bv-grid"><div class="bv-grid-row bv-grid-head">'
            . '<div class="bv-cell">' . $escape($translate((string) ($opts['before_label'] ?? 'Before'))) . ' <span class="bv-n-total">' . $before . '</span></div>'
            . '<div class="bv-cell">' . $escape($translate((string) ($opts['after_label'] ?? 'After'))) . ' <span class="bv-n-total">' . $after . '</span></div></div>';
        $run = [];
        $flush = function () use (&$run, $row, $fold, $escape, $translate) {
            if (!$run) {
                return '';
            }
            $out = '';
            if (count($run) > $fold) {
                $out = '<details class="bv-grid-fold"><summary>' . $escape(sprintf($translate('%d values not touched'), count($run))) . '</summary>';
                foreach ($run as $line) {
                    $out .= $row($line);
                }
                $out .= '</details>';
            } else {
                foreach ($run as $line) {
                    $out .= $row($line);
                }
            }
            $run = [];
            return $out;
        };
        foreach ($lines as $line) {
            if ($line['kind'] === 'same') {
                $run[] = $line;
                continue;
            }
            $html .= $flush() . $row($line);
        }
        $html .= $flush();
        if (!$lines) {
            $html .= '<div class="bv-grid-row"><div class="bv-cell"><em class="bv-muted">' . $escape($translate('(no value)')) . '</em></div>'
                . '<div class="bv-cell"><em class="bv-muted">' . $escape($translate('(no value)')) . '</em></div></div>';
        }
        return $html . '</div>';
    }

    /**
     * Old order → new order, as two numbered lists.
     */
    public function sortedHtml(array $pairs): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $list = function (array $snapshots) {
            $html = '<ol class="bv-order">';
            foreach ($snapshots as $snapshot) {
                $html .= '<li>' . $this->__invoke($snapshot) . $this->noteBadge($snapshot) . '</li>';
            }
            return $html . '</ol>';
        };
        $before = array_values(array_filter(array_column($pairs, 'before')));
        $after = array_values(array_filter(array_column($pairs, 'after')));
        return '<div class="bv-sorted"><div><span class="bv-value-meta">' . $escape($view->translate('now')) . '</span>' . $list($before) . '</div>'
            . '<div class="bv-arrow">→</div><div><span class="bv-value-meta">' . $escape($view->translate('after')) . '</span>' . $list($after) . '</div></div>';
    }

    /**
     * One value line: what happens to it (Added, Removed, Changed, Moved or
     * nothing for a value that stays) and the value (0.6.5).
     */
    protected function lineHtml(string $kind, string $label, string $content): string
    {
        return '<div class="bv-line bv-line-' . $kind . '"><span class="bv-lop">' . $this->getView()->escapeHtml($label) . '</span>'
            . '<div class="bv-lval">' . $content . '</div></div>';
    }

    /**
     * A value before and after. When only the language, the visibility or the
     * data type differ, one line says it in words; plain texts are shown side
     * by side with the words that differ marked; anything else side by side.
     */
    protected function editHtml(array $before, array $after): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $same = function (array $keys) use ($before, $after) {
            foreach ($keys as $k) {
                if ((string) ($before[$k] ?? '') !== (string) ($after[$k] ?? '')) {
                    return false;
                }
            }
            return true;
        };
        $words = [];
        if ((string) ($before['lang'] ?? '') !== (string) ($after['lang'] ?? '')) {
            $words[] = sprintf($view->translate('Language: %1$s → %2$s'), ($before['lang'] ?? '') ?: '—', ($after['lang'] ?? '') ?: '—');
        }
        if ((bool) ($before['is_public'] ?? true) !== (bool) ($after['is_public'] ?? true)) {
            $words[] = !empty($after['is_public']) ? $view->translate('Private → Public') : $view->translate('Public → Private');
        }
        if ((string) ($before['type'] ?? '') !== (string) ($after['type'] ?? '')) {
            $words[] = sprintf($view->translate('Data type: %1$s → %2$s'), $this->typeLabel((string) $before['type']), $this->typeLabel((string) $after['type']));
        }
        if ($same(['value', 'uri', 'value_resource_id'])) {
            return $this->__invoke($after) . $this->noteBadge($after) . ($words ? '<div class="bv-what">' . $escape(implode(' · ', $words)) . '</div>' : '');
        }
        [$b, $a] = $this->editCells($before, $after);
        return '<div class="bv-ba"><div class="bv-b"><span class="bv-cap">' . $escape($view->translate('Before')) . '</span>' . $b . '</div>'
            . '<div class="bv-a"><span class="bv-cap">' . $escape($view->translate('After')) . '</span>' . $a . '</div></div>';
    }

    /**
     * What changed, in words, when the two texts read the same on the screen
     * (0.9.2): spaces and characters that cannot be seen. Without this a run
     * that took out a non-breaking space looks like a change of nothing.
     *
     * @return string|null
     */
    public static function invisibleChange(?string $before, ?string $after): ?string
    {
        if ($before === null || $after === null || $before === $after) {
            return null;
        }
        $names = [
            "\u{00A0}" => 'non-breaking space', // @translate
            "\u{200B}" => 'zero-width space', // @translate
            "\u{FEFF}" => 'byte order mark', // @translate
            "\u{2060}" => 'word joiner', // @translate
            "\t" => 'tab', // @translate
        ];
        // The same text once every space and invisible character is left out?
        $bare = function (string $text) use ($names) {
            return preg_replace('/\s+/u', '', str_replace(array_keys($names), '', $text));
        };
        if ($bare($before) !== $bare($after)) {
            return null;
        }
        $translate = function (string $text) {
            return $text;
        };
        $spaces = function (string $text) {
            return (int) preg_match_all('/ /u', $text);
        };
        $breaks = function (string $text) {
            return (int) preg_match_all('/\r\n|\n|\r/u', $text);
        };
        $said = [];
        $spaceGain = $spaces($after) - $spaces($before);
        foreach ($names as $char => $name) {
            $gone = substr_count($before, $char) - substr_count($after, $char);
            if ($gone > 0 && $spaceGain >= $gone) {
                // Replaced by a plain space, which is what "Clean up" does.
                $said[] = sprintf('%1$d × %2$s → space', $gone, $name); // @translate
                $spaceGain -= $gone;
            } elseif ($gone > 0) {
                $said[] = sprintf('%1$d × %2$s taken out', $gone, $name); // @translate
            } elseif ($gone < 0) {
                $said[] = sprintf('%1$d × %2$s put in', -$gone, $name); // @translate
            }
        }
        if ($spaceGain < 0) {
            $said[] = sprintf('%d × space taken out', -$spaceGain); // @translate
        } elseif ($spaceGain > 0) {
            $said[] = sprintf('%d × space put in', $spaceGain); // @translate
        }
        $breakGain = $breaks($after) - $breaks($before);
        if ($breakGain !== 0) {
            $said[] = $breakGain < 0
                ? sprintf('%d × line break taken out', -$breakGain) // @translate
                : sprintf('%d × line break put in', $breakGain); // @translate
        }
        if (!$said) {
            $said[] = 'spaces changed'; // @translate
        }
        return implode(' · ', $said);
    }

    /**
     * The two sides of a change, each on its own: the words that differ are
     * marked in both when the values are plain text (0.8.0).
     *
     * @return array [before html, after html]
     */
    protected function editCells(array $before, array $after): array
    {
        $escape = $this->getView()->plugin('escapeHtml');
        $plain = function (array $s) {
            return empty($s['uri']) && empty($s['value_resource_id']) && isset($s['value'])
                && \BatchValues\Stdlib\Planner::isTextType((string) $s['type']);
        };
        if ($plain($before) && $plain($after)) {
            // Characters that cannot be seen are named (NBSP, ZWSP, BOM, WJ,
            // TAB, ↵): a change of those is otherwise a change of nothing on
            // the screen (0.9.2).
            [$b, $a] = self::marked((string) $before['value'], (string) $after['value'], $escape, 0, true);
            $b = '<span class="bv-value">' . $b . '</span> ' . $this->metaHtml($before);
            $a = '<span class="bv-value">' . $a . '</span> ' . $this->metaHtml($after);
        } else {
            $b = $this->__invoke($before);
            $a = $this->__invoke($after);
        }
        return [$b . $this->noteBadge($before), $a . $this->noteBadge($after)];
    }

    /**
     * Data type, language and visibility of a value, as after the rendered value.
     */
    protected function metaHtml(array $snapshot): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $meta = [$escape($this->typeLabel((string) $snapshot['type']))];
        if (!empty($snapshot['lang'])) {
            $meta[] = $escape($snapshot['lang']);
        }
        if (isset($snapshot['is_public']) && !$snapshot['is_public']) {
            $meta[] = $escape($view->translate('private'));
        }
        return '<span class="bv-value-meta">' . implode(' · ', $meta) . '</span>';
    }

    /**
     * Two texts, with the words that differ wrapped in <del> and <ins>. A run
     * of Thai (no spaces between words) is split by written character, the
     * rest into words and spaces. Adapted from Revision History
     * (RevisionHistoryShow::marked), same author.
     *
     * @return array{0:string, 1:string}
     */
    public static function marked(string $b, string $a, callable $e, int $context = 0, bool $visible = false): array
    {
        // $visible: characters that cannot be seen are named (NBSP, ZWSP…)
        // and line breaks drawn as ↵. $context: text that does not change is
        // cut to this many characters around each change.
        $esc = function (string $text, bool $hardSpaces = false) use ($e, $visible) {
            $html = $e($text);
            if ($hardSpaces) {
                // Spaces inside a mark are drawn, so a change of spaces shows.
                // Done before the names below, or their own spaces would be
                // replaced inside the markup (0.9.2).
                $html = str_replace(' ', '&nbsp;', $html);
            }
            if (!$visible) {
                return nl2br($html);
            }
            return strtr($html, [
                "\u{00A0}" => '<span class="bv-char" title="non-breaking space">NBSP</span>',
                "\u{200B}" => '<span class="bv-char" title="zero-width space">ZWSP</span>',
                "\u{FEFF}" => '<span class="bv-char" title="byte order mark">BOM</span>',
                "\u{2060}" => '<span class="bv-char" title="word joiner">WJ</span>',
                "\t" => '<span class="bv-char" title="tab">TAB</span>',
                "\r\n" => '<span class="bv-char" title="line break">↵</span>',
                "\n" => '<span class="bv-char" title="line break">↵</span>',
            ]);
        };
        $cut = function (string $text, string $where) use ($context) {
            if ($context <= 0) {
                return $text;
            }
            preg_match_all('/\X/u', $text, $m);
            $g = $m[0];
            if (count($g) <= ($where === 'mid' ? 2 * $context + 5 : $context + 5)) {
                return $text;
            }
            if ($where === 'head') {
                return '…' . implode('', array_slice($g, -$context));
            }
            if ($where === 'tail') {
                return implode('', array_slice($g, 0, $context)) . '…';
            }
            return implode('', array_slice($g, 0, $context)) . ' … ' . implode('', array_slice($g, -$context));
        };
        $split = function (string $s) {
            preg_match_all('/(?=[\x{0E00}-\x{0E7F}])\X|[^\s\x{0E00}-\x{0E7F}]+|\s+/u', $s, $m);
            return $m[0];
        };
        $x = $split($b);
        $y = $split($a);
        $n = count($x);
        $m = count($y);
        if ($n * $m > 250000) {
            return ['<del>' . $esc($b) . '</del>', '<ins>' . $esc($a) . '</ins>'];
        }
        $pre = 0;
        while ($pre < $n && $pre < $m && $x[$pre] === $y[$pre]) {
            $pre++;
        }
        $suf = 0;
        while ($suf < $n - $pre && $suf < $m - $pre && $x[$n - 1 - $suf] === $y[$m - 1 - $suf]) {
            $suf++;
        }
        $xs = array_slice($x, $pre, $n - $pre - $suf);
        $ys = array_slice($y, $pre, $m - $pre - $suf);
        $p = count($xs);
        $q = count($ys);
        $L = array_fill(0, $p + 1, array_fill(0, $q + 1, 0));
        for ($i = $p - 1; $i >= 0; $i--) {
            for ($j = $q - 1; $j >= 0; $j--) {
                $L[$i][$j] = $xs[$i] === $ys[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $bo = [];
        $ao = [];
        $i = 0;
        $j = 0;
        while ($i < $p || $j < $q) {
            if ($i < $p && $j < $q && $xs[$i] === $ys[$j]) {
                $bo[] = [0, $xs[$i]];
                $ao[] = [0, $ys[$j]];
                $i++;
                $j++;
            } elseif ($j < $q && ($i >= $p || $L[$i][$j + 1] >= $L[$i + 1][$j])) {
                $ao[] = [1, $ys[$j]];
                $j++;
            } else {
                $bo[] = [1, $xs[$i]];
                $i++;
            }
        }
        // Spaces are drawn visibly inside a mark, so a change of spaces shows.
        $mark = function (string $run, string $tag) use ($e, $esc) {
            if (trim($run) === '' || preg_match('/^\s|\s$/u', $run)) {
                return "<$tag class=\"bv-ws\" title=\"" . $e('space') . '">' . $esc($run, true) . "</$tag>";
            }
            return "<$tag>" . $esc($run) . "</$tag>";
        };
        $join = function (array $head, array $mid, array $tail, string $tag) use ($esc, $cut, $mark) {
            $out = $esc($cut(implode('', $head), 'head'));
            $run = '';
            $open = false;
            foreach ($mid as [$diff, $tok]) {
                if ($diff && !$open) {
                    $out .= $esc($cut($run, 'mid'));
                    $run = '';
                    $open = true;
                } elseif (!$diff && $open) {
                    $out .= $mark($run, $tag);
                    $run = '';
                    $open = false;
                }
                $run .= $tok;
            }
            $out .= $open ? $mark($run, $tag) : $esc($cut($run, 'mid'));
            $out .= $esc($cut(implode('', $tail), 'tail'));
            return $out;
        };
        $head = array_slice($x, 0, $pre);
        return [
            $b === '' ? '<em>' . $e('(empty)') . '</em>' : $join($head, $bo, array_slice($x, $n - $suf), 'del'),
            $a === '' ? '<em>' . $e('(empty)') . '</em>' : $join($head, $ao, array_slice($y, $m - $suf), 'ins'),
        ];
    }

    /** A file size in words. */
    public function bytes(int $size): string
    {
        if ($size <= 0) {
            return '—';
        }
        foreach ([['MB', 1048576], ['kB', 1024]] as [$unit, $n]) {
            if ($size >= $n) {
                return round($size / $n, $size >= 10 * $n ? 0 : 1) . ' ' . $unit;
            }
        }
        return $size . ' B';
    }

    protected function noteBadge(array $snapshot): string
    {
        if (empty($snapshot['annotation']) && empty($snapshot['had_annotation'])) {
            return '';
        }
        return ' <span class="bv-flag bv-flag-note">' . $this->getView()->escapeHtml($this->getView()->translate('annotation')) . '</span>';
    }

    protected function onlyVisibilityChanged(array $a, array $b): bool
    {
        foreach (['type', 'value', 'uri', 'value_resource_id', 'lang'] as $k) {
            if ((string) ($a[$k] ?? '') !== (string) ($b[$k] ?? '')) {
                return false;
            }
        }
        return (bool) ($a['is_public'] ?? true) !== (bool) ($b['is_public'] ?? true);
    }

    /**
     * "Label (term)" of a property.
     */
    protected function propertyLabelOf(int $propertyId): string
    {
        static $labels = [];
        if (!isset($labels[$propertyId])) {
            $label = $this->services->get('Omeka\Connection')->fetchOne('SELECT label FROM property WHERE id = ?', [$propertyId]);
            $labels[$propertyId] = ($label ? $this->getView()->translate($label) . ' ' : '') . '(' . $this->termOf($propertyId) . ')';
        }
        return $labels[$propertyId];
    }

    /**
     * The label of a property on its own, and its term, for the heading of a
     * field (0.8.0).
     */
    public function propertyLabel(int $propertyId): string
    {
        static $labels = [];
        if (!isset($labels[$propertyId])) {
            $label = $this->services->get('Omeka\Connection')->fetchOne('SELECT label FROM property WHERE id = ?', [$propertyId]);
            $labels[$propertyId] = $label ? $this->getView()->translate($label) : $this->termOf($propertyId);
        }
        return $labels[$propertyId];
    }

    public function propertyTerm(int $propertyId): string
    {
        return $this->termOf($propertyId);
    }

    protected function termOf(int $propertyId): string
    {
        static $terms = [];
        if (!isset($terms[$propertyId])) {
            $terms[$propertyId] = (string) $this->services->get('Omeka\Connection')->fetchOne(
                "SELECT CONCAT(v.prefix, ':', p.local_name) FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?",
                [$propertyId]
            ) ?: '#' . $propertyId;
        }
        return $terms[$propertyId];
    }

    /**
     * Changes of the resource itself.
     */
    public function resourceChangesHtml(array $changes): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $html = '';
        foreach ($changes as $field => $change) {
            switch ($field) {
                case 'is_public':
                    $label = $translate('Visibility');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $translate('Public') : $translate('Private');
                    };
                    break;
                case 'is_open':
                    $label = $translate('Openness');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $translate('Open to additions') : $translate('Closed to additions');
                    };
                    break;
                case 'template':
                    $label = $translate('Template');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $this->labelOf('SELECT label FROM resource_template WHERE id = ?', $v) : $translate('none');
                    };
                    break;
                case 'class':
                    $label = $translate('Class');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $this->labelOf("SELECT CONCAT(v.prefix, ':', c.local_name) FROM resource_class c JOIN vocabulary v ON v.id = c.vocabulary_id WHERE c.id = ?", $v) : $translate('none');
                    };
                    break;
                case 'owner':
                    $label = $translate('Owner');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $this->labelOf('SELECT name FROM user WHERE id = ?', $v) : $translate('none');
                    };
                    break;
                case 'item_sets':
                case 'sites':
                    $label = $field === 'item_sets' ? $translate('Item sets') : $translate('Sites');
                    $sql = $field === 'item_sets' ? 'SELECT title FROM resource WHERE id = ?' : 'SELECT title FROM site WHERE id = ?';
                    $parts = [];
                    foreach ($change['added'] ?? [] as $id) {
                        $parts[] = '<span class="bv-plus">+</span> ' . $escape($this->labelOf($sql, $id));
                    }
                    foreach ($change['removed'] ?? [] as $id) {
                        $parts[] = '<span class="bv-minus">−</span> <span class="bv-struck">' . $escape($this->labelOf($sql, $id)) . '</span>';
                    }
                    $html .= '<div class="bv-pair"><span class="bv-value-meta">' . $escape($label) . '</span> ' . implode(' · ', $parts) . '</div>';
                    continue 2;
                case 'thumbnail':
                    $label = $translate('Thumbnail');
                    $fmt = function ($v) use ($translate) {
                        return $v ? $this->labelOf('SELECT name FROM asset WHERE id = ?', $v) : $translate('none (made from the media)');
                    };
                    break;
                default:
                    $media = $this->mediaChangeHtml($field, (array) $change);
                    if ($media !== null) {
                        $html .= $media;
                    }
                    continue 2;
            }
            $html .= '<div class="bv-pair"><span class="bv-value-meta">' . $escape($label) . '</span> '
                . '<span class="bv-before">' . $escape($fmt($change['before'])) . '</span> <span class="bv-arrow">→</span> '
                . '<span class="bv-after">' . $escape($fmt($change['after'])) . '</span></div>';
        }
        return $html;
    }

    /**
     * Media changes (order, visibility, details, files, split) as HTML.
     */
    protected function mediaChangeHtml(string $field, array $change): ?string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $labels = $change['labels'] ?? [];
        $name = function ($id) use ($labels, $escape) {
            $id = (int) $id;
            if (isset($labels[$id])) {
                return $escape($labels[$id]);
            }
            $label = $this->labelOf("SELECT COALESCE(NULLIF(r.title, ''), m.source) FROM resource r JOIN media m ON m.id = r.id WHERE r.id = ?", $id);
            return $escape($label);
        };
        $list = function (array $ids) use ($name) {
            return '<ol class="bv-order">' . implode('', array_map(function ($id) use ($name) {
                return '<li>' . $name($id) . '</li>';
            }, $ids)) . '</ol>';
        };
        $meta = function ($text) use ($escape) {
            return '<span class="bv-value-meta">' . $escape($text) . '</span> ';
        };
        switch ($field) {
            case 'media_order':
                return '<div class="bv-sorted"><div>' . $meta($translate('Media now')) . $list($change['before'] ?? []) . '</div>'
                    . '<div class="bv-arrow">→</div><div>' . $meta($translate('after')) . $list($change['after'] ?? []) . '</div></div>';
            case 'media_visibility':
                $html = '';
                foreach ($change['media'] ?? [] as $id => $pair) {
                    $html .= '<div class="bv-pair">' . $meta($translate('Media')) . $name($id) . ' <span class="bv-before">'
                        . $escape($pair['before'] ? $translate('Public') : $translate('Private')) . '</span> <span class="bv-arrow">→</span> <span class="bv-after">'
                        . $escape($pair['after'] ? $translate('Public') : $translate('Private')) . '</span></div>';
                }
                return $html;
            case 'media_fields':
                $names = ['alt_text' => 'Alt text', 'source' => 'Source', 'media_type' => 'Media type', 'lang' => 'Language']; // @translate
                $html = '';
                foreach ($change['media'] ?? [] as $id => $fields) {
                    foreach ($fields as $f => $pair) {
                        $show = function ($v) use ($escape, $translate) {
                            return $v === null || $v === '' ? '<em>' . $escape($translate('(empty)')) . '</em>' : $escape((string) $v);
                        };
                        $html .= '<div class="bv-pair">' . $meta($translate($names[$f] ?? $f)) . $name($id) . ': <span class="bv-before">' . $show($pair['before'])
                            . '</span> <span class="bv-arrow">→</span> <span class="bv-after">' . $show($pair['after']) . '</span></div>';
                    }
                }
                return $html;
            case 'media_removed':
                $html = '';
                foreach ($change['media'] ?? [] as $id => $snapshot) {
                    $source = (string) ($snapshot['source'] ?? '');
                    $label = trim((string) ($snapshot['title'] ?? '')) !== ''
                        ? $snapshot['title']
                        : ($source !== '' ? basename((string) (parse_url($source, PHP_URL_PATH) ?: $source)) : '#' . $id);
                    $html .= '<div class="bv-pair"><span class="bv-minus">−</span> <span class="bv-struck">' . $escape($label) . '</span> '
                        . '<span class="bv-muted">' . $escape((string) ($snapshot['media_type'] ?: $snapshot['ingester'])) . ' · #' . (int) $id
                        . (!empty($snapshot['kept_file']) ? ' · ' . $escape($translate('file kept, can be put back')) : '') . '</span></div>';
                }
                return $html;
            case 'media_thumbnails':
                $n = count($change['media'] ?? []);
                $html = '<div class="bv-pair">' . $meta($translate('Thumbnails')) . $escape(sprintf($translate('made again for %d media'), $n)) . '</div>';
                if (!empty($change['failed'])) {
                    $html .= '<div class="bv-failed">' . $escape(sprintf($translate('Not possible for: %s'), implode(', ', array_map(function ($id) {
                        return '#' . (int) $id;
                    }, $change['failed'])))) . '</div>';
                }
                return $html;
            case 'media_restored':
                $back = $change['restored'] ?? [];
                $html = '<div class="bv-pair">' . $meta($translate('Put back'))
                    . $escape(sprintf($translate('%d media, with their files, values and place'), count($back))) . '</div>';
                foreach ($back as $oldId => $newId) {
                    $label = $change['media'][$oldId]['title'] ?? ($change['media'][$oldId]['source'] ?? null);
                    $html .= '<div class="bv-muted">#' . (int) $oldId . ($label ? ' ' . $escape((string) $label) : '')
                        . ' <span class="bv-arrow">→</span> #' . (int) $newId . '</div>';
                }
                if (!empty($change['note'])) {
                    $html .= '<div class="bv-muted">' . $escape($change['note']) . '</div>';
                }
                return $html;
            case 'media_rotate':
                $words = [90 => 'a quarter turn to the right (↻ 90°)', 180 => 'upside down (180°)', 270 => 'a quarter turn to the left (↺ 90°)']; // @translate
                $turned = $change['turned'] ?? [];
                $html = '<div class="bv-pair">' . $meta($translate('Turned'))
                    . $escape(sprintf($translate('%1$d images: %2$s'), count($turned) ?: count($change['media'] ?? []), $translate($words[(int) ($change['degrees'] ?? 0)] ?? '')));
                $tools = array_unique(array_filter(array_column($turned, 'tool')));
                if ($tools) {
                    $html .= ' <span class="bv-muted">' . $escape(sprintf($translate('with %s'), implode(', ', $tools)));
                    if (in_array(false, array_column($turned, 'lossless'), true)) {
                        $html .= ' · ' . $escape($translate('encoded again'));
                    }
                    $html .= '</span>';
                }
                $html .= '</div>';
                foreach ($turned as $id => $info) {
                    $label = $change['media'][$id]['title'] ?? null;
                    $html .= '<div class="bv-muted">#' . (int) $id . ($label !== null && $label !== '' ? ' ' . $escape($label) : '')
                        . ' · ' . $escape($this->bytes((int) ($info['size']['before'] ?? 0))) . ' <span class="bv-arrow">→</span> '
                        . $escape($this->bytes((int) ($info['size']['after'] ?? 0))) . '</div>';
                }
                if (!empty($change['failed'])) {
                    $html .= '<div class="bv-failed">' . $escape(sprintf($translate('Not possible for: %s'), implode(', ', array_map(function ($id) {
                        return '#' . (int) $id;
                    }, $change['failed'])))) . '</div>';
                }
                if (!empty($change['note'])) {
                    $html .= '<div class="bv-muted">' . $escape($change['note']) . '</div>';
                }
                return $html;
            case 'pdf_pages':
                $pdfs = $change['pdfs'] ?? [['pdf' => $change['pdf'] ?? null, 'label' => $change['label'] ?? null] + (isset($change['created']) ? ['created' => $change['created']] : [])];
                $html = '';
                foreach ($pdfs as $pdfData) {
                    $html .= '<div class="bv-pair">' . $meta($translate('PDF')) . $escape((string) ($pdfData['label'] ?? '#' . ($pdfData['pdf'] ?? ''))) . ' · ' . (int) ($change['dpi'] ?? 150) . ' dpi';
                    if (isset($pdfData['created'])) {
                        $html .= ' <span class="bv-plus">+</span> ' . $escape(sprintf($translate('%d page images'), count($pdfData['created'])));
                    }
                    $html .= '</div>';
                }
                return $html;
            case 'split_item':
                $html = '<div class="bv-pair">' . $meta($translate('Stays in this item')) . $name($change['keep'] ?? 0) . '</div>';
                if (isset($change['items'])) {
                    foreach ($change['items'] as $newId => $mediaIds) {
                        $html .= '<div class="bv-pair"><span class="bv-plus">+</span> ' . $escape($translate('New item')) . ' '
                            . $view->hyperlink('#' . (int) $newId, $view->url('admin/id', ['controller' => 'item', 'id' => (int) $newId]), ['target' => '_blank'])
                            . ': ' . implode(', ', array_map($name, $mediaIds)) . '</div>';
                    }
                } else {
                    foreach ($change['move'] ?? [] as $mediaId) {
                        $html .= '<div class="bv-pair"><span class="bv-plus">+</span> ' . $escape($translate('New item with')) . ' ' . $name($mediaId) . '</div>';
                    }
                }
                return $html;
            case 'unsplit_item':
                $html = '';
                foreach ($change['items'] ?? [] as $newId => $mediaIds) {
                    $kept = $change['kept'][$newId] ?? null;
                    $html .= '<div class="bv-pair">' . $escape(sprintf($translate('Media back from item #%d'), $newId)) . ': ' . implode(', ', array_map($name, $mediaIds))
                        . ($kept ? ' <span class="bv-muted">(' . $escape(sprintf($translate('item #%d kept: it has other media'), $newId)) . ')</span>' : ' <span class="bv-muted">(' . $escape(sprintf($translate('item #%d deleted'), $newId)) . ')</span>')
                        . '</div>';
                }
                return $html;
        }
        return null;
    }

    protected function labelOf(string $sql, $id): string
    {
        $label = $this->services->get('Omeka\Connection')->fetchOne($sql, [(int) $id]);
        return ($label !== false && $label !== null && $label !== '') ? (string) $label : '#' . (int) $id;
    }

    /**
     * Changes of a log row or plan entry, whatever version wrote it.
     */
    public function changeHtml(array $change, bool $showProperty = false): string
    {
        $data = $change['values'] ?? null;
        if (isset($change['resource'])) {
            return $this->resourceChangesHtml($change['resource']);
        }
        if (isset($change['pairs']) && !isset($change['values'])) {
            return $this->pairsHtml($change['pairs'], $showProperty);
        }
        if (isset($data['resource'])) {
            return $this->resourceChangesHtml($data['resource']);
        }
        return $this->pairsHtml(\BatchValues\Stdlib\Processor::pairsOf($change + ['values' => (array) $data]));
    }

    /**
     * Lines describing a set's params: text or rendered snapshots.
     */
    public function displayHtml(array $lines): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');
        $html = '<dl class="bv-display">';
        foreach ($lines as $line) {
            $html .= '<dt>' . $escape($translate((string) $line['label'])) . '</dt><dd>';
            if (isset($line['snapshots'])) {
                foreach ($line['snapshots'] as $snapshot) {
                    $html .= '<div>' . $this->__invoke($snapshot) . '</div>';
                }
            } elseif (isset($line['code'])) {
                // Text typed by the user: shown exactly, spaces included.
                $html .= $line['code'] === ''
                    ? '<em>' . $escape($translate('(nothing)')) . '</em>'
                    : '<code class="bv-code">' . $escape($line['code']) . '</code>';
            } else {
                $html .= $escape($translate((string) ($line['text'] ?? '')));
            }
            $html .= '</dd>';
        }
        return $html . '</dl>';
    }

    /**
     * Load the quick-look popup on the page (once).
     */
    /**
     * @param bool $auto Also open the quick look from the pictures of linked
     *   resources anywhere in the page (Omeka's show pages, 0.6.8).
     */
    public function peekAssets(bool $auto = false): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        $view = $this->getView();
        $view->headScript()->appendFile($view->assetUrl('js/batch-values-peek.js', 'BatchValues'));
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $template = $view->url('admin/batch-values', ['action' => 'peek', 'id' => 987654321], ['query' => ['resource_type' => 'RESOURCETYPE']]);
        return '<span id="bv-peek-i18n" hidden data-prev="' . $escapeAttr($view->translate('Previous')) . '" data-next="'
            . $escapeAttr($view->translate('Next')) . '" data-close="' . $escapeAttr($view->translate('Close')) . '"'
            . ' data-url="' . $escapeAttr($template) . '"'
            . ' data-title="' . $escapeAttr($view->translate('Quick look (Ctrl or Cmd-click: open the page)')) . '"'
            . ($auto ? ' data-auto="1"' : '') . '></span>';
    }

    /**
     * Thumbnail, title and id of a resource, linking to its admin page. Used
     * wherever resources are listed: people know a work by its picture, not
     * by its accession number.
     *
     * @param array $thumbs id => url|null, from thumbs()
     */
    public function resourceLink(int $id, ?string $title, string $resourceType, array $thumbs, string $extra = ''): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        $controller = \BatchValues\Stdlib\Planner::RESOURCE_TYPES[$resourceType][1] ?? 'item';
        $thumb = $thumbs[$id] ?? null;
        $url = $view->url('admin/id', ['controller' => $controller, 'id' => $id]);
        $peek = $view->url('admin/batch-values', ['action' => 'peek', 'id' => $id], ['query' => ['resource_type' => $resourceType]]);
        return '<a class="bv-sample-resource" href="' . $escapeAttr($url) . '" data-peek-url="' . $escapeAttr($peek) . '">'
            . '<span class="bv-thumb">' . ($thumb ? '<img src="' . $escapeAttr($thumb) . '" alt="" loading="lazy">' : '<span class="bv-thumb-none o-icon-items" aria-hidden="true"></span>') . '</span>'
            . '<span>' . $escape($title !== null && $title !== '' ? $title : $view->translate('[Untitled]'))
            . ' <span class="bv-muted">#' . $id . $escape($extra) . '</span></span></a>';
    }

    /**
     * Square thumbnail urls of resources that still exist.
     *
     * @return array id => url|null
     */
    public function thumbs(array $ids, string $resourceType): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $out = [];
        try {
            foreach ($this->services->get('Omeka\ApiManager')->search($resourceType, ['id' => $ids])->getContent() as $resource) {
                $out[$resource->id()] = $resource->thumbnailDisplayUrl('square');
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * One plain sentence saying what a change set does, e.g.
     * Move “1960-0071-0-CA” from General notes to Related Item.
     */
    public function sentence(string $operation, array $params, array $lines, ?string $propertyLabel, string $typeLabel): string
    {
        $view = $this->getView();
        $escape = $view->plugin('escapeHtml');
        $t = function ($text) use ($view) {
            return $view->translate($text);
        };
        $prop = '<strong>' . $escape((string) $propertyLabel) . '</strong>';
        $type = $escape(mb_strtolower($typeLabel));
        $values = function (array $lines, bool $new = false) use ($escape, $t) {
            $snaps = [];
            foreach ($lines as $line) {
                $isNew = strpos((string) $line['label'], 'New value') === 0;
                if ($isNew !== $new) {
                    continue;
                }
                if (isset($line['text']) && $line['text'] === 'All values of this property') {
                    return $escape($t('all its values'));
                }
                if (isset($line['text']) && !$new && !isset($line['snapshots']) && in_array($line['label'], ['Values', 'Values to remove', 'Values to replace', 'Values to copy', 'Values to move'], true)) {
                    // A rule ("Values containing …"), said in the sentence.
                    return $escape(mb_strtolower(mb_substr($line['text'], 0, 1)) . mb_substr($line['text'], 1));
                }
                foreach ($line['snapshots'] ?? [] as $snap) {
                    $snaps[] = '“' . trim(strip_tags($this->__invoke($snap, false), '<a><img>')) . '”'
                        . (isset($snap['is_public']) && !$snap['is_public'] ? ' <span class="bv-flag bv-flag-private">' . $escape($t('private')) . '</span>' : '');
                }
            }
            $snaps = array_values(array_unique($snaps));
            if (!$snaps) {
                return '';
            }
            $more = count($snaps) > 3 ? ' ' . sprintf($escape($t('and %d more')), count($snaps) - 3) : '';
            return implode(', ', array_slice($snaps, 0, 3)) . $more;
        };
        $textOf = function (string $label) use ($lines, $escape, $t) {
            foreach ($lines as $line) {
                if ($line['label'] === $label && isset($line['text'])) {
                    return '<strong>' . $escape($t($line['text'])) . '</strong>';
                }
            }
            return '';
        };
        $quote = function (string $text) use ($escape) {
            return '<code class="bv-code">' . $escape($text) . '</code>';
        };
        $mediaWhich = function (array $params) use ($escape, $t) {
            $parts = [];
            if (!empty($params['kinds'])) {
                $parts[] = sprintf($escape($t('the media of type %s')), '<strong>' . $escape(implode(', ', $params['kinds'])) . '</strong>');
            }
            if (!empty($params['extensions'])) {
                $parts[] = sprintf($escape($t('the media with the extension %s')), '<strong>.' . $escape(implode(', .', $params['extensions'])) . '</strong>');
            }
            return $parts ? implode(' ' . $escape($t('and')) . ' ', $parts) : $escape($t('all media'));
        };
        $target = isset($params['target_label'])
            ? '<strong>' . $escape($t($params['target_label'])) . '</strong>'
            : '';
        switch ($operation) {
            case 'add':
                return sprintf($t('Add %1$s to %2$s'), $values($lines), $prop);
            case 'remove':
                return sprintf($t('Remove %1$s from %2$s'), $values($lines), $prop);
            case 'replace':
                return sprintf($t('Replace %1$s with %2$s in %3$s'), $values($lines), $values($lines, true), $prop);
            case 'convert':
                if (($params['target'] ?? 'template') !== 'template') {
                    return sprintf($t('Change %1$s of %2$s to the data type %3$s'), $values($lines), $prop, $textOf('To'));
                }
                return sprintf($t('Convert %1$s of %2$s to the data type its template sets'), $values($lines), $prop);
            case 'copy':
                return sprintf($t('Copy %1$s from %2$s to %3$s'), $values($lines), $prop, $target);
            case 'move':
                return sprintf($t('Move %1$s from %2$s to %3$s'), $values($lines), $prop, $target);
            case 'visibility':
                $mode = $params['mode'] ?? (!empty($params['is_public']) ? 'public' : 'private');
                if ($mode === 'switch') {
                    return sprintf($t('Switch %1$s of %2$s between public and private'), $values($lines), $prop);
                }
                return sprintf($mode === 'public' ? $t('Make %1$s of %2$s public') : $t('Make %1$s of %2$s private'), $values($lines), $prop);
            case 'language':
                if (($params['mode'] ?? 'chosen') === 'code') {
                    $from = ($params['from'] ?? '') === '' ? $escape($t('no language')) : '<strong>' . $escape($params['from']) . '</strong>';
                    return ($params['lang'] ?? '') === ''
                        ? sprintf($t('In %1$s, remove the language code %2$s'), $prop, $from)
                        : sprintf($t('In %1$s, change the language %2$s to %3$s'), $prop, $from, '<strong>' . $escape($params['lang']) . '</strong>');
                }
                return ($params['lang'] ?? '') === ''
                    ? sprintf($t('Remove the language of %1$s of %2$s'), $values($lines), $prop)
                    : sprintf($t('Set the language of %1$s of %2$s to %3$s'), $values($lines), $prop, '<strong>' . $escape($params['lang']) . '</strong>');
            case 'sort':
                return sprintf($t('Sort the values of %1$s %2$s'), $prop, ($params['direction'] ?? 'asc') === 'desc' ? 'Z → A' : 'A → Z');
            case 'resource_visibility':
                return sprintf(!empty($params['is_public']) ? $t('Make the %s public') : $t('Make the %s private'), $type);
            case 'item_set_open':
                return !empty($params['is_open']) ? $t('Open the item sets to additions') : $t('Close the item sets to additions');
            case 'template':
                $parts = [];
                if (($params['template'] ?? 'keep') !== 'keep') {
                    $parts[] = sprintf($t('set the template to %s'), $textOf('Template'));
                }
                if (($params['class'] ?? 'keep') !== 'keep') {
                    $parts[] = sprintf($t('set the class to %s'), $textOf('Class'));
                }
                return ucfirst(implode(' · ', $parts));
            case 'item_sets':
            case 'sites':
                $parts = [];
                if ($textOf('Add to') !== '') {
                    $parts[] = sprintf($t('add to %s'), $textOf('Add to'));
                }
                if ($textOf('Remove from') !== '') {
                    $parts[] = sprintf($t('remove from %s'), $textOf('Remove from'));
                }
                return ucfirst(implode(' · ', $parts));
            case 'owner':
                return sprintf($t('Give the %1$s to %2$s'), $type, $textOf('New owner'));
            case 'find_replace':
                switch ($params['action'] ?? 'replace') {
                    case 'remove':
                        return sprintf($t('In %1$s, remove the values that contain %2$s'), $prop, $quote($params['find'] ?? ''));
                    case 'basename':
                    case 'filename':
                        return sprintf($params['action'] === 'basename' ? $t('In %s, keep only the file name of the values') : $t('In %s, keep only the file name without extension of the values'), $prop)
                            . (($params['find'] ?? '') !== '' ? ' ' . sprintf($t('that contain %s'), $quote($params['find'])) : '');
                }
                return sprintf($t('In %1$s, replace %2$s with %3$s'), $prop, $quote($params['find'] ?? ''), $quote($params['replace'] ?? ''));
            case 'affix':
                $parts = [];
                if (($params['prefix'] ?? '') !== '') {
                    $parts[] = sprintf($t('%s before'), $quote($params['prefix']));
                }
                if (($params['suffix'] ?? '') !== '') {
                    $parts[] = sprintf($t('%s after'), $quote($params['suffix']));
                }
                return sprintf($t('Add %1$s to %2$s of %3$s'), implode(' ' . $t('and') . ' ', $parts), $values($lines), $prop);
            case 'split':
                return sprintf($t('Split the values of %1$s at %2$s'), $prop, $quote(($params['separator'] ?? '') === "\n" ? '↵' : ($params['separator'] ?? '')));
            case 'join':
                if (($params['join_mode'] ?? 'text') === 'link') {
                    return sprintf($t('In %s, give each link the text that follows it as its label'), $prop);
                }
                return sprintf($t('Join %1$s of %2$s into one value, separated by %3$s'), $values($lines), $prop, $quote(($params['separator'] ?? '') === "\n" ? '↵' : ($params['separator'] ?? '')));
            case 'clean':
                return sprintf($t('Clean up %1$s: %2$s'), $prop, $textOf('Clean'));
            case 'thumbnail':
                return ($params['mode'] ?? 'set') === 'remove'
                    ? sprintf($t('Remove the thumbnail of the %s'), $type)
                    : sprintf($t('Use %1$s as the thumbnail of the %2$s'), $textOf('Thumbnail'), $type);
            case 'media_order':
                return sprintf($t('Reorder the media of each item: %s'), $textOf('Order'));
            case 'media_visibility':
                return sprintf(!empty($params['is_public']) ? $t('Make %s public') : $t('Make %s private'), $mediaWhich($params));
            case 'media_details':
                return sprintf($t('Change %1$s of %2$s'), $textOf('Detail'), $mediaWhich($params));
            case 'media_thumbnails':
                return sprintf($t('Make the thumbnails of %s again'), $mediaWhich($params));
            case 'media_rotate':
                $words = [90 => 'a quarter turn to the right', 180 => 'upside down', 270 => 'a quarter turn to the left']; // @translate
                return sprintf($t('Turn %1$s: %2$s'), $mediaWhich($params), $t($words[(int) ($params['degrees'] ?? 0)] ?? ''));
            case 'media_remove':
                return array_key_exists('keep_files', $params) && empty($params['keep_files'])
                    ? sprintf($t('Delete %s from the items, with their files'), $mediaWhich($params))
                    : sprintf($t('Delete %s from the items (their files are kept aside)'), $mediaWhich($params));
            case 'split_pdf':
                return sprintf($t('Make one image per page of each PDF (%d dpi)'), (int) ($params['dpi'] ?? 150));
            case 'split_item':
                return $escape($t('Split each item into one item per media'));
            case 'cancel':
                return $escape($t('Cancel changes'));
        }
        return '';
    }

    public function outcomeLabel(string $outcome): string
    {
        $labels = [
            'added' => 'Added', // @translate
            'removed' => 'Removed', // @translate
            'skipped_duplicate' => 'Skipped: already has this value', // @translate
            'skipped_group' => 'Skipped: nothing to add for this template', // @translate
            'skipped_no_match' => 'Skipped: value not present', // @translate
            'skipped_required' => 'Skipped: property required by template', // @translate
            'skipped_missing' => 'Skipped: item no longer exists', // @translate
            'skipped_changed' => 'Skipped: changed since', // @translate
            'changed' => 'Changed', // @translate
            'skipped_same' => 'Skipped: already so', // @translate
            'skipped_type' => 'Skipped: data type not accepted', // @translate
            'skipped_annotation' => 'Skipped: both values carry an annotation', // @translate
            'skipped_not_convertible' => 'Skipped: nothing accepts the value', // @translate
            'skipped_invalid' => 'Skipped: the result is not accepted by its data type', // @translate
            'skipped_no_media' => 'Skipped: no media concerned', // @translate
            'skipped_has_thumbnail' => 'Skipped: has a thumbnail already', // @translate
            'skipped_has_media' => 'Skipped: has media', // @translate
            'skipped_last_media' => 'Skipped: it would be left without media', // @translate
            'skipped_irreversible' => 'Not cancelled: cannot be undone', // @translate
            'failed' => 'Failed', // @translate
        ];
        return $this->getView()->translate($labels[$outcome] ?? $outcome);
    }

    public function statusLabel(string $status): string
    {
        $labels = [
            'running' => 'Running', // @translate
            'completed' => 'Completed', // @translate
            'completed_with_errors' => 'Completed with errors', // @translate
            'stopped' => 'Stopped', // @translate
            'failed' => 'Failed', // @translate
        ];
        return $this->getView()->translate($labels[$status] ?? $status);
    }

    public function operation(string $name): ?\BatchValues\Operation\AbstractOperation
    {
        return $this->services->get(\BatchValues\Stdlib\Operations::class)->get($name);
    }

    public function operationLabel(string $operation): string
    {
        if ($operation === 'cancel') {
            return $this->getView()->translate('Cancel changes');
        }
        if ($operation === \BatchValues\Module::FORM_EDIT) {
            return $this->getView()->translate('Edited on the resource\'s own form');
        }
        $op = $this->services->get(\BatchValues\Stdlib\Operations::class)->get($operation);
        return $this->getView()->translate($op ? $op->label() : $operation);
    }
}
