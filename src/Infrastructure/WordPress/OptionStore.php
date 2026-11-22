<?php

declare(strict_types=1);

namespace MySeoForAll\Infrastructure\WordPress;

/**
 * Provides a small, injectable site-option boundary so domain code can be tested without defining WordPress globals.
 */
final class OptionStore
{
    /**
     * @param callable(string,mixed):mixed|null $reader
     * @param callable(string,mixed,bool):bool|null $writer
     * @param callable(string):bool|null $deleter
     * @param callable(string,mixed,mixed):bool|null $cas
     */
    public function __construct(
        private readonly mixed $reader = null,
        private readonly mixed $writer = null,
        private readonly mixed $deleter = null,
        private readonly mixed $cas = null,
    ) {
    }

    /**
     * Read one site option through the injected seam or the WordPress API.
     */
    public function get(string $key, mixed $default = false): mixed
    {
        if (is_callable($this->reader)) {
            return ($this->reader)($key, $default);
        }
        if (!function_exists('get_option')) {
            return $default;
        }
        return get_option($key, $default);
    }

    /**
     * Persist one non-autoloaded option, creating it with add_option when available.
     */
    public function update(string $key, mixed $value): bool
    {
        if (is_callable($this->writer)) {
            return (bool) ($this->writer)($key, $value, false);
        }
        if (function_exists('add_option') && function_exists('get_option')) {
            $missing = '__myseo_missing_option__';
            $current = get_option($key, $missing);
            if ($current === $missing) {
                return (bool) add_option($key, $value, '', false);
            }
            if ($current === $value) {
                return true;
            }
        }
        if (!function_exists('update_option')) {
            return false;
        }
        return (bool) update_option($key, $value, false);
    }

    /** Report whether an atomic compare-and-swap seam was supplied. */
    public function hasCas(): bool
    {
        return is_callable($this->cas);
    }

    /**
     * Compare and swap an option when the platform seam supports it.
     */
    public function compareAndSwap(string $key, mixed $expected, mixed $value): bool
    {
        if (is_callable($this->cas)) {
            return (bool) ($this->cas)($key, $expected, $value);
        }
        return false;
    }

    /**
     * Delete one option through the injected seam or the WordPress API.
     */
    public function delete(string $key): bool
    {
        if (is_callable($this->deleter)) {
            return (bool) ($this->deleter)($key);
        }
        return function_exists('delete_option') && (bool) delete_option($key);
    }
}
