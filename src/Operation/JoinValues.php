<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Join chosen values of a resource into one: "Bangkok" and "Chiang Mai"
 * become "Bangkok; Chiang Mai". The first value keeps its place, visibility,
 * language and annotation; the others are removed.
 */
class JoinValues extends AbstractTextOperation
{
    public function name(): string
    {
        return 'join';
    }

    public function label(): string
    {
        return 'Join values into one'; // @translate
    }

    public function help(): string
    {
        return 'Several values of the same item become one value with a separator between them.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true];
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
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $choice = (string) ($post['separator'] ?? 'semicolon');
        $map = ['semicolon' => '; ', 'comma' => ', ', 'pipe' => ' | ', 'slash' => ' / ', 'newline' => "\n", 'space' => ' '];
        $separator = $choice === 'other' ? (string) ($post['separator_other'] ?? '') : ($map[$choice] ?? '; ');
        $mode = ($post['join_mode'] ?? 'text') === 'link' ? 'link' : 'text';
        if ($separator === '' && $mode === 'text') {
            $errors[] = $this->translate('Type the separator to put between the values.');
        }
        return ['params' => [
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            'separator' => $separator,
            'join_mode' => $mode,
            'display' => $mode === 'link' ? [
                $this->pickerDisplay($picker, 'Values'), // @translate
                ['label' => 'Join', 'text' => 'Each link gets the text that follows it as its label'], // @translate
            ] : [
                $this->pickerDisplay($picker, 'Values'), // @translate
                ['label' => 'Separator', 'code' => $separator === "\n" ? '↵' : $separator], // @translate
            ],
        ], 'errors' => $errors];
    }

    protected function editRow(array $row, array $params): ?array
    {
        return null;
    }

    protected function planRows(string $key, array $rows, array $params): array
    {
        if (($params['join_mode'] ?? 'text') === 'link') {
            return $this->planLinks($key, $rows, $params);
        }
        $chosen = [];
        $notes = [];
        foreach ($rows as $row) {
            if (!$this->picked($row, $params)) {
                continue;
            }
            if (!Planner::isPlainText($row) || strpos((string) $row['type'], 'customvocab:') === 0) {
                $notes[] = sprintf($this->translate('“%s” left as it is: only plain text values are joined.'), self::short($row));
                continue;
            }
            $chosen[] = $row;
        }
        if (!$chosen) {
            return self::entry($key, $notes ? 'skipped_type' : 'skipped_no_match', [], ['message' => $notes ? implode(' ', $notes) : null]);
        }
        // Values are joined per data type, language and visibility: a Thai
        // value is not glued to an English one, a private one not to a
        // public one.
        $sets = [];
        foreach ($chosen as $row) {
            $sets[$row['type'] . '|' . (string) $row['lang'] . '|' . (int) $row['is_public']][] = $row;
        }
        $pairs = [];
        foreach ($sets as $set) {
            if (count($set) < 2) {
                continue;
            }
            $annotated = array_values(array_filter($set, function ($r) {
                return !empty($r['value_annotation_id']);
            }));
            if (count($annotated) > 1) {
                $notes[] = sprintf($this->translate('Several of the values carry an annotation: “%s” and the others were left as they are.'), self::short($set[0]));
                continue;
            }
            // The value that stays: the annotated one if any, else the first.
            $keeper = $annotated[0] ?? $set[0];
            $text = implode($params['separator'], array_map(function ($r) {
                return (string) $r['value'];
            }, $set));
            $before = Planner::snapshotFromRow($keeper);
            $checked = $this->planner->validateSnapshot(Planner::withColumns($before, ['value' => $text]));
            if ($checked['snapshot'] === null) {
                $notes[] = sprintf($this->translate('The joined text is not accepted by its data type: “%s” and the others were left as they are.'), self::short($set[0]));
                continue;
            }
            $pairs[] = ['value_id' => (int) $keeper['id'], 'before' => $before, 'after' => $checked['snapshot']];
            foreach ($set as $row) {
                if ((int) $row['id'] !== (int) $keeper['id']) {
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => Planner::snapshotFromRow($row), 'after' => null, 'reason' => 'joined'];
                }
            }
        }
        $message = $notes ? implode(' ', array_unique($notes)) : null;
        if (!$pairs) {
            return self::entry($key, 'skipped_same', [], ['message' => $message ?? $this->translate('Fewer than two of the chosen values: nothing to join.')]);
        }
        return self::entry($key, 'changed', $pairs, ['message' => $message]);
    }

    /**
     * A link (URI) and a text entered as two values become one link with the
     * text as its label: links and texts are paired in their order.
     */
    protected function planLinks(string $key, array $rows, array $params): array
    {
        $links = [];
        $texts = [];
        foreach ($rows as $row) {
            if (!$this->picked($row, $params)) {
                continue;
            }
            if (Planner::hasLink($row)) {
                $links[] = $row;
            } elseif (Planner::isPlainText($row) && trim((string) $row['value']) !== '') {
                $texts[] = $row;
            }
        }
        if (!$links || !$texts) {
            return self::entry($key, 'skipped_no_match', [], ['message' => $this->translate('A link and a text are needed.')]);
        }
        $pairs = [];
        $notes = [];
        foreach ($links as $i => $link) {
            $text = $texts[$i] ?? null;
            if (!$text) {
                break;
            }
            if (!empty($text['value_annotation_id'])) {
                $notes[] = sprintf($this->translate('“%s” carries an annotation: left as it is.'), self::short($text));
                continue;
            }
            $before = Planner::snapshotFromRow($link);
            $columns = ['value' => trim((string) $text['value'])];
            if (!$link['lang'] && $text['lang']) {
                $columns['lang'] = $text['lang'];
            }
            $checked = $this->planner->validateSnapshot(Planner::withColumns($before, $columns));
            if ($checked['snapshot'] === null) {
                $notes[] = sprintf($this->translate('“%s” is not accepted as a label.'), self::short($text));
                continue;
            }
            $pairs[] = ['value_id' => (int) $link['id'], 'before' => $before, 'after' => $checked['snapshot']];
            $pairs[] = ['value_id' => (int) $text['id'], 'before' => Planner::snapshotFromRow($text), 'after' => null, 'reason' => 'joined'];
        }
        if (count($links) !== count($texts)) {
            $notes[] = sprintf($this->translate('%1$d link(s) and %2$d text(s): only the first pairs were joined.'), count($links), count($texts));
        }
        $message = $notes ? implode(' ', $notes) : null;
        return $pairs ? self::entry($key, 'changed', $pairs, ['message' => $message]) : self::entry($key, 'skipped_same', [], ['message' => $message]);
    }
}
