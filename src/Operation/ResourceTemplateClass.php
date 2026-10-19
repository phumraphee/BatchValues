<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

class ResourceTemplateClass extends AbstractResourceOperation
{
    public function name(): string
    {
        return 'template';
    }

    public function label(): string
    {
        return 'Change template or class'; // @translate
    }

    public function help(): string
    {
        return 'Resource template and/or resource class. Values are not touched.'; // @translate
    }

    public function formVars(array $ctx): array
    {
        $connection = $this->services->get('Omeka\Connection');
        return [
            'templates' => $connection->fetchAllAssociative('SELECT id, label FROM resource_template ORDER BY label'),
            'classes' => $connection->fetchAllAssociative(
                'SELECT c.id, c.label, c.local_name, v.prefix, v.label AS vocabulary FROM resource_class c JOIN vocabulary v ON v.id = c.vocabulary_id ORDER BY v.label, c.label'
            ),
        ];
    }

    public function parse(array $post, array $ctx): array
    {
        $template = (string) ($post['template'] ?? 'keep');
        $class = (string) ($post['class'] ?? 'keep');
        $errors = [];
        if ($template === 'keep' && $class === 'keep') {
            $errors[] = $this->translate('Choose a template or a class to set.');
        }
        $display = [];
        $connection = $this->services->get('Omeka\Connection');
        if ($template !== 'keep') {
            $label = $template === 'none' ? 'None' : $connection->fetchOne('SELECT label FROM resource_template WHERE id = ?', [(int) $template]); // @translate
            if ($label === false) {
                $errors[] = $this->translate('Unknown template.');
            }
            $display[] = ['label' => 'Template', 'text' => (string) $label]; // @translate
        }
        if ($class !== 'keep') {
            $label = $class === 'none' ? 'None' : $connection->fetchOne("SELECT CONCAT(c.label, ' (', v.prefix, ':', c.local_name, ')') FROM resource_class c JOIN vocabulary v ON v.id = c.vocabulary_id WHERE c.id = ?", [(int) $class]); // @translate
            if ($label === false) {
                $errors[] = $this->translate('Unknown class.');
            }
            $display[] = ['label' => 'Class', 'text' => (string) $label]; // @translate
        }
        return ['params' => ['template' => $template, 'class' => $class, 'display' => $display], 'errors' => $errors];
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $template = $params['template'] === 'keep' ? false : ($params['template'] === 'none' ? null : (int) $params['template']);
        $class = $params['class'] === 'keep' ? false : ($params['class'] === 'none' ? null : (int) $params['class']);
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $changes = [];
            if ($template !== false && $info[$id]['template_id'] !== $template) {
                $changes['template'] = ['before' => $info[$id]['template_id'], 'after' => $template];
            }
            if ($class !== false && $info[$id]['class_id'] !== $class) {
                $changes['class'] = ['before' => $info[$id]['class_id'], 'after' => $class];
            }
            $plan[$id] = $changes
                ? ['group' => $key, 'outcome' => 'changed', 'resource' => $changes]
                : self::entry($key, 'skipped_same');
        }
        return $plan;
    }
}
