<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Replace chosen values by one value, typed like the template wants.
 *
 * Each replaced value is modified in place: it keeps its position, its
 * visibility and its annotation. Where the resource already has the new
 * value, the old one is removed instead (so no duplicate is created); when
 * only the old one carries an annotation, the annotated one is kept.
 */
class ReplaceValues extends AbstractOperation
{
    public function name(): string
    {
        return 'replace';
    }

    public function label(): string
    {
        return 'Replace whole values'; // @translate
    }

    public function help(): string
    {
        return 'Swap chosen values for one new value, typed as the template wants.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        return (new AddValues($this->services, $this->planner))->formVars($ctx) + ['picker' => true];
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $groups = (new AddValues($this->services, $this->planner))->parseGroups($post, $ctx, true);
        $errors = array_merge($errors, $groups['errors']);
        $display = [$this->pickerDisplay($picker, 'Values to replace')]; // @translate
        foreach ($groups['params']['display'] as $line) {
            $display[] = ['label' => sprintf($this->translate('New value (%s)'), $line['label']), 'snapshots' => $line['snapshots']];
        }
        return ['params' => [
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            'groups' => $groups['params']['groups'],
            'display' => $display,
        ], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $targets = [];
        foreach ($params['groups'] ?? [] as $key => $group) {
            if (empty($group['skip']) && !empty($group['snapshots'][0])) {
                $targets[$key] = $group['snapshots'][0];
            }
        }
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $rows = $existing[$id] ?? [];
            $matching = array_values(array_filter($rows, function ($row) use ($params) {
                return $this->picked($row, $params);
            }));
            // Annotated values first, so that the one that becomes the new
            // value is one carrying an annotation whenever there is one.
            usort($matching, function ($a, $b) {
                return (int) !empty($b['value_annotation_id']) <=> (int) !empty($a['value_annotation_id']) ?: (int) $a['id'] <=> (int) $b['id'];
            });
            if (!$matching) {
                $plan[$id] = self::entry($key, 'skipped_no_match');
                continue;
            }
            if (!isset($targets[$key])) {
                $plan[$id] = self::entry($key, 'skipped_group');
                continue;
            }
            $target = $targets[$key];
            $targetExact = Planner::keyFromSnapshot($target, true);
            $targetLoose = Planner::keyFromSnapshot($target, false);
            $pairs = [];
            $notes = [];
            $gone = [];
            // A value that is the new value already, before anything changes.
            $holder = null;
            foreach ($rows as $row) {
                if (!$this->picked($row, $params) && Planner::rowKey($row, false) === $targetLoose) {
                    $holder = $row;
                    break;
                }
            }
            foreach ($matching as $row) {
                if (Planner::rowKey($row, true) === $targetExact) {
                    continue;
                }
                $before = Planner::snapshotFromRow($row);
                $after = Planner::withColumns($before, [
                    'type' => $target['type'],
                    'value' => $target['value'],
                    'uri' => $target['uri'],
                    'value_resource_id' => $target['value_resource_id'],
                    'lang' => $target['lang'],
                ]);
                if ($holder === null) {
                    // The first replaced value becomes the new value; any other
                    // ticked value would now be a duplicate of it.
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => $after];
                    $holder = $row + ['_became_target' => true];
                    continue;
                }
                $holderAnnotated = !empty($holder['value_annotation_id']) && !isset($gone[$holder['id']]);
                $rowAnnotated = !empty($row['value_annotation_id']);
                if ($rowAnnotated && $holderAnnotated) {
                    $notes[] = sprintf($this->translate('Value #%d was left as it is: it and the value it would merge with both carry an annotation.'), $row['id']);
                    continue;
                }
                if ($rowAnnotated && empty($holder['_became_target'])) {
                    // Keep the annotated value: it becomes the new value and
                    // the plain copy goes.
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => $after];
                    $pairs[] = ['value_id' => (int) $holder['id'], 'before' => Planner::snapshotFromRow($holder), 'after' => null, 'reason' => 'merged'];
                    $gone[$holder['id']] = true;
                    $holder = $row + ['_became_target' => true];
                    continue;
                }
                $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => null, 'reason' => 'merged'];
            }
            if (!$pairs) {
                $plan[$id] = self::entry($key, $notes ? 'skipped_annotation' : 'skipped_same', [], ['message' => $notes ? implode(' ', $notes) : null]);
            } else {
                $plan[$id] = self::entry($key, 'changed', $pairs, ['message' => $notes ? implode(' ', $notes) : null]);
            }
        }
        return $plan;
    }
}
