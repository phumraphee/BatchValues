<?php
namespace BatchValues\Operation;

/**
 * Put text before and/or after chosen values.
 */
class AffixValues extends AbstractTextOperation
{
    public function name(): string
    {
        return 'affix';
    }

    public function label(): string
    {
        return 'Add text before or after'; // @translate
    }

    public function help(): string
    {
        return 'Put the same words at the start or end of chosen values.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true];
    }

    protected function usesPicker(): bool
    {
        return true;
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $prefix = (string) ($post['prefix'] ?? '');
        $suffix = (string) ($post['suffix'] ?? '');
        if ($prefix === '' && $suffix === '') {
            $errors[] = $this->translate('Type the text to add before, after, or both.');
        }
        return ['params' => [
            'all' => $picker['all'],
            'keys' => $picker['keys'],
            'match' => $picker['match'],
            'prefix' => $prefix,
            'suffix' => $suffix,
            'skip_present' => !empty($post['skip_present']),
            'link_part' => (string) ($post['link_part'] ?? 'label'),
            'display' => [
                $this->pickerDisplay($picker, 'Values'), // @translate
                ['label' => 'Before', 'code' => $prefix], // @translate
                ['label' => 'After', 'code' => $suffix], // @translate
            ],
        ], 'errors' => $errors];
    }

    protected function editRow(array $row, array $params): ?array
    {
        $prefix = (string) $params['prefix'];
        $suffix = (string) $params['suffix'];
        $skip = !empty($params['skip_present']);
        $columns = $this->mapText($row, $params, function ($text) use ($prefix, $suffix, $skip) {
            $new = $text;
            if ($prefix !== '' && !($skip && mb_substr($text, 0, mb_strlen($prefix)) === $prefix)) {
                $new = $prefix . $new;
            }
            if ($suffix !== '' && !($skip && mb_strlen($text) >= mb_strlen($suffix) && mb_substr($text, -mb_strlen($suffix)) === $suffix)) {
                $new .= $suffix;
            }
            return $new;
        });
        return $columns ? ['columns' => $columns] : ['note' => null];
    }
}
