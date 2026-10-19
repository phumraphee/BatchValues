<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Add a value. Inputs follow each template's data types, grouped by template;
 * a resource that already has the value is skipped.
 */
class AddValues extends AbstractOperation
{
    protected $prepared = [];

    public function name(): string
    {
        return 'add';
    }

    public function label(): string
    {
        return 'Add values'; // @translate
    }

    public function help(): string
    {
        return 'Add new values to the field. An item that already has the value is skipped.'; // @translate
    }

    public function effectiveOutcome(): string
    {
        return 'added';
    }

    public function formVars(array $ctx): array
    {
        return [
            'groups' => $this->planner->templateGroups($ctx['ids'], $ctx['property_id']),
            // Omeka's general link type ("Omeka resource") is left out of the
            // choices: Item, Item set and Media say what the link points to,
            // and mixing the two makes the same link count as two values
            // (0.8.2). A template that asks for it is still honoured.
            'dataTypeNames' => array_values(array_diff(
                $this->services->get('Omeka\DataTypeManager')->getRegisteredNames(),
                ['resource']
            )),
        ];
    }

    /**
     * Shared with ReplaceValues: read the per-template inputs.
     */
    public function parseGroups(array $post, array $ctx, bool $single = false): array
    {
        $groups = $this->planner->templateGroups($ctx['ids'], $ctx['property_id']);
        $input = json_decode($post['groups_json'] ?? '{}', true) ?: [];
        $params = ['groups' => [], 'display' => []];
        $errors = [];
        $any = false;
        foreach ($groups as $key => $group) {
            $in = $input[$key] ?? ['skip' => true];
            // Allowed data types come from the template, never from the browser.
            $allowed = ($group['has_property'] && $group['data_types']) ? $group['data_types'] : [];
            $isPublic = array_key_exists('is_public', $in) ? !empty($in['is_public']) : !$group['is_private'];
            $skip = !empty($in['skip']);
            $prepared = $skip
                ? ['values' => [], 'errors' => []]
                : $this->planner->prepareNewValues($in['values'] ?? [], $ctx['property_id'], $isPublic, $allowed);
            if ($single && count($prepared['values']) > 1) {
                $prepared['values'] = array_slice($prepared['values'], 0, 1);
            }
            foreach ($prepared['errors'] as $error) {
                $errors[] = ($group['template_label'] ?: $this->translate('No template')) . ': ' . $error;
            }
            $skip = $skip || !$prepared['values'];
            $any = $any || !$skip;
            $params['groups'][$key] = [
                'skip' => $skip,
                'is_public' => $isPublic,
                'allowed_types' => $allowed,
                'template_label' => $group['template_label'],
                'values' => array_column($prepared['values'], 'object'),
                'snapshots' => array_column($prepared['values'], 'snapshot'),
                'keys' => array_column($prepared['values'], 'key'),
            ];
            if (!$skip) {
                $params['display'][] = [
                    'label' => $group['template_label'] ?? $this->translate('No template'),
                    'snapshots' => array_column($prepared['values'], 'snapshot'),
                ];
            }
        }
        if (!$any) {
            $errors[] = $this->translate('No value entered: fill in at least one group.');
        }
        // Entered once for all templates: one line, not one per template.
        if (count($params['display']) > 1 && count(array_unique(array_map(function ($line) {
            return json_encode($line['snapshots']);
        }, $params['display']))) === 1 && count($params['display']) === count($groups)) {
            $params['display'] = [['label' => $this->translate('All templates'), 'snapshots' => $params['display'][0]['snapshots']]];
        }
        // Errors repeated for each template when entered once.
        $errors = array_values(array_unique($errors));
        return ['params' => $params, 'errors' => $errors];
    }

    public function parse(array $post, array $ctx): array
    {
        return $this->parseGroups($post, $ctx);
    }

    /**
     * Stored inputs, validated again (once per run).
     */
    protected function preparedGroups(array $params, array $ctx): array
    {
        $hash = md5(json_encode([$params['groups'] ?? [], $ctx['property_id']]));
        if (isset($this->prepared[$hash])) {
            return $this->prepared[$hash];
        }
        $result = [];
        foreach ($params['groups'] ?? [] as $key => $group) {
            $prepared = empty($group['skip'])
                ? $this->planner->prepareNewValues($group['values'] ?? [], $ctx['property_id'], !empty($group['is_public']), $group['allowed_types'] ?? [])['values']
                : [];
            $result[$key] = ['skip' => !empty($group['skip']) || !$prepared, 'values' => $prepared];
        }
        return $this->prepared[$hash] = $result;
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $groups = $this->preparedGroups($params, $ctx);
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $group = $groups[$key] ?? null;
            if (!$group || $group['skip']) {
                $plan[$id] = self::entry($key, 'skipped_group');
                continue;
            }
            $existingKeys = [];
            foreach ($existing[$id] ?? [] as $row) {
                $existingKeys[Planner::targetKey($row)] = true;
            }
            $toAdd = [];
            $present = [];
            foreach ($group['values'] as $prepared) {
                if (isset($existingKeys[$prepared['key']])) {
                    $present[] = $prepared['key'];
                } else {
                    $toAdd[] = $prepared;
                }
            }
            $pairs = array_map(function ($p) {
                return ['value_id' => null, 'before' => null, 'after' => $p['snapshot']];
            }, $toAdd);
            $plan[$id] = self::entry($key, $toAdd ? 'added' : 'skipped_duplicate', $pairs, [
                'objects' => array_column($toAdd, 'object'),
                'prepared' => $toAdd,
                'present' => $present,
            ]);
        }
        return $plan;
    }
}
