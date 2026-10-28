<?php
namespace BatchValues\Operation;

/**
 * The thumbnail image of the resources (an asset), as on the "Thumbnail" tab
 * of the edit page: it replaces the image made from the media.
 */
class ResourceThumbnail extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'thumbnail';
    }

    public function label(): string
    {
        return 'Set or remove the thumbnail'; // @translate
    }

    public function help(): string
    {
        return 'Use one image (an asset) as the thumbnail, instead of the one made from the media.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        $connection = $this->services->get('Omeka\Connection');
        $assets = $connection->fetchAllAssociative('SELECT id, name, media_type, storage_id, extension FROM asset ORDER BY id DESC LIMIT 500');
        $store = $this->services->get('Omeka\File\Store');
        foreach ($assets as &$asset) {
            $asset['url'] = $store->getUri('asset/' . $asset['storage_id'] . ($asset['extension'] !== null && $asset['extension'] !== '' ? '.' . $asset['extension'] : ''));
        }
        unset($asset);
        $counts = $connection->fetchAllKeyValue(
            'SELECT thumbnail_id, COUNT(*) FROM resource WHERE thumbnail_id IS NOT NULL AND id IN (' . implode(',', array_map('intval', $ctx['ids'] ?: [0])) . ') GROUP BY thumbnail_id'
        );
        return ['assets' => $assets, 'thumbnailCounts' => $counts, 'primaryKinds' => $this->primaryKinds($ctx)];
    }

    /** Conditions on the primary media (1.1.0). */
    const PRIMARY = [
        'no_thumb' => 'its primary media has no image of its own (audio, a document…)', // @translate
        'has_thumb' => 'its primary media has an image of its own', // @translate
        'kinds' => 'its primary media is of a kind ticked below', // @translate
    ];

    /**
     * The primary media of each resource (1.1.0): for an item, the one chosen
     * on its page, else its first media (as Omeka shows it); a media is its
     * own. Item sets have none.
     *
     * @return array resource id => media row (id, media_type, ingester, has_thumbnails)
     */
    public function primaryMedia(array $ids, string $resourceType): array
    {
        if (!$ids || !in_array($resourceType, ['items', 'media'], true)) {
            return [];
        }
        $connection = $this->services->get('Omeka\Connection');
        $list = implode(',', array_map('intval', $ids));
        if ($resourceType === 'media') {
            $sql = "SELECT m.id AS resource_id, m.id, m.media_type, m.ingester, m.has_thumbnails FROM media m WHERE m.id IN ($list)";
        } else {
            $first = '(SELECT m2.id FROM media m2 WHERE m2.item_id = i.id ORDER BY m2.position, m2.id LIMIT 1)';
            $chosen = $connection->fetchOne("SHOW COLUMNS FROM item LIKE 'primary_media_id'") ? "COALESCE(i.primary_media_id, $first)" : $first;
            $sql = "SELECT i.id AS resource_id, m.id, m.media_type, m.ingester, m.has_thumbnails FROM item i JOIN media m ON m.id = $chosen WHERE i.id IN ($list)";
        }
        $out = [];
        foreach ($connection->fetchAllAssociative($sql) as $row) {
            $row['has_thumbnails'] = (bool) $row['has_thumbnails'];
            $out[(int) $row['resource_id']] = $row;
        }
        return $out;
    }

    /** Kinds of the primary media of the selection: kind => count. */
    protected function primaryKinds(array $ctx): array
    {
        $kinds = [];
        foreach ($this->primaryMedia($ctx['ids'] ?? [], $ctx['resource_type'] ?? 'items') as $row) {
            $kind = AbstractMediaOperation::kindOf($row);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        arsort($kinds);
        return $kinds;
    }

    /**
     * Whether a primary media (or none) fits the condition of the params.
     */
    public static function primaryFits(?array $media, array $params): bool
    {
        switch ($params['primary'] ?? '') {
            case 'no_thumb':
                return $media !== null && !$media['has_thumbnails'];
            case 'has_thumb':
                return $media !== null && $media['has_thumbnails'];
            case 'kinds':
                return $media !== null && in_array(AbstractMediaOperation::kindOf($media), $params['primary_kinds'] ?? [], true);
        }
        return true;
    }

    public function parse(array $post, array $ctx): array
    {
        $action = ($post['thumb_action'] ?? 'set') === 'remove' ? 'remove' : 'set';
        $errors = [];
        $params = [
            'mode' => $action,
            'only_no_thumbnail' => !empty($post['only_no_thumbnail']),
            'only_has_thumbnail' => !empty($post['only_has_thumbnail']),
            'only_no_media' => !empty($post['only_no_media']),
            'only_has_media' => !empty($post['only_has_media']),
            'only_this' => !empty($post['only_this']),
            'primary' => isset(self::PRIMARY[$post['only_primary'] ?? '']) ? (string) $post['only_primary'] : '',
            'primary_kinds' => array_values(array_filter(array_map('strval', (array) ($post['primary_kinds'] ?? [])), 'strlen')),
        ];
        if ($params['primary'] !== 'kinds') {
            $params['primary_kinds'] = [];
        }
        // 0.5 forms.
        if (($post['thumb_mode'] ?? '') === 'remove') {
            $params['mode'] = 'remove';
        } elseif (($post['thumb_mode'] ?? '') === 'set_empty') {
            $params['only_no_thumbnail'] = true;
        }
        $assetId = (int) ($post['asset'] ?? 0);
        $name = $assetId ? $this->services->get('Omeka\Connection')->fetchOne('SELECT name FROM asset WHERE id = ?', [$assetId]) : false;
        $needsAsset = $params['mode'] === 'set' || $params['only_this'];
        if ($needsAsset && $name === false) {
            $errors[] = $this->translate('Choose the image.');
        }
        if ($params['only_no_thumbnail'] && $params['only_has_thumbnail']) {
            $errors[] = $this->translate('“No thumbnail yet” and “has a thumbnail already” cannot both be ticked.');
        }
        if ($params['only_no_media'] && $params['only_has_media']) {
            $errors[] = $this->translate('“Without media” and “with media” cannot both be ticked.');
        }
        if ($params['primary'] === 'kinds' && !$params['primary_kinds']) {
            $errors[] = $this->translate('Tick the kinds of primary media.');
        }
        if ($params['primary'] !== '' && $params['only_no_media']) {
            $errors[] = $this->translate('“Without media” and a condition on the primary media cannot go together.');
        }
        $params['asset'] = $needsAsset ? $assetId : null;
        $where = [];
        if ($params['only_no_thumbnail']) {
            $where[] = $this->translate('no thumbnail yet');
        }
        if ($params['only_has_thumbnail']) {
            $where[] = $this->translate('a thumbnail already');
        }
        if ($params['only_no_media']) {
            $where[] = $this->translate('no media');
        }
        if ($params['only_has_media']) {
            $where[] = $this->translate('media');
        }
        if ($params['primary'] !== '') {
            $where[] = $params['primary'] === 'kinds'
                ? sprintf($this->translate('a primary media of the kind %s'), implode(', ', $params['primary_kinds']))
                : $this->translate($params['primary'] === 'no_thumb' ? 'a primary media without an image of its own' : 'a primary media with an image of its own');
        }
        if ($params['mode'] === 'remove') {
            $params['display'] = [['label' => 'Thumbnail', 'text' => $params['only_this']
                ? sprintf($this->translate('Removed where it is %s'), sprintf('%s (#%d)', (string) $name, $assetId))
                : $this->translate('Removed: the image made from the media is shown again')]]; // @translate
        } else {
            $params['display'] = [['label' => 'Thumbnail', 'text' => sprintf('%s (#%d)', (string) $name, $assetId)]]; // @translate
        }
        $params['display'][] = ['label' => 'Where', 'text' => $where ? sprintf($this->translate('Only those with %s'), implode(', ', $where)) : $this->translate('All')]; // @translate
        return ['params' => $params, 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $remove = ($params['mode'] ?? 'set') === 'remove';
        $after = $remove ? null : (int) $params['asset'];
        $plan = $this->planScalar($ids, 'thumbnail', $after, function ($info) {
            return $info['thumbnail_id'];
        });
        $mediaCounts = [];
        if (!empty($params['only_no_media']) || !empty($params['only_has_media'])) {
            if (($ctx['resource_type'] ?? 'items') === 'items') {
                $mediaCounts = $this->services->get('Omeka\Connection')->fetchAllKeyValue(
                    'SELECT item_id, COUNT(*) FROM media WHERE item_id IN (?) GROUP BY item_id',
                    [$ids],
                    [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                );
            } elseif (($ctx['resource_type'] ?? '') === 'media') {
                // A media is its own file.
                $mediaCounts = array_fill_keys($ids, 1);
            }
        }
        $primary = !empty($params['primary']) ? $this->primaryMedia($ids, $ctx['resource_type'] ?? 'items') : [];
        foreach ($plan as $id => $entry) {
            if (($entry['outcome'] ?? '') !== 'changed') {
                continue;
            }
            if (!empty($params['primary']) && !self::primaryFits($primary[$id] ?? null, $params)) {
                $plan[$id] = self::entry($entry['group'], 'skipped_primary');
                continue;
            }
            $current = $entry['resource']['thumbnail']['before'];
            $hasMedia = !empty($mediaCounts[$id]);
            $skip = null;
            if (!empty($params['only_no_thumbnail']) && $current !== null) {
                $skip = 'skipped_has_thumbnail';
            } elseif (!empty($params['only_has_thumbnail']) && $current === null) {
                $skip = 'skipped_no_match';
            } elseif (!empty($params['only_no_media']) && $hasMedia) {
                $skip = 'skipped_has_media';
            } elseif (!empty($params['only_has_media']) && !$hasMedia) {
                $skip = 'skipped_no_media';
            } elseif ($remove && !empty($params['only_this']) && $current !== (int) $params['asset']) {
                $skip = 'skipped_no_match';
            }
            if ($skip) {
                $plan[$id] = self::entry($entry['group'], $skip);
            }
        }
        return $plan;
    }
}
