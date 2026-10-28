<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Authority;
use BatchValues\Stdlib\Planner;

/**
 * Fill from an authority (1.1.0): the label of links from the service the
 * link points to, or the link of a text from a Value Suggest service.
 *
 *  - label_missing: links whose label is empty (or is the link itself)
 *  - label_all: every link, its label set to the service's name for it
 *  - uri_missing: values without a link, the link found by their text; a
 *    plain Text value becomes the Value Suggest data type chosen
 *
 * The services are asked during the preview; their answers are kept with
 * the change set (keepFromPreview) and the run asks nothing new, so it
 * writes what the preview showed. Labels are written as values are, through
 * the API, and cancelling sets them back.
 */
class AuthorityValues extends AbstractTextOperation
{
    const MODES = [
        'label_missing' => 'Fill empty labels from the link', // @translate
        'label_all' => 'Set every label to the authority’s name', // @translate
        'uri_missing' => 'Find the link from the text', // @translate
    ];

    /** Why a value was left, for the resource being planned. */
    protected $misses = [];

    public function name(): string
    {
        return 'authority';
    }

    public function label(): string
    {
        return 'Fill from an authority'; // @translate
    }

    public function help(): string
    {
        return 'Fetch the label of links, or the link of a text, from authority services (Library of Congress, Getty, GeoNames, Wikidata… and Value Suggest).'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    protected function usesPicker(): bool
    {
        return true;
    }

    protected function pickerDefault(): string
    {
        return 'all';
    }

    public function syncLimit(int $default): int
    {
        // Each value may wait for a service: small runs only while the page waits.
        return min($default, 20);
    }

    public function formVars(array $ctx): array
    {
        $types = [];
        if (Authority::hasValueSuggest()) {
            $dataTypes = $this->services->get('Omeka\DataTypeManager');
            foreach ($dataTypes->getRegisteredNames() as $name) {
                if (Planner::isValueSuggest($name)) {
                    $types[$name] = $this->translate($dataTypes->get($name)->getLabel());
                }
            }
            asort($types);
        }
        return ['picker' => true, 'pickerAllDefault' => true, 'suggestTypes' => $types, 'known' => Authority::KNOWN];
    }

    public function parse(array $post, array $ctx): array
    {
        $picker = $this->parsePicker($post, $ctx);
        $errors = $picker['error'] ? [$this->translate($picker['error'])] : [];
        $mode = (string) ($post['authority_mode'] ?? '');
        if (!isset(self::MODES[$mode])) {
            $errors[] = $this->translate('Choose what to fill.');
            $mode = 'label_missing';
        }
        $lang = substr(preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($post['authority_lang'] ?? '')), 0, 20);
        $langMode = in_array($post['authority_lang_mode'] ?? 'keep', ['keep', 'set', 'remove'], true) ? (string) ($post['authority_lang_mode'] ?? 'keep') : 'keep';
        if ($langMode === 'set' && $lang === '') {
            $errors[] = $this->translate('Type the language code to give the labels.');
        }
        $target = (string) ($post['authority_target'] ?? '');
        if ($target !== '') {
            $dataTypes = $this->services->get('Omeka\DataTypeManager');
            if (!Planner::isValueSuggest($target) || !$dataTypes->has($target)) {
                $errors[] = $this->translate('Choose a Value Suggest data type for the plain text values.');
                $target = '';
            }
        }
        if ($mode === 'uri_missing' && !Authority::hasValueSuggest()) {
            $errors[] = $this->translate('Finding the link of a text needs the module Value Suggest.');
        }
        $display = [
            $this->pickerDisplay($picker, 'Values'), // @translate
            ['label' => 'Fill', 'text' => self::MODES[$mode]], // @translate
        ];
        if ($lang !== '') {
            $display[] = ['label' => 'Language', 'text' => $lang]; // @translate
        }
        if ($mode === 'uri_missing') {
            $display[] = ['label' => 'Plain text becomes', 'text' => $target !== '' ? $target : 'Left as it is']; // @translate
            if (!empty($post['authority_loose'])) {
                $display[] = ['label' => 'Also', 'text' => 'The only suggestion, even when its wording differs']; // @translate
            }
        } elseif ($langMode !== 'keep') {
            $display[] = ['label' => 'Language of the labels', 'text' => $langMode === 'set' ? $lang : 'Removed']; // @translate
        }
        return ['params' => self::pickerParams($picker) + [
            'mode' => $mode,
            'lang' => $lang,
            'lang_mode' => $mode === 'uri_missing' ? 'keep' : $langMode,
            'target' => $mode === 'uri_missing' ? $target : '',
            'loose' => $mode === 'uri_missing' && !empty($post['authority_loose']),
            'display' => $display,
        ], 'errors' => $errors];
    }

    public function keepFromPreview(array $params): array
    {
        $params['answers'] = $this->authority()->answers();
        return $params;
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        // A change set carries the preview's answers: the run uses them only.
        $this->authority()->preload($params['answers'] ?? [])->freeze(array_key_exists('answers', $params));
        return parent::plan($ids, $params, $ctx);
    }

    protected function planRows(string $key, array $rows, array $params): array
    {
        $this->misses = [];
        $entry = parent::planRows($key, $rows, $params);
        if ($entry['outcome'] === 'skipped_same' && $this->misses) {
            $entry['outcome'] = array_intersect(['limit', 'down', 'later'], $this->misses) ? 'skipped_not_asked' : 'skipped_not_found';
        }
        return $entry;
    }

    protected function editRow(array $row, array $params): ?array
    {
        $mode = $params['mode'] ?? 'label_missing';
        $lang = (string) ($params['lang'] ?? '');
        $type = (string) $row['type'];
        $text = trim((string) $row['value']);
        $uri = trim((string) $row['uri']);
        if ($mode === 'uri_missing') {
            if ($uri !== '' || $text === '') {
                return null;
            }
            if (Planner::isValueSuggest($type)) {
                $use = $type;
            } elseif ($type === 'literal' && ($params['target'] ?? '') !== '') {
                $use = (string) $params['target'];
            } else {
                return null;
            }
            $found = $this->authority()->uri($text, $use, $lang, !empty($params['loose']));
            if ($found['uri'] === null) {
                return $this->miss($found['error'], $text, (int) $found['count']);
            }
            return ['columns' => ['type' => $use, 'uri' => $found['uri']]];
        }
        if (!Authority::isAuthorityType($type) || $uri === '') {
            return null;
        }
        if ($mode === 'label_missing' && $text !== '' && !Authority::sameUri($text, $uri)) {
            return null;
        }
        $found = $this->authority()->label($uri, $type, $text, $lang);
        if ($found['label'] === null) {
            return $this->miss($found['error'], $uri);
        }
        $columns = [];
        if ($found['label'] !== (string) $row['value']) {
            $columns['value'] = $found['label'];
        }
        $langMode = $params['lang_mode'] ?? 'keep';
        $also = null;
        if ($langMode === 'set' && (string) $row['lang'] !== $lang) {
            // Only a name in that language is marked as being in it: the
            // authority's main name, used when it has none, keeps its own.
            if (self::sameLang($found['lang'] ?? null, $lang)) {
                $columns['lang'] = $lang;
            } else {
                $also = sprintf($this->translate('%1$s: no name in “%2$s”, the main name is used and the language left as it was.'), $uri, $lang);
            }
        } elseif ($langMode === 'remove' && (string) $row['lang'] !== '') {
            $columns['lang'] = null;
        }
        if (!$columns) {
            return ['note' => $also];
        }
        return ['columns' => $columns, 'also' => $also];
    }

    /** A label's language is the one asked for ("th" for "th-TH" too). */
    protected static function sameLang(?string $code, string $lang): bool
    {
        if ($code === null || $code === '' || $lang === '') {
            return false;
        }
        $main = function ($c) {
            return explode('-', str_replace('_', '-', strtolower($c)))[0];
        };
        return $main($code) === $main($lang);
    }

    /**
     * A value left as it is, with the reason.
     */
    protected function miss(?string $error, string $what, int $count = 0): array
    {
        $this->misses[] = (string) $error;
        switch ($error) {
            case 'several':
                return ['note' => sprintf($this->translate('“%1$s”: %2$d suggestions have this text, none chosen.'), $what, $count)];
            case 'inexact':
                return ['note' => sprintf($this->translate('“%1$s”: no suggestion has this text (%2$d with other words), none chosen.'), $what, $count)];
            case 'unknown':
                return ['note' => sprintf($this->translate('%s: no service here can read this link.'), $what)];
            case 'limit':
                return ['note' => sprintf($this->translate('Not asked: a preview asks at most %1$d questions, for %2$d seconds. Run it again for the rest.'), Authority::MAX_LOOKUPS, Authority::MAX_SECONDS)];
            case 'down':
                return ['note' => $this->translate('Not asked: the service did not answer.')];
            case 'later':
                return ['note' => $this->translate('Not looked up in the preview (new since): run it again.')];
        }
        return ['note' => sprintf($this->translate('“%s”: the service has nothing for it.'), $what)];
    }

    protected function authority(): Authority
    {
        return $this->services->get(Authority::class);
    }
}
