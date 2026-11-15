<?php
/**
 * Encapsulates the WordPress option boundary used by core configuration.
 *
 * Reads and writes remain site-scoped, use one namespace, and degrade safely in
 * isolated unit tests where WordPress functions are not loaded.
 */

declare(strict_types=1);

namespace MySeoForAll\Infrastructure\WordPress;

// Defines OptionStore class behavior for OptionStore.php.
final class OptionStore
{
    /**
     * Reads a site option and returns the supplied fallback outside WordPress.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = false): mixed
    {
        if (! function_exists('get_option')) {
            return $default;
        }

        return get_option($key, $default);
    }

    /**
     * Persists one site option without creating network-wide assumptions.
     */
    public function update(string $key, mixed $value): bool
    {
        if (! function_exists('update_option')) {
            return false;
        }

        return (bool) update_option($key, $value, false);
    }
}
