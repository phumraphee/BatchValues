<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Tidy the values of a property: spaces at the ends, double spaces,
 * invisible characters, empty values, the same value twice.
 */
class CleanValues extends AbstractTextOperation
{
    /** Characters that are invisible and never wanted in catalogue text. */
    const INVISIBLE = '/[\x{200B}\x{FEFF}\x{2060}]/u';

    public function name(): string
    {
        return 'clean';
    }

    public function label(): string
    {
        return 'Clean up spaces and duplicates'; // @translate
    }

    public function help(): string
    {
        return 'Remove extra spaces, invisible characters, empty values and the same value entered twice.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function parse(array $post, array $ctx): array
    {
        $options = [];
        foreach (['trim', 'collapse', 'invisible', 'nbsp', 'empty', 'duplicates', 'duplicates_case', 'mojibake', 'resource_type', 'nulls'] as $option) {
            $options[$option] = !empty($post[$option]);
        }
        $errors = array_filter($options) ? [] : [$this->translate('Tick at least one thing to clean.')];
        $labels = [
            'trim' => 'Spaces at the start and end', // @translate
            'collapse' => 'Double spaces', // @translate
            'invisible' => 'Invisible characters', // @translate
            'nbsp' => 'Non-breaking spaces', // @translate
            'empty' => 'Empty values', // @translate
            'duplicates' => 'Values entered twice', // @translate
            'duplicates_case' => 'Values entered twice, whatever the case', // @translate
            'mojibake' => 'Garbled characters', // @translate
            'resource_type' => 'Data type of linked resources', // @translate
            'nulls' => 'Empty language, text or URL stored as none', // @translate
        ];
        if ($options['duplicates_case']) {
            $options['duplicates'] = true;
        }
        // Which copy of a value entered twice stays (0.6.2).
        $keepCopy = ($post['keep_copy'] ?? 'first') === 'last' ? 'last' : 'first';
        $chosen = [];
        foreach ($options as $k => $on) {
            if ($on) {
                $label = $this->translate($labels[$k]);
                if ($k === 'duplicates' && $keepCopy === 'last') {
                    $label .= ' (' . $this->translate('the last copy stays') . ')';
                }
                $chosen[] = $label;
            }
        }
        // Copies chosen one by one on the check page (0.6.5).
        $keepIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string) ($post['keep_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)))));
        if ($keepIds && $options['duplicates']) {
            $chosen[] = sprintf($this->translate('%d copies chosen one by one'), count($keepIds));
        }
        return ['params' => $options + ['keep_copy' => $keepCopy] + ($keepIds && $options['duplicates'] ? ['keep_ids' => $keepIds] : []) + [
            'display' => [['label' => 'Clean', 'text' => implode(' · ', $chosen)]], // @translate
        ], 'errors' => $errors];
    }

    /** Characters of Windows-1252 above 0x7F that are not Latin-1, by code point. */
    const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    /**
     * Text that was UTF-8 read as Windows-1252 or Latin-1 and saved again
     * ("CafÃ©", "à¸à¸²à¸£"): the bytes are put back. The text is changed only
     * when every character maps back to one byte and the bytes are valid
     * UTF-8 with at least one character beyond ASCII, so correct text
     * ("Café", Thai text) is never touched.
     */
    public static function fixMojibake(string $text): string
    {
        if (!preg_match('/[\x{00C2}-\x{00F4}]/u', $text)) {
            return $text;
        }
        $bytes = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $cp = mb_ord($char, 'UTF-8');
            if ($cp < 0x100) {
                $bytes .= chr($cp);
            } elseif (isset(self::CP1252[$cp])) {
                $bytes .= chr(self::CP1252[$cp]);
            } else {
                return $text;
            }
        }
        if ($bytes === $text || !mb_check_encoding($bytes, 'UTF-8') || !preg_match('/[^\x00-\x7F]/', $bytes)) {
            return $text;
        }
        return $bytes;
    }

    public function cleanText(string $text, array $params): string
    {
        if (!empty($params['mojibake'])) {
            $text = self::fixMojibake($text);
        }
        if (!empty($params['invisible'])) {
            $text = preg_replace(self::INVISIBLE, '', $text);
        }
        // Non-breaking spaces are their own option since 0.6.6; before, they
        // went with the invisible characters (change sets without "nbsp").
        if (!empty($params['nbsp']) || (!array_key_exists('nbsp', $params) && !empty($params['invisible']))) {
            $text = str_replace("\u{00A0}", ' ', $text);
        }
        if (!empty($params['collapse'])) {
            $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
        }
        if (!empty($params['trim'])) {
            $text = preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $text);
        }
        return $text;
    }

    /**
     * What "Clean up" would fix in one value row, one option at a time (for
     * the "Values to clean up" check). Duplicates are counted elsewhere,
     * since they need the other values of the field.
     *
     * @return string[] option names: trim, collapse, invisible, nbsp, empty,
     *   mojibake, nulls, resource_type
     */
    public function issues(array $row): array
    {
        $found = [];
        if (Planner::isTextType($row['type'])) {
            foreach (['empty', 'trim', 'collapse', 'invisible', 'nbsp', 'mojibake'] as $option) {
                $result = $this->editRow($row, [$option => true, 'nbsp' => $option === 'nbsp']);
                if ($result !== null && (!empty($result['remove']) || !empty($result['columns']))) {
                    $found[] = $option;
                    if ($option === 'empty') {
                        // An empty value is only that.
                        break;
                    }
                }
            }
        }
        foreach (['value', 'uri', 'lang'] as $column) {
            if ($row[$column] !== null && $row[$column] === '') {
                $found[] = 'nulls';
                break;
            }
        }
        if ($row['type'] === 'resource' && $row['value_resource_id']) {
            $found[] = 'resource_type';
        }
        return $found;
    }

    protected function editRow(array $row, array $params): ?array
    {
        $params['link_part'] = 'both';
        $isEmpty = function ($r) {
            $main = Planner::mainColumn($r);
            return trim(preg_replace(self::INVISIBLE, '', (string) $r[$main]), " \t\n\r\0\x0B\u{00A0}") === '';
        };
        if (!empty($params['empty']) && $isEmpty($row)) {
            return ['remove' => true];
        }
        $columns = $this->mapText($row, $params, function ($text) use ($params) {
            return $this->cleanText($text, $params);
        });
        if (!$columns) {
            return null;
        }
        if ($isEmpty(array_merge($row, $columns))) {
            return !empty($params['empty'])
                ? ['remove' => true]
                : ['note' => sprintf($this->translate('“%s” would become empty: left as it is.'), self::short($row))];
        }
        return ['columns' => $columns];
    }

    protected function planRows(string $key, array $rows, array $params): array
    {
        $entry = parent::planRows($key, $rows, $params);
        if ($rows && $entry['outcome'] === 'skipped_no_match') {
            // The field has values: they are clean already.
            $entry['outcome'] = 'skipped_same';
        }
        if (empty($params['duplicates'])) {
            return $entry;
        }
        // Values that are the same already, before any cleaning. Which copy
        // stays: the one chosen on the check page (keep_ids, 0.6.5), else an
        // annotated copy, else the first one (or the last one, keep_copy).
        // Copies with a different visibility are different on purpose.
        $keepLast = ($params['keep_copy'] ?? 'first') === 'last';
        $keepIds = array_flip(array_map('intval', $params['keep_ids'] ?? []));
        // Values cleaned above are compared as they will be (0.6.5): " Bangkok "
        // becomes "Bangkok", so it is the same as another "Bangkok".
        $pairs = $entry['pairs'] ?? [];
        $touched = [];
        foreach ($pairs as $i => $pair) {
            $touched[(int) $pair['value_id']] = $i;
        }
        $groups = [];
        foreach ($rows as $row) {
            $rid = (int) $row['id'];
            if (isset($touched[$rid])) {
                $after = $pairs[$touched[$rid]]['after'];
                if ($after === null) {
                    // Removed already (empty).
                    continue;
                }
                $exact = Planner::keyFromSnapshot($after, true);
                $isPublic = (int) !empty($after['is_public']);
            } else {
                $exact = Planner::rowKey($row, true);
                $isPublic = (int) (bool) $row['is_public'];
            }
            if (!empty($row['value_resource_id'])) {
                // Two links to the same item are the same value, even when
                // one is "Omeka resource" and the other one "Item" (0.8.2).
                $exact = 'resource#' . (int) $row['value_resource_id'] . '|' . (string) $row['lang'];
            }
            $k = (!empty($params['duplicates_case']) ? mb_strtolower($exact) : $exact) . '|' . $isPublic;
            $groups[$k][] = $row;
        }
        $keep = [];
        $notes = $entry['message'] ? [$entry['message']] : [];
        $removed = [];
        foreach ($groups as $k => $copies) {
            if (count($copies) < 2) {
                $keep[$k] = $copies[0];
                continue;
            }
            $chosen = array_values(array_filter($copies, function ($r) use ($keepIds) {
                return isset($keepIds[(int) $r['id']]);
            }));
            $annotated = array_values(array_filter($copies, function ($r) {
                return !empty($r['value_annotation_id']);
            }));
            // Of two links to the same item, the one whose data type says
            // what it links to (Item, Item set, Media) is the one to keep:
            // the other one is Omeka's general "Omeka resource" (0.8.2).
            $exactLinks = array_values(array_filter($copies, function ($r) {
                return !empty($r['value_resource_id']) && strpos((string) $r['type'], 'resource:') === 0;
            }));
            if ($chosen) {
                $stay = [$chosen[0]];
            } elseif ($annotated) {
                $stay = $annotated;
            } elseif ($exactLinks && count($exactLinks) < count($copies)) {
                $stay = [$keepLast ? $exactLinks[count($exactLinks) - 1] : $exactLinks[0]];
            } else {
                $stay = [$keepLast ? $copies[count($copies) - 1] : $copies[0]];
            }
            $stayIds = array_map(function ($r) {
                return (int) $r['id'];
            }, $stay);
            foreach ($copies as $row) {
                if (in_array((int) $row['id'], $stayIds, true)) {
                    continue;
                }
                if (!empty($row['value_annotation_id'])) {
                    // An annotation is never dropped by this cleaning.
                    $notes[] = sprintf($this->translate('A copy of “%s” has an annotation: that copy is left as it is.'), self::short($row));
                    continue;
                }
                if (isset($touched[(int) $row['id']])) {
                    // Cleaned and then the same as the copy that stays: removed.
                    $pairs[$touched[(int) $row['id']]]['after'] = null;
                    $pairs[$touched[(int) $row['id']]]['reason'] = 'merged';
                    continue;
                }
                $removed[(int) $row['id']] = ['value_id' => (int) $row['id'], 'before' => Planner::snapshotFromRow($row), 'after' => null, 'reason' => 'merged'];
            }
            if (count($stay) > 1) {
                $notes[] = sprintf($this->translate('“%s” is there more than once with an annotation: those copies are left as they are.'), self::short($stay[0]));
            }
            $keep[$k] = $stay[0];
        }
        ksort($removed);
        foreach ($removed as $pair) {
            $pairs[] = $pair;
        }
        // Same wording, different visibility: say so, change nothing.
        $byExact = [];
        foreach ($keep as $row) {
            $byExact[Planner::rowKey($row, true)][] = $row;
        }
        foreach ($byExact as $list) {
            if (count($list) > 1) {
                $notes[] = sprintf($this->translate('“%s” is there twice, once public and once private: left as it is.'), self::short($list[0]));
            }
        }
        $message = $notes ? implode(' ', array_unique($notes)) : null;
        if ($pairs) {
            return self::entry($key, 'changed', $pairs, ['message' => $message]);
        }
        return ['message' => $message] + $entry;
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $plan = parent::plan($ids, $params, $ctx);
        if (empty($params['resource_type']) && empty($params['nulls'])) {
            return $plan;
        }
        // Technical fixes, value by value, on top of the rest.
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $info = $this->planner->resourceInfo($ids);
        $linked = [];
        if (!empty($params['resource_type'])) {
            $linkedIds = [];
            foreach ($existing as $rows) {
                foreach ($rows as $row) {
                    if ($row['type'] === 'resource' && $row['value_resource_id']) {
                        $linkedIds[(int) $row['value_resource_id']] = true;
                    }
                }
            }
            if ($linkedIds) {
                $linked = $this->services->get('Omeka\\Connection')->fetchAllKeyValue(
                    'SELECT id, resource_type FROM resource WHERE id IN (?)',
                    [array_keys($linkedIds)],
                    [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                );
            }
        }
        $precise = [
            'Omeka\\Entity\\Item' => 'resource:item',
            'Omeka\\Entity\\ItemSet' => 'resource:itemset',
            'Omeka\\Entity\\Media' => 'resource:media',
        ];
        foreach ($plan as $id => $entry) {
            if (!isset($info[$id])) {
                continue;
            }
            $templateProperty = $this->planner->templateProperty($info[$id]['template_id'], (int) $ctx['property_id']);
            $allowed = $templateProperty['data_types'] ?? [];
            $pairs = $entry['pairs'] ?? [];
            $byValue = [];
            foreach ($pairs as $i => $pair) {
                if (!empty($pair['value_id'])) {
                    $byValue[(int) $pair['value_id']] = $i;
                }
            }
            $added = false;
            foreach ($existing[$id] ?? [] as $row) {
                $columns = [];
                if (!empty($params['nulls'])) {
                    foreach (['value', 'uri', 'lang'] as $column) {
                        if ($row[$column] !== null && $row[$column] === '') {
                            $columns[$column] = null;
                        }
                    }
                }
                if (!empty($params['resource_type']) && $row['type'] === 'resource' && isset($linked[(int) $row['value_resource_id']])) {
                    $target = $precise[$linked[(int) $row['value_resource_id']]] ?? null;
                    if ($target && (!$allowed || in_array($target, $allowed, true))) {
                        $columns['type'] = $target;
                    }
                }
                if (!$columns) {
                    continue;
                }
                $rid = (int) $row['id'];
                if (isset($byValue[$rid])) {
                    $i = $byValue[$rid];
                    if ($pairs[$i]['after'] !== null) {
                        $pairs[$i]['after'] = Planner::withColumns($pairs[$i]['after'], $columns);
                    }
                    continue;
                }
                $before = Planner::snapshotFromRow($row);
                $pairs[] = ['value_id' => $rid, 'before' => $before, 'after' => Planner::withColumns($before, $columns)];
                $added = true;
            }
            if ($added || $pairs !== ($entry['pairs'] ?? [])) {
                $plan[$id] = ['outcome' => 'changed', 'pairs' => $pairs] + $entry;
            }
        }
        return $plan;
    }
}
