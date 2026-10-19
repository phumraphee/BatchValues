<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Copy chosen values to another property of the same resource.
 */
class CopyValues extends AbstractOperation
{
    public function name(): string
    {
        return 'copy';
    }

    public function label(): string
    {
        return 'Copy to another property'; // @translate
    }

    public function help(): string
    {
        return 'The same values also in a second field. Skipped where that field has them already (the same linked item counts as the same value, whatever data type says so).'; // @translate
    }

    protected function isMove(): bool
    {
        return false;
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true, 'targetProperty' => true];
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $targetId = (int) ($post['target_property'] ?? 0);
        $target = $targetId ? $this->services->get('Omeka\EntityManager')->find(\Omeka\Entity\Property::class, $targetId) : null;
        if (!$target) {
            $errors[] = $this->translate('Choose the property to copy or move to.');
        } elseif ($targetId === (int) $ctx['property_id']) {
            $errors[] = $this->translate('The target property is the same as the source.');
        }
        $targetTerm = $target ? $target->getVocabulary()->getPrefix() . ':' . $target->getLocalName() : null;
        return ['params' => [
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            'target_property_id' => $targetId,
            'limit' => max(0, (int) ($post['limit'] ?? 0)),
            'target_term' => $targetTerm,
            'target_label' => $target ? $target->getLabel() : null,
            'display' => [
                $this->pickerDisplay($picker, $this->isMove() ? 'Values to move' : 'Values to copy'), // @translate
                ['label' => 'To', 'text' => $target ? $target->getLabel() . ' (' . $targetTerm . ')' : ''], // @translate
            ] + (!empty($post['limit']) ? [2 => ['label' => 'At most', 'text' => sprintf($this->translate('%d per item, the first ones'), (int) $post['limit'])]] : []), // @translate
        ], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $targetId = (int) $params['target_property_id'];
        $move = $this->isMove();
        $info = $this->planner->resourceInfo($ids);
        $source = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $targetRows = $this->planner->valuesByResource($ids, $targetId);
        $required = $move ? $this->planner->requiredTemplates($ctx['property_id']) : [];
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $templateId = $info[$id]['template_id'];
            $key = Planner::groupKey($templateId);
            $rows = $source[$id] ?? [];
            $picked = array_values(array_filter($rows, function ($row) use ($params) {
                return $this->picked($row, $params);
            }));
            if (!empty($params['limit'])) {
                $picked = array_slice($picked, 0, (int) $params['limit']);
            }
            if (!$picked) {
                $plan[$id] = self::entry($key, 'skipped_no_match');
                continue;
            }
            $tp = $this->planner->templateProperty($templateId, $targetId);
            $allowed = $tp['data_types'] ?? [];
            // What the target field holds already, and whether that copy
            // carries an annotation (0.7.3).
            $there = [];
            foreach ($targetRows[$id] ?? [] as $row) {
                $key = Planner::targetKey($row);
                $there[$key] = !empty($there[$key]) || !empty($row['value_annotation_id']);
            }
            $pairs = [];
            $notes = [];
            $wrongType = 0;
            $duplicates = 0;
            $annotated = 0;
            foreach ($picked as $row) {
                $before = Planner::snapshotFromRow($row);
                if ($allowed && !in_array($row['type'], $allowed, true)) {
                    ++$wrongType;
                    continue;
                }
                $loose = Planner::targetKey($row);
                if (array_key_exists($loose, $there)) {
                    // A value with an annotation is never dropped for being
                    // there already: the annotation would go with it. It is
                    // moved, and the target then holds it twice (0.7.3).
                    if ($move && !empty($row['value_annotation_id']) && empty($there[$loose])) {
                        ++$annotated;
                    } else {
                        ++$duplicates;
                        if ($move) {
                            // Already in the target: moving it means only
                            // taking it out of the source.
                            $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => null];
                        }
                        continue;
                    }
                }
                $there[$loose] = !empty($row['value_annotation_id']);
                $after = Planner::withColumns($before, ['property_id' => $targetId]);
                if ($move) {
                    $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => $after];
                } else {
                    unset($after['value_id']);
                    $pairs[] = ['value_id' => null, 'before' => null, 'after' => $after];
                }
            }
            if ($wrongType) {
                $notes[] = sprintf($this->translate('%d value(s) not taken: the template of the target property does not accept their data type.'), $wrongType);
            }
            if ($duplicates) {
                $notes[] = $move
                    ? sprintf($this->translate('%d value(s) already in the target property: taken out of this field, not added again.'), $duplicates)
                    : sprintf($this->translate('%d value(s) already in the target property.'), $duplicates);
            }
            if ($annotated) {
                $notes[] = sprintf($this->translate('%d value(s) with an annotation moved even though the target property has that value, so the annotation is not lost.'), $annotated);
            }
            $message = $notes ? implode(' ', $notes) : null;
            if ($move && $pairs && $templateId && isset($required[$templateId]) && count($pairs) === count($rows)) {
                $plan[$id] = self::entry($key, 'skipped_required', [], ['message' => $message]);
            } elseif ($pairs) {
                $plan[$id] = self::entry($key, 'changed', $pairs, ['message' => $message]);
            } else {
                $plan[$id] = self::entry($key, $wrongType ? 'skipped_type' : 'skipped_duplicate', [], ['message' => $message]);
            }
        }
        return $plan;
    }
}
