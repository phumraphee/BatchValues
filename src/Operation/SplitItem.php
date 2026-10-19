<?php
namespace BatchValues\Operation;

/**
 * One item per media: the first media stays, each other media goes to a new
 * item with a copy of the item's values.
 */
class SplitItem extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'split_item';
    }

    public function label(): string
    {
        return 'Split items: one item per media'; // @translate
    }

    public function help(): string
    {
        return 'An item with several media becomes several items, each with one media and a copy of the metadata.'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['items'];
    }

    public function syncLimit(int $default): int
    {
        return min($default, 3);
    }

    public function parse(array $post, array $ctx): array
    {
        $modes = [
            'copy' => 'A copy of the item’s values', // @translate
            'media_title' => 'A copy of the item’s values, with the title of its media when the media has one', // @translate
            'append' => 'A copy of the item’s values, plus the values of its media', // @translate
            'update' => 'The item’s values, replaced by the media’s for the fields the media has', // @translate
            'replace' => 'The values of its media only (the item’s when the media has none)', // @translate
        ];
        $mode = isset($modes[$post['title_mode'] ?? '']) ? $post['title_mode'] : 'copy';
        return ['params' => [
            'title_mode' => $mode,
            'display' => [['label' => 'Metadata of the new items', 'text' => $modes[$mode]]], // @translate
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planMedia($ids, $ctx, function (array $rows) use ($params) {
            if (count($rows) < 2) {
                return ['outcome' => $rows ? 'skipped_same' : 'skipped_no_media'];
            }
            $labels = [];
            foreach ($rows as $row) {
                $labels[$row['id']] = self::mediaLabel($row);
            }
            $move = array_slice(array_column($rows, 'id'), 1);
            return ['outcome' => 'changed', 'custom' => 'split_item', 'resource' => ['split_item' => [
                'keep' => $rows[0]['id'],
                'move' => $move,
                'title_mode' => $params['title_mode'] ?? 'copy',
                'labels' => $labels,
            ]]];
        });
    }
}
