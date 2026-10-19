<?php
namespace BatchValues\Operation;

class ItemSets extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'item_sets';
    }

    public function label(): string
    {
        return 'Add to or remove from item sets'; // @translate
    }

    public function help(): string
    {
        return 'Other item sets of each item are kept.'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['items'];
    }

    public function formVars(array $ctx): array
    {
        return ['itemSets' => $this->services->get('Omeka\Connection')->fetchAllAssociative(
            'SELECT r.id, r.title FROM resource r JOIN item_set s ON s.id = r.id ORDER BY r.title'
        )];
    }

    public function parse(array $post, array $ctx): array
    {
        $add = $this->idList($post['add'] ?? []);
        $remove = array_values(array_diff($this->idList($post['remove'] ?? []), $add));
        if (!$add && !$remove) {
            return ['params' => [], 'errors' => [$this->translate('Choose item sets to add to or remove from.')]];
        }
        $sql = 'SELECT r.id, r.title AS label FROM resource r JOIN item_set s ON s.id = r.id WHERE r.id IN (?)';
        $display = [];
        if ($add) {
            $display[] = ['label' => 'Add to', 'text' => implode(' · ', $this->labels($sql, $add))]; // @translate
        }
        if ($remove) {
            $display[] = ['label' => 'Remove from', 'text' => implode(' · ', $this->labels($sql, $remove))]; // @translate
        }
        return ['params' => ['add' => $add, 'remove' => $remove, 'display' => $display], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planLinks($ids, 'item_sets', 'item_item_set', 'item_set_id', $params['add'] ?? [], $params['remove'] ?? []);
    }
}
