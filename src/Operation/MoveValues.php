<?php
namespace BatchValues\Operation;

/**
 * Move chosen values to another property: the value itself changes property,
 * keeping its id, visibility and annotation.
 */
class MoveValues extends CopyValues
{
    public function name(): string
    {
        return 'move';
    }

    public function label(): string
    {
        return 'Move to another property'; // @translate
    }

    public function help(): string
    {
        return 'Values leave this field for another one, with their visibility and annotation.'; // @translate
    }

    protected function isMove(): bool
    {
        return true;
    }
}
