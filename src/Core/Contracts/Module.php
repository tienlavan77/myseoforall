<?php
/**
 * Defines the lifecycle contract shared by independent plugin modules.
 */
namespace MySeoForAll\Core\Contracts;

/**
 * Provides registration, boot and health hooks for a plugin module.
 */
interface Module
{
    /**
     * Registers services owned by the module.
     */
    public function register(): void;

    /**
     * Registers WordPress hooks owned by the module.
     */
    public function boot(): void;

    /**
     * Reports whether the module can provide its behavior.
     */
    public function isAvailable(): bool;

    /**
     * Returns a serializable health status for the module.
     *
     * @return array<string, mixed>
     */
    public function health(): array;
}
