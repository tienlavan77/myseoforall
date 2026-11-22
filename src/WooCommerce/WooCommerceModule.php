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
    private ?\MySeoForAll\Core\HookDispatcher $dispatcher;
    private $availabilityProbe;
    private bool $lifecycleObserved = false;

    /**
     * Accept the optional composition services while preserving the foundation constructor contract.
     * The dispatcher is used only for a named read-only lifecycle hook.
     * @param \MySeoForAll\Core\HookDispatcher|null $dispatcher
     * @return void
     */
    public function __construct(?\MySeoForAll\Core\HookDispatcher $dispatcher = null, ?callable $availabilityProbe = null)
    {
        $this->dispatcher = $dispatcher;
        $this->availabilityProbe = $availabilityProbe;
    }

    /**
     * Register the product reader when the SEO module has already composed it.
     * No WooCommerce object is instantiated while the dependency is unavailable.
     * @return void
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
        if (!$this->isAvailable() || $this->dispatcher === null || !$this->dispatcher->canDispatch()) {
            return;
        }

        if ($this->dispatcher !== null) {
            $this->dispatcher->addAction('woocommerce_init', [$this, 'onWooCommerceInit']);
        }
    }

    /**
     * Mark the WooCommerce lifecycle as observed without reading or writing product data.
     * @return void
     */
    public function onWooCommerceInit(): void
    {
        $this->lifecycleObserved = true;
    }

    /**
     * Detects active WooCommerce without instantiating unavailable classes.
     */
    public function isAvailable(): bool
    {
        return is_callable($this->availabilityProbe)
            ? (($this->availabilityProbe)() === true)
            : (class_exists('WooCommerce') || defined('WC_VERSION'));
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
            'lifecycle_observed' => $this->lifecycleObserved,
            'message' => $available
                ? 'WooCommerce sẵn sàng.'
                : 'WooCommerce chưa được kích hoạt; tính năng sản phẩm chưa hoạt động.',
        ];
    }
}
