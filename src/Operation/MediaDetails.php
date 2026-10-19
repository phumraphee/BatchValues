<?php
namespace BatchValues\Operation;

/**
 * Edit a detail of media that is not a property value: alt text, original
 * file name (source), media type, language, and the HTML of HTML media.
 */
class MediaDetails extends AbstractMediaOperation
{
    const MODES = [
        'set' => 'Set to', // @translate
        'find_replace' => 'Find and replace', // @translate
        'prefix' => 'Add text before', // @translate
        'suffix' => 'Add text after', // @translate
        'from_title' => 'Take it from the title of the media (or of the item)', // @translate
        'basename' => 'Keep only the file name (last part of a path or URL)', // @translate
        'filename' => 'Keep only the file name, without extension', // @translate
        'clear' => 'Clear it', // @translate
    ];

    public function name(): string
    {
        return 'media_details';
    }

    public function label(): string
    {
        return 'Edit alt text, file name, media type or HTML'; // @translate
    }

    public function help(): string
    {
        return 'Details of the media that are not metadata fields: alt text, original file name, media type, language, and the HTML of HTML media.'; // @translate
    }

    public function fields(): array
    {
        $fields = [];
        if ($this->planner->hasMediaAltText()) {
            $fields['alt_text'] = 'Alt text'; // @translate
        }
        $fields['source'] = 'Original file name or URL (source)'; // @translate
        $fields['media_type'] = 'Media type (for example image/jpeg)'; // @translate
        $fields['lang'] = 'Language of the media'; // @translate
        $fields['html'] = 'HTML (media of the type HTML)'; // @translate
        return $fields;
    }

    public function formVars(array $ctx): array
    {
        return ['kinds' => $this->mediaKinds($ctx['ids'], $ctx), 'fields' => $this->fields(), 'modes' => self::MODES];
    }

    public function parse(array $post, array $ctx): array
    {
        $fields = $this->fields();
        $field = (string) ($post['media_field'] ?? '');
        $mode = (string) ($post['media_mode'] ?? 'set');
        $errors = [];
        if (!isset($fields[$field])) {
            $errors[] = $this->translate('Choose what to edit.');
        }
        if (!isset(self::MODES[$mode])) {
            $mode = 'set';
        }
        $params = [
            'field' => $field,
            'mode' => $mode,
            'text' => (string) ($post['media_text'] ?? ''),
            'find' => (string) ($post['media_find'] ?? ''),
            'replace' => (string) ($post['media_replace'] ?? ''),
            'affix' => (string) ($post['media_affix'] ?? ''),
            'match_case' => !empty($post['media_case']),
            'regex' => !empty($post['media_regex']),
            'only_empty' => !empty($post['only_empty']),
            'kinds' => $this->parseKinds($post),
        ];
        if ($mode === 'from_title' && $field !== 'alt_text') {
            $errors[] = $this->translate('Only the alt text can be taken from the title.');
        }
        if (in_array($mode, ['basename', 'filename'], true) && $field !== 'source') {
            $errors[] = $this->translate('Only the source can be cut to its file name.');
        }
        if ($mode === 'set' && trim($params['text']) === '') {
            $errors[] = $this->translate('Type the new text (or choose “Clear it”).');
        }
        if (in_array($mode, ['prefix', 'suffix'], true) && $params['affix'] === '') {
            $errors[] = $this->translate('Type the text to add.');
        }
        if ($mode === 'find_replace') {
            if ($params['find'] === '') {
                $errors[] = $this->translate('Type the text to find.');
            } elseif (@preg_match(self::pattern($params), '') === false) {
                $errors[] = $this->translate('The regular expression is not valid.');
            }
        }
        if ($field === 'media_type' && $mode === 'clear') {
            $errors[] = $this->translate('A media type cannot be cleared.');
        }
        if ($field === 'media_type' && $mode === 'set' && !preg_match('~^[a-z0-9][\w.+-]*/[\w.+-]+$~i', trim($params['text']))) {
            $errors[] = sprintf($this->translate('“%s” is not a media type (for example image/jpeg, application/pdf).'), $params['text']);
        }
        $params['display'] = [
            $this->kindsDisplay($params),
            ['label' => 'Detail', 'text' => $fields[$field] ?? $field], // @translate
            ['label' => 'Change', 'text' => self::MODES[$mode]], // @translate
        ];
        if ($mode === 'set') {
            $params['display'][] = ['label' => 'New text', 'code' => $params['text']]; // @translate
        } elseif ($mode === 'find_replace') {
            $params['display'][] = ['label' => 'Find', 'code' => $params['find']]; // @translate
            $params['display'][] = ['label' => 'Replace with', 'code' => $params['replace']]; // @translate
        } elseif (in_array($mode, ['prefix', 'suffix'], true)) {
            $params['display'][] = ['label' => 'Text', 'code' => $params['affix']]; // @translate
        }
        if ($params['only_empty']) {
            $params['display'][] = ['label' => 'Where', 'text' => 'Only where it is empty']; // @translate
        }
        return ['params' => $params, 'errors' => $errors];
    }

    public static function pattern(array $params): string
    {
        $body = !empty($params['regex']) ? str_replace('~', '\~', $params['find']) : preg_quote($params['find'], '~');
        return '~' . $body . '~u' . (empty($params['match_case']) ? 'i' : '');
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $field = $params['field'];
        $itemTitles = [];
        if ($params['mode'] === 'from_title') {
            $itemIds = [];
            foreach ($this->mediaRows($ids, $ctx) as $rows) {
                foreach ($rows as $row) {
                    $itemIds[$row['item_id']] = true;
                }
            }
            foreach ($this->planner->resourceInfo(array_keys($itemIds)) as $itemId => $itemInfo) {
                $itemTitles[$itemId] = $itemInfo['title'];
            }
        }
        $purifier = $field === 'html' ? $this->services->get('Omeka\HtmlPurifier') : null;
        return $this->planMedia($ids, $ctx, function (array $rows) use ($params, $field, $itemTitles, $purifier) {
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
                if ($field === 'html' && $row['html'] === null) {
                    // Not an HTML media.
                    continue;
                }
                $matched = true;
                $old = $row[$field] === null ? '' : (string) $row[$field];
                if (!empty($params['only_empty']) && trim($old) !== '') {
                    continue;
                }
                switch ($params['mode']) {
                    case 'set':
                        $new = trim($params['text']);
                        break;
                    case 'clear':
                        $new = '';
                        break;
                    case 'from_title':
                        $new = trim((string) $row['title']) !== '' ? trim((string) $row['title']) : trim((string) ($itemTitles[$row['item_id']] ?? ''));
                        break;
                    case 'prefix':
                        $new = $params['affix'] . $old;
                        break;
                    case 'suffix':
                        $new = $old . $params['affix'];
                        break;
                    case 'basename':
                    case 'filename':
                        $path = str_replace("\\", "/", (string) (parse_url($old, PHP_URL_PATH) ?: $old));
                        $new = $params['mode'] === 'basename' ? basename($path) : pathinfo($path, PATHINFO_FILENAME);
                        break;
                    default:
                        $replace = !empty($params['regex']) ? $params['replace'] : str_replace(['\\', '$'], ['\\\\', '\$'], $params['replace']);
                        $new = (string) preg_replace(self::pattern($params), $replace, $old);
                }
                if ($purifier && $new !== '') {
                    $new = (string) $purifier->purify($new);
                }
                if ($new === $old || ($field === 'media_type' && $new === '')) {
                    continue;
                }
                $changes[$row['id']] = [$field => ['before' => $row[$field], 'after' => $new === '' ? null : $new]];
                $labels[$row['id']] = self::mediaLabel($row);
            }
            if (!$changes) {
                return ['outcome' => $matched ? 'skipped_same' : 'skipped_no_media'];
            }
            return ['outcome' => 'changed', 'resource' => ['media_fields' => ['media' => $changes, 'labels' => $labels]]];
        });
    }
}
