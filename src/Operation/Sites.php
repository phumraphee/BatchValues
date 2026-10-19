<?php
namespace BatchValues\Operation;

class Sites extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'sites';
    }

    public function label(): string
    {
        return 'Add to or remove from sites'; // @translate
    }

    public function help(): string
    {
        return 'Other sites of each item are kept.'; // @translate
    }

    public function resourceTypes(): array
    {
        return ['items'];
    }

    public function formVars(array $ctx): array
    {
        return ['sites' => $this->services->get('Omeka\Connection')->fetchAllAssociative('SELECT id, title FROM site ORDER BY title')];
    }

    public function parse(array $post, array $ctx): array
    {
        $add = $this->idList($post['add'] ?? []);
        $remove = array_values(array_diff($this->idList($post['remove'] ?? []), $add));
        if (!$add && !$remove) {
            return ['params' => [], 'errors' => [$this->translate('Choose sites to add to or remove from.')]];
        }
        $sql = 'SELECT id, title AS label FROM site WHERE id IN (?)';
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
        return $this->planLinks($ids, 'sites', 'item_site', 'site_id', $params['add'] ?? [], $params['remove'] ?? []);
    }
}
