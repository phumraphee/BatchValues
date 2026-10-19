<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Remove values chosen from the ones the resources actually have.
 */
class RemoveValues extends AbstractOperation
{
    public function name(): string
    {
        return 'remove';
    }

    public function label(): string
    {
        return 'Remove values'; // @translate
    }

    public function help(): string
    {
        return 'Take chosen values out of the field, or empty the field.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function effectiveOutcome(): string
    {
        return 'removed';
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true];
    }

    public function parse(array $post, array $ctx): array
    {
        // Values found by a check ("Links to check"): exactly these value ids
        // are removed, whatever they hold (0.9.0).
        $valueIds = array_values(array_unique(array_filter(array_map('intval', (array) ($post['value_ids'] ?? [])))));
        if ($valueIds) {
            return ['params' => [
                'position' => 'all',
                'position_count' => 1,
                'all' => false,
                'keys' => [],
                'match' => null,
                'snapshots' => [],
                'value_ids' => $valueIds,
                'display' => [['label' => 'Values to remove', 'text' => sprintf($this->translate('%d value(s) found by the check'), count($valueIds))]], // @translate
            ], 'errors' => []];
        }
        $picker = $this->parsePicker($post, $ctx);
        if ($picker['error']) {
            return ['params' => [], 'errors' => [$this->translate($picker['error'])]];
        }
        if (!empty($ctx['all_properties']) && $picker['all']) {
            return ['params' => [], 'errors' => [$this->translate('On all properties, give a rule for the values to remove: removing every value of every field is not offered.')]];
        }
        $position = (in_array($post['position'] ?? 'all', ['all', 'keep_first', 'remove_first', 'remove_last'], true) ? ($post['position'] ?? 'all') : 'all');
        $count = max(1, (int) ($post['position_count'] ?? 1));
        $positions = [
            'keep_first' => 'Keep the first %d, remove the others', // @translate
            'remove_first' => 'Only the first %d', // @translate
            'remove_last' => 'Only the last %d', // @translate
        ];
        $display = [$this->pickerDisplay($picker, 'Values to remove')]; // @translate
        if ($position !== 'all') {
            $display[] = ['label' => 'Which ones', 'text' => sprintf($this->translate($positions[$position]), $count)]; // @translate
        }
        return ['params' => [
            'position' => $position,
            'position_count' => $count,
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            // Kept for pages written by 0.x.
            'snapshots' => $picker['snapshots'],
            'display' => $display,
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $required = $this->planner->requiredTemplates($ctx['property_id']);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $templateId = $info[$id]['template_id'];
            $key = Planner::groupKey($templateId);
            $rows = $existing[$id] ?? [];
            $pairs = [];
            foreach ($rows as $row) {
                if ($this->picked($row, $params)) {
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => Planner::snapshotFromRow($row), 'after' => null];
                }
            }
            $n = (int) ($params['position_count'] ?? 1);
            switch ($params['position'] ?? 'all') {
                case 'keep_first':
                    $pairs = array_slice($pairs, $n);
                    break;
                case 'remove_first':
                    $pairs = array_slice($pairs, 0, $n);
                    break;
                case 'remove_last':
                    $pairs = array_slice($pairs, -$n);
                    break;
            }
            if (!$pairs) {
                $plan[$id] = self::entry($key, 'skipped_no_match');
            } elseif ($templateId && isset($required[$templateId]) && count($pairs) === count($rows)) {
                $plan[$id] = self::entry($key, 'skipped_required');
            } else {
                $plan[$id] = self::entry($key, 'removed', $pairs);
            }
        }
        return $plan;
    }
}
