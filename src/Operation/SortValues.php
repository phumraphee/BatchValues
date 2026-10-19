<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Put the values of a property in order, by what people see: the title of a
 * linked item, the label of a term, the text itself.
 *
 * Omeka keeps values in the order they were created, so the values are taken
 * off and put back in the new order (each with its visibility, language and
 * annotation). Cancelling puts them back in the old order.
 */
class SortValues extends AbstractOperation
{
    public function name(): string
    {
        return 'sort';
    }

    public function label(): string
    {
        return 'Sort the values'; // @translate
    }

    public function help(): string
    {
        return 'A → Z or Z → A by what is shown: the text, the label of a term, the title of a linked item.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        return [];
    }

    public function parse(array $post, array $ctx): array
    {
        $direction = ($post['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $langFirst = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($post['lang_first'] ?? ''))), 'strlen'));
        $display = [['label' => 'Order', 'text' => $direction === 'asc' ? 'A → Z' : 'Z → A']]; // @translate
        if ($langFirst) {
            $display[] = ['label' => 'Languages first', 'text' => implode(', ', $langFirst)]; // @translate
        }
        return ['params' => [
            'direction' => $direction,
            'lang_first' => $langFirst,
            'display' => $display,
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $desc = ($params['direction'] ?? 'asc') === 'desc';
        $collator = class_exists('Collator') ? new \Collator('th_TH') : null;
        if ($collator) {
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $rows = $existing[$id] ?? [];
            if (count($rows) < 2) {
                $plan[$id] = self::entry($key, $rows ? 'skipped_same' : 'skipped_no_match');
                continue;
            }
            $texts = [];
            foreach ($rows as $row) {
                $texts[$row['id']] = $this->planner->displayText($row);
            }
            $sorted = $rows;
            $langRank = array_flip(array_map('strtolower', $params['lang_first'] ?? []));
            usort($sorted, function ($a, $b) use ($texts, $collator, $desc, $langRank) {
                if ($langRank) {
                    // Chosen languages first, in the order given; the others after.
                    $ra = $langRank[strtolower((string) $a['lang'])] ?? count($langRank);
                    $rb = $langRank[strtolower((string) $b['lang'])] ?? count($langRank);
                    if ($ra !== $rb) {
                        return $ra <=> $rb;
                    }
                }
                $ta = $texts[$a['id']];
                $tb = $texts[$b['id']];
                $c = $collator ? $collator->compare($ta, $tb) : strnatcasecmp($ta, $tb);
                if ($desc) {
                    $c = -$c;
                }
                return $c ?: ((int) $a['id'] <=> (int) $b['id']);
            });
            if (array_column($sorted, 'id') === array_column($rows, 'id')) {
                $plan[$id] = self::entry($key, 'skipped_same');
                continue;
            }
            $pairs = [];
            foreach ($rows as $row) {
                $pairs[] = ['value_id' => (int) $row['id'], 'before' => Planner::snapshotFromRow($row), 'after' => null, 'reason' => 'sort'];
            }
            foreach ($sorted as $row) {
                $after = Planner::snapshotFromRow($row);
                unset($after['value_id']);
                $pairs[] = ['value_id' => null, 'before' => null, 'after' => $after, 'reason' => 'sort'];
            }
            $plan[$id] = self::entry($key, 'changed', $pairs, ['sorted' => true]);
        }
        return $plan;
    }
}
