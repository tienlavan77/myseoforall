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
    private $probe;

    /**
     * Accept an optional environment reader so tri-state behavior can be tested without platform globals.
     * The default path remains passive and only calls guarded PHP or WordPress functions.
     * @param callable|mixed|null $probe
     * @return void
     */
    public function __construct($probe = null)
    {
        $this->probe = is_callable($probe) ? $probe : null;
    }

    /**
     * Returns the minimum platform and optional WooCommerce dependency statuses.
     *
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        if (is_callable($this->probe)) {
            $provided = ($this->probe)();
            if (is_array($provided)) {
                return $this->normalizeProbe($provided);
            }
        }

        $phpCompatible = PHP_VERSION_ID >= 80000;
        $wordpressLoaded = function_exists('get_bloginfo');
        $woocommerceActive = class_exists('WooCommerce') || defined('WC_VERSION');
        $mbstringLoaded = extension_loaded('mbstring');

        return [
            'php' => [
                'name' => 'PHP',
                'installed' => true,
                'active' => true,
                'installation' => 'installed',
                'activation' => 'active',
                'compatible' => $phpCompatible,
                'version' => PHP_VERSION,
                'message' => $phpCompatible ? 'PHP tương thích.' : 'Cần PHP 8.0 trở lên.',
            ],
            'wordpress' => [
                'name' => 'WordPress',
                'installed' => $wordpressLoaded,
                'active' => $wordpressLoaded,
                'installation' => $wordpressLoaded ? 'installed' : 'absent',
                'activation' => $wordpressLoaded ? 'active' : 'inactive',
                'compatible' => $wordpressLoaded,
                'version' => $wordpressLoaded ? (string) get_bloginfo('version') : null,
                'message' => $wordpressLoaded ? 'WordPress sẵn sàng.' : 'WordPress chưa sẵn sàng.',
            ],
            'woocommerce' => [
                'name' => 'WooCommerce',
                'installed' => $woocommerceActive ? true : 'unknown',
                'active' => $woocommerceActive,
                'installation' => $woocommerceActive ? 'installed' : 'unknown',
                'activation' => $woocommerceActive ? 'active' : 'unknown',
                'compatible' => $woocommerceActive ? true : 'unknown',
                'version' => $woocommerceActive && defined('WC_VERSION') ? WC_VERSION : null,
                'message' => $woocommerceActive
                    ? 'WooCommerce sẵn sàng.'
                    : 'WooCommerce chưa được xác nhận; tính năng sản phẩm đang tạm unavailable.',
            ],
            'mbstring' => [
                'name' => 'mbstring',
                'installed' => $mbstringLoaded,
                'active' => $mbstringLoaded,
                'installation' => $mbstringLoaded ? 'installed' : 'absent',
                'activation' => $mbstringLoaded ? 'active' : 'inactive',
                'compatible' => true,
                'version' => null,
                'fallback' => !$mbstringLoaded,
                'message' => $mbstringLoaded
                    ? 'mbstring đang bật.'
                    : 'mbstring thiếu; đang dùng fallback UTF-8 deterministic.',
            ],
        ];
    }

    /**
     * Normalize an injected tri-state probe while preserving legacy boolean keys.
     * @param array<string,mixed> $provided
     * @return array<string,array<string,mixed>>
     */
    private function normalizeProbe(array $provided): array
    {
        $result = [];
        foreach (['php', 'wordpress', 'woocommerce', 'mbstring'] as $name) {
            $value = is_array($provided[$name] ?? null) ? $provided[$name] : [];
            $value['name'] = (string) ($value['name'] ?? $name);
            $value['installation'] = in_array($value['installation'] ?? null, ['installed', 'absent', 'unknown'], true)
                ? $value['installation'] : 'unknown';
            $value['activation'] = in_array($value['activation'] ?? null, ['active', 'inactive', 'unknown'], true)
                ? $value['activation'] : 'unknown';
            $value['compatible'] = in_array($value['compatible'] ?? null, [true, false, 'unknown'], true)
                ? $value['compatible'] : 'unknown';
            $value['installed'] = $value['installed'] ?? ($value['installation'] === 'installed');
            $value['active'] = $value['active'] ?? ($value['activation'] === 'active');
            $value['version'] = isset($value['version']) && is_string($value['version']) ? $value['version'] : null;
            $value['message'] = is_string($value['message'] ?? null) ? $value['message'] : 'Không có thông tin môi trường.';
            $result[$name] = $value;
        }
        return $result;
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
