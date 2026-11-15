<?php
/**
 * Isolates WooCommerce availability and health reporting from the core bootstrap.
 *
 * The module never references WooCommerce classes until availability is confirmed,
 * allowing the plugin foundation to run without a fatal error when it is inactive.
 */

declare(strict_types=1);

namespace MySeoForAll\WooCommerce;

use MySeoForAll\Core\Contracts\Module;

// Defines WooCommerceModule class behavior for WooCommerceModule.php.
final class WooCommerceModule implements Module
{
    /**
     * Registers no WooCommerce service until the dependency is active.
     */
    public function register(): void
    {
        // Future product abstractions belong here once WooCommerce is available.
    }

    /**
     * Registers no hooks when WooCommerce is unavailable, preserving safe bootstrap behavior.
     */
    public function boot(): void
    {
        if (! $this->isAvailable() || ! function_exists('add_action')) {
            return;
        }

        add_action('woocommerce_init', static function (): void {
            // Product services will be attached by a later feature module.
        });
    }

    /**
     * Detects active WooCommerce without instantiating unavailable classes.
     */
    public function isAvailable(): bool
    {
        return class_exists('WooCommerce') || defined('WC_VERSION');
    }

    /**
     * Returns dependency status suitable for the Health Check screen.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $available = $this->isAvailable();

        return [
            'name' => 'woocommerce',
            'available' => $available,
            'message' => $available
                ? 'WooCommerce sẵn sàng.'
                : 'WooCommerce chưa được kích hoạt; tính năng sản phẩm chưa hoạt động.',
        ];
    }
}
