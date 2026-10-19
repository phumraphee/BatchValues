<?php
namespace BatchValues\Operation;

/**
 * Make the media of the selected items public or private.
 */
class MediaVisibility extends AbstractMediaOperation
{
    public function name(): string
    {
        return 'media_visibility';
    }

    public function label(): string
    {
        return 'Make media public or private'; // @translate
    }

    public function help(): string
    {
        return 'The media inside the items, for example to hide the PDFs but keep the images public.'; // @translate
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
        $public = ($post['media_public'] ?? '') === '1';
        if (!in_array($post['media_public'] ?? '', ['0', '1'], true)) {
            return ['params' => [], 'errors' => [$this->translate('Choose public or private.')]];
        }
        $params = ['is_public' => $public, 'kinds' => $this->parseKinds($post)];
        $params['display'] = [
            $this->kindsDisplay($params),
            ['label' => 'Visibility', 'text' => $public ? 'Public' : 'Private'], // @translate
        ];
        return ['params' => $params, 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $public = !empty($params['is_public']);
        return $this->planMedia($ids, $ctx, function (array $rows) use ($params, $public) {
            if (!$rows) {
                return ['outcome' => 'skipped_no_media'];
            }
            $changes = [];
            $labels = [];
            $matched = false;
            foreach ($rows as $row) {
                if (!$this->kindMatches($row, $params)) {
                    continue;
                }
                $matched = true;
                if ((bool) $row['is_public'] !== $public) {
                    $changes[$row['id']] = ['before' => (bool) $row['is_public'], 'after' => $public];
                    $labels[$row['id']] = self::mediaLabel($row);
                }
            }
            if (!$changes) {
                return ['outcome' => $matched ? 'skipped_same' : 'skipped_no_media'];
            }
            return ['outcome' => 'changed', 'resource' => ['media_visibility' => ['media' => $changes, 'labels' => $labels]]];
        });
    }
}
