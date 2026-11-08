<?php
namespace BatchValues\Stdlib;

/**
 * Notes another module adds to a preview (1.1.5). A hook is called with the operation's name, its parameters and the
 * plan (resource id => entry) and answers resource id => one sentence; the sentence is added to that resource's entry
 * when the entry changes something, and shows on the preview and on the run's page like any other note. A note never
 * stops a change. A hook that fails is skipped.
 *
 *   \BatchValues\Stdlib\Notes::add(function (string $op, array $params, array $plan): array { … });
 *
 * Asked by the operations that change which item sets an item is in (item_sets).
 */
class Notes
{
    /** @var callable[] */
    private static $hooks = [];

    public static function add(callable $hook): void
    {
        self::$hooks[] = $hook;
    }

    public static function apply(string $op, array $params, array $plan): array
    {
        foreach (self::$hooks as $hook) {
            try {
                $said = (array) $hook($op, $params, $plan);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($said as $id => $text) {
                $text = trim((string) $text);
                if ($text === '' || !isset($plan[$id]) || ($plan[$id]['outcome'] ?? '') !== 'changed') {
                    continue;
                }
                $plan[$id]['message'] = trim(($plan[$id]['message'] ?? '') . ' ' . $text);
            }
        }
        return $plan;
    }
}
