<?php
namespace BatchValues\Operation;

/**
 * Find a piece of text inside values and replace it, like "Find and replace"
 * in a word processor. Optionally a regular expression.
 */
class FindReplaceValues extends AbstractTextOperation
{
    public function name(): string
    {
        return 'find_replace';
    }

    public function label(): string
    {
        return 'Find and replace text'; // @translate
    }

    public function help(): string
    {
        return 'Change part of the text inside values, like Find and replace in Word.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function parse(array $post, array $ctx): array
    {
        $find = (string) ($post['find'] ?? '');
        $replace = (string) ($post['replace'] ?? '');
        $regex = !empty($post['regex']);
        $matchCase = !empty($post['match_case']);
        $errors = [];
        $action = (in_array($post['action'] ?? 'replace', ['replace', 'basename', 'filename', 'remove'], true) ? ($post['action'] ?? 'replace') : 'replace');
        if ($find === '' && in_array($action, ['replace', 'remove'], true)) {
            $errors[] = $this->translate('Type the text to find.');
        }
        $params = [
            'action' => $action,
            'entities' => !empty($post['entities']),
            'find' => $find,
            'replace' => $replace,
            'regex' => $regex,
            'match_case' => $matchCase,
            'trim' => !empty($post['trim']),
            'remove_empty' => !empty($post['remove_empty']),
            'link_part' => (string) ($post['link_part'] ?? 'label'),
        ];
        if ($find !== '' && @preg_match(self::pattern($params), '') === false) {
            $errors[] = sprintf($this->translate('The regular expression is not valid: %s'), $find);
        }
        $options = [];
        $options[] = $matchCase ? 'Match case' : 'Ignore case'; // @translate
        if ($regex) {
            $options[] = 'Regular expression'; // @translate
        }
        if ($params['entities']) {
            $options[] = 'Also written with HTML entities'; // @translate
        }
        $actions = [
            'replace' => null,
            'basename' => 'Keep only the file name', // @translate
            'filename' => 'Keep only the file name, without extension', // @translate
            'remove' => 'Remove the whole value', // @translate
        ];
        $params['display'] = [['label' => 'Find', 'code' => $find]]; // @translate
        if ($action === 'replace') {
            $params['display'][] = ['label' => 'Replace with', 'code' => $replace]; // @translate
        } else {
            $params['display'][] = ['label' => 'Instead', 'text' => $actions[$action]]; // @translate
        }
        $params['display'][] = ['label' => 'Options', 'text' => implode(' · ', array_map([$this, 'translate'], $options))]; // @translate
        return ['params' => $params, 'errors' => $errors];
    }

    public static function pattern(array $params): string
    {
        if ((string) $params['find'] === '') {
            // Basename / file name of every value.
            return '~^~u';
        }
        $body = !empty($params['regex']) ? str_replace('~', '\~', $params['find']) : preg_quote($params['find'], '~');
        if (empty($params['regex']) && !empty($params['entities'])) {
            // The same text as it may have been saved with HTML entities
            // ("&amp;" for "&", "&quot;"…).
            $encoded = htmlspecialchars($params['find'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $numeric = htmlspecialchars($params['find'], ENT_QUOTES | ENT_HTML401, 'UTF-8');
            $body = '(?:' . implode('|', array_unique([$body, preg_quote($encoded, '~'), preg_quote($numeric, '~')])) . ')';
        }
        return '~' . $body . '~u' . (empty($params['match_case']) ? 'i' : '');
    }

    protected function editRow(array $row, array $params): ?array
    {
        $pattern = self::pattern($params);
        $action = $params['action'] ?? 'replace';
        if ($action !== 'replace') {
            $found = false;
            foreach (['value', 'uri'] as $column) {
                if ($row[$column] !== null && preg_match($pattern, (string) $row[$column])) {
                    $found = true;
                }
            }
            if (!$found) {
                return null;
            }
            if ($action === 'remove') {
                return ['remove' => true];
            }
            $columns = $this->mapText($row, $params, function ($text) use ($action) {
                $path = str_replace("\\", "/", (string) (parse_url($text, PHP_URL_PATH) ?: $text));
                return $action === 'basename' ? basename($path) : pathinfo($path, PATHINFO_FILENAME);
            });
            return $columns ? $this->emptyCheck($row, $columns, $params) : ['note' => null];
        }
        $replace = !empty($params['regex'])
            ? (string) $params['replace']
            : str_replace(['\\', '$'], ['\\\\', '\$'], (string) $params['replace']);
        $found = false;
        $columns = $this->mapText($row, $params, function ($text) use ($pattern, $replace, $params, &$found) {
            if (!preg_match($pattern, $text)) {
                return null;
            }
            $found = true;
            $new = preg_replace($pattern, $replace, $text);
            if ($new === null) {
                return null;
            }
            return !empty($params['trim']) ? trim($new) : $new;
        });
        if (!$found) {
            return null;
        }
        if (!$columns) {
            return ['note' => null];
        }
        return $this->emptyCheck($row, $columns, $params);
    }

    /**
     * A value whose text becomes empty is removed when asked, else left as it
     * is with a note.
     */
    protected function emptyCheck(array $row, array $columns, array $params): array
    {
        $main = \BatchValues\Stdlib\Planner::mainColumn($row);
        $mainText = array_key_exists($main, $columns) ? $columns[$main] : $row[$main];
        if (trim((string) $mainText) === '') {
            if (!empty($params['remove_empty'])) {
                return ['remove' => true];
            }
            return ['note' => sprintf($this->translate('“%s” would become empty: left as it is.'), self::short($row))];
        }
        return ['columns' => $columns];
    }
}
