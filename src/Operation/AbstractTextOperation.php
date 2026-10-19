<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Operations that edit the text of values in place.
 *
 * Only data types whose stored text is what people read are edited (see
 * Planner::isTextType()); linked resources and terms stored by id are left
 * alone. Every changed value is checked again by its data type before it is
 * planned, and a value that would become the same as another value of the
 * resource is removed instead of making a duplicate (unless it carries an
 * annotation).
 */
abstract class AbstractTextOperation extends AbstractOperation
{
    /**
     * New columns for one row: null when the row is not concerned, else
     * ['columns' => [...]], ['remove' => true], or ['note' => ?string]
     * (concerned, left as it is).
     */
    abstract protected function editRow(array $row, array $params): ?array;

    /** Whether the value picker limits the rows. */
    protected function usesPicker(): bool
    {
        return false;
    }

    /**
     * Which parts of a link to edit: "label", "uri" or "both".
     */
    protected static function linkPart(array $params): string
    {
        return in_array($params['link_part'] ?? 'label', ['label', 'uri', 'both'], true) ? $params['link_part'] : 'label';
    }

    /**
     * Apply a string function to the text columns of a row.
     *
     * @return array|null New columns, or null when nothing changes.
     */
    protected function mapText(array $row, array $params, callable $fn): ?array
    {
        $columns = [];
        if (Planner::hasLink($row)) {
            $part = self::linkPart($params);
            if (in_array($part, ['label', 'both'], true) && $row['value'] !== null) {
                $columns['value'] = $fn((string) $row['value']);
            }
            if (in_array($part, ['uri', 'both'], true) && $row['uri'] !== null) {
                $columns['uri'] = $fn((string) $row['uri']);
            }
        } elseif ($row['value'] !== null) {
            $columns['value'] = $fn((string) $row['value']);
        }
        foreach ($columns as $k => $v) {
            if ($v === null || $v === (string) $row[$k]) {
                unset($columns[$k]);
            }
        }
        return $columns ?: null;
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $plan[$id] = $this->planRows($key, $existing[$id] ?? [], $params);
        }
        return $plan;
    }

    /**
     * Plan the edits of the rows of one resource.
     */
    protected function planRows(string $key, array $rows, array $params): array
    {
        $edits = [];
        $notes = [];
        $concerned = false;
        $invalid = 0;
        $notText = 0;
        foreach ($rows as $row) {
            if ($this->usesPicker() && !$this->picked($row, $params)) {
                continue;
            }
            if (!Planner::isTextType($row['type'])) {
                // Counted only when the value was chosen on purpose.
                if ($this->usesPicker() && empty($params['all'])) {
                    ++$notText;
                }
                continue;
            }
            $result = $this->editRow($row, $params);
            if ($result === null) {
                continue;
            }
            $concerned = true;
            if (array_key_exists('note', $result)) {
                if ($result['note'] !== null) {
                    $notes[] = $result['note'];
                }
                continue;
            }
            $before = Planner::snapshotFromRow($row);
            if (!empty($result['remove'])) {
                $edits[(int) $row['id']] = ['row' => $row, 'before' => $before, 'after' => null];
                continue;
            }
            $after = Planner::withColumns($before, $result['columns']);
            $checked = $this->planner->validateSnapshot($after);
            if ($checked['snapshot'] === null) {
                ++$invalid;
                $notes[] = sprintf($this->translate('“%1$s” would become “%2$s”, which its data type does not accept.'), self::short($row), self::short($after));
                continue;
            }
            if (Planner::keyFromSnapshot($checked['snapshot'], true) === Planner::rowKey($row, true)) {
                continue;
            }
            $edits[(int) $row['id']] = ['row' => $row, 'before' => $before, 'after' => $checked['snapshot']];
        }
        if ($notText) {
            $notes[] = sprintf($this->translate('%d value(s) left as they are: not text (linked resource or term).'), $notText);
        }
        $pairs = $this->mergeDuplicates($rows, $edits, $notes);
        $message = $notes ? implode(' ', array_unique($notes)) : null;
        if ($pairs) {
            return self::entry($key, 'changed', $pairs, ['message' => $message]);
        }
        if ($invalid) {
            return self::entry($key, 'skipped_invalid', [], ['message' => $message]);
        }
        if ($notText && !$concerned) {
            return self::entry($key, 'skipped_type', [], ['message' => $message]);
        }
        return self::entry($key, $concerned ? 'skipped_same' : 'skipped_no_match', [], ['message' => $message]);
    }

    /**
     * Turn edits into pairs. An edited value that becomes the same as a value
     * the resource keeps (or as another edited value) is removed rather than
     * duplicated; an annotated one is kept and noted.
     */
    protected function mergeDuplicates(array $rows, array $edits, array &$notes): array
    {
        $taken = [];
        foreach ($rows as $row) {
            if (!isset($edits[(int) $row['id']])) {
                $taken[Planner::pickKey($row)] = true;
            }
        }
        $pairs = [];
        foreach ($edits as $valueId => $edit) {
            if ($edit['after'] === null) {
                $pairs[] = ['value_id' => $valueId, 'before' => $edit['before'], 'after' => null];
                continue;
            }
            // Same wording and same visibility: a duplicate. A public and a
            // private copy are different on purpose and both stay.
            $k = Planner::keyFromSnapshot($edit['after'], true) . '|' . (int) !empty($edit['after']['is_public']);
            if (isset($taken[$k])) {
                if (!empty($edit['row']['value_annotation_id'])) {
                    $notes[] = sprintf($this->translate('“%s” now appears twice: the copy with an annotation was kept.'), self::short($edit['after']));
                } else {
                    $pairs[] = ['value_id' => $valueId, 'before' => $edit['before'], 'after' => null, 'reason' => 'merged'];
                    continue;
                }
            }
            $taken[$k] = true;
            $pairs[] = ['value_id' => $valueId, 'before' => $edit['before'], 'after' => $edit['after']];
        }
        return $pairs;
    }

    protected static function short(array $s): string
    {
        $text = (string) ($s['value'] ?? '');
        if ($text === '') {
            $text = (string) ($s['uri'] ?? '');
        }
        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }
}
