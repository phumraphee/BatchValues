<?php
namespace BatchValues\Operation;

/**
 * Change the order of the media inside each item.
 */
class MediaOrder extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'media_order';
    }

    public function label(): string
    {
        return 'Reorder media'; // @translate
    }

    public function help(): string
    {
        return 'Put the media of each item in order: by title, by file name, images first, or reversed.'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['items'];
    }

    public function formVars(array $ctx): array
    {
        return ['kinds' => $this->mediaKinds($ctx['ids'], $ctx)];
    }

    public function parse(array $post, array $ctx): array
    {
        $by = (string) ($post['order_by'] ?? 'source');
        $labels = [
            'source' => 'By file name, A → Z (page-2 before page-10)', // @translate
            'title' => 'By title, A → Z', // @translate
            'media_type' => 'By media type (image/jpeg, application/pdf…)', // @translate
            'extension' => 'By file extension (jpg, pdf, tif…)', // @translate
            'images_first' => 'Images first, then the other files', // @translate
            'kinds_first' => 'The kinds ticked below first', // @translate
            'kinds_last' => 'The kinds ticked below last', // @translate
            'reverse' => 'Reverse the current order', // @translate
        ];
        if (!isset($labels[$by])) {
            $by = 'source';
        }
        $kinds = $this->parseKinds($post);
        $errors = [];
        if (in_array($by, ['kinds_first', 'kinds_last'], true) && !$kinds) {
            $errors[] = $this->translate('Tick the kinds of media to put first or last.');
        }
        $display = [['label' => 'Order', 'text' => $labels[$by]]]; // @translate
        if (in_array($by, ['kinds_first', 'kinds_last'], true)) {
            $display[] = ['label' => 'Media', 'text' => implode(', ', $kinds)]; // @translate
        }
        return ['params' => [
            'by' => $by,
            'kinds' => in_array($by, ['kinds_first', 'kinds_last'], true) ? $kinds : [],
            'display' => $display,
        ], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $collator = class_exists('Collator') ? new \Collator('th_TH') : null;
        if ($collator) {
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);
        }
        $compare = function ($a, $b) use ($collator) {
            return $collator ? $collator->compare($a, $b) : strnatcasecmp($a, $b);
        };
        $by = $params['by'] ?? 'source';
        return $this->planMedia($ids, $ctx, function (array $rows) use ($by, $compare, $params) {
            if (count($rows) < 2) {
                return ['outcome' => $rows ? 'skipped_same' : 'skipped_no_media'];
            }
            $sorted = $rows;
            if ($by === 'reverse') {
                $sorted = array_reverse($rows);
            } else {
                // Stable: equal keys keep their current order.
                $index = array_flip(array_column($rows, 'id'));
                $kinds = $params['kinds'] ?? [];
                usort($sorted, function ($a, $b) use ($by, $compare, $index, $kinds) {
                    if ($by === 'images_first') {
                        $c = (int) (strpos((string) $b['media_type'], 'image/') === 0) <=> (int) (strpos((string) $a['media_type'], 'image/') === 0);
                    } elseif ($by === 'kinds_first' || $by === 'kinds_last') {
                        $ia = in_array(self::kindOf($a), $kinds, true);
                        $ib = in_array(self::kindOf($b), $kinds, true);
                        $c = $by === 'kinds_first' ? ((int) $ib <=> (int) $ia) : ((int) $ia <=> (int) $ib);
                    } elseif ($by === 'media_type') {
                        $c = $compare(self::kindOf($a), self::kindOf($b));
                    } elseif ($by === 'extension') {
                        $c = $compare(strtolower((string) $a['extension']), strtolower((string) $b['extension']));
                    } else {
                        // Title, or the file name for a media without one.
                        $c = $by === 'title'
                            ? $compare(self::mediaLabel($a), self::mediaLabel($b))
                            : $compare(basename((string) $a['source']), basename((string) $b['source']));
                    }
                    return $c ?: $index[$a['id']] <=> $index[$b['id']];
                });
            }
            $before = array_column($rows, 'id');
            $after = array_column($sorted, 'id');
            if ($before === $after) {
                return ['outcome' => 'skipped_same'];
            }
            $labels = [];
            foreach ($rows as $row) {
                $labels[$row['id']] = self::mediaLabel($row);
            }
            return ['outcome' => 'changed', 'resource' => ['media_order' => ['before' => $before, 'after' => $after, 'labels' => $labels]]];
        });
    }
}
