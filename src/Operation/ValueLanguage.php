<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;

class ValueLanguage extends AbstractOperation
{
    /** Data types whose language is meaningful (and Value Suggest). */
    const TYPES = ['literal', 'uri'];

    public static function hasLanguage(?string $type): bool
    {
        return in_array((string) $type, self::TYPES, true) || \BatchValues\Stdlib\Planner::isValueSuggest($type);
    }

    public function name(): string
    {
        return 'language';
    }

    public function label(): string
    {
        return 'Set, change or remove the language'; // @translate
    }

    public function help(): string
    {
        return 'The language code of values (th, en…): set it, remove it, or change one code to another.'; // @translate
    }

    public function allowsAllProperties(): bool
    {
        return true;
    }

    public function formVars(array $ctx): array
    {
        return ['picker' => true, 'languages' => $this->languages($ctx)];
    }

    /**
     * Language codes found in the property among the selection, with counts
     * ("" for values without one), text and URI values only.
     */
    public function languages(array $ctx): array
    {
        $counts = [];
        foreach ($this->planner->valuesByResource($ctx['ids'], $ctx['property_id']) as $rows) {
            foreach ($rows as $row) {
                if (self::hasLanguage($row['type'])) {
                    $code = (string) $row['lang'];
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        }
        uksort($counts, function ($a, $b) use ($counts) {
            return $counts[$b] <=> $counts[$a] ?: strcmp($a, $b);
        });
        return $counts;
    }

    public function parse(array $post, array $ctx): array
    {
        $mode = ($post['lang_mode'] ?? 'chosen') === 'code' ? 'code' : 'chosen';
        $errors = [];
        $display = [];
        $params = ['mode' => $mode];
        if ($mode === 'code') {
            $from = (string) ($post['from_lang'] ?? '');
            $params += ['all' => true, 'keys' => [], 'from' => $from === '(none)' ? '' : $from];
            if (!array_key_exists('from_lang', $post)) {
                $errors[] = $this->translate('Choose the language code to change.');
            }
            $display[] = ['label' => 'Values', 'text' => $params['from'] === '' ? 'All values without a language' : sprintf($this->translate('All values in “%s”'), $params['from'])]; // @translate
        } else {
            $picker = $this->parsePicker($post, $ctx);
            if ($picker['error']) {
                $errors[] = $this->translate($picker['error']);
            }
            $params += self::pickerParams($picker);
            $display[] = $this->pickerDisplay($picker, 'Values'); // @translate
        }
        $lang = trim((string) ($post['lang'] ?? ''));
        if ($lang !== '' && !preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $lang)) {
            $errors[] = sprintf($this->translate('"%s" does not look like a language code (for example th, en, en-US).'), $lang);
        }
        $params['lang'] = $lang;
        $display[] = ['label' => 'Language', 'text' => $lang !== '' ? $lang : 'removed']; // @translate
        $params['display'] = $display;
        return ['params' => $params, 'errors' => $errors];
    }

    protected function picked(array $row, array $params): bool
    {
        if (($params['mode'] ?? 'chosen') === 'code') {
            return (string) $row['lang'] === (string) ($params['from'] ?? '');
        }
        return parent::picked($row, $params);
    }

    public function plan(array $ids, array $params, array $ctx): array
    {
        $info = $this->planner->resourceInfo($ids);
        $existing = $this->planner->valuesByResource($ids, $ctx['property_id']);
        $lang = $params['lang'] !== '' ? $params['lang'] : null;
        $plan = [];
        foreach ($ids as $id) {
            if (!isset($info[$id])) {
                $plan[$id] = self::missing();
                continue;
            }
            $key = Planner::groupKey($info[$id]['template_id']);
            $pairs = [];
            $matched = false;
            $wrongType = 0;
            foreach ($existing[$id] ?? [] as $row) {
                if (!$this->picked($row, $params)) {
                    continue;
                }
                $matched = true;
                if (!self::hasLanguage($row['type'])) {
                    ++$wrongType;
                    continue;
                }
                if ((string) $row['lang'] === (string) $lang) {
                    continue;
                }
                $before = Planner::snapshotFromRow($row);
                $pairs[] = ['value_id' => (int) $row['id'], 'before' => $before, 'after' => Planner::withColumns($before, ['lang' => $lang])];
            }
            $message = $wrongType ? sprintf($this->translate('%d value(s) left as they are: their data type has no language.'), $wrongType) : null;
            $outcome = $pairs ? 'changed' : ($wrongType ? 'skipped_type' : ($matched ? 'skipped_same' : 'skipped_no_match'));
            $plan[$id] = self::entry($key, $outcome, $pairs, ['message' => $message]);
        }
        return $plan;
    }
}
