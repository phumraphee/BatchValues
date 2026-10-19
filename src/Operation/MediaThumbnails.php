<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Make the thumbnails of media files again from their original file, for
 * example after the thumbnail settings changed or a thumbnail is missing.
 */
class MediaThumbnails extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'media_thumbnails';
    }

    public function label(): string
    {
        return 'Make thumbnails again'; // @translate
    }

    public function help(): string
    {
        return 'Rebuild the small images of media files from their original file, when a thumbnail is missing or wrong.'; // @translate
    }

    public function reversible(): bool
    {
        return false;
    }

    public function formVars(array $ctx): array
    {
        return ['kinds' => $this->mediaKinds($ctx['ids'], $ctx)];
    }

    public function syncLimit(int $default): int
    {
        return min($default, 3);
    }

    public function parse(array $post, array $ctx): array
    {
        $params = ['kinds' => $this->parseKinds($post), 'only_missing' => !empty($post['only_missing'])];
        $params['display'] = [$this->kindsDisplay($params)];
        if ($params['only_missing']) {
            $params['display'][] = ['label' => 'Where', 'text' => 'Only media without thumbnails']; // @translate
        }
        return ['params' => $params, 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planMedia($ids, $ctx, function (array $rows) use ($params) {
            $media = [];
            foreach ($rows as $row) {
                if (!$row['has_original'] || !$this->kindMatches($row, $params)) {
                    continue;
                }
                if (!empty($params['only_missing']) && $row['has_thumbnails']) {
                    continue;
                }
                $media[$row['id']] = Planner::mediaSnapshot($row);
            }
            if (!$media) {
                return ['outcome' => 'skipped_no_media'];
            }
            return ['outcome' => 'changed', 'custom' => 'rebuild_thumbnails', 'resource' => ['media_thumbnails' => ['media' => $media]]];
        });
    }
}
