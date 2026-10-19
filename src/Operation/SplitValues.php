<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Split one value into several at a separator: "Bangkok; Chiang Mai" becomes
 * "Bangkok" and "Chiang Mai", in the same place in the list.
 *
 * The first part stays in the original value (same id, visibility, language,
 * annotation); the other parts are new values with the same visibility and
 * language. Omeka lists values in the order they were created, so the values
 * after the split one are taken off and put back after the new parts (pairs
 * with the reason "order": same content, nothing changed).
 */
class SplitValues extends AbstractTextOperation
{
    const SEPARATORS = [
        'semicolon' => ';',
        'comma' => ',',
        'pipe' => '|',
        'slash' => '/',
        'newline' => "\n",
    ];

    public function name(): string
    {
        return 'split';
    }

    public function label(): string
    {
        return 'Split into several values'; // @translate
    }

    public function help(): string
    {
        return 'One value that holds a list becomes one value per item of the list.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true, 'pickerAllDefault' => true];
    }

    protected function usesPicker(): bool
    {
        return true;
    }

    protected function pickerDefault(): string
    {
        return 'all';
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $choice = (string) ($post['separator'] ?? 'semicolon');
        $separator = $choice === 'other'
            ? strtr((string) ($post['separator_other'] ?? ''), ['\\t' => "\t", '\\n' => "\n", '\\r' => "\r"])
            : (self::SEPARATORS[$choice] ?? ';');
        $maxParts = max(0, (int) ($post['max_parts'] ?? 0));
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        if ($separator === '' || trim($separator) === '' && $choice !== 'newline') {
            $errors[] = $this->translate('Type the separator (the character between the parts).');
        }
        $display = [$this->pickerDisplay($picker, 'Values'), ['label' => 'Split at', 'code' => $separator === "\n" ? '↵' : ($separator === "\t" ? '⇥' : $separator)]]; // @translate
        if ($maxParts) {
            $display[] = ['label' => 'At most', 'text' => sprintf($this->translate('%d parts (the rest stays in the last part)'), $maxParts)]; // @translate
        }
        return ['params' => self::pickerParams($picker) + [
            'separator' => $separator,
            'max_parts' => $maxParts,
            'display' => $display,
        ], 'errors' => $errors];
    }

    /**
     * Parts of a text: trimmed, without empty or repeated parts.
     */
    public static function parts(string $text, string $separator, int $maxParts = 0): array
    {
        $limit = $maxParts > 0 ? $maxParts : -1;
        if ($separator === "\n") {
            $pieces = preg_split('/\r\n|\r|\n/u', $text, $limit);
        } else {
            $pieces = $maxParts > 0 ? explode($separator, $text, $maxParts) : explode($separator, $text);
        }
        $parts = [];
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && !in_array($piece, $parts, true)) {
                $parts[] = $piece;
            }
        }
        return $parts;
    }

    protected function editRow(array $row, array $params): ?array
    {
        // Only plain text is split; see planRows().
        return null;
    }

    protected static function splittable(array $row): bool
    {
        return Planner::isPlainText($row);
    }

    protected function planRows(string $key, array $rows, array $params): array
    {
        $separator = (string) $params['separator'];
        $replacements = [];
        $notes = [];
        $concerned = false;
        // What the resource has already, so a part that is there is not added.
        $present = [];
        foreach ($rows as $row) {
            $present[Planner::pickKey($row)] = true;
        }
        foreach ($rows as $row) {
            if (!$this->picked($row, $params) || !self::splittable($row) || $row['value'] === null) {
                // Links (with a URI) and non-text data types are not split.
                continue;
            }
            $text = (string) $row['value'];
            if ($separator === "\n" ? !preg_match('/[\r\n]/', $text) : strpos($text, $separator) === false) {
                continue;
            }
            $concerned = true;
            $parts = self::parts($text, $separator, (int) ($params['max_parts'] ?? 0));
            if (count($parts) < 1) {
                $notes[] = sprintf($this->translate('“%s” has nothing but separators: left as it is.'), self::short($row));
                continue;
            }
            $before = Planner::snapshotFromRow($row);
            $snapshots = [];
            $ok = true;
            foreach ($parts as $i => $part) {
                $candidate = Planner::withColumns($before, ['value' => $part]);
                if ($i > 0) {
                    unset($candidate['annotation']);
                }
                $checked = $this->planner->validateSnapshot($candidate);
                if ($checked['snapshot'] === null) {
                    $notes[] = sprintf($this->translate('“%1$s”: the part “%2$s” is not accepted by its data type, so the value was left as it is.'), self::short($row), $part);
                    $ok = false;
                    break;
                }
                $snap = $checked['snapshot'];
                if ($i > 0 && isset($present[Planner::keyFromSnapshot($snap, true) . '|' . (int) !empty($snap['is_public'])])) {
                    continue;
                }
                $present[Planner::keyFromSnapshot($snap, true) . '|' . (int) !empty($snap['is_public'])] = true;
                $snapshots[] = $snap;
            }
            if (!$ok) {
                continue;
            }
            $replacements[(int) $row['id']] = $snapshots;
        }
        if (!$replacements) {
            $message = $notes ? implode(' ', $notes) : null;
            return self::entry($key, $concerned ? ($notes ? 'skipped_invalid' : 'skipped_same') : 'skipped_no_match', [], ['message' => $message]);
        }
        $pairs = $this->rebuild($rows, $replacements);
        return self::entry($key, 'changed', $pairs, ['message' => $notes ? implode(' ', $notes) : null]);
    }

    /**
     * Pairs that put each replacement list where its value was.
     *
     * @param array $replacements value id => snapshots (the first one keeps
     *   the value's own id and annotation)
     */
    protected function rebuild(array $rows, array $replacements): array
    {
        $pairs = [];
        $on = [];
        $started = false;
        foreach ($rows as $row) {
            $rid = (int) $row['id'];
            $list = $replacements[$rid] ?? null;
            if (!$started && $list === null) {
                continue;
            }
            $before = Planner::snapshotFromRow($row);
            if (!$started) {
                // The first split value is edited in place; its other parts and
                // every later value follow it.
                $started = true;
                $first = array_shift($list);
                $first['value_id'] = $rid;
                $pairs[] = ['value_id' => $rid, 'before' => $before, 'after' => self::bare($first)];
                foreach ($list as $snapshot) {
                    $on[] = ['value_id' => null, 'before' => null, 'after' => self::bare($snapshot)];
                }
                continue;
            }
            if ($list === null) {
                $pairs[] = ['value_id' => $rid, 'before' => $before, 'after' => null, 'reason' => 'order'];
                $on[] = ['value_id' => null, 'before' => null, 'after' => self::bare($before), 'reason' => 'order'];
                continue;
            }
            // A later split value: taken off, its parts put in its place.
            $pairs[] = ['value_id' => $rid, 'before' => $before, 'after' => null];
            foreach ($list as $snapshot) {
                $on[] = ['value_id' => null, 'before' => null, 'after' => self::bare($snapshot)];
            }
        }
        return array_merge($pairs, $on);
    }

    protected static function bare(array $snapshot): array
    {
        unset($snapshot['value_id']);
        return $snapshot;
    }
}
