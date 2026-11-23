<?php
/*
 * Provides a small WordPress hook boundary that records registrations and
 * suppresses duplicate listener registration within a request-scoped dispatcher.
 */

declare(strict_types=1);

namespace MySeoForAll\Core;

/**
 * Runtime hook registrar used by the plugin composition root.
 */
final class HookDispatcher
{
    /** @var array<string, array{hook:string, listener_key:string, priority:int, accepted_args:int}> */
    private array $registrations = [];

    private static ?self $shared = null;
    private $forwarder;

    /**
     * Configure an optional hook forwarder while keeping the default WordPress bridge guarded.
     * @param callable|null $forwarder
     * @return void
     */
    public function __construct(?callable $forwarder = null)
    {
        $this->forwarder = $forwarder;
    }

    /**
     * Report whether this dispatcher can forward a hook in the current runtime.
     * @return bool
     */
    public function canDispatch(): bool
    {
        return is_callable($this->forwarder) || function_exists('add_action');
    }

    /**
     * Return the process-local dispatcher used by default plugin instances.
     * The shared object makes duplicate protection apply across plugin instances in one request.
     * @return self
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Clear the default dispatcher so an isolated request or test can start cleanly.
     * This does not alter WordPress registrations that have already been forwarded.
     * @return void
     */
    public static function resetShared(): void
    {
        self::$shared = null;
    }

    /**
     * Record one hook and forward it to WordPress when the API is available.
     * The returned boolean is false only when this dispatcher already recorded the same key.
     * @param callable|array{0:object|string,1:string} $listener
     * @return bool
     */
    public function addAction(string $hook, callable|array $listener, int $priority = 10, int $acceptedArgs = 1): bool
    {
        if ($listener instanceof \Closure) {
            throw new \InvalidArgumentException('Anonymous hook listeners are not supported.');
        }
        if (!$this->canDispatch()) {
            return false;
        }
        $key = $this->listenerKey($listener);
        $registrationKey = $hook . '|' . $key . '|' . $priority;
        if (isset($this->registrations[$registrationKey])) {
            return false;
        }

        $this->registrations[$registrationKey] = [
            'hook' => $hook,
            'listener_key' => $key,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];

        if (is_callable($this->forwarder)) {
            ($this->forwarder)($hook, $listener, $priority, $acceptedArgs);
        } elseif (function_exists('add_action')) {
            add_action($hook, $listener, $priority, $acceptedArgs);
        }

        return true;
    }

    /**
     * Return the deterministic registration snapshot for diagnostics and spies.
     * The snapshot contains no callable objects or source payloads.
     * @return list<array{hook:string, listener_key:string, priority:int, accepted_args:int}>
     */
    public function registrations(): array
    {
        return array_values($this->registrations);
    }

    /**
     * Convert a listener into a stable key without invoking or reflecting it.
     * Object methods are keyed by class and method so equivalent instances deduplicate.
     * @param callable|array{0:object|string,1:string} $listener
     * @return string
     */
    private function listenerKey(callable|array $listener): string
    {
        if (is_array($listener) && count($listener) === 2) {
            $target = $listener[0];
            $name = is_object($target) ? $target::class : (string) $target;
            return $name . '::' . (string) $listener[1];
        }

        if ($listener instanceof \Closure) {
            return 'closure:' . spl_object_id($listener);
        }

        return is_string($listener) ? $listener : 'callable:' . spl_object_id((object) $listener);
    }
}
