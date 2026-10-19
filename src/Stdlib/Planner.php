<?php
namespace BatchValues\Stdlib;

use Doctrine\DBAL\Connection;
use Interop\Container\ContainerInterface;
use Omeka\Entity\Value;

/**
 * Read-only helpers shared by all operations: selections, resources, value
 * rows, comparison keys, snapshots. Never writes anything.
 */
class Planner
{
    /** API resource name => [label, admin controller, resource.resource_type] */
    const RESOURCE_TYPES = [
        'items' => ['Items', 'item', 'Omeka\Entity\Item'],
        'item_sets' => ['Item sets', 'item-set', 'Omeka\Entity\ItemSet'],
        'media' => ['Media', 'media', 'Omeka\Entity\Media'],
    ];

    /**
     * @var ContainerInterface
     */
    protected $services;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $resourceName = 'items';

    /**
     * @var array Cache of template property configurations.
     */
    protected $templateProperties = [];

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
        $this->connection = $services->get('Omeka\Connection');
    }

    public function setResourceType(string $resourceName): self
    {
        $this->resourceName = isset(self::RESOURCE_TYPES[$resourceName]) ? $resourceName : 'items';
        return $this;
    }

    public function getResourceType(): string
    {
        return $this->resourceName;
    }

    // ------------------------------------------------------------------
    // Selection and resources
    // ------------------------------------------------------------------

    /**
     * Resolve a selection to a sorted list of resource ids the user can see.
     *
     * @param array $selection ['ids' => int[]] or ['query' => array]
     */
    public function resolveSelection(array $selection): array
    {
        $api = $this->services->get('Omeka\ApiManager');
        if (isset($selection['query']) && is_array($selection['query'])) {
            $query = $selection['query'];
            unset($query['page'], $query['per_page'], $query['offset'], $query['limit'], $query['sort_by'], $query['sort_order']);
            $ids = $api->search($this->resourceName, $query, ['returnScalar' => 'id'])->getContent();
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', $selection['ids'] ?? []))));
            if (!$ids) {
                return [];
            }
            $found = [];
            foreach (array_chunk($ids, 1000) as $chunk) {
                $found = array_merge($found, $api->search($this->resourceName, ['id' => $chunk], ['returnScalar' => 'id'])->getContent());
            }
            $ids = $found;
        }
        $ids = array_map('intval', array_values($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Map id => ['template_id', 'title', 'is_public', 'is_open' (item sets), 'class_id', 'owner_id', 'thumbnail_id'].
     */
    public function resourceInfo(array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT r.id, r.resource_template_id, r.resource_class_id, r.owner_id, r.thumbnail_id, r.is_public, r.title, s.is_open FROM resource r LEFT JOIN item_set s ON s.id = r.id WHERE r.id IN (?)',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $result[(int) $row['id']] = [
                    'template_id' => $row['resource_template_id'] === null ? null : (int) $row['resource_template_id'],
                    'class_id' => $row['resource_class_id'] === null ? null : (int) $row['resource_class_id'],
                    'owner_id' => $row['owner_id'] === null ? null : (int) $row['owner_id'],
                    'thumbnail_id' => $row['thumbnail_id'] === null ? null : (int) $row['thumbnail_id'],
                    'is_public' => (bool) $row['is_public'],
                    'is_open' => $row['is_open'] === null ? null : (bool) $row['is_open'],
                    'title' => $row['title'],
                ];
            }
        }
        return $result;
    }

    /**
     * Ids of a link table (item_item_set, item_site) per item.
     *
     * @return array item id => int[]
     */
    public function links(array $ids, string $table, string $column): array
    {
        $allowed = ['item_item_set' => 'item_set_id', 'item_site' => 'site_id'];
        if (($allowed[$table] ?? null) !== $column) {
            throw new \InvalidArgumentException('Unknown link table.');
        }
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT item_id, $column AS link FROM $table WHERE item_id IN (?) ORDER BY $column",
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $result[(int) $row['item_id']][] = (int) $row['link'];
            }
        }
        return $result;
    }

    /**
     * @var bool|null Whether media has an alt_text column (Omeka S 4.1+).
     */
    protected $hasAltText;

    public function hasMediaAltText(): bool
    {
        if ($this->hasAltText === null) {
            $this->hasAltText = (bool) $this->connection->fetchOne("SHOW COLUMNS FROM media LIKE 'alt_text'");
        }
        return $this->hasAltText;
    }

    /**
     * Media rows by item, in their order on the item (or, for media ids, the
     * media themselves keyed by their own id).
     *
     * @param bool $byMediaId Ids are media ids, not item ids.
     * @return array id => list of media rows
     */
    public function mediaOf(array $ids, bool $byMediaId = false): array
    {
        $result = [];
        $alt = $this->hasMediaAltText() ? 'm.alt_text' : 'NULL AS alt_text';
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT m.id, m.item_id, m.ingester, m.renderer, m.source, m.media_type, m.storage_id, m.extension,"
                . " m.has_original, m.has_thumbnails, m.sha256, m.size, m.position, m.lang, m.data, $alt, r.is_public, r.title, r.owner_id"
                . ' FROM media m JOIN resource r ON r.id = m.id WHERE ' . ($byMediaId ? 'm.id' : 'm.item_id') . ' IN (?)'
                . ' ORDER BY m.item_id, m.position, m.id',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $row['id'] = (int) $row['id'];
                $row['item_id'] = (int) $row['item_id'];
                $row['is_public'] = (bool) $row['is_public'];
                $row['has_original'] = (bool) $row['has_original'];
                $row['has_thumbnails'] = (bool) $row['has_thumbnails'];
                // The HTML of media of the ingester "html".
                $data = $row['data'] ? json_decode($row['data'], true) : null;
                $row['html'] = $row['ingester'] === 'html' && is_array($data) ? (string) ($data['html'] ?? '') : null;
                unset($row['data']);
                $result[$byMediaId ? $row['id'] : $row['item_id']][] = $row;
            }
        }
        return $result;
    }

    /**
     * A short description of a media row for logs and previews.
     */
    public static function mediaSnapshot(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'source' => $row['source'],
            'media_type' => $row['media_type'],
            'ingester' => $row['ingester'],
            'is_public' => (bool) $row['is_public'],
        ];
    }

    public static function groupKey(?int $templateId): string
    {
        return $templateId ? 't' . $templateId : 'none';
    }

    /**
     * How a property is configured in a template (cached).
     *
     * @return array|null ['data_types' => string[], 'is_required', 'is_private', 'default_lang', 'alternate_label']
     */
    public function templateProperty(?int $templateId, int $propertyId): ?array
    {
        if (!$templateId) {
            return null;
        }
        $cacheKey = $templateId . ':' . $propertyId;
        if (array_key_exists($cacheKey, $this->templateProperties)) {
            return $this->templateProperties[$cacheKey];
        }
        $result = null;
        $em = $this->services->get('Omeka\EntityManager');
        $template = $em->find(\Omeka\Entity\ResourceTemplate::class, $templateId);
        if ($template) {
            foreach ($template->getResourceTemplateProperties() as $rtp) {
                if ($rtp->getProperty()->getId() !== $propertyId) {
                    continue;
                }
                $dataTypes = $rtp->getDataType();
                $result = [
                    'data_types' => is_array($dataTypes) ? array_values(array_filter($dataTypes)) : ($dataTypes ? [$dataTypes] : []),
                    'alternate_label' => $rtp->getAlternateLabel(),
                    'is_required' => (bool) $rtp->isRequired(),
                    'is_private' => method_exists($rtp, 'isPrivate') ? (bool) $rtp->isPrivate() : false,
                    'default_lang' => method_exists($rtp, 'getDefaultLang') ? $rtp->getDefaultLang() : null,
                ];
                break;
            }
        }
        return $this->templateProperties[$cacheKey] = $result;
    }

    /**
     * Group resources by resource template and describe how the property is
     * configured in each template.
     *
     * @return array Keyed by group key ("t<id>" or "none").
     */
    public function templateGroups(array $ids, int $propertyId): array
    {
        $em = $this->services->get('Omeka\EntityManager');
        $counts = [];
        foreach ($this->resourceInfo($ids) as $info) {
            $key = self::groupKey($info['template_id']);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $groups = [];
        foreach ($counts as $key => $count) {
            $templateId = $key === 'none' ? null : (int) substr($key, 1);
            $template = $templateId ? $em->find(\Omeka\Entity\ResourceTemplate::class, $templateId) : null;
            $tp = $this->templateProperty($templateId, $propertyId);
            $groups[$key] = [
                'key' => $key,
                'template_id' => $template ? $template->getId() : null,
                'template_label' => $template ? $template->getLabel() : null,
                'count' => $count,
                'has_property' => $tp !== null,
                'data_types' => $tp['data_types'] ?? [],
                'alternate_label' => $tp['alternate_label'] ?? null,
                'is_required' => $tp['is_required'] ?? false,
                'is_private' => $tp['is_private'] ?? false,
                'default_lang' => $tp['default_lang'] ?? null,
            ];
        }
        uasort($groups, function ($a, $b) {
            if ($a['key'] === 'none') {
                return 1;
            }
            if ($b['key'] === 'none') {
                return -1;
            }
            return strcasecmp((string) $a['template_label'], (string) $b['template_label']);
        });
        return $groups;
    }

    public function requiredTemplates(int $propertyId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT resource_template_id FROM resource_template_property WHERE property_id = ? AND is_required = 1',
            [$propertyId]
        );
        return array_fill_keys(array_map('intval', $rows), true);
    }

    // ------------------------------------------------------------------
    // Keys and snapshots
    // ------------------------------------------------------------------

    /**
     * Loose key, used to decide whether a value is already there before
     * adding one: trimmed text, exact case.
     */
    public static function keyFromColumns($type, $value, $uri, $valueResourceId, $lang): string
    {
        return json_encode([
            (string) $type,
            trim((string) $value),
            trim((string) $uri),
            $valueResourceId ? (int) $valueResourceId : 0,
            trim((string) $lang),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Key for "the target field has this value already" (copy and move,
     * 0.7.3).
     *
     * A value that links a resource is the same value when it links the same
     * resource, whatever data type says so ("Omeka resource" and "Item" both
     * point at the same item) and whatever label was saved with it. Other
     * values are compared as keyFromColumns does. Visibility is part of the
     * key: a public and a private copy are two values, as everywhere else in
     * this module.
     */
    public static function targetKey(array $row): string
    {
        $linked = $row['value_resource_id'] ?? null;
        $base = $linked
            ? 'resource#' . (int) $linked
            : self::keyFromColumns($row['type'] ?? '', $row['value'] ?? null, $row['uri'] ?? null, null, $row['lang'] ?? null);
        return $base . '|' . (int) (bool) ($row['is_public'] ?? true);
    }

    /**
     * Exact key, byte for byte: what a list of values shows as separate rows
     * and what matching (remove, replace, cancel) uses. "A4" and "A4 " differ.
     */
    public static function exactKey($type, $value, $uri, $valueResourceId, $lang): string
    {
        return json_encode([
            (string) $type,
            (string) $value,
            (string) $uri,
            $valueResourceId ? (int) $valueResourceId : 0,
            (string) $lang,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function rowKey(array $row, bool $exact = true): string
    {
        $f = $exact ? 'exactKey' : 'keyFromColumns';
        return self::$f($row['type'], $row['value'], $row['uri'], $row['value_resource_id'], $row['lang']);
    }

    /**
     * Key of a row in the value picker: the exact key plus the visibility.
     */
    public static function pickKey(array $row): string
    {
        return self::rowKey($row, true) . '|' . ((int) (bool) $row['is_public']);
    }

    /**
     * Key for "the same value twice" (0.8.2): as pickKey, except that a link
     * is the item it points to, whatever data type says so. Omeka's own form
     * saves a link as "Omeka resource" (`resource`) where the template does
     * not say which kind it is, and as `resource:item` where it does, so the
     * same item can sit in a field twice under two data types.
     */
    public static function dupKey(array $row): string
    {
        if (!empty($row['value_resource_id'])) {
            return 'resource#' . (int) $row['value_resource_id']
                . '|' . (string) ($row['lang'] ?? '')
                . '|' . ((int) (bool) ($row['is_public'] ?? true));
        }
        return self::pickKey($row);
    }


    public static function keyFromSnapshot(array $s, bool $exact = false): string
    {
        $f = $exact ? 'exactKey' : 'keyFromColumns';
        return self::$f($s['type'] ?? '', $s['value'] ?? null, $s['uri'] ?? null, $s['value_resource_id'] ?? null, $s['lang'] ?? null);
    }

    public static function keyFromEntity(Value $value, bool $exact = false): string
    {
        $vr = $value->getValueResource();
        $f = $exact ? 'exactKey' : 'keyFromColumns';
        return self::$f($value->getType(), $value->getValue(), $value->getUri(), $vr ? $vr->getId() : null, $value->getLang());
    }

    public static function snapshotFromEntity(Value $value, int $propertyId, bool $isPublic): array
    {
        $vr = $value->getValueResource();
        return [
            'property_id' => $propertyId,
            'type' => $value->getType(),
            'value' => $value->getValue(),
            'uri' => $value->getUri(),
            'value_resource_id' => $vr ? $vr->getId() : null,
            'lang' => $value->getLang(),
            'is_public' => $isPublic,
        ];
    }

    /**
     * Snapshot of a stored value, with its annotation (as snapshots of the
     * annotation's own values) when it has one.
     */
    public static function snapshotFromRow(array $row): array
    {
        $snapshot = [
            'value_id' => (int) $row['id'],
            'property_id' => (int) $row['property_id'],
            'type' => $row['type'],
            'value' => $row['value'],
            'uri' => $row['uri'],
            'value_resource_id' => $row['value_resource_id'] ? (int) $row['value_resource_id'] : null,
            'lang' => $row['lang'],
            'is_public' => (bool) $row['is_public'],
        ];
        if (!empty($row['annotation_rows'])) {
            $snapshot['annotation'] = array_map(function ($a) {
                return [
                    'property_id' => (int) $a['property_id'],
                    'type' => $a['type'],
                    'value' => $a['value'],
                    'uri' => $a['uri'],
                    'value_resource_id' => $a['value_resource_id'] ? (int) $a['value_resource_id'] : null,
                    'lang' => $a['lang'],
                    'is_public' => (bool) $a['is_public'],
                ];
            }, $row['annotation_rows']);
        }
        return $snapshot;
    }

    /**
     * A snapshot with some columns replaced, keeping visibility, property
     * and annotation unless given.
     */
    public static function withColumns(array $snapshot, array $columns): array
    {
        foreach (['type', 'value', 'uri', 'value_resource_id', 'lang', 'is_public', 'property_id'] as $k) {
            if (array_key_exists($k, $columns)) {
                $snapshot[$k] = $columns[$k];
            }
        }
        return $snapshot;
    }

    // ------------------------------------------------------------------
    // Value rows
    // ------------------------------------------------------------------

    /**
     * Stored values of one property (or all properties) for the resources,
     * each with its annotation values under "annotation_rows".
     *
     * @return array resource id => list of value rows
     */
    /**
     * Value rows by their id (0.8.0), for the two columns of the history
     * page: values the run did not touch are read as they are now.
     *
     * @return array [value id => row]
     */
    /**
     * Links that look wrong (0.9.0), for the "Links to check" page:
     *
     *  - self: an item linked to itself
     *  - one_way: A links to B in a field, B does not link back in the same
     *    field. Only fields that are used both ways somewhere are looked at,
     *    so a field like Creator (never mutual) is not reported.
     *
     * @return array [property id => ['label', 'term', 'self' => [...],
     *   'self_total', 'one_way' => [...], 'one_way_total', 'mutual']]
     */
    /**
     * The shapes accession numbers are written in, as set in Configure (1.0.0).
     *
     * One shape per line: a regular expression with a named group "serial" (the
     * number that climbs by one for each new piece), then " | ", then the named
     * groups the serial counts inside, separated by commas — a new group starts
     * the count again. For example:
     *
     *   /^(?<year>\d{4})-(?<serial>\d{4})$/ | year
     *   /^(?<kind>[A-Z]+)-(?<year>\d{4})-(?<serial>\d{4})$/ | kind, year
     *
     * Shapes are named A, B, C… in the order written. No shapes: the check has
     * nothing to read.
     *
     * @return array [[name => ['pattern', 'group', 'label']], [error, …]]
     */
    public static function parseAccessionShapes(string $text): array
    {
        $shapes = [];
        $errors = [];
        $letter = 'A';
        foreach (preg_split('/\R/', $text) as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $cut = strrpos($line, ' | ');
            $pattern = $cut === false ? $line : trim(substr($line, 0, $cut));
            $group = $cut === false ? [] : array_values(array_filter(array_map('trim', explode(',', substr($line, $cut + 3))), 'strlen'));
            if (@preg_match($pattern, '') === false) {
                $errors[] = sprintf('Line %d: not a regular expression.', $n + 1); // @translate
                continue;
            }
            if (strpos($pattern, '(?<serial>') === false && strpos($pattern, "(?P<serial>") === false) {
                $errors[] = sprintf('Line %d: has no group named "serial".', $n + 1); // @translate
                continue;
            }
            foreach ($group as $g) {
                if (strpos($pattern, '(?<' . $g . '>') === false && strpos($pattern, '(?P<' . $g . '>') === false) {
                    $errors[] = sprintf('Line %1$d: has no group named "%2$s".', $n + 1, $g); // @translate
                    continue 2;
                }
            }
            $shapes[$letter] = [
                'pattern' => $pattern,
                'group' => $group,
                // The run is named the way the number itself is written.
                'label' => $group ? implode('-', array_map(function ($i) { return '%' . $i . '$s'; }, range(1, count($group)))) . '-' : '',
            ];
            $letter++;
        }
        return [$shapes, $errors];
    }

    /** @var array the shapes in use, from parseAccessionShapes() */
    private $accessionShapes = [];

    public function setAccessionShapes(array $shapes): self
    {
        $this->accessionShapes = $shapes;
        return $this;
    }

    /**
     * Accession numbers of a selection: which numbers of a run were never
     * used, what does not fit either shape, and what looks like a slip.
     *
     * Nothing is changed and nothing is judged: a number that was never used
     * is often a piece that was withdrawn or never accessioned, and only the
     * archive knows which.
     *
     * @param int[] $ids
     * @param int $propertyId the field that holds the number
     * @param string[] $placeholderYears years that stand for "not known"
     * @param string|null $membersOf key of the one run whose numbers are
     *        wanted in full, in order, with the item each is on (0.12.3)
     */
    public function accessionIssues(array $ids, int $propertyId, array $placeholderYears = [], int $sample = 200, ?string $membersOf = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [
            'total' => 0, 'kinds' => ['text' => 0, 'link' => 0, 'uri' => 0, 'empty' => 0],
            'parsed' => [], 'groups' => [], 'unparsed' => [],
            'case_twins' => [], 'shared' => [], 'rare_kinds' => [], 'members' => [], 'zeros' => [],
        ];
        if (!$ids || !$propertyId) {
            return $out;
        }
        // Every value of the field, whatever its data type. A value that links
        // to another item is not a number this item was given: it points at the
        // item that holds one. Those are counted, and then left alone — an item
        // can hold several numbers, so there is no saying which one a link
        // stands for, and guessing would put a number in a run that nobody
        // ever gave out (0.12.1).
        $rows = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = array_merge($rows, $this->connection->fetchAllAssociative(
                'SELECT v.id, v.resource_id, v.type, v.`value`, v.uri, v.value_resource_id FROM `value` v'
                . ' WHERE v.property_id = ?'
                . ' AND v.resource_id IN (' . implode(',', $chunk) . ') ORDER BY v.id',
                [$propertyId]
            ));
        }
        if (!$rows) {
            return $out;
        }
        $out['total'] = count($rows);
        $out['kinds'] = ['text' => 0, 'link' => 0, 'uri' => 0, 'empty' => 0];
        $placeholder = array_flip(array_map('trim', $placeholderYears));

        $groups = [];          // key => [serial => true]
        $kindCase = [];        // uppercase kind => [as written => count]
        $byValue = [];         // value => [[resource id, kind, type], …]
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $value = trim((string) $row['value']);
            if ($row['value_resource_id']) {
                $kind = 'link';
                $value = '';
            } elseif ($value === '' && trim((string) $row['uri']) !== '') {
                $kind = 'uri';
                $value = trim((string) $row['uri']);
            } elseif ($value === '') {
                $kind = 'empty';
            } else {
                $kind = 'text';
            }
            $out['kinds'][$kind] = ($out['kinds'][$kind] ?? 0) + 1;
            if ($kind === 'text') {
                $byValue[$value][] = ['resource_id' => (int) $row['resource_id'], 'type' => $type];
            }
            if ($kind !== 'text') {
                // Not a number this item was given, so it takes no place in a
                // run and it is not held against any shape.
                continue;
            }
            $hit = null;
            foreach ($this->accessionShapes as $name => $shape) {
                if (preg_match($shape['pattern'], $value, $m)) {
                    $hit = [$name, $shape, $m];
                    break;
                }
            }
            if ($hit === null) {
                if (count($out['unparsed']) < $sample) {
                    $out['unparsed'][] = ['value' => $value, 'resource_id' => (int) $row['resource_id']];
                }
                $out['parsed']['?'] = ($out['parsed']['?'] ?? 0) + 1;
                continue;
            }
            [$name, $shape, $m] = $hit;
            $out['parsed'][$name] = ($out['parsed'][$name] ?? 0) + 1;
            if (isset($m['kind'])) {
                {
                $upper = strtoupper($m['kind']);
                $kindCase[$upper][$m['kind']]['n'] = ($kindCase[$upper][$m['kind']]['n'] ?? 0) + 1;
                if (count($kindCase[$upper][$m['kind']]['where'] ?? []) < 10) {
                    $kindCase[$upper][$m['kind']]['where'][] = ['value' => $value, 'resource_id' => (int) $row['resource_id']];
                }
            }
            }
            $parts = [];
            foreach ($shape['group'] as $part) {
                $parts[] = $m[$part];
            }
            $key = $name . '|' . implode('|', $parts);
            $serial = (int) ltrim($m['serial'], '0');
            // Every number of one run, sub-pieces and all, for the list that
            // opens when a run is pressed (0.12.3).
            if ($membersOf !== null && $key === $membersOf) {
                $out['members'][] = ['serial' => $serial, 'value' => $value, 'resource_id' => (int) $row['resource_id']];
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'pattern' => $name,
                    'parts' => $parts,
                    'label' => vsprintf($shape['label'], array_pad($parts, max(3, count($parts)), '')),
                    'placeholder' => isset($m['year']) && isset($placeholder[$m['year']]),
                    'width' => strlen($m['serial']),
                    'serials' => [],
                ];
            }
            // The value itself, so a gap can be read with the numbers that sit
            // either side of it and the items they are on (0.12.2).
            if (!isset($groups[$key]['serials'][$serial])) {
                $groups[$key]['serials'][$serial] = ['value' => $value, 'resource_id' => (int) $row['resource_id']];
            }
        }

        foreach ($groups as $key => $group) {
            $at = $group['serials'];
            // 0000 is not a place in the run: a run starts at 0001, so a
            // piece numbered 0000 is almost always a slip. It is taken out of
            // the run and said apart (0.12.4).
            if (isset($at[0])) {
                $out['zeros'][] = $at[0] + ['label' => $group['label'], 'width' => $group['width']];
                unset($at[0]);
            }
            if (!$at) {
                continue;
            }
            $serials = array_keys($at);
            sort($serials);
            $have = array_flip($serials);
            $from = $serials[0];
            $to = $serials[count($serials) - 1];
            // Every run is read from 0001: nobody starts at 0002 on purpose,
            // so a run that starts high is missing the numbers before it just
            // as it is missing a number in the middle (0.12.4).
            $missing = [];
            for ($n = 1; $n <= $to; ++$n) {
                if (!isset($have[$n])) {
                    if ($missing && end($missing)[1] === $n - 1) {
                        $missing[count($missing) - 1][1] = $n;
                    } else {
                        $missing[] = [$n, $n];
                    }
                }
            }
            $total = 0;
            foreach ($missing as [$a, $b]) {
                $total += $b - $a + 1;
            }
            // For each gap, the number in use just under it and just over it.
            $withNeighbours = [];
            foreach ($missing as [$a, $b]) {
                $withNeighbours[] = [
                    'from' => $a,
                    'to' => $b,
                    'count' => $b - $a + 1,
                    'under' => isset($at[$a - 1]) ? $at[$a - 1] + ['serial' => $a - 1] : null,
                    'over' => isset($at[$b + 1]) ? $at[$b + 1] + ['serial' => $b + 1] : null,
                ];
            }
            unset($group['serials']);
            $out['groups'][] = $group + [
                'key' => (string) $key,
                'gaps' => $withNeighbours,
                'count' => count($serials),
                'from' => $from,
                'to' => $to,
                'missing' => $missing,
                'missing_total' => $total,
            ];
        }
        usort($out['groups'], function ($a, $b) {
            return [$a['placeholder'], $a['pattern'], $a['parts']] <=> [$b['placeholder'], $b['pattern'], $b['parts']];
        });
        if ($out['members']) {
            usort($out['members'], function ($a, $b) {
                return [$a['serial'], $a['value']] <=> [$b['serial'], $b['value']];
            });
        }

        // A kind written in a different case from the rest of its uses: one
        // "Al-1986-0064" among hundreds of "AL-" is a slip of the shift key,
        // and it takes that number out of its run.
        foreach ($kindCase as $upper => $written) {
            if (count($written) < 2) {
                continue;
            }
            uasort($written, function ($a, $b) { return $b['n'] <=> $a['n']; });
            $usual = array_key_first($written);
            foreach ($written as $as => $row) {
                if ($as !== $usual) {
                    $out['case_twins'][] = [
                        'usual' => $usual,
                        'usual_count' => $written[$usual]['n'],
                        'odd' => $as,
                        'odd_count' => $row['n'],
                        'where' => $row['where'] ?? [],
                    ];
                }
            }
        }
        // A kind used once or twice where the others are used in the hundreds.
        $kindTotals = [];
        foreach ($kindCase as $upper => $written) {
            $kindTotals[$upper] = 0;
            foreach ($written as $row) {
                $kindTotals[$upper] += $row['n'];
            }
        }
        arsort($kindTotals);
        foreach ($kindTotals as $kind => $n) {
            if ($n <= 2) {
                $where = [];
                foreach ($kindCase[$kind] as $row) {
                    foreach ($row['where'] ?? [] as $one) {
                        $where[] = $one;
                    }
                }
                $out['rare_kinds'][] = ['kind' => $kind, 'count' => $n, 'where' => array_slice($where, 0, 4)];
            }
        }
        // The same number written out on more than one item. Links are not in
        // here at all: they are counted in "kinds" and nothing more.
        foreach ($byValue as $value => $uses) {
            $resources = array_values(array_unique(array_column($uses, 'resource_id')));
            if (count($resources) < 2) {
                continue;
            }
            $out['shared'][] = [
                'value' => (string) $value,
                'resources' => $resources,
                'count' => count($resources),
                'types' => array_values(array_unique(array_column($uses, 'type'))),
            ];
        }
        usort($out['shared'], function ($a, $b) {
            return [$b['count'], $a['value']] <=> [$a['count'], $b['value']];
        });
        return $out;
    }

    public function linkIssues(array $ids, ?int $propertyId = null, int $limit = 50): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $result = [];
        // No limit when the caller asks for every row (the "Fix" needs them all).
        $all = $limit <= 0;
        $where = $propertyId ? ' AND v.property_id = ' . (int) $propertyId : '';
        foreach (array_chunk($ids, 1000) as $chunk) {
            // An item linked to itself.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT v.property_id, v.resource_id, v.id AS value_id, r.title'
                . ' FROM value v JOIN resource r ON r.id = v.resource_id'
                . ' WHERE v.resource_id IN (?) AND v.value_resource_id = v.resource_id' . $where
                . ' ORDER BY v.resource_id, v.id',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $pid = (int) $row['property_id'];
                $result[$pid] = $result[$pid] ?? self::emptyLinkIssue();
                ++$result[$pid]['self_total'];
                if ($all || count($result[$pid]['self']) < $limit) {
                    $result[$pid]['self'][] = [
                        'resource_id' => (int) $row['resource_id'],
                        'value_id' => (int) $row['value_id'],
                        'title' => $row['title'],
                    ];
                }
            }
            // Links that go one way only, in fields that are mutual elsewhere.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT v.property_id, v.resource_id, v.value_resource_id, v.id AS value_id, r.title, t.title AS target_title'
                . ' FROM value v JOIN resource r ON r.id = v.resource_id'
                . ' JOIN resource t ON t.id = v.value_resource_id'
                . ' WHERE v.resource_id IN (?) AND v.value_resource_id IS NOT NULL'
                . ' AND v.value_resource_id <> v.resource_id' . $where
                . ' AND NOT EXISTS (SELECT 1 FROM value b WHERE b.resource_id = v.value_resource_id'
                . ' AND b.property_id = v.property_id AND b.value_resource_id = v.resource_id)'
                . ' ORDER BY v.resource_id, v.id',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $pid = (int) $row['property_id'];
                $result[$pid] = $result[$pid] ?? self::emptyLinkIssue();
                ++$result[$pid]['one_way_total'];
                if ($all || count($result[$pid]['one_way']) < $limit) {
                    $result[$pid]['one_way'][] = [
                        'resource_id' => (int) $row['resource_id'],
                        'target_id' => (int) $row['value_resource_id'],
                        'value_id' => (int) $row['value_id'],
                        'title' => $row['title'],
                        'target_title' => $row['target_title'],
                    ];
                }
            }
        }
        if (!$result) {
            return [];
        }
        // How many links of each of these fields do go both ways: a field
        // with none is not a mutual field, so "one way" says nothing there.
        foreach (array_keys($result) as $pid) {
            $result[$pid]['mutual'] = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM value v WHERE v.property_id = ? AND v.value_resource_id IS NOT NULL'
                . ' AND v.value_resource_id <> v.resource_id'
                . ' AND EXISTS (SELECT 1 FROM value b WHERE b.resource_id = v.value_resource_id'
                . ' AND b.property_id = v.property_id AND b.value_resource_id = v.resource_id)',
                [$pid]
            );
            $meta = $this->connection->fetchAssociative(
                "SELECT p.label, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p"
                . ' JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?',
                [$pid]
            ) ?: ['label' => '#' . $pid, 'term' => ''];
            $result[$pid]['label'] = $meta['label'];
            $result[$pid]['term'] = $meta['term'];
        }
        // Keep the fields that have something to say.
        foreach (array_keys($result) as $pid) {
            if (!$result[$pid]['mutual']) {
                $result[$pid]['one_way'] = [];
                $result[$pid]['one_way_total'] = 0;
            }
            if (!$result[$pid]['self_total'] && !$result[$pid]['one_way_total']) {
                unset($result[$pid]);
            }
        }
        return $result;
    }

    protected static function emptyLinkIssue(): array
    {
        return [
            'label' => '', 'term' => '', 'mutual' => 0,
            'self' => [], 'self_total' => 0,
            'one_way' => [], 'one_way_total' => 0,
        ];
    }

    public function valuesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $found = $this->connection->fetchAllAssociative(
                'SELECT id, resource_id, property_id, value_resource_id, value_annotation_id, type, lang, value, uri, is_public'
                . ' FROM value WHERE id IN (?) ORDER BY id',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($found as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param int|array|null $propertyId One property, several (0.8.0) or all.
     */
    public function valuesByResource(array $ids, $propertyId): array
    {
        $result = [];
        $annotationIds = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $sql = 'SELECT id, resource_id, property_id, value_resource_id, value_annotation_id, type, lang, value, uri, is_public'
                . ' FROM value WHERE resource_id IN (?)';
            $params = [$chunk];
            $types = [Connection::PARAM_INT_ARRAY];
            if (is_array($propertyId)) {
                $sql .= ' AND property_id IN (?)';
                $params[] = array_map('intval', $propertyId);
                $types[] = Connection::PARAM_INT_ARRAY;
            } elseif ($propertyId) {
                $sql .= ' AND property_id = ?';
                $params[] = $propertyId;
                $types[] = \PDO::PARAM_INT;
            }
            $sql .= ' ORDER BY id';
            foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
                $result[(int) $row['resource_id']][] = $row;
                if ($row['value_annotation_id']) {
                    $annotationIds[(int) $row['value_annotation_id']] = true;
                }
            }
        }
        if ($annotationIds) {
            $annotations = [];
            foreach (array_chunk(array_keys($annotationIds), 1000) as $chunk) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT resource_id, property_id, value_resource_id, type, lang, value, uri, is_public FROM value WHERE resource_id IN (?) ORDER BY id',
                    [$chunk],
                    [Connection::PARAM_INT_ARRAY]
                );
                foreach ($rows as $row) {
                    $annotations[(int) $row['resource_id']][] = $row;
                }
            }
            foreach ($result as &$rows) {
                foreach ($rows as &$row) {
                    if ($row['value_annotation_id']) {
                        $row['annotation_rows'] = $annotations[(int) $row['value_annotation_id']] ?? [];
                    }
                }
                unset($row);
            }
            unset($rows);
        }
        return $result;
    }

    /**
     * Distinct stored values of a property among the resources, with counts,
     * grouped byte for byte.
     *
     * @return array key => ['key', 'sample_value_id', 'type', 'lang', 'items', 'occurrences', 'spacing', 'annotated']
     */
    public function distinctValues(array $ids, int $propertyId): array
    {
        $groups = [];
        foreach ($this->valuesByResource($ids, $propertyId) as $rows) {
            $seen = [];
            foreach ($rows as $row) {
                // One row per wording AND visibility: the same value can be
                // public on some resources and private on others, and each
                // side must be choosable on its own.
                $key = self::pickKey($row);
                if (!isset($groups[$key])) {
                    $text = (string) ($row['value'] ?? '');
                    $uri = (string) ($row['uri'] ?? '');
                    $groups[$key] = [
                        'key' => $key,
                        'sample_value_id' => (int) $row['id'],
                        'type' => $row['type'],
                        'lang' => $row['lang'],
                        'items' => 0,
                        'occurrences' => 0,
                        'annotated' => 0,
                        'private' => 0,
                        'is_public' => (bool) $row['is_public'],
                        'spacing' => ($text !== trim($text)) || ($uri !== trim($uri)),
                    ];
                }
                $groups[$key]['occurrences']++;
                if ($row['value_annotation_id']) {
                    $groups[$key]['annotated']++;
                }
                if (!$row['is_public']) {
                    $groups[$key]['private']++;
                }
                if (!isset($seen[$key])) {
                    $groups[$key]['items']++;
                    $seen[$key] = true;
                }
            }
        }
        uasort($groups, function ($a, $b) {
            return $b['items'] <=> $a['items'] ?: $a['sample_value_id'] <=> $b['sample_value_id'];
        });
        return $groups;
    }

    /**
     * Validate and canonicalize values posted from the data type forms.
     *
     * Each value is checked with its data type's own isValid(), then hydrated
     * into a detached Value (never persisted) so that the comparison uses
     * exactly what would be stored.
     *
     * @return array ['values' => [['object', 'key', 'snapshot']], 'errors' => string[]]
     */
    public function prepareNewValues(array $valueObjects, int $propertyId, bool $isPublic, array $allowedTypes = []): array
    {
        $prepared = [];
        $errors = [];
        $seen = [];
        foreach ($valueObjects as $object) {
            if (!is_array($object) || empty($object['type'])) {
                continue;
            }
            $type = (string) $object['type'];
            // Visibility per value when the form gives one, else the default.
            $valuePublic = array_key_exists('is_public', $object) ? (bool) (int) $object['is_public'] : $isPublic;
            if ($allowedTypes && !in_array($type, $allowedTypes, true)) {
                $errors[] = sprintf('Data type "%s" is not allowed by the template for this property.', $type); // @translate
                continue;
            }
            foreach ($object as $k => $v) {
                if (is_string($v)) {
                    $object[$k] = trim($v);
                }
                if ($object[$k] === '') {
                    unset($object[$k]);
                }
            }
            $object['type'] = $type;
            $object['property_id'] = $propertyId;
            $object['is_public'] = $valuePublic ? 1 : 0;
            $result = $this->hydrateObject($object);
            if ($result === null) {
                continue;
            }
            if (is_string($result)) {
                $errors[] = $result;
                continue;
            }
            // The same test the target of copy/move uses (0.7.3): a linked
            // resource is the same value whatever data type says so, and a
            // public and a private copy are two values.
            $vr = $result->getValueResource();
            $key = self::targetKey([
                'type' => $result->getType(),
                'value' => $result->getValue(),
                'uri' => $result->getUri(),
                'value_resource_id' => $vr ? $vr->getId() : null,
                'lang' => $result->getLang(),
                'is_public' => $valuePublic,
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $prepared[] = [
                'object' => $object,
                'key' => $key,
                'snapshot' => self::snapshotFromEntity($result, $propertyId, $valuePublic),
            ];
        }
        return ['values' => $prepared, 'errors' => $errors];
    }

    /**
     * Run a value object through its data type: null when invalid, a message
     * string on error, otherwise the detached hydrated Value.
     *
     * @return Value|string|null
     */
    public function hydrateObject(array $object)
    {
        $dataTypes = $this->services->get('Omeka\DataTypeManager');
        $type = (string) ($object['type'] ?? '');
        if (!$dataTypes->has($type)) {
            return sprintf('Unknown data type "%s".', $type); // @translate
        }
        $dataType = $dataTypes->get($type);
        try {
            if (!$dataType->isValid($object)) {
                return null;
            }
            $value = new Value;
            $value->setType($dataType->getName());
            $dataType->hydrate($object, $value, $this->services->get('Omeka\ApiAdapterManager')->get($this->resourceName));
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return $value;
    }

    /**
     * Data types whose text can be edited as text: the "value" column holds
     * what people read (for links: the label; the URL is in "uri"). Other
     * data types (linked resources, terms stored by id) are never edited as
     * text, whatever their stored columns look like.
     */
    public static function isTextType(?string $type): bool
    {
        $type = (string) $type;
        if (in_array($type, ['literal', 'uri', 'html', 'xml'], true)) {
            return true;
        }
        foreach (['customvocab:', 'numeric:', 'valuesuggest:', 'valuesuggestall:'] as $prefix) {
            if (strpos($type, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Data types that store a link: "value" is its label, "uri" the URL. */
    public static function isLinkType(?string $type): bool
    {
        $type = (string) $type;
        return $type === 'uri' || self::isValueSuggest($type);
    }

    /**
     * Value Suggest data types keep either a link (URI and label) or, when
     * nothing was picked from the suggestions or the suggestions come from
     * this Omeka ("omeka:property"), plain text in "value" with no URI.
     */
    public static function isValueSuggest(?string $type): bool
    {
        $type = (string) $type;
        return strpos($type, 'valuesuggest:') === 0 || strpos($type, 'valuesuggestall:') === 0;
    }

    /** A stored value that is a link with a URI (not plain text). */
    public static function hasLink(array $row): bool
    {
        return self::isLinkType($row['type'] ?? null) && trim((string) ($row['uri'] ?? '')) !== '';
    }

    /**
     * The column that must not be empty: the URI of a link, else the text.
     */
    public static function mainColumn(array $row): string
    {
        return self::hasLink($row) ? 'uri' : 'value';
    }

    /**
     * Plain text that can be split or joined: Text, Custom vocab (terms),
     * and Value Suggest values that hold text without a URI.
     */
    public static function isPlainText(array $row): bool
    {
        $type = (string) ($row['type'] ?? '');
        return $type === 'literal'
            || strpos($type, 'customvocab:') === 0
            || (self::isValueSuggest($type) && trim((string) ($row['uri'] ?? '')) === '');
    }

    /**
     * Check a changed value with its data type (isValid, then hydrate into a
     * detached Value) and return the snapshot as it would be stored, or an
     * error message. Visibility, property and annotation are kept.
     *
     * @return array ['snapshot' => ?array, 'error' => ?string]
     */
    public function validateSnapshot(array $snapshot): array
    {
        $object = [
            'type' => $snapshot['type'],
            'property_id' => $snapshot['property_id'] ?? null,
        ];
        $text = $snapshot['value'] ?? null;
        $uri = $snapshot['uri'] ?? null;
        if ($text !== null && $text !== '') {
            $object['@value'] = $text;
            $object['o:label'] = $text;
        }
        if ($uri !== null && $uri !== '') {
            $object['@id'] = $uri;
        }
        if (!empty($snapshot['value_resource_id'])) {
            $object['value_resource_id'] = (int) $snapshot['value_resource_id'];
        }
        if (isset($snapshot['lang']) && $snapshot['lang'] !== '') {
            $object['@language'] = $snapshot['lang'];
            $object['o:lang'] = $snapshot['lang'];
        }
        $result = $this->hydrateObject($object);
        if ($result === null) {
            return ['snapshot' => null, 'error' => 'not accepted by its data type'];
        }
        if (is_string($result)) {
            return ['snapshot' => null, 'error' => $result];
        }
        $vr = $result->getValueResource();
        return ['snapshot' => self::withColumns($snapshot, [
            'type' => $result->getType(),
            'value' => $result->getValue(),
            'uri' => $result->getUri(),
            'value_resource_id' => $vr ? $vr->getId() : null,
            'lang' => $result->getLang(),
        ]), 'error' => null];
    }

    /**
     * Values whose data type is not one the resource's template sets for
     * their property, counted per property, template and data type.
     *
     * @return array property id => ['label', 'term', 'templates' => [template id =>
     *   ['label', 'expected' => string[], 'found' => [type => ['values', 'resources']]]], 'values', 'resources']
     */
    public function typeMismatches(array $ids): array
    {
        $counts = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT v.property_id, r.resource_template_id AS template_id, v.type, COUNT(*) AS n, COUNT(DISTINCT v.resource_id) AS res'
                . ' FROM value v JOIN resource r ON r.id = v.resource_id'
                . ' WHERE v.resource_id IN (?) AND r.resource_template_id IS NOT NULL'
                . ' GROUP BY v.property_id, r.resource_template_id, v.type',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
            foreach ($rows as $row) {
                $k = $row['property_id'] . ':' . $row['template_id'] . ':' . $row['type'];
                $counts[$k] = [
                    'p' => (int) $row['property_id'],
                    't' => (int) $row['template_id'],
                    'type' => $row['type'],
                    'n' => ($counts[$k]['n'] ?? 0) + (int) $row['n'],
                    'res' => ($counts[$k]['res'] ?? 0) + (int) $row['res'],
                ];
            }
        }
        $result = [];
        $templateLabels = [];
        foreach ($counts as $c) {
            $tp = $this->templateProperty($c['t'], $c['p']);
            if (!$tp || !$tp['data_types'] || in_array($c['type'], $tp['data_types'], true)) {
                continue;
            }
            if (!isset($result[$c['p']])) {
                $meta = $this->connection->fetchAssociative(
                    "SELECT p.label, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?",
                    [$c['p']]
                ) ?: ['label' => '#' . $c['p'], 'term' => ''];
                $result[$c['p']] = ['label' => $meta['label'], 'term' => $meta['term'], 'templates' => [], 'values' => 0, 'resources' => 0];
            }
            if (!isset($templateLabels[$c['t']])) {
                $templateLabels[$c['t']] = (string) $this->connection->fetchOne('SELECT label FROM resource_template WHERE id = ?', [$c['t']]);
            }
            $result[$c['p']]['templates'][$c['t']]['label'] = $templateLabels[$c['t']];
            $result[$c['p']]['templates'][$c['t']]['expected'] = $tp['data_types'];
            $result[$c['p']]['templates'][$c['t']]['found'][$c['type']] = ['values' => $c['n'], 'resources' => $c['res']];
            $result[$c['p']]['values'] += $c['n'];
            $result[$c['p']]['resources'] += $c['res'];
        }
        uasort($result, function ($a, $b) {
            return $b['values'] <=> $a['values'];
        });
        return $result;
    }

    /**
     * The same value more than once in one field of one resource: same data
     * type, text, URI, linked resource, language and visibility, byte for
     * byte (what "Clean up › The same value entered twice" removes).
     *
     * @param ?int $propertyId Only this property (for "Fix"), else all.
     * @param int $limit Rows kept per property (0: none, only the ids).
     * @return array property id => ['label', 'term', 'resources' => int,
     *   'extra' => int (copies to remove), 'resource_ids' => int[],
     *   'rows' => [['value_id', 'resource_id', 'title', 'n', 'is_public']], 'total' => int]
     */
    public function duplicates(array $ids, ?int $propertyId = null, int $limit = 50): array
    {
        $result = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $sql = 'SELECT v.property_id, v.resource_id, MIN(v.id) AS value_id, COUNT(*) AS n, MAX(v.is_public) AS is_public, r.title'
                . ' FROM value v JOIN resource r ON r.id = v.resource_id'
                . ' WHERE v.resource_id IN (?)' . ($propertyId ? ' AND v.property_id = ?' : '')
                // A link is grouped by the item it points to, not by its data
                // type: "Omeka resource" and "Item" are the same value (0.8.2).
                . ' GROUP BY v.resource_id, v.property_id,'
                . ' IF(v.value_resource_id IS NULL, CONCAT(\'t\', BINARY v.type), \'link\'),'
                . ' MD5(COALESCE(v.value, \'\')), v.value IS NULL,'
                . ' MD5(COALESCE(v.uri, \'\')), v.uri IS NULL, v.value_resource_id, BINARY COALESCE(v.lang, \'\'), v.lang IS NULL, v.is_public, r.title'
                . ' HAVING COUNT(*) > 1 ORDER BY v.resource_id, MIN(v.id)';
            $params = $propertyId ? [$chunk, $propertyId] : [$chunk];
            $types = $propertyId ? [Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT] : [Connection::PARAM_INT_ARRAY];
            foreach ($this->connection->fetchAllAssociative($sql, $params, $types) as $row) {
                $pid = (int) $row['property_id'];
                if (!isset($result[$pid])) {
                    $result[$pid] = ['resources' => 0, 'extra' => 0, 'resource_ids' => [], 'rows' => [], 'total' => 0];
                }
                $rid = (int) $row['resource_id'];
                if (!isset($result[$pid]['resource_ids'][$rid])) {
                    $result[$pid]['resource_ids'][$rid] = true;
                    ++$result[$pid]['resources'];
                }
                $result[$pid]['extra'] += (int) $row['n'] - 1;
                ++$result[$pid]['total'];
                if (count($result[$pid]['rows']) < $limit) {
                    $result[$pid]['rows'][] = [
                        'value_id' => (int) $row['value_id'],
                        'resource_id' => $rid,
                        'title' => $row['title'],
                        'n' => (int) $row['n'],
                        'is_public' => (bool) $row['is_public'],
                    ];
                }
            }
        }
        foreach ($result as $pid => &$r) {
            $meta = $this->connection->fetchAssociative(
                "SELECT p.label, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?",
                [$pid]
            ) ?: ['label' => '#' . $pid, 'term' => ''];
            $r['label'] = $meta['label'];
            $r['term'] = $meta['term'];
            $r['resource_ids'] = array_keys($r['resource_ids']);
        }
        unset($r);
        uasort($result, function ($a, $b) {
            return $b['extra'] <=> $a['extra'];
        });
        return $result;
    }

    /**
     * The values of one property, in resources of one template, whose data
     * type is one of $types: the resources holding them and the first rows.
     *
     * @return array ['resource_ids' => int[], 'rows' => array, 'total' => int]
     */
    public function mismatchRows(array $ids, int $propertyId, int $templateId, array $types, int $limit = 100): array
    {
        $resourceIds = [];
        $rows = [];
        $total = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $found = $this->connection->fetchAllAssociative(
                'SELECT v.id, v.resource_id, v.type, v.value, v.uri, v.value_resource_id, v.lang, v.is_public, r.title'
                . ' FROM value v JOIN resource r ON r.id = v.resource_id'
                . ' WHERE v.resource_id IN (?) AND v.property_id = ? AND r.resource_template_id = ? AND v.type IN (?)'
                . ' ORDER BY v.resource_id, v.id',
                [$chunk, $propertyId, $templateId, $types],
                [Connection::PARAM_INT_ARRAY, \PDO::PARAM_INT, \PDO::PARAM_INT, Connection::PARAM_STR_ARRAY]
            );
            foreach ($found as $row) {
                $resourceIds[(int) $row['resource_id']] = true;
                ++$total;
                if (count($rows) < $limit) {
                    $rows[] = $row;
                }
            }
        }
        return ['resource_ids' => array_keys($resourceIds), 'rows' => $rows, 'total' => $total];
    }

    /**
     * Numbers of a property before and after a plan, for the selected
     * resources and for the whole archive: values, resources holding at
     * least one, distinct values (byte for byte).
     *
     * @param int[] $propertyIds
     * @return array property id => ['term', 'label', 'before' => [...], 'after' => [...], 'archive' => [before, after]]
     */
    public function propertyStats(array $ids, array $propertyIds, array $plan): array
    {
        $propertyIds = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        if (!$propertyIds) {
            return [];
        }
        // Current rows of the involved properties, by value id.
        $rows = [];
        foreach ($propertyIds as $pid) {
            foreach ($this->valuesByResource($ids, $pid) as $resourceId => $list) {
                foreach ($list as $row) {
                    $rows[(int) $row['id']] = ['r' => (int) $resourceId, 'p' => $pid, 'k' => self::rowKey($row, true)];
                }
            }
        }
        $count = function (array $rows) use ($propertyIds) {
            $out = [];
            foreach ($propertyIds as $pid) {
                $out[$pid] = ['values' => 0, 'resources' => [], 'distinct' => []];
            }
            foreach ($rows as $row) {
                if (!isset($out[$row['p']])) {
                    continue;
                }
                $out[$row['p']]['values']++;
                $out[$row['p']]['resources'][$row['r']] = true;
                $out[$row['p']]['distinct'][$row['k']] = true;
            }
            foreach ($out as &$o) {
                $o = ['values' => $o['values'], 'resources' => count($o['resources']), 'distinct' => count($o['distinct'])];
            }
            return $out;
        };
        $before = $count($rows);
        $new = 0;
        foreach ($plan as $resourceId => $entry) {
            if (!in_array($entry['outcome'] ?? '', ['added', 'removed', 'changed'], true)) {
                continue;
            }
            foreach ($entry['pairs'] ?? [] as $pair) {
                $vid = $pair['value_id'] ?? null;
                if ($pair['before'] !== null && $vid) {
                    unset($rows[(int) $vid]);
                }
                if ($pair['after'] !== null) {
                    $rows[$vid && $pair['before'] !== null ? (int) $vid : 'n' . (++$new)] = [
                        'r' => (int) $resourceId,
                        'p' => (int) $pair['after']['property_id'],
                        'k' => self::keyFromSnapshot($pair['after'], true),
                    ];
                }
            }
        }
        $after = $count($rows);
        $result = [];
        foreach ($propertyIds as $pid) {
            $meta = $this->connection->fetchAssociative(
                "SELECT p.label, CONCAT(v.prefix, ':', p.local_name) AS term FROM property p JOIN vocabulary v ON v.id = p.vocabulary_id WHERE p.id = ?",
                [$pid]
            ) ?: ['label' => '#' . $pid, 'term' => ''];
            $archive = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM value v JOIN resource r ON r.id = v.resource_id WHERE v.property_id = ? AND r.resource_type = ?',
                [$pid, self::RESOURCE_TYPES[$this->resourceName][2]]
            );
            $result[$pid] = [
                'term' => $meta['term'],
                'label' => $meta['label'],
                'before' => $before[$pid],
                'after' => $after[$pid],
                'archive' => [$archive, $archive + $after[$pid]['values'] - $before[$pid]['values']],
            ];
        }
        return $result;
    }

    /**
     * @var array Cache of display texts by exact key.
     */
    protected $texts = [];

    protected $textLoads = 0;

    /**
     * The value as people see it, as plain text: a term's label, a linked
     * resource's title, a link's label. Uses the data type's own render().
     */
    public function displayText(array $row): string
    {
        $key = self::rowKey($row, true);
        if (isset($this->texts[$key])) {
            return $this->texts[$key];
        }
        $text = '';
        $em = $this->services->get('Omeka\EntityManager');
        try {
            $value = $em->find(Value::class, (int) $row['id']);
            $dataTypes = $this->services->get('Omeka\DataTypeManager');
            if ($value && $dataTypes->has($value->getType())) {
                $html = (string) $dataTypes->get($value->getType())->render(
                    $this->services->get('ViewRenderer'),
                    new \Omeka\Api\Representation\ValueRepresentation($value, $this->services),
                    []
                );
                $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        } catch (\Throwable $e) {
        }
        if ($text === '') {
            $text = trim((string) ($row['value'] ?? $row['uri'] ?? ''));
        }
        // Reading only: keep memory flat on long runs.
        if (++$this->textLoads % 2000 === 0) {
            $em->clear();
        }
        return $this->texts[$key] = $text;
    }

    /**
     * Count outcomes per group from a plan.
     */
    public static function summarize(array $plan): array
    {
        $summary = ['total' => [], 'groups' => [], 'values' => []];
        foreach ($plan as $entry) {
            $o = $entry['outcome'];
            $g = $entry['group'] ?? 'missing';
            $summary['total'][$o] = ($summary['total'][$o] ?? 0) + 1;
            $summary['groups'][$g][$o] = ($summary['groups'][$g][$o] ?? 0) + 1;
            foreach ($entry['prepared'] ?? [] as $prepared) {
                $k = $prepared['key'];
                $summary['values'][$g][$k]['added'] = ($summary['values'][$g][$k]['added'] ?? 0) + 1;
            }
            foreach ($entry['present'] ?? [] as $k) {
                $summary['values'][$g][$k]['present'] = ($summary['values'][$g][$k]['present'] ?? 0) + 1;
            }
        }
        return $summary;
    }
}
