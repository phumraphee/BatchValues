<?php
namespace BatchValues\Operation;

class ResourceVisibility extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'resource_visibility';
    }

    public function label(): string
    {
        return 'Make public or private'; // @translate
    }

    public function help(): string
    {
        return 'Publish or hide the whole item, not only some values.'; // @translate
    }

    public function parse(array $post, array $ctx): array
    {
        if (!isset($post['is_public']) || !in_array((string) $post['is_public'], ['0', '1'], true)) {
            return ['params' => [], 'errors' => [$this->translate('Choose public or private.')]];
        }
        $isPublic = $post['is_public'] === '1';
        return ['params' => [
            'is_public' => $isPublic,
            'display' => [['label' => 'Becomes', 'text' => $isPublic ? 'Public' : 'Private']], // @translate
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planScalar($ids, 'is_public', (bool) $params['is_public'], function ($info) {
            return (bool) $info['is_public'];
        });
    }
}
