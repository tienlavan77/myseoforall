<?php
/**
 * Evaluates host and optional dependency readiness without loading unavailable APIs.
 *
 * The result is serializable so the admin health screen can explain blockers and
 * warnings without exposing runtime secrets or causing a fatal dependency error.
 */

declare(strict_types=1);

namespace MySeoForAll\Core\Environment;

// Defines EnvironmentCheck class behavior for EnvironmentCheck.php.
final class EnvironmentCheck
{
    /**
     * Returns the minimum platform and optional WooCommerce dependency statuses.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $phpCompatible = PHP_VERSION_ID >= 80000;
        $wordpressLoaded = function_exists('get_bloginfo');
        $woocommerceActive = class_exists('WooCommerce') || defined('WC_VERSION');

        return [
            'php' => [
                'name' => 'PHP',
                'installed' => true,
                'active' => true,
                'compatible' => $phpCompatible,
                'version' => PHP_VERSION,
                'message' => $phpCompatible ? 'PHP tương thích.' : 'Cần PHP 8.0 trở lên.',
            ],
            'wordpress' => [
                'name' => 'WordPress',
                'installed' => $wordpressLoaded,
                'active' => $wordpressLoaded,
                'compatible' => $wordpressLoaded,
                'version' => $wordpressLoaded ? (string) get_bloginfo('version') : null,
                'message' => $wordpressLoaded ? 'WordPress sẵn sàng.' : 'WordPress chưa sẵn sàng.',
            ],
            'woocommerce' => [
                'name' => 'WooCommerce',
                'installed' => $woocommerceActive,
                'active' => $woocommerceActive,
                'compatible' => $woocommerceActive,
                'version' => $woocommerceActive && defined('WC_VERSION') ? WC_VERSION : null,
                'message' => $woocommerceActive
                    ? 'WooCommerce sẵn sàng.'
                    : 'WooCommerce chưa được kích hoạt; tính năng sản phẩm đang tạm unavailable.',
            ],
        ];
    }

    /**
     * Reports whether the mandatory PHP and WordPress platform checks pass.
     */
    public function isReady(): bool
    {
        $statuses = $this->evaluate();

        return $statuses['php']['compatible'] && $statuses['wordpress']['compatible'];
    }
}
