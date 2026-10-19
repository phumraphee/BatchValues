<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

class ValueVisibility extends AbstractOperation
{
    public function name(): string
    {
        return 'visibility';
    }

    public function label(): string
    {
        return 'Make values public or private'; // @translate
    }

    public function help(): string
    {
        return 'Hide or show chosen values; the item keeps its own visibility.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true];
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $mode = (string) ($post['mode'] ?? '');
        if (!in_array($mode, ['switch', 'public', 'private'], true)) {
            $errors[] = $this->translate('Choose what to do with the visibility.');
        }
        $labels = [
            'switch' => 'Switched: public ones become private, private ones become public', // @translate
            'public' => 'Public', // @translate
            'private' => 'Private', // @translate
        ];
        return ['params' => [
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            'mode' => $mode,
            'display' => [
                $this->pickerDisplay($picker, 'Values'), // @translate
                ['label' => 'Becomes', 'text' => $labels[$mode] ?? ''], // @translate
            ],
        ], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        // 0.4.x change sets stored is_public; later ones store a mode.
        $mode = $params['mode'] ?? (!empty($params['is_public']) ? 'public' : 'private');
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $pairs = [];
            $matched = false;
            foreach ($existing[$id] ?? [] as $row) {
                if (!$this->picked($row, $params)) {
                    continue;
                }
                $matched = true;
                $isPublic = $mode === 'switch' ? !$row['is_public'] : $mode === 'public';
                if ((bool) $row['is_public'] === $isPublic) {
                    continue;
                }
                $before = Planner::snapshotFromRow($row);
                $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => Planner::withColumns($before, ['is_public' => $isPublic])];
            }
            $plan[$id] = self::entry($key, $pairs ? 'changed' : ($matched ? 'skipped_same' : 'skipped_no_match'), $pairs);
        }
        return $plan;
    }
}
