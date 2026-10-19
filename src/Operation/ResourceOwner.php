<?php
namespace BatchValues\Operation;

class ResourceOwner extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'owner';
    }

    public function label(): string
    {
        return 'Change owner'; // @translate
    }

    public function help(): string
    {
        return 'The user the item belongs to.'; // @translate
    }

    public function isAllowed(string $resourceType): bool
    {
        // Omeka lets only some roles (not editors) change owners.
        $class = \BatchValues\Stdlib\Planner::RESOURCE_TYPES[$resourceType][2] ?? \Omeka\Entity\Item::class;
        return $this->services->get('Omeka\Acl')->userIsAllowed($class, 'change-owner');
    }

    public function formVars(array $ctx): array
    {
        return ['users' => $this->services->get('Omeka\Connection')->fetchAllAssociative('SELECT id, name, email FROM user ORDER BY name')];
    }

    public function parse(array $post, array $ctx): array
    {
        $owner = (string) ($post['owner'] ?? '');
        if ($owner === '') {
            return ['params' => [], 'errors' => [$this->translate('Choose the new owner.')]];
        }
        $label = 'No owner'; // @translate
        if ($owner !== 'none') {
            $label = $this->services->get('Omeka\Connection')->fetchOne("SELECT CONCAT(name, ' <', email, '>') FROM user WHERE id = ?", [(int) $owner]);
            if ($label === false) {
                return ['params' => [], 'errors' => [$this->translate('Unknown user.')]];
            }
        }
        return ['params' => [
            'owner' => $owner === 'none' ? null : (int) $owner,
            'display' => [['label' => 'New owner', 'text' => $label]], // @translate
        ], 'errors' => []];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        return $this->planScalar($ids, 'owner', $params['owner'] === null ? null : (int) $params['owner'], function ($info) {
            return $info['owner_id'];
        });
    }
}
