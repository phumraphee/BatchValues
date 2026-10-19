<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Operations on the resources themselves rather than on their values.
 */
abstract class AbstractResourceOperation extends AbstractOperation
{
    public function group(): string
    {
        return 'resource';
    }

    /**
     * Compare a scalar field and make the entry.
     *
     * @param callable $current fn(array $info): mixed
     */
    protected function planScalar(array $ids, string $field, $after, callable $current): array
    {
        $info = $this->planner->resourceInfo($ids);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $before = $current($info[$id]);
            if ($before === $after) {
                $plan[$id] = self::entry($key, 'skipped_same');
                continue;
            }
            $plan[$id] = ['group' => $key, 'outcome' => 'changed', 'resource' => [$field => ['before' => $before, 'after' => $after]]];
        }
        return $plan;
    }

    protected function planLinks(array $ids, string $field, string $table, string $column, array $add, array $remove): array
    {
        $info = $this->planner->resourceInfo($ids);
        $links = $this->planner->links($ids, $table, $column);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $current = $links[$id] ?? [];
            $added = array_values(array_diff($add, $current));
            $removed = array_values(array_intersect($remove, $current));
            if (!$added && !$removed) {
                $plan[$id] = self::entry($key, 'skipped_same');
                continue;
            }
            $plan[$id] = ['group' => $key, 'outcome' => 'changed', 'resource' => [$field => ['added' => $added, 'removed' => $removed]]];
        }
        return $plan;
    }

    protected function idList($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $value))));
    }

    protected function labels(string $sql, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = $this->services->get('Omeka\Connection')->fetchAllAssociative($sql, [$ids], [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ($row['label'] !== null && $row['label'] !== '' ? $row['label'] : '#' . $row['id']);
        }
        return $out;
    }
}
