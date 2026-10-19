<?php
namespace BatchValues\Stdlib;

use Doctrine\DBAL\Connection;
use Interop\Container\ContainerInterface;

/**
 * Append-only log of change sets and their per-resource changes.
 *
 * Changes are never edited or deleted. Cancelling creates a new change set
 * (operation "cancel") whose changes point to the ones they reverse.
 * Only the running counters/status of a change set are updated in place.
 */
class ChangeLog
{
    const SET = 'batch_values_change_set';
    const CHANGE = 'batch_values_change';

    /** Outcomes that actually modified a resource. */
    const EFFECTIVE = ['added', 'removed', 'changed'];
    const EFFECTIVE_SQL = "('added', 'removed', 'changed')";

    /**
     * Outcomes of a reversal attempt that settle the original change: it was
     * reversed, or it cannot be reversed anymore (value changed since, item
     * deleted). A failed attempt does not settle it, so it can be retried.
     */
    const SETTLED_SQL = "('added', 'removed', 'changed', 'skipped_changed', 'skipped_missing', 'skipped_irreversible')";

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var ContainerInterface
     */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
        $this->connection = $services->get('Omeka\Connection');
    }

    public function createSet(array $data): int
    {
        $user = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        $row = [
            'operation' => $data['operation'],
            'resource_type' => $data['resource_type'] ?? 'items',
            'property_id' => $data['property_id'] ?? null,
            'property_term' => $data['property_term'] ?? null,
            'property_label' => $data['property_label'] ?? null,
            'reverses_id' => $data['reverses_id'] ?? null,
            'params' => json_encode($data['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'selection' => json_encode($data['selection'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'running',
            'total' => (int) ($data['total'] ?? 0),
            'owner_id' => $user ? $user->getId() : null,
            'owner_name' => $user ? $user->getName() : null,
            'owner_email' => $user ? $user->getEmail() : null,
            'created' => $this->now(),
        ];
        $this->connection->insert(self::SET, $row);
        return (int) $this->connection->lastInsertId();
    }

    public function getSet(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ' . self::SET . ' WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        return $this->decodeSet($row);
    }

    public function updateSet(int $id, array $data): void
    {
        $this->connection->update(self::SET, $data, ['id' => $id]);
    }

    public function finishSet(int $id, string $status, ?string $message = null): void
    {
        $this->connection->update(self::SET, [
            'status' => $status,
            'finished' => $this->now(),
            'message' => $message,
        ], ['id' => $id]);
    }

    /**
     * Increment the counters of a set.
     */
    public function bump(int $id, int $processed, int $changed, int $skipped, int $failed): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::SET . ' SET processed = processed + ?, changed = changed + ?, skipped = skipped + ?, failed = failed + ? WHERE id = ?',
            [$processed, $changed, $skipped, $failed, $id]
        );
    }

    public function addChange(int $setId, array $data): int
    {
        $this->connection->insert(self::CHANGE, [
            'change_set_id' => $setId,
            'resource_id' => (int) $data['resource_id'],
            'resource_title' => $data['resource_title'] ?? null,
            'template_id' => $data['template_id'] ?? null,
            'outcome' => $data['outcome'],
            'value_data' => isset($data['values'])
                ? json_encode($data['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'reverses_change_id' => $data['reverses_change_id'] ?? null,
            'message' => $data['message'] ?? null,
            'created' => $this->now(),
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * The order of the fields this change touched, before and after it
     * (0.8.0): {property id: {"before": [value id, …], "after": [value id, …]}}.
     * Written after the resource is saved, so the "after" list is the real one.
     */
    public function setFieldData(int $changeId, array $data): void
    {
        $this->setFieldDataBatch([$changeId => $data]);
    }

    /**
     * The same for a whole chunk, in one statement.
     *
     * @param array $byChangeId [change id => field order]
     */
    public function setFieldDataBatch(array $byChangeId): void
    {
        $byChangeId = array_filter($byChangeId);
        if (!$byChangeId) {
            return;
        }
        foreach (array_chunk($byChangeId, 200, true) as $chunk) {
            $sql = 'UPDATE ' . self::CHANGE . ' SET field_data = CASE id';
            $params = [];
            foreach ($chunk as $changeId => $data) {
                $sql .= ' WHEN ' . (int) $changeId . ' THEN ?';
                $params[] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $sql .= ' END WHERE id IN (' . implode(',', array_map('intval', array_keys($chunk))) . ')';
            $this->connection->executeStatement($sql, $params);
        }
    }

    public function browseSets(int $page = 1, int $perPage = 25): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.*, (SELECT COUNT(*) FROM ' . self::SET . ' c WHERE c.reverses_id = s.id) AS cancel_count'
            . ' FROM ' . self::SET . ' s ORDER BY s.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        return array_map([$this, 'decodeSet'], $rows);
    }

    public function countSets(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::SET);
    }

    /**
     * Changes of a set, with the id of the change that reversed each one (if any).
     */
    public function browseChanges(int $setId, ?string $outcome = null, int $page = 1, int $perPage = 50): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sub = 'FROM ' . self::CHANGE . ' r WHERE r.reverses_change_id = c.id AND r.outcome IN ' . self::SETTLED_SQL . ' ORDER BY r.id LIMIT 1';
        $sql = 'SELECT c.*,'
            . ' (SELECT r.change_set_id ' . $sub . ') AS reversed_by_set_id,'
            . ' (SELECT r.outcome ' . $sub . ') AS reversed_by_outcome'
            . ' FROM ' . self::CHANGE . ' c WHERE c.change_set_id = ?';
        $params = [$setId];
        if ($outcome === 'effective') {
            $sql .= ' AND c.outcome IN ' . self::EFFECTIVE_SQL;
        } elseif ($outcome === 'skipped') {
            $sql .= " AND c.outcome LIKE 'skipped%'";
        } elseif ($outcome && $outcome !== 'all') {
            $sql .= ' AND c.outcome = ?';
            $params[] = $outcome;
        }
        $sql .= ' ORDER BY c.id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        foreach ($rows as &$row) {
            $row['values'] = $row['value_data'] ? json_decode($row['value_data'], true) : [];
            $row['fields'] = !empty($row['field_data']) ? json_decode($row['field_data'], true) : null;
        }
        return $rows;
    }

    public function countChangesByOutcome(int $setId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT outcome, COUNT(*) AS total FROM ' . self::CHANGE . ' WHERE change_set_id = ? GROUP BY outcome',
            [$setId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['outcome']] = (int) $row['total'];
        }
        return $result;
    }

    /**
     * Effective changes of a set that have not been reversed yet.
     *
     * @param int[]|null $changeIds Limit to these change ids.
     */
    public function getReversibleChanges(int $setId, ?array $changeIds = null): array
    {
        $sql = 'SELECT c.* FROM ' . self::CHANGE . ' c'
            . ' WHERE c.change_set_id = ? AND c.outcome IN ' . self::EFFECTIVE_SQL . ''
            . ' AND NOT EXISTS (SELECT 1 FROM ' . self::CHANGE . ' r WHERE r.reverses_change_id = c.id AND r.outcome IN ' . self::SETTLED_SQL . ')';
        $params = [$setId];
        $types = [\PDO::PARAM_INT];
        if ($changeIds !== null) {
            if (!$changeIds) {
                return [];
            }
            $sql .= ' AND c.id IN (?)';
            $params[] = array_map('intval', $changeIds);
            $types[] = Connection::PARAM_INT_ARRAY;
        }
        $sql .= ' ORDER BY c.id';
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        foreach ($rows as &$row) {
            $row['values'] = $row['value_data'] ? json_decode($row['value_data'], true) : [];
            $row['fields'] = !empty($row['field_data']) ? json_decode($row['field_data'], true) : null;
        }
        return $rows;
    }

    public function countReversible(int $setId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::CHANGE . ' c'
            . ' WHERE c.change_set_id = ? AND c.outcome IN ' . self::EFFECTIVE_SQL . ''
            . ' AND NOT EXISTS (SELECT 1 FROM ' . self::CHANGE . ' r WHERE r.reverses_change_id = c.id AND r.outcome IN ' . self::SETTLED_SQL . ')',
            [$setId]
        );
    }

    /**
     * Change sets that cancelled (part of) this set.
     */
    public function getCancelSets(int $setId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ' . self::SET . ' WHERE reverses_id = ? ORDER BY id',
            [$setId]
        );
        return array_map([$this, 'decodeSet'], $rows);
    }

    protected function decodeSet(array $row): array
    {
        $row['params'] = json_decode($row['params'] ?: '[]', true) ?: [];
        $row['selection'] = json_decode($row['selection'] ?: '[]', true) ?: [];
        return $row;
    }

    protected function now(): string
    {
        return (new \DateTime('now'))->format('Y-m-d H:i:s');
    }
}
