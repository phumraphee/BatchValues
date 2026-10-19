<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Change the data type of values: to the one the template sets for the
 * property (default), or to one chosen.
 *
 * Each value is turned into text (as Omeka shows it, or as chosen for links),
 * then given to the target data type through its own isValid() and hydrate().
 * For data types other than Text and URI, a value is converted only when the
 * result reads exactly as the text, so "1961" becomes the term named 1961,
 * never whatever has id 1961. Linked resources are found by an identifier.
 * A value that cannot be converted is left as it is and listed.
 */
class ConvertValues extends AbstractOperation
{
    const RESOURCE_TYPES = [
        'resource' => ['Omeka\Entity\Item', 'Omeka\Entity\ItemSet', 'Omeka\Entity\Media'],
        'resource:item' => ['Omeka\Entity\Item'],
        'resource:itemset' => ['Omeka\Entity\ItemSet'],
        'resource:media' => ['Omeka\Entity\Media'],
    ];

    public function name(): string
    {
        return 'convert';
    }

    public function label(): string
    {
        return 'Change the data type'; // @translate
    }

    public function help(): string
    {
        return 'To the data type the template sets, or one you choose: text → term, text → link, text → linked item (found by its identifier), link → text…'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        $dataTypes = $this->services->get('Omeka\DataTypeManager');
        $targets = [];
        foreach ($dataTypes->getRegisteredNames() as $name) {
            $targets[$name] = $this->translate($dataTypes->get($name)->getLabel());
        }
        $sites = $this->services->get('Omeka\Connection')->fetchAllKeyValue('SELECT slug, title FROM site ORDER BY title');
        return ['picker' => true, 'pickerAllDefault' => true, 'targets' => $targets, 'sites' => $sites];
    }

    protected function pickerDefault(): string
    {
        return 'all';
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $dataTypes = $this->services->get('Omeka\DataTypeManager');
        $target = (string) ($post['target'] ?? 'template');
        if ($target !== 'template' && !$dataTypes->has($target)) {
            $errors[] = $this->translate('Choose the data type to change to.');
        }
        $lookup = (string) ($post['lookup'] ?? 'dcterms:identifier');
        $params = self::pickerParams($picker) + [
            'target' => $target,
            'link_text' => (in_array($post['link_text'] ?? 'label_or_uri', ['label_or_uri', 'label', 'uri', 'label_uri', 'uri_label'], true) ? ($post['link_text'] ?? 'label_or_uri') : 'label_or_uri'),
            'split_label' => !empty($post['split_label']),
            'lookup' => $lookup,
            'lookup_mode' => (in_array($post['lookup_mode'] ?? 'full', ['full', 'basename', 'filename'], true) ? ($post['lookup_mode'] ?? 'full') : 'full'),
            'ignore_template' => !empty($post['ignore_template']),
            'uri_base' => (string) ($post['uri_base'] ?? 'api'),
        ];
        // Addresses of linked resources, made now (the run may be a job with
        // no web address to build from). "__ID__" is the resource id.
        $url = $this->services->get('ViewHelperManager')->get('url');
        $templates = [];
        try {
            if (strpos($params['uri_base'], 'site:') === 0) {
                $slug = substr($params['uri_base'], 5);
                foreach (['items' => 'item', 'item_sets' => 'item-set', 'media' => 'media'] as $name => $controller) {
                    $templates[$name] = $url('site/resource-id', ['site-slug' => $slug, 'controller' => $controller, 'id' => 999999999], ['force_canonical' => true]);
                }
            } else {
                foreach (['items', 'item_sets', 'media'] as $name) {
                    $templates[$name] = $url('api/default', ['resource' => $name, 'id' => 999999999], ['force_canonical' => true]);
                }
            }
        } catch (\Throwable $e) {
        }
        $params['uri_templates'] = array_map(function ($u) {
            return str_replace('999999999', '__ID__', (string) $u);
        }, $templates);
        if (isset(self::RESOURCE_TYPES[$target]) && $lookup !== 'o:id' && $lookup !== 'o:title' && !$this->propertyIdOf($lookup)) {
            $errors[] = sprintf($this->translate('Unknown property “%s” to find the linked resources by.'), $lookup);
        }
        $params['display'] = [
            $this->pickerDisplay($picker, 'Values to convert'), // @translate
            ['label' => 'To', 'text' => $target === 'template'
                ? $this->translate('The data type the template sets')
                : ($dataTypes->has($target) ? $this->translate($dataTypes->get($target)->getLabel()) : $target)], // @translate
        ];
        if (isset(self::RESOURCE_TYPES[$target])) {
            $params['display'][] = ['label' => 'Found by', 'text' => $lookup === 'o:id' ? 'Omeka id' : ($lookup === 'o:title' ? 'Title' : $lookup)]; // @translate
        }
        return ['params' => $params, 'errors' => $errors];
    }

    protected function propertyIdOf(string $term): ?int
    {
        if (strpos($term, ':') === false) {
            return null;
        }
        [$prefix, $local] = explode(':', $term, 2);
        $id = $this->services->get('Omeka\Connection')->fetchOne(
            'SELECT p.id FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE v.prefix = ? AND p.local_name = ?',
            [$prefix, $local]
        );
        return $id ? (int) $id : null;
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $em = $this->services->get('Omeka\EntityManager');
        $explicit = ($params['target'] ?? 'template') !== 'template';
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $templateId = $info[$id]['template_id'];
            $key = Planner::groupKey($templateId);
            $tp = $this->planner->templateProperty($templateId, $ctx['property_id']);
            $allowed = $tp['data_types'] ?? [];
            if ($explicit) {
                if ($allowed && !in_array($params['target'], $allowed, true) && empty($params['ignore_template'])) {
                    $plan[$id] = self::entry($key, 'skipped_type', [], [
                        'message' => $this->translate('The template does not allow this data type for this property.'),
                    ]);
                    continue;
                }
                $targets = [$params['target']];
            } elseif (!$allowed) {
                $plan[$id] = self::entry($key, 'skipped_group', [], [
                    'message' => $this->translate('The template does not fix a data type for this property.'),
                ]);
                continue;
            } else {
                $targets = $allowed;
            }
            $pairs = [];
            $left = [];
            $matched = false;
            foreach ($existing[$id] ?? [] as $row) {
                if (!$this->picked($row, $params)) {
                    continue;
                }
                $matched = true;
                if (in_array($row['type'], $targets, true)) {
                    continue;
                }
                $text = $this->textFor($em, $row, $params);
                $converted = null;
                foreach ($targets as $target) {
                    $converted = $this->convert($row, $text, $target, $params);
                    if ($converted) {
                        break;
                    }
                }
                if (!$converted) {
                    $left[] = '"' . mb_substr($text, 0, 60) . '"';
                    continue;
                }
                $before = Planner::snapshotFromRow($row);
                $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => Planner::withColumns($before, $converted)];
            }
            $message = $left ? sprintf($this->translate('Not converted (not accepted, or not found): %s'), implode(', ', array_slice($left, 0, 5)) . (count($left) > 5 ? ' …' : '')) : null;
            $outcome = $pairs ? 'changed' : ($left ? 'skipped_not_convertible' : ($matched ? 'skipped_same' : 'skipped_no_match'));
            $plan[$id] = self::entry($key, $outcome, $pairs, ['message' => $message]);
        }
        return $plan;
    }

    /**
     * The text a value is converted from. Links: as chosen (label, URL or
     * both); other values: as Omeka shows them (a term's label, a
     * resource's title, HTML without its tags).
     */
    protected function textFor($em, array $row, array $params): string
    {
        if (Planner::hasLink($row)) {
            $label = trim((string) $row['value']);
            $uri = trim((string) $row['uri']);
            switch ($params['link_text'] ?? 'label_or_uri') {
                case 'label':
                    return $label;
                case 'uri':
                    return $uri;
                case 'label_uri':
                    return trim($label . ' ' . $uri);
                case 'uri_label':
                    return trim($uri . ' ' . $label);
                default:
                    return $label !== '' ? $label : $uri;
            }
        }
        return $this->textOf($em, $row);
    }

    /**
     * The value as Omeka shows it as text (a term's label, a link's label or
     * address, a resource's title).
     */
    protected function textOf($em, array $row): string
    {
        try {
            $value = $em->find(\Omeka\Entity\Value::class, (int) $row['id']);
            if ($value) {
                $representation = new ValueRepresentation($value, $this->services);
                $dataTypes = $this->services->get('Omeka\DataTypeManager');
                $text = $dataTypes->has($value->getType())
                    ? html_entity_decode(strip_tags((string) $dataTypes->get($value->getType())->render($this->services->get('ViewRenderer'), $representation, [])), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : (string) $representation;
                if (trim($text) !== '') {
                    return trim($text);
                }
            }
        } catch (\Throwable $e) {
        }
        return trim((string) ($row['value'] ?? $row['uri'] ?? ''));
    }

    /**
     * Columns of the value converted to $target, or null.
     */
    protected function convert(array $row, string $text, string $target, array $params = []): ?array
    {
        if ($text === '') {
            return null;
        }
        if (isset(self::RESOURCE_TYPES[$target])) {
            $resourceId = $this->findResource($text, $target, $params);
            if (!$resourceId) {
                return null;
            }
            $object = ['value_resource_id' => $resourceId];
        } elseif ($target === 'literal') {
            $object = ['@value' => $text];
        } elseif ($target === 'uri' && !empty($row['value_resource_id'])) {
            // A linked resource becomes a link to its page (site or API).
            $kinds = [
                'Omeka\Entity\Item' => 'items',
                'Omeka\Entity\ItemSet' => 'item_sets',
                'Omeka\Entity\Media' => 'media',
            ];
            $linked = $this->services->get('Omeka\Connection')->fetchAssociative('SELECT resource_type, title FROM resource WHERE id = ?', [(int) $row['value_resource_id']]);
            $template = $linked ? ($params['uri_templates'][$kinds[$linked['resource_type']] ?? ''] ?? null) : null;
            if (!$template) {
                return null;
            }
            $object = ['@id' => str_replace('__ID__', (string) (int) $row['value_resource_id'], $template)];
            if ($linked['title'] !== null && $linked['title'] !== '') {
                $object['o:label'] = $linked['title'];
            }
        } elseif ($target === 'uri' || (Planner::isValueSuggest($target) && Planner::hasLink($row))) {
            $uri = Planner::hasLink($row) ? (string) $row['uri'] : $text;
            $label = Planner::hasLink($row) ? $row['value'] : null;
            if (!Planner::hasLink($row) && !empty($params['split_label']) && preg_match('~^(\S+)\s+(.+)$~u', $text, $m)) {
                // "http://… Label": the address, then its label.
                $uri = $m[1];
                $label = trim($m[2]);
            }
            if (!filter_var($uri, FILTER_VALIDATE_URL)) {
                return null;
            }
            $object = ['@id' => $uri];
            if ($label !== null && $label !== '') {
                $object['o:label'] = $label;
            }
        } else {
            $object = ['@value' => $text];
        }
        // The language goes along to data types that keep one (Text, URI,
        // Value Suggest); the others ignore it.
        if ($row['lang']) {
            $object['@language'] = $row['lang'];
            $object['o:lang'] = $row['lang'];
        }
        $object['type'] = $target;
        $object['property_id'] = (int) $row['property_id'];
        $value = $this->planner->hydrateObject($object);
        if (!$value instanceof \Omeka\Entity\Value) {
            return null;
        }
        if (!in_array($target, ['literal', 'uri'], true) && !isset(self::RESOURCE_TYPES[$target]) && !(Planner::isValueSuggest($target) && isset($object['@id']))) {
            // The converted value must read exactly as the text it came from.
            // This is what keeps "1961" from silently becoming whatever has
            // id 1961 in a data type that also accepts ids, while a term
            // whose label really is "1961" is still found.
            try {
                $dataType = $this->services->get('Omeka\DataTypeManager')->get($target);
                // What people see (render), not toString(), which for some
                // data types is the stored id itself.
                $view = $this->services->get('ViewRenderer');
                $html = (string) $dataType->render($view, new ValueRepresentation($value, $this->services), []);
                $readsAs = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } catch (\Throwable $e) {
                return null;
            }
            if ($readsAs !== $text) {
                return null;
            }
        }
        $vr = $value->getValueResource();
        return [
            'type' => $value->getType(),
            'value' => $value->getValue(),
            'uri' => $value->getUri(),
            'value_resource_id' => $vr ? $vr->getId() : null,
            'lang' => $value->getLang(),
        ];
    }

    /**
     * The one resource whose identifier is the text: by Omeka id, by title,
     * or by the value of a property (exact text). Several or none: null.
     */
    protected function findResource(string $text, string $target, array $params): ?int
    {
        $mode = $params['lookup_mode'] ?? 'full';
        if ($mode !== 'full') {
            $path = str_replace('\\', '/', (string) (parse_url($text, PHP_URL_PATH) ?: $text));
            $text = $mode === 'basename' ? basename($path) : pathinfo($path, PATHINFO_FILENAME);
        }
        if ($text === '') {
            return null;
        }
        $classes = self::RESOURCE_TYPES[$target];
        $connection = $this->services->get('Omeka\Connection');
        $lookup = $params['lookup'] ?? 'dcterms:identifier';
        if ($lookup === 'o:id') {
            if (!ctype_digit($text)) {
                return null;
            }
            $ids = $connection->fetchFirstColumn('SELECT id FROM resource WHERE id = ? AND resource_type IN (?)', [(int) $text, $classes], [\PDO::PARAM_INT, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]);
        } elseif ($lookup === 'o:title') {
            $ids = $connection->fetchFirstColumn('SELECT id FROM resource WHERE title = ? COLLATE utf8mb4_bin AND resource_type IN (?) LIMIT 2', [$text, $classes], [\PDO::PARAM_STR, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]);
        } else {
            $propertyId = $this->propertyIdOf($lookup);
            if (!$propertyId) {
                return null;
            }
            $ids = $connection->fetchFirstColumn(
                'SELECT DISTINCT r.id FROM value v JOIN resource r ON r.id = v.resource_id WHERE v.property_id = ? AND v.value = ? COLLATE utf8mb4_bin AND r.resource_type IN (?) LIMIT 2',
                [$propertyId, $text, $classes],
                [\PDO::PARAM_INT, \PDO::PARAM_STR, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY]
            );
        }
        return count($ids) === 1 ? (int) $ids[0] : null;
    }
}
