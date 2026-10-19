<?php
namespace BatchValues\Operation;

/**
 * Open or close item sets ("Set openness" of Omeka's batch edit).
 *
 * An open item set is listed in the "Item sets" tab of the item form, so
 * items can be added to it there; a closed one is not listed.
 */
class ItemSetOpen extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'item_set_open';
    }

    public function label(): string
    {
        return 'Open or close item sets'; // @translate
    }

    public function help(): string
    {
        return 'Open: the item set is listed when you add an item to item sets in the item form. Closed: it is not listed there (items already in it stay).'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['item_sets'];
    }

    public function parse(array $post, array $ctx): array
    {
        if (!isset($post['is_open']) || !in_array((string) $post['is_open'], ['0', '1'], true)) {
            return ['params' => [], 'errors' => [$this->translate('Choose open or closed.')]];
        }
        $isOpen = $post['is_open'] === '1';
        return ['params' => [
            'is_open' => $isOpen,
            'display' => [['label' => 'Becomes', 'text' => $isOpen ? 'Open to additions' : 'Closed to additions']], // @translate
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planScalar($ids, 'is_open', (bool) $params['is_open'], function ($info) {
            return (bool) $info['is_open'];
        });
    }
}
