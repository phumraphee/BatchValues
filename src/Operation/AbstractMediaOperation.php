<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

/**
 * Operations on media: the media of the selected items, or the selected media
 * themselves.
 */
abstract class AbstractMediaOperation extends AbstractResourceOperation
{
    public function group(): string
    {
        return 'media';
    }

    public function resourceTypes(): array
    {
        return ['items', 'media'];
    }

    /**
     * Media rows per selected resource: the media of each item, or the media
     * itself.
     */
    protected function mediaRows(array $ids, array $ctx): array
    {
        return $this->planner->mediaOf($ids, ($ctx['resource_type'] ?? 'items') === 'media');
    }

    /**
     * Kinds of media in the selection, for the "only these kinds" filter:
     * media type (or ingester for media without a file) => count.
     */
    protected function mediaKinds(array $ids, array $ctx): array
    {
        $kinds = [];
        foreach ($this->mediaRows($ids, $ctx) as $rows) {
            foreach ($rows as $row) {
                $kind = self::kindOf($row);
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
        }
        arsort($kinds);
        return $kinds;
    }

    public static function kindOf(array $row): string
    {
        return $row['media_type'] ? (string) $row['media_type'] : (string) $row['ingester'];
    }

    /**
     * Renderers of the media in the selection (1.1.0), for the "only these
     * renderers" filter: renderer => count. The renderer is how Omeka shows
     * a media: file, html, oembed, youtube, iiif…
     */
    protected function mediaRenderers(array $ids, array $ctx): array
    {
        $renderers = [];
        foreach ($this->mediaRows($ids, $ctx) as $rows) {
            foreach ($rows as $row) {
                $renderer = (string) $row['renderer'];
                $renderers[$renderer] = ($renderers[$renderer] ?? 0) + 1;
            }
        }
        arsort($renderers);
        return $renderers;
    }

    protected function parseKinds(array $post): array
    {
        return array_values(array_filter(array_map('strval', (array) ($post['media_kinds'] ?? [])), 'strlen'));
    }

    protected function parseRenderers(array $post): array
    {
        return array_values(array_filter(array_map('strval', (array) ($post['media_renderers'] ?? [])), 'strlen'));
    }

    protected function rendererMatches(array $row, array $params): bool
    {
        return empty($params['renderers']) || in_array((string) $row['renderer'], $params['renderers'], true);
    }

    protected function kindMatches(array $row, array $params): bool
    {
        return (empty($params['kinds']) || in_array(self::kindOf($row), $params['kinds'], true))
            && $this->rendererMatches($row, $params);
    }

    protected function kindsDisplay(array $params): array
    {
        $text = empty($params['kinds']) ? $this->translate('All media') : implode(', ', $params['kinds']);
        if (!empty($params['renderers'])) {
            $text .= ' · ' . sprintf($this->translate('shown by %s'), implode(', ', $params['renderers']));
        }
        return ['label' => 'Media', 'text' => $text]; // @translate
    }

    protected static function mediaLabel(array $row): string
    {
        $title = trim((string) $row['title']);
        if ($title !== '') {
            return $title;
        }
        // A file name, not a whole URL or path.
        $source = (string) $row['source'];
        $name = basename((string) (parse_url($source, PHP_URL_PATH) ?: $source));
        return $name !== '' ? $name : '#' . $row['id'];
    }

    /**
     * A plan with one entry per selected resource, built from its media.
     *
     * @param callable $fn fn(array $mediaRows, int $id): array entry
     */
    protected function planMedia(array $ids, array $ctx, callable $fn): array
    {
        $info = $this->planner->resourceInfo($ids);
        $media = $this->mediaRows($ids, $ctx);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $entry = $fn($media[$id] ?? [], $id);
            $entry['group'] = Planner::groupKey($info[$id]['template_id']);
            $plan[$id] = $entry;
        }
        return $plan;
    }
}
