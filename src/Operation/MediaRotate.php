<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\MediaTools;
use BatchValues\Stdlib\Planner;

/**
 * Turn the images of media by 90, 180 or 270 degrees, for files that were
 * uploaded the wrong way round, and make their thumbnails again.
 */
class MediaRotate extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'media_rotate';
    }

    public function label(): string
    {
        return 'Turn images'; // @translate
    }

    public function help(): string
    {
        return 'Turn the file itself by 90, 180 or 270 degrees, and make the thumbnails again. Cancel turns it back.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        $kinds = $this->mediaKinds($ctx['ids'], $ctx);
        // Only the kinds this module turns.
        $kinds = array_intersect_key($kinds, array_flip(MediaTools::ROTATABLE));
        return ['kinds' => $kinds, 'tools' => MediaTools::rotateTools()];
    }

    public function syncLimit(int $default): int
    {
        return min($default, 3);
    }

    public function parse(array $post, array $ctx): array
    {
        $degrees = (int) ($post['degrees'] ?? 0);
        if (!in_array($degrees, [90, 180, 270], true)) {
            return ['params' => [], 'errors' => [$this->translate('Choose which way to turn them.')]];
        }
        if (!MediaTools::rotateTools()) {
            return ['params' => [], 'errors' => [$this->translate('This server has neither jpegtran nor ImageMagick (convert), so images cannot be turned.')]];
        }
        $params = ['degrees' => $degrees, 'kinds' => $this->parseKinds($post)];
        $words = [90 => 'A quarter turn to the right', 180 => 'Upside down', 270 => 'A quarter turn to the left']; // @translate
        $params['display'] = [
            ['label' => 'Turn', 'text' => $this->translate($words[$degrees])], // @translate
            $this->kindsDisplay($params),
        ];
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
                if (!in_array((string) $row['media_type'], MediaTools::ROTATABLE, true)) {
                    continue;
                }
                $media[$row['id']] = Planner::mediaSnapshot($row) + [
                    'sha256' => $row['sha256'] ?? null,
                    'size' => isset($row['size']) ? (int) $row['size'] : null,
                ];
            }
            if (!$media) {
                return ['outcome' => 'skipped_no_media'];
            }
            return [
                'outcome' => 'changed',
                'custom' => 'rotate_media',
                'resource' => ['media_rotate' => ['degrees' => (int) $params['degrees'], 'media' => $media]],
            ];
        });
    }
}
