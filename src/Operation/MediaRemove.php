<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Delete media of the selected items by kind (media type or ingester). The
 * file is put aside first (module setting, on by default), so Cancel can put
 * the media back with its file, its values and its place in the item.
 */
class MediaRemove extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'media_remove';
    }

    public function label(): string
    {
        return 'Delete media'; // @translate
    }

    public function help(): string
    {
        return 'Delete media of one kind from the items, with their files. Cannot be cancelled.'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['items'];
    }

    public function reversible(): bool
    {
        return $this->keepsFiles();
    }

    /** Module setting: keep the file of a deleted media (0.7.2). */
    protected function keepsFiles(): bool
    {
        return (bool) $this->services->get('Omeka\Settings')->get('batchvalues_keep_removed_files', true);
    }

    public function formVars(array $ctx): array
    {
        $extensions = [];
        foreach ($this->mediaRows($ctx['ids'], $ctx) as $rows) {
            foreach ($rows as $row) {
                if ((string) $row['extension'] !== '') {
                    $ext = strtolower((string) $row['extension']);
                    $extensions[$ext] = ($extensions[$ext] ?? 0) + 1;
                }
            }
        }
        arsort($extensions);
        return ['kinds' => $this->mediaKinds($ctx['ids'], $ctx), 'extensions' => $extensions, 'keepsFiles' => $this->keepsFiles()];
    }

    public function parse(array $post, array $ctx): array
    {
        $params = [
            'kinds' => $this->parseKinds($post),
            'extensions' => array_values(array_filter(array_map('strtolower', array_map('strval', (array) ($post['media_extensions'] ?? []))), 'strlen')),
            'keep_one' => !empty($post['keep_one']),
            // Recorded so the log says which way this run was done (0.7.2).
            'keep_files' => $this->keepsFiles(),
        ];
        $errors = [];
        if (!$params['kinds'] && !$params['extensions']) {
            $errors[] = $this->translate('Tick the kinds or the extensions of media to delete.');
        }
        if (empty($post['confirm_delete'])) {
            $errors[] = $this->translate('Tick the box that says you understand the files are deleted for good.');
        }
        $params['display'] = [];
        if ($params['kinds']) {
            $params['display'][] = $this->kindsDisplay($params);
        }
        if ($params['extensions']) {
            $params['display'][] = ['label' => 'Extensions', 'text' => implode(', ', $params['extensions'])]; // @translate
        }
        if ($params['keep_one']) {
            $params['display'][] = ['label' => 'Where', 'text' => 'Never the last media of an item']; // @translate
        }
        $params['display'][] = ['label' => 'Files', 'text' => $params['keep_files'] // @translate
            ? 'Kept aside, so this can be cancelled' // @translate
            : 'Deleted for good']; // @translate
        return ['params' => $params, 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planMedia($ids, $ctx, function (array $rows) use ($params) {
            $remove = [];
            foreach ($rows as $row) {
                $byKind = $params['kinds'] && in_array(self::kindOf($row), $params['kinds'], true);
                $byExtension = !empty($params['extensions']) && in_array(strtolower((string) $row['extension']), $params['extensions'], true);
                if ($byKind || $byExtension) {
                    // Everything needed to put it back: its details, its own
                    // values and where it was in the item (0.7.2).
                    $values = [];
                    foreach ($this->planner->valuesByResource([$row['id']], null) as $valueRows) {
                        foreach ($valueRows as $value) {
                            $values[] = Planner::snapshotFromRow($value);
                        }
                    }
                    $remove[$row['id']] = Planner::mediaSnapshot($row) + [
                        'item_id' => (int) $row['item_id'],
                        'position' => (int) $row['position'],
                        'alt_text' => $row['alt_text'] ?? null,
                        'lang' => $row['lang'] ?? null,
                        'storage_id' => $row['storage_id'] ?? null,
                        'extension' => $row['extension'] ?? null,
                        'sha256' => $row['sha256'] ?? null,
                        'size' => isset($row['size']) ? (int) $row['size'] : null,
                        'values' => $values,
                    ];
                }
            }
            if (!$remove) {
                return ['outcome' => 'skipped_no_media'];
            }
            if (!empty($params['keep_one']) && count($remove) === count($rows)) {
                return ['outcome' => 'skipped_last_media'];
            }
            return ['outcome' => 'changed', 'custom' => 'remove_media', 'resource' => ['media_removed' => ['media' => $remove]]];
        });
    }
}
