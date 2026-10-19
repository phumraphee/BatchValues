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
        return ['assets' => $assets, 'thumbnailCounts' => $counts];
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
        ];
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
        foreach ($plan as $id => $entry) {
            if (($entry['outcome'] ?? '') !== 'changed') {
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
