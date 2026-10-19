<?php
namespace BatchValues\Stdlib;

use Interop\Container\ContainerInterface;

/**
 * Applies a logged change set, resource by resource, through the Omeka API,
 * and records one change row per resource.
 *
 * The plan is recomputed at run time, chunk by chunk, from the current state
 * of the database, so a resource edited between preview and run is handled
 * correctly.
 */
class Processor
{
    const CHUNK = 100;

    protected $services;

    /**
     * @var Planner
     */
    protected $planner;

    /**
     * @var ChangeLog
     */
    protected $log;

    /** The run being done, sent with every request it makes (0.7.2). */
    protected $setId;

    protected $shouldStop;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
        $this->planner = $services->get(Planner::class);
        $this->log = $services->get(ChangeLog::class);
    }

    public function setShouldStop(?callable $shouldStop): self
    {
        $this->shouldStop = $shouldStop;
        return $this;
    }

    public function run(int $setId): void
    {
        $set = $this->log->getSet($setId);
        if (!$set || $set['status'] !== 'running') {
            return;
        }
        // Every request this run makes carries its number, so other modules
        // (the archive's history) can tie what they see to this run (0.7.2).
        $this->setId = $setId;
        $this->services->get(MediaTools::class)->setChangeSetId($setId);
        $this->planner->setResourceType($set['resource_type'] ?: 'items');
        try {
            $stopped = $set['operation'] === 'cancel' ? $this->runCancel($set) : $this->runOperation($set);
        } catch (\Throwable $e) {
            $this->services->get('Omeka\Logger')->err((string) $e);
            $this->log->finishSet($setId, 'failed', $e->getMessage());
            return;
        }
        $set = $this->log->getSet($setId);
        if ($stopped) {
            $this->log->finishSet($setId, 'stopped');
        } else {
            $this->log->finishSet($setId, $set['failed'] > 0 ? 'completed_with_errors' : 'completed');
        }
    }

    public function context(array $set, array $ids = []): array
    {
        return [
            'resource_type' => $this->planner->getResourceType(),
            'property_id' => $set['property_id'] ? (int) $set['property_id'] : null,
            'all_properties' => !empty($set['params']['all_properties']),
            'term' => $set['property_term'],
            'ids' => $ids,
        ];
    }

    protected function runOperation(array $set): bool
    {
        $operation = $this->services->get(Operations::class)->get($set['operation']);
        if (!$operation) {
            throw new \RuntimeException(sprintf('Unknown operation "%s".', $set['operation']));
        }
        if ($operation->needsProperty() && !$set['property_id'] && empty($set['params']['all_properties'])) {
            throw new \RuntimeException('The property of this change set no longer exists.');
        }
        $setId = (int) $set['id'];
        $ids = $set['selection']['ids'] ?? [];
        $ctx = $this->context($set, $ids);
        $fieldProperties = $this->fieldProperties($set);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if ($this->stopRequested()) {
                return true;
            }
            $info = $this->planner->resourceInfo($chunk);
            $plan = $operation->planAll($chunk, $set['params'], $ctx);
            $counts = [0, 0, 0, 0];
            $before = $this->fieldOrder($chunk, $fieldProperties);
            $touched = $changeIds = [];
            foreach ($plan as $id => $entry) {
                $changeId = $this->applyEntry($setId, $id, $entry, $info[$id] ?? null, $set['property_term'], $counts);
                $properties = $changeId ? $this->touchedProperties($entry) : [];
                if ($properties) {
                    $changeIds[$id] = $changeId;
                    $touched[$id] = $properties;
                }
            }
            $this->storeFieldOrder($before, $touched, $changeIds, $fieldProperties);
            $this->log->bump($setId, ...$counts);
            $this->recover();
        }
        return false;
    }

    /**
     * Write one planned entry and log it.
     */
    protected function applyEntry(int $setId, int $id, array $entry, ?array $info, ?string $term, array &$counts, ?int $reverses = null): ?int
    {
        $base = [
            'resource_id' => $id,
            'resource_title' => $info['title'] ?? null,
            'template_id' => $info['template_id'] ?? null,
            'reverses_change_id' => $reverses,
            'message' => $entry['message'] ?? null,
        ];
        $counts[0]++;
        $effective = in_array($entry['outcome'], ['added', 'removed', 'changed'], true);
        if (!$effective) {
            $this->log->addChange($setId, $base + ['outcome' => $entry['outcome']]);
            $counts[2]++;
            return null;
        }
        try {
            if (!empty($entry['custom'])) {
                // Work that is not a plain update of the resource (files,
                // new items or media): done by MediaTools, which returns what
                // to log.
                $entry['resource'] = $this->services->get(MediaTools::class)
                    ->apply($this->planner->getResourceType(), $id, (string) $entry['custom'], $entry['resource'] ?? []);
            } elseif (!empty($entry['objects'])) {
                $this->update($id, [$term => $entry['objects']], 'append');
            } elseif (!empty($entry['pairs'])) {
                $this->update($id, $this->pairsContent($entry['pairs']), 'append');
            } elseif (!empty($entry['resource'])) {
                $this->update($id, $this->resourceContent($id, $entry['resource']), 'replace');
            }
            $changeId = $this->log->addChange($setId, $base + [
                'outcome' => $entry['outcome'],
                'values' => $this->logData($entry),
            ]);
            $counts[1]++;
            return $changeId;
        } catch (\Throwable $e) {
            $this->recover();
            $this->log->addChange($setId, array_merge($base, ['outcome' => 'failed', 'message' => $this->errorMessage($e)]));
            $counts[3]++;
        }
        return null;
    }

    /**
     * The value ids of every field of these resources, in their order
     * (0.8.0). Read before anything is written, so the history page can show
     * the whole field as it was, without keeping a copy of every value.
     *
     * @return array [resource id => [property id => [value id, …]]]
     */
    protected function fieldOrder(array $ids, ?array $properties = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $order = [];
        foreach ($this->planner->valuesByResource($ids, $properties ?: null) as $id => $rows) {
            foreach ($rows as $row) {
                $order[(int) $id][(int) $row['property_id']][] = (int) $row['id'];
            }
        }
        return $order;
    }

    /**
     * The properties one planned entry changes values in.
     *
     * @return array [property id => true]
     */
    protected function touchedProperties(array $entry): array
    {
        $properties = [];
        foreach ($entry['pairs'] ?? [] as $pair) {
            foreach (['before', 'after'] as $side) {
                if (!empty($pair[$side]['property_id'])) {
                    $properties[(int) $pair[$side]['property_id']] = true;
                }
            }
        }
        return $properties;
    }

    /**
     * Read the touched fields again, now that the resources are saved, and
     * keep both orders with the change rows (0.8.0).
     *
     * @param array $before From fieldOrder(), before anything was written.
     * @param array $touched [resource id => [property id => true]]
     * @param array $changeIds [resource id => change id]
     */
    protected function storeFieldOrder(array $before, array $touched, array $changeIds, ?array $properties = null): void
    {
        if (!$changeIds) {
            return;
        }
        $after = $this->fieldOrder(array_keys($changeIds), $properties);
        $rows = [];
        foreach ($changeIds as $id => $changeId) {
            $data = [];
            foreach (array_keys($touched[$id] ?? []) as $propertyId) {
                $data[$propertyId] = [
                    'before' => $before[$id][$propertyId] ?? [],
                    'after' => $after[$id][$propertyId] ?? [],
                ];
            }
            if ($data) {
                $rows[$changeId] = $data;
            }
        }
        try {
            $this->log->setFieldDataBatch($rows);
        } catch (\Throwable $e) {
            // The history display is not worth failing a run for.
            $this->services->get('Omeka\Logger')->warn((string) $e);
        }
    }

    /**
     * The properties a run can touch, when they are known from the set: the
     * field itself and, for copy and move, the target. Null when the run can
     * touch any property ("All properties"), so every field is read.
     */
    protected function fieldProperties(array $set): ?array
    {
        if (!empty($set['params']['all_properties'])) {
            return null;
        }
        $properties = array_filter([
            (int) ($set['property_id'] ?? 0),
            (int) ($set['params']['target_property_id'] ?? 0),
        ]);
        return $properties ? array_values($properties) : null;
    }

    /**
     * What goes in value_data. Rows "added" and "removed" keep the list of
     * snapshots written since 0.1 (read by other modules); other rows carry
     * {"pairs": [...]} or {"resource": {...}}.
     */
    protected function logData(array $entry)
    {
        if (!empty($entry['resource'])) {
            return ['resource' => $entry['resource']];
        }
        $pairs = $entry['pairs'] ?? [];
        if ($entry['outcome'] === 'added') {
            return array_values(array_filter(array_column($pairs, 'after')));
        }
        if ($entry['outcome'] === 'removed') {
            return array_values(array_filter(array_column($pairs, 'before')));
        }
        return ['pairs' => $pairs];
    }

    protected function pairsContent(array $pairs): array
    {
        $content = ['batchvalues_remove' => [], 'batchvalues_modify' => [], 'batchvalues_restore' => []];
        foreach ($pairs as $pair) {
            if ($pair['before'] !== null && $pair['after'] === null) {
                $content['batchvalues_remove'][] = (int) $pair['value_id'];
            } elseif ($pair['before'] !== null && $pair['after'] !== null) {
                $content['batchvalues_modify'][] = ['value_id' => (int) $pair['value_id'], 'after' => $pair['after']];
            } elseif ($pair['after'] !== null) {
                $content['batchvalues_restore'][] = $pair['after'];
            }
        }
        return array_filter($content);
    }

    protected function resourceContent(int $id, array $changes): array
    {
        $content = [];
        foreach ($changes as $field => $change) {
            switch ($field) {
                case 'is_public':
                    $content['o:is_public'] = (bool) $change['after'];
                    break;
                case 'is_open':
                    $content['o:is_open'] = (bool) $change['after'];
                    break;
                case 'template':
                    $content['o:resource_template'] = $change['after'] ? ['o:id' => (int) $change['after']] : [];
                    break;
                case 'class':
                    $content['o:resource_class'] = $change['after'] ? ['o:id' => (int) $change['after']] : [];
                    break;
                case 'owner':
                    $content['o:owner'] = ['o:id' => $change['after'] ? (int) $change['after'] : null];
                    break;
                case 'thumbnail':
                    $content['o:thumbnail'] = $change['after'] ? ['o:id' => (int) $change['after']] : [];
                    break;
                case 'media_order':
                    $current = $this->mediaIds($id);
                    $after = array_map('intval', $change['after']);
                    $a = $current;
                    $b = $after;
                    sort($a);
                    sort($b);
                    if ($a !== $b) {
                        throw new \RuntimeException('The media of this item changed since the preview; run it again.'); // @translate
                    }
                    $content['o:media'] = array_map(function ($m) {
                        return ['o:id' => $m];
                    }, $after);
                    break;
                case 'media_visibility':
                    $content['o:media'] = array_map(function ($m) use ($change) {
                        $data = ['o:id' => $m];
                        if (isset($change['media'][$m])) {
                            $data['o:is_public'] = (bool) $change['media'][$m]['after'];
                        }
                        return $data;
                    }, $this->mediaIds($id));
                    break;
                case 'media_fields':
                    $fields = [];
                    foreach ($change['media'] ?? [] as $mediaId => $mediaFields) {
                        foreach ($mediaFields as $name => $pair) {
                            $fields[(int) $mediaId][$name] = $pair['after'];
                        }
                    }
                    $content['batchvalues_media_fields'] = $fields;
                    break;
                case 'item_sets':
                case 'sites':
                    [$table, $column, $key] = $field === 'item_sets'
                        ? ['item_item_set', 'item_set_id', 'o:item_set']
                        : ['item_site', 'site_id', 'o:site'];
                    $current = $this->planner->links([$id], $table, $column)[$id] ?? [];
                    $new = array_values(array_diff(array_unique(array_merge($current, $change['added'] ?? [])), $change['removed'] ?? []));
                    $content[$key] = array_map(function ($i) {
                        return ['o:id' => $i];
                    }, $new);
                    break;
            }
        }
        return $content;
    }

    /**
     * Ids of the media of an item, in order.
     */
    protected function mediaIds(int $itemId): array
    {
        return array_map('intval', $this->services->get('Omeka\Connection')->fetchFirstColumn(
            'SELECT id FROM media WHERE item_id = ? ORDER BY position, id',
            [$itemId]
        ));
    }

    // ------------------------------------------------------------------
    // Cancel
    // ------------------------------------------------------------------

    /**
     * Reverse effective changes of another set. Each reversal is a new change
     * pointing to the change it reverses; original rows are left untouched.
     */
    protected function runCancel(array $set): bool
    {
        $setId = (int) $set['id'];
        $originalId = (int) $set['reverses_id'];
        $original = $this->log->getSet($originalId);
        if ($original) {
            $this->planner->setResourceType($original['resource_type'] ?: 'items');
        }
        $changes = $this->log->getReversibleChanges($originalId, $set['params']['change_ids'] ?? null);
        foreach (array_chunk($changes, self::CHUNK) as $chunk) {
            if ($this->stopRequested()) {
                return true;
            }
            $resourceIds = array_values(array_unique(array_map('intval', array_column($chunk, 'resource_id'))));
            $info = $this->planner->resourceInfo($resourceIds);
            $counts = [0, 0, 0, 0];
            $before = $this->fieldOrder($resourceIds);
            $touched = $changeIds = [];
            foreach ($chunk as $change) {
                $id = (int) $change['resource_id'];
                $entry = isset($info[$id])
                    ? $this->reverseEntry($change, $id)
                    : ['outcome' => 'skipped_missing'];
                $rowInfo = $info[$id] ?? ['title' => $change['resource_title'], 'template_id' => $change['template_id']];
                $changeId = $this->applyEntry($setId, $id, $entry, $rowInfo, null, $counts, (int) $change['id']);
                $properties = $changeId ? $this->touchedProperties($entry) : [];
                if ($properties) {
                    $changeIds[$id] = $changeId;
                    $touched[$id] = $properties;
                }
            }
            $this->storeFieldOrder($before, $touched, $changeIds);
            $this->log->bump($setId, ...$counts);
            $this->recover();
        }
        return false;
    }

    /**
     * Pairs of an original change, whatever version wrote it.
     */
    public static function pairsOf(array $change): array
    {
        $data = $change['values'];
        if (isset($data['pairs'])) {
            return $data['pairs'];
        }
        if (isset($data['resource'])) {
            return [];
        }
        // 0.x rows: a list of snapshots.
        $pairs = [];
        foreach ((array) $data as $snapshot) {
            if ($change['outcome'] === 'added') {
                $pairs[] = ['value_id' => null, 'before' => null, 'after' => $snapshot];
            } elseif ($change['outcome'] === 'removed') {
                $pairs[] = ['value_id' => $snapshot['value_id'] ?? null, 'before' => $snapshot, 'after' => null];
            }
        }
        return $pairs;
    }

    /**
     * Plan the reversal of one change against the current state.
     */
    protected function reverseEntry(array $change, int $id): array
    {
        if (isset($change['values']['resource'])) {
            return $this->reverseResource($change['values']['resource'], $id);
        }
        $pairs = self::pairsOf($change);
        $propertyIds = [];
        foreach ($pairs as $pair) {
            foreach (['before', 'after'] as $side) {
                if ($pair[$side]) {
                    $propertyIds[(int) $pair[$side]['property_id']] = true;
                }
            }
        }
        $current = [];
        foreach (array_keys($propertyIds) as $propertyId) {
            foreach ($this->planner->valuesByResource([$id], $propertyId)[$id] ?? [] as $row) {
                $current[] = $row;
            }
        }
        $used = [];
        $find = function (array $snapshot, ?int $preferId) use (&$current, &$used) {
            $key = Planner::keyFromSnapshot($snapshot, true);
            $found = null;
            foreach ($current as $row) {
                if (isset($used[$row['id']]) || (int) $row['property_id'] !== (int) $snapshot['property_id']) {
                    continue;
                }
                if (Planner::rowKey($row, true) !== $key || (bool) $row['is_public'] !== (bool) ($snapshot['is_public'] ?? true)) {
                    continue;
                }
                if ($preferId && (int) $row['id'] === $preferId) {
                    $found = $row;
                    break;
                }
                $found = $found ?? $row;
            }
            if ($found) {
                $used[$found['id']] = true;
            }
            return $found;
        };
        // Undo what the change wrote before putting back what it removed:
        // a value removed and written again (sorting) must first be taken
        // off, else it would look "already there" and not come back.
        $pairs = array_merge(
            array_values(array_filter($pairs, function ($p) { return $p['after'] !== null; })),
            array_values(array_filter($pairs, function ($p) { return $p['after'] === null; }))
        );
        $reversed = [];
        $notes = [];
        foreach ($pairs as $pair) {
            $before = $pair['before'];
            $after = $pair['after'];
            if ($after !== null) {
                // What the change wrote must still be there to be undone.
                $row = $find($after, $pair['value_id'] ?? null);
                if (!$row) {
                    $notes[] = 'A value written by this change is no longer on the resource.'; // @translate
                    continue;
                }
                $reversed[] = [
                    'value_id' => (int) $row['id'],
                    'before' => Planner::snapshotFromRow($row),
                    'after' => $before === null ? null : Planner::withColumns(Planner::snapshotFromRow($row), $before),
                ];
                continue;
            }
            // The change removed a value: put it back unless it is there.
            // A value removed as a duplicate ("merged") had an identical copy
            // that stayed: that copy does not count.
            if (($pair['reason'] ?? null) === 'merged') {
                $find($before, null);
            }
            if ($find($before, null)) {
                $notes[] = 'A value removed by this change is there again.'; // @translate
                continue;
            }
            if (!empty($before['value_resource_id']) && !$this->resourceExists((int) $before['value_resource_id'])) {
                $notes[] = sprintf('Linked resource #%d no longer exists; value not restored.', $before['value_resource_id']); // @translate
                continue;
            }
            $restore = $before;
            unset($restore['value_id'], $restore['had_annotation']);
            $origId = (int) ($pair['value_id'] ?? $before['value_id'] ?? 0);
            $reversed[] = ['value_id' => null, 'before' => null, 'after' => $restore, '_orig_id' => $origId];
        }
        $reversed = $this->placeRestores($reversed, $current);
        $message = $notes ? implode(' ', array_unique($notes)) : null;
        if (!$reversed) {
            return ['outcome' => 'skipped_changed', 'message' => $message];
        }
        $hasBefore = (bool) array_filter(array_column($reversed, 'before'));
        $hasAfter = (bool) array_filter(array_column($reversed, 'after'));
        $outcome = $hasBefore && $hasAfter ? 'changed' : ($hasAfter ? 'added' : 'removed');
        $modifies = array_filter($reversed, function ($p) {
            return $p['before'] !== null && $p['after'] !== null;
        });
        if ($modifies) {
            $outcome = 'changed';
        }
        return ['outcome' => $outcome, 'pairs' => $reversed, 'message' => $message];
    }

    /**
     * Values put back by a cancel would come last (Omeka orders values by
     * creation). To put them back where they were, the values that came after
     * them are taken off and put back too (reason "order": same content).
     */
    protected function placeRestores(array $reversed, array $current): array
    {
        $restores = [];
        foreach ($reversed as $i => $pair) {
            if (!empty($pair['_orig_id'])) {
                $restores[(int) $pair['after']['property_id']][$i] = $pair;
            }
        }
        if (!$restores) {
            return array_map(function ($p) {
                unset($p['_orig_id']);
                return $p;
            }, $reversed);
        }
        // What the cancel takes off already, and what it changes in place.
        $touched = [];
        $modified = [];
        foreach ($reversed as $pair) {
            if (!empty($pair['value_id'])) {
                $touched[(int) $pair['value_id']] = true;
                if ($pair['before'] !== null && $pair['after'] !== null) {
                    $modified[(int) $pair['value_id']] = $pair;
                }
            }
        }
        $firstByProperty = [];
        foreach ($restores as $propertyId => $list) {
            $firstByProperty[$propertyId] = min(array_map(function ($p) {
                return (int) $p['_orig_id'];
            }, $list));
        }
        // A value changed back in place that comes after a value put back is
        // taken off and put back (changed) in its turn, so the order is kept.
        $movedModified = [];
        foreach ($modified as $rid => $pair) {
            $pid = (int) $pair['after']['property_id'];
            if (isset($firstByProperty[$pid]) && $rid > $firstByProperty[$pid]) {
                $movedModified[$rid] = $pair;
            }
        }
        $out = array_values(array_filter($reversed, function ($p) use ($movedModified) {
            return empty($p['_orig_id']) && !(!empty($p['value_id']) && isset($movedModified[(int) $p['value_id']]));
        }));
        $tail = [];
        foreach ($restores as $propertyId => $list) {
            $first = min(array_map(function ($p) {
                return (int) $p['_orig_id'];
            }, $list));
            $on = [];
            foreach ($list as $pair) {
                $on[] = ['key' => (int) $pair['_orig_id'], 'pair' => ['value_id' => null, 'before' => null, 'after' => $pair['after']]];
            }
            foreach ($movedModified as $rid => $pair) {
                if ((int) $pair['after']['property_id'] !== (int) $propertyId) {
                    continue;
                }
                $out[] = ['value_id' => $rid, 'before' => $pair['before'], 'after' => null];
                $after = $pair['after'];
                unset($after['value_id']);
                $on[] = ['key' => $rid, 'pair' => ['value_id' => null, 'before' => null, 'after' => $after]];
            }
            foreach ($current as $row) {
                $rid = (int) $row['id'];
                if ((int) $row['property_id'] !== (int) $propertyId || $rid < $first || isset($touched[$rid])) {
                    continue;
                }
                $snapshot = Planner::snapshotFromRow($row);
                $out[] = ['value_id' => $rid, 'before' => $snapshot, 'after' => null, 'reason' => 'order'];
                unset($snapshot['value_id']);
                $on[] = ['key' => $rid, 'pair' => ['value_id' => null, 'before' => null, 'after' => $snapshot, 'reason' => 'order']];
            }
            usort($on, function ($a, $b) {
                return $a['key'] <=> $b['key'];
            });
            foreach ($on as $item) {
                $tail[] = $item['pair'];
            }
        }
        return array_merge($out, $tail);
    }

    protected function reverseResource(array $changes, int $id): array
    {
        $info = $this->planner->resourceInfo([$id])[$id];
        $current = [
            'is_public' => (bool) $info['is_public'],
            'is_open' => $info['is_open'],
            'template' => $info['template_id'],
            'class' => $info['class_id'],
            'owner' => $info['owner_id'],
            'thumbnail' => $info['thumbnail_id'],
        ];
        $reversed = [];
        $notes = [];
        $custom = null;
        foreach ($changes as $field => $change) {
            if (strpos($field, 'media_') === 0 || in_array($field, ['split_item', 'unsplit_item', 'pdf_pages'], true)) {
                $result = $this->reverseMedia($field, $change, $id);
                if (isset($result['note'])) {
                    $notes[] = $result['note'];
                }
                if (!empty($result['irreversible'])) {
                    return ['outcome' => 'skipped_irreversible', 'message' => $result['note'] ?? null];
                }
                if (isset($result['change'])) {
                    $reversed[$result['field'] ?? $field] = $result['change'];
                    $custom = $result['custom'] ?? $custom;
                }
                continue;
            }
            if (in_array($field, ['item_sets', 'sites'], true)) {
                [$table, $column] = $field === 'item_sets' ? ['item_item_set', 'item_set_id'] : ['item_site', 'site_id'];
                $links = $this->planner->links([$id], $table, $column)[$id] ?? [];
                $removeAgain = array_values(array_intersect($change['added'] ?? [], $links));
                $addBack = array_values(array_diff($change['removed'] ?? [], $links));
                if ($removeAgain || $addBack) {
                    $reversed[$field] = ['added' => $addBack, 'removed' => $removeAgain];
                } else {
                    $notes[] = 'Already as before.'; // @translate
                }
                continue;
            }
            $isBool = in_array($field, ['is_public', 'is_open'], true);
            $now = $isBool ? (bool) $current[$field] : $current[$field];
            $after = $isBool ? (bool) $change['after'] : $change['after'];
            if ($now !== $after) {
                $notes[] = sprintf('The %s was changed since.', $field); // @translate
                continue;
            }
            $reversed[$field] = ['before' => $now, 'after' => $change['before']];
        }
        if (!$reversed) {
            return ['outcome' => 'skipped_changed', 'message' => $notes ? implode(' ', $notes) : null];
        }
        $entry = ['outcome' => 'changed', 'resource' => $reversed, 'message' => $notes ? implode(' ', $notes) : null];
        if ($custom) {
            $entry['custom'] = $custom;
        }
        return $entry;
    }

    /**
     * Reverse one media change against the current state.
     *
     * @return array ['change' => ?array, 'field' => ?string, 'custom' => ?string,
     *   'note' => ?string, 'irreversible' => ?bool]
     */
    protected function reverseMedia(string $field, array $change, int $id): array
    {
        $isMedia = $this->planner->getResourceType() === 'media';
        switch ($field) {
            case 'media_order':
                if ($this->mediaIds($id) !== array_map('intval', $change['after'])) {
                    return ['note' => 'The order of the media was changed since.']; // @translate
                }
                $before = array_map('intval', $change['before']);
                $a = $before;
                $b = $this->mediaIds($id);
                sort($a);
                sort($b);
                if ($a !== $b) {
                    return ['note' => 'Media were added or removed since.']; // @translate
                }
                return ['change' => ['before' => $change['after'], 'after' => $before]];
            case 'media_visibility':
            case 'media_fields':
                $rows = [];
                foreach ($this->planner->mediaOf([$id], $isMedia)[$id] ?? [] as $row) {
                    $rows[$row['id']] = $row;
                }
                $out = [];
                $changedSince = false;
                foreach ($change['media'] ?? [] as $mediaId => $pairOrFields) {
                    $row = $rows[(int) $mediaId] ?? null;
                    if (!$row) {
                        $changedSince = true;
                        continue;
                    }
                    if ($field === 'media_visibility') {
                        if ((bool) $row['is_public'] !== (bool) $pairOrFields['after']) {
                            $changedSince = true;
                            continue;
                        }
                        $out[(int) $mediaId] = ['before' => $pairOrFields['after'], 'after' => $pairOrFields['before']];
                        continue;
                    }
                    foreach ($pairOrFields as $name => $pair) {
                        if ((string) ($row[$name] ?? '') !== (string) $pair['after']) {
                            $changedSince = true;
                            continue;
                        }
                        $out[(int) $mediaId][$name] = ['before' => $pair['after'], 'after' => $pair['before']];
                    }
                }
                $note = $changedSince ? 'Some media were changed or removed since.' : null; // @translate
                return $out ? ['change' => ['media' => $out], 'note' => $note] : ['note' => $note ?? 'Already as before.']; // @translate
            case 'pdf_pages':
                // The page images made from a PDF: deleted again.
                $existing = [];
                $createdIds = $change['created'] ?? [];
                foreach ($change['pdfs'] ?? [] as $pdfData) {
                    $createdIds = array_merge($createdIds, $pdfData['created'] ?? []);
                }
                foreach ($this->planner->mediaOf(array_map('intval', $createdIds), true) as $mediaId => $list) {
                    $existing[$mediaId] = Planner::mediaSnapshot($list[0]);
                }
                if (!$existing) {
                    return ['note' => 'The page images are no longer there.']; // @translate
                }
                $itemId = (int) ($change['item_id'] ?? $id);
                return ['field' => 'media_removed', 'custom' => 'remove_media', 'change' => ['media' => $existing, 'item_id' => $itemId]];
            case 'split_item':
                return ['custom' => 'unsplit_item', 'change' => $change];
            case 'media_removed':
                // Put back when the files were kept aside (0.7.2).
                $kept = [];
                foreach ($change['media'] ?? [] as $mediaId => $snapshot) {
                    $file = (string) ($snapshot['kept_file'] ?? '');
                    if ($file !== '' && is_file(MediaTools::keptRoot() . '/' . basename($file))) {
                        $kept[(int) $mediaId] = $snapshot;
                    }
                }
                if (!$kept) {
                    return ['irreversible' => true, 'note' => 'The files of these media were not kept, so they cannot be brought back.']; // @translate
                }
                return [
                    'custom' => 'restore_media',
                    'field' => 'media_restored',
                    'change' => ['media' => $kept, 'item_id' => (int) ($change['item_id'] ?? $id)],
                ];
            case 'unsplit_item':
                return ['irreversible' => true, 'note' => 'To split the item again, run the split again.']; // @translate
            case 'media_thumbnails':
                return ['irreversible' => true, 'note' => 'Nothing to cancel: the thumbnails were only made again from the files.']; // @translate
            case 'media_rotate':
                // Turned back by what is left of a full turn (0.7.0).
                $turned = $change['turned'] ?? [];
                if (!$turned) {
                    return ['irreversible' => true, 'note' => 'No image was turned.']; // @translate
                }
                $back = (360 - (int) ($change['degrees'] ?? 0)) % 360;
                if (!in_array($back, [90, 180, 270], true)) {
                    return ['irreversible' => true, 'note' => 'This turn cannot be turned back.']; // @translate
                }
                $media = [];
                foreach ($turned as $mediaId => $info) {
                    $media[(int) $mediaId] = ($change['media'][$mediaId] ?? [])
                        + ['sha256' => $info['sha256']['after'] ?? null, 'size' => $info['size']['after'] ?? null];
                }
                return ['custom' => 'rotate_media', 'change' => ['degrees' => $back, 'media' => $media]];
        }
        return ['note' => null];
    }

    // ------------------------------------------------------------------

    protected function update(int $id, array $content, string $collectionAction): void
    {
        $this->services->get('Omeka\ApiManager')->update($this->planner->getResourceType(), $id, $content, [], [
            'isPartial' => true,
            'collectionAction' => $collectionAction,
            'batchvalues' => true,
            'batchvalues_change_set' => $this->setId,
            'responseContent' => 'resource',
            'finalize' => true,
        ]);
    }

    protected function resourceExists(int $id): bool
    {
        return (bool) $this->services->get('Omeka\Connection')->fetchOne('SELECT 1 FROM resource WHERE id = ?', [$id]);
    }

    protected function recover(): void
    {
        $em = $this->services->get('Omeka\EntityManager');
        if ($em->isOpen()) {
            $em->clear();
        }
    }

    protected function stopRequested(): bool
    {
        return $this->shouldStop ? (bool) call_user_func($this->shouldStop) : false;
    }

    protected function errorMessage(\Throwable $e): string
    {
        if ($e instanceof \Omeka\Api\Exception\ValidationException) {
            $messages = [];
            foreach ($e->getErrorStore()->getErrors() as $errors) {
                foreach ((array) $errors as $error) {
                    $messages[] = is_array($error) ? json_encode($error) : (string) $error;
                }
            }
            if ($messages) {
                return implode(' ', $messages);
            }
        }
        return $e->getMessage();
    }
}
