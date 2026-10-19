<?php
namespace BatchValues\Operation;

use BatchValues\Stdlib\Planner;
use Interop\Container\ContainerInterface;

/**
 * One kind of batch change.
 *
 * An operation parses its form into params (stored in the log), then plans
 * per resource what would change. A plan entry is:
 *
 *   ['group' => template group key, 'outcome' => string,
 *    'pairs' => [['value_id' => ?int, 'before' => ?snapshot, 'after' => ?snapshot]],
 *    'objects' => [value objects to append through the API] (add only),
 *    'resource' => [field => change] (resource operations),
 *    'message' => ?string]
 *
 * A pair with only "after" adds a value, only "before" removes the value
 * value_id, both modifies value_id in place (keeping its id, position and
 * annotation). Planning never writes.
 */
abstract class AbstractOperation
{
    /**
     * @var ContainerInterface
     */
    protected $services;

    /**
     * @var Planner
     */
    protected $planner;

    public function __construct(ContainerInterface $services, Planner $planner)
    {
        $this->services = $services;
        $this->planner = $planner;
    }

    abstract public function name(): string;

    abstract public function label(): string;

    /** Short explanation shown in the operation menu. */
    abstract public function help(): string;

    /** "values" or "resource". */
    public function group(): string
    {
        return 'values';
    }

    public function needsProperty(): bool
    {
        return $this->group() === 'values';
    }

    public function resourceTypes(): array
    {
        return ['items', 'item_sets', 'media'];
    }

    /**
     * Whether the current user may use it on this kind of resource. Every
     * change is still authorized by the API; this only keeps out of the menu
     * what would fail for every resource.
     */
    public function isAllowed(string $resourceType): bool
    {
        return true;
    }

    /**
     * Whether it can work on all properties at once ("All properties" in the
     * property menu). The operation is then planned property by property.
     */
    public function allowsAllProperties(): bool
    {
        return false;
    }

    /**
     * Plan, for one property or, with $ctx['all_properties'], for every
     * property the resources have values in, one after the other.
     */
    public function planAll(array $ids, array $params, array $ctx): array
    {
        if (empty($ctx['all_properties'])) {
            return $this->plan($ids, $params, $ctx);
        }
        $propertyIds = $this->services->get('Omeka\\Connection')->fetchFirstColumn(
            'SELECT DISTINCT property_id FROM value WHERE resource_id IN (?) ORDER BY property_id',
            [$ids],
            [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
        );
        $merged = [];
        foreach ($propertyIds as $propertyId) {
            $one = $this->plan($ids, $params, ['property_id' => (int) $propertyId, 'all_properties' => false] + $ctx);
            foreach ($one as $id => $entry) {
                $merged[$id] = isset($merged[$id]) ? self::mergeEntries($merged[$id], $entry) : $entry;
            }
        }
        foreach ($ids as $id) {
            if (!isset($merged[$id])) {
                $info = $this->planner->resourceInfo([$id]);
                $merged[$id] = isset($info[$id]) ? self::entry(Planner::groupKey($info[$id]['template_id']), 'skipped_no_match') : self::missing();
            }
        }
        return $merged;
    }

    /**
     * One entry from the entries of two properties of the same resource.
     */
    protected static function mergeEntries(array $a, array $b): array
    {
        $effective = ['added', 'removed', 'changed'];
        $aOn = in_array($a['outcome'], $effective, true);
        $bOn = in_array($b['outcome'], $effective, true);
        $messages = array_filter([$a['message'] ?? null, $b['message'] ?? null]);
        $message = $messages ? implode(' ', array_unique($messages)) : null;
        if (!$aOn && !$bOn) {
            // Keep the most telling reason: "not present" only when both say so.
            $outcome = $a['outcome'] === 'skipped_no_match' ? $b['outcome'] : $a['outcome'];
            return ['outcome' => $outcome, 'message' => $message] + $a;
        }
        if ($aOn !== $bOn) {
            $entry = $aOn ? $a : $b;
            $entry['message'] = $message;
            return $entry;
        }
        $entry = $a;
        $entry['pairs'] = array_merge($a['pairs'] ?? [], $b['pairs'] ?? []);
        $entry['outcome'] = $a['outcome'] === $b['outcome'] ? $a['outcome'] : 'changed';
        $entry['message'] = $message;
        if (!empty($a['sorted']) || !empty($b['sorted'])) {
            $entry['sorted'] = true;
        }
        return $entry;
    }

    /** Whether its changes can be cancelled afterwards. */
    public function reversible(): bool
    {
        return true;
    }

    /**
     * How many resources are done at once, while the page waits, before the
     * run goes to a background job. Work on files uses a lower number.
     */
    public function syncLimit(int $default): int
    {
        return $default;
    }

    /** Outcome of a resource that is changed. */
    public function effectiveOutcome(): string
    {
        return 'changed';
    }

    /**
     * Variables for the form partial "batch-values/admin/op/<name>".
     */
    public function formVars(array $ctx): array
    {
        return [];
    }

    /**
     * @return array ['params' => array, 'errors' => string[]]
     */
    abstract public function parse(array $post, array $ctx): array;

    /**
     * @return array resource id => entry
     */
    abstract public function plan(array $ids, array $params, array $ctx): array;

    /**
     * Lines describing the params for preview and log pages:
     * [['label' => string, 'snapshots' => array] | ['label' => string, 'text' => string]].
     */
    public function describe(array $params, array $ctx): array
    {
        if (isset($params['display'])) {
            return $params['display'];
        }
        // Change sets written by 0.1–0.3.
        $lines = [];
        foreach ($params['groups'] ?? [] as $group) {
            if (empty($group['skip']) && !empty($group['snapshots'])) {
                $lines[] = ['label' => $group['template_label'] ?? 'No template', 'snapshots' => $group['snapshots']]; // @translate
            }
        }
        if (!empty($params['snapshots'])) {
            $lines[] = ['label' => 'Values', 'snapshots' => $params['snapshots']]; // @translate
        }
        return $lines;
    }

    // ------------------------------------------------------------------
    // Helpers for value operations
    // ------------------------------------------------------------------

    /**
     * The values used when the form says nothing: "list" (the ones checked)
     * or "all".
     */
    protected function pickerDefault(): string
    {
        return 'list';
    }

    /**
     * Read the value picker: values checked in the list, all values, or the
     * values that match a rule.
     *
     * @return array ['all' => bool, 'keys' => string[], 'match' => ?array, 'snapshots' => array, 'error' => ?string]
     */
    protected function parsePicker(array $post, array $ctx): array
    {
        $mode = (string) ($post['pick_mode'] ?? '');
        if ($mode === '') {
            // Forms and hand-overs written before 0.6.
            $mode = !empty($post['all_values']) ? 'all' : (empty($post['keys']) ? $this->pickerDefault() : 'list');
        }
        if (!empty($ctx['all_properties']) && $mode === 'list') {
            $mode = 'all';
        }
        $result = ['all' => false, 'keys' => [], 'match' => null, 'snapshots' => [], 'error' => null];
        if ($mode === 'all') {
            $result['all'] = true;
            return $result;
        }
        if ($mode === 'match') {
            $match = [
                'text' => (string) ($post['match_text'] ?? ''),
                'case' => !empty($post['match_case']),
                'whole' => !empty($post['match_whole']),
                'regex' => !empty($post['match_regex']),
                'types' => array_values(array_filter(array_map('strval', (array) ($post['match_types'] ?? [])), 'strlen')),
                'lang' => (string) ($post['match_lang'] ?? '*'),
                'visibility' => (in_array($post['match_visibility'] ?? 'any', ['public', 'private'], true) ? ($post['match_visibility'] ?? 'any') : 'any'),
            ];
            if ($match['text'] === '' && !$match['types'] && $match['lang'] === '*' && $match['visibility'] === 'any') {
                $result['error'] = 'Give at least one part of the rule (text, data type, language or visibility), or choose all values.'; // @translate
            } elseif ($match['regex'] && $match['text'] !== '' && @preg_match(self::matchPattern($match), '') === false) {
                $result['error'] = 'The regular expression is not valid.'; // @translate
            }
            $result['match'] = $match;
            return $result;
        }
        $keys = array_values(array_filter((array) ($post['keys'] ?? []), 'is_string'));
        if (!$keys) {
            $result['error'] = 'Select at least one value.'; // @translate
            return $result;
        }
        $result['keys'] = $keys;
        $distinct = $this->planner->distinctValues($ctx['ids'], $ctx['property_id']);
        $connection = $this->services->get('Omeka\Connection');
        foreach ($keys as $key) {
            if (isset($distinct[$key])) {
                $row = $connection->fetchAssociative('SELECT * FROM value WHERE id = ?', [$distinct[$key]['sample_value_id']]);
                if ($row) {
                    $s = Planner::snapshotFromRow($row);
                    unset($s['value_id']);
                    $result['snapshots'][] = $s;
                }
            }
        }
        return $result;
    }

    public static function matchPattern(array $match): string
    {
        $body = !empty($match['regex']) ? str_replace('~', '\~', $match['text']) : preg_quote($match['text'], '~');
        if (!empty($match['whole'])) {
            $body = '^(?:' . $body . ')$';
        }
        return '~' . $body . '~u' . (empty($match['case']) ? 'i' : '');
    }

    /**
     * Params of the picker, to merge into an operation's params.
     */
    protected static function pickerParams(array $picker): array
    {
        return ['all' => $picker['all'], 'keys' => $picker['keys'], 'match' => $picker['match']];
    }

    protected function pickerDisplay(array $picker, string $label): array
    {
        if ($picker['all']) {
            return ['label' => $label, 'text' => 'All values of this property']; // @translate
        }
        if (!empty($picker['match'])) {
            return ['label' => $label, 'text' => $this->matchText($picker['match'])];
        }
        return ['label' => $label, 'snapshots' => $picker['snapshots']];
    }

    /**
     * The rule in words, for preview and log pages.
     */
    public function matchText(array $match): string
    {
        $parts = [];
        if ($match['text'] !== '') {
            $parts[] = sprintf(
                $match['regex'] ? $this->translate('matching the pattern “%s”') : ($match['whole'] ? $this->translate('equal to “%s”') : $this->translate('containing “%s”')),
                $match['text']
            ) . ($match['case'] ? ' ' . $this->translate('(match case)') : '');
        }
        if ($match['types']) {
            $labels = [];
            $dataTypes = $this->services->get('Omeka\DataTypeManager');
            foreach ($match['types'] as $type) {
                $labels[] = $dataTypes->has($type) ? $this->translate($dataTypes->get($type)->getLabel()) : $type;
            }
            $parts[] = sprintf($this->translate('of data type %s'), implode(', ', $labels));
        }
        if ($match['lang'] !== '*') {
            $parts[] = $match['lang'] === '' ? $this->translate('without language') : sprintf($this->translate('in language “%s”'), $match['lang']);
        }
        if ($match['visibility'] !== 'any') {
            $parts[] = $match['visibility'] === 'public' ? $this->translate('public') : $this->translate('private');
        }
        return sprintf($this->translate('Values %s'), implode(', ', $parts));
    }

    protected function picked(array $row, array $params): bool
    {
        // Exact value ids, given by a check that found those values (0.9.0).
        if (!empty($params['value_ids'])) {
            static $ids = [];
            $hash = md5(json_encode($params['value_ids']));
            if (!isset($ids[$hash])) {
                $ids[$hash] = array_fill_keys(array_map('intval', $params['value_ids']), true);
            }
            return isset($ids[$hash][(int) ($row['id'] ?? 0)]);
        }
        if (!empty($params['all'])) {
            return true;
        }
        if (!empty($params['match'])) {
            return self::matches($row, $params['match']);
        }
        static $index = [];
        $hash = md5(json_encode($params['keys'] ?? []));
        if (!isset($index[$hash])) {
            $index[$hash] = array_fill_keys($params['keys'] ?? [], true);
        }
        // Keys from the picker carry the visibility; keys stored by 0.1–0.4.0
        // do not and match both.
        return isset($index[$hash][Planner::pickKey($row)]) || isset($index[$hash][Planner::rowKey($row, true)]);
    }

    public static function matches(array $row, array $match): bool
    {
        if ($match['types'] && !in_array($row['type'], $match['types'], true)) {
            return false;
        }
        if ($match['lang'] !== '*' && (string) $row['lang'] !== $match['lang']) {
            return false;
        }
        if ($match['visibility'] === 'public' && !$row['is_public']) {
            return false;
        }
        if ($match['visibility'] === 'private' && $row['is_public']) {
            return false;
        }
        if ($match['text'] === '') {
            return true;
        }
        $pattern = self::matchPattern($match);
        foreach (['value', 'uri'] as $column) {
            if ($row[$column] !== null && preg_match($pattern, (string) $row[$column])) {
                return true;
            }
        }
        return false;
    }

    protected static function missing(): array
    {
        return ['group' => null, 'outcome' => 'skipped_missing'];
    }

    protected static function entry(?string $group, string $outcome, array $pairs = [], array $extra = []): array
    {
        return ['group' => $group, 'outcome' => $outcome, 'pairs' => $pairs] + $extra;
    }

    protected function translate(string $text): string
    {
        return $this->services->get('MvcTranslator')->translate($text);
    }
}
