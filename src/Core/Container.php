<?php
/**
 * Provides a small composition-root container for foundation services.
 *
 * The container owns construction-time objects only; application classes receive
 * their dependencies explicitly and do not use this class as a global locator.
 */

declare(strict_types=1);

namespace MySeoForAll\Core;

use RuntimeException;

// Defines Container class behavior for Container.php.
final class Container
{
    /** @var array<string, object> */
    private array $services = [];

    /**
     * Stores one service under its stable composition-root identifier.
     */
    public function set(string $id, object $service): void
    {
        if (isset($this->services[$id]) && $this->services[$id] !== $service) {
            throw new \LogicException("Service already registered with a different instance: {$id}");
        }

        $this->services[$id] = $service;
    }

    /**
     * Returns a previously composed service or fails with a clear configuration error.
     */
    public function get(string $id): object
    {
        if (! isset($this->services[$id])) {
            throw new RuntimeException("Service not registered: {$id}");
        }

        return $this->services[$id];
    }

    /**
     * Reports whether the composition root has registered a service identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
