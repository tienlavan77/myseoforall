<?php
/**
 * Registers the Vietnamese settings and health screen for site administrators.
 *
 * Hooks are attached only when WordPress is loaded, and every rendered value is
 * escaped at output so the foundation remains safe before feature modules exist.
 */

declare(strict_types=1);

namespace MySeoForAll\Admin;

use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Contracts\Module;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Infrastructure\WordPress\OptionStore;

// Defines AdminModule class behavior for AdminModule.php.
final class AdminModule implements Module
{
    /**
     * Captures the configuration, environment checker, and option boundary owned by admin screens.
     */
    private $healthProvider;
    private ?\MySeoForAll\Core\HookDispatcher $dispatcher;

    /**
     * Capture configuration, environment, options, and optional diagnostic wiring.
     * The provider returns an already-redacted snapshot and is never used during settings persistence.
     * @param Configuration $configuration
     * @param EnvironmentCheck $environment
     * @param OptionStore $options
     * @param callable|null $healthProvider
     * @param \MySeoForAll\Core\HookDispatcher|null $dispatcher
     * @return void
     */
    public function __construct(
        private Configuration $configuration,
        private EnvironmentCheck $environment,
        private OptionStore $options,
        $healthProvider = null,
        ?\MySeoForAll\Core\HookDispatcher $dispatcher = null
    ) {
        $this->healthProvider = is_callable($healthProvider) ? $healthProvider : null;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Registers the module's settings service boundary for future admin features.
     */
    public function register(): void
    {
        // Configuration is composed by the root; this hook keeps the lifecycle explicit.
    }

    /**
     * Registers settings and menu hooks when the WordPress admin API is available.
     */
    public function boot(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        if ($this->dispatcher !== null) {
            $this->dispatcher->addAction('admin_menu', [$this, 'registerMenu']);
            $this->dispatcher->addAction('admin_init', [$this, 'registerSettings']);
        }
    }

    /**
     * Reports whether the admin module can provide its settings and health screens.
     */
    public function isAvailable(): bool
    {
        return function_exists('add_action');
    }

    /**
     * Returns the module health state without exposing configuration secrets.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [
            'name' => 'admin',
            'available' => $this->isAvailable(),
            'message' => $this->isAvailable() ? 'Settings và Health Check sẵn sàng.' : 'WordPress admin chưa sẵn sàng.',
            'write_allowed' => false,
            'frontend_owned' => false,
        ];
    }

    /**
     * Adds the site-scoped settings page with the configured capability policy.
     */
    public function registerMenu(): void
    {
        if (! function_exists('add_options_page')) {
            return;
        }

        add_options_page(
            'My SEO For All',
            'My SEO For All',
            'manage_options',
            'myseo-for-all',
            [$this, 'renderPage']
        );
    }

    /**
     * Registers the namespaced option with WordPress settings APIs.
     */
    public function registerSettings(): void
    {
        if (! function_exists('register_setting')) {
            return;
        }

        register_setting('myseo_for_all', Configuration::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
            'default' => Configuration::defaults(),
        ]);
    }

    /**
     * Sanitizes only the foundation settings fields before persistence.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function sanitizeSettings(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $configuration = new Configuration($value);

        return $configuration->all();
    }

    /**
     * Renders the minimal Vietnamese settings and health view with nonce protection.
     */
    public function renderPage(): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        $statuses = $this->environment->evaluate();
        $diagnostics = is_callable($this->healthProvider) ? ($this->healthProvider)() : [];
        if (is_array($diagnostics['environment'] ?? null)) {
            $statuses = $diagnostics['environment'];
        }

        echo '<div class="wrap"><h1>' . $this->escapeHtml('My SEO For All') . '</h1>';
        echo '<form method="post" action="options.php">';
        echo '<p>' . $this->escapeHtml('Cấu hình nền tảng theo phạm vi site.') . '</p>';
        echo '<h2>' . $this->escapeHtml('Health Check') . '</h2><ul>';
        foreach (['php', 'wordpress', 'woocommerce', 'mbstring'] as $key) {
            $status = is_array($statuses[$key] ?? null) ? $statuses[$key] : [];
            $label = $key;
            $compatible = in_array($status['compatible'] ?? null, [true, false, 'unknown'], true)
                ? $status['compatible'] : 'unknown';
            $message = $compatible === true ? $key . ' sẵn sàng.'
                : ($compatible === false ? $key . ' chưa tương thích.' : $key . ' chưa đủ bằng chứng runtime.');
            echo '<li><strong>' . $this->escapeHtml($label) . '</strong>: '
                . $this->escapeHtml($message) . '</li>';
        }
        foreach (['admin', 'woocommerce', 'seo', 'rank_math'] as $key) {
            $module = [];
            foreach (($diagnostics['modules'] ?? []) as $candidate) {
                if (is_array($candidate) && ($candidate['name'] ?? null) === $key) {
                    $module = $candidate;
                    break;
                }
            }
            $available = ($module['available'] ?? false) === true;
            $message = $key === 'rank_math'
                ? 'Rank Math chỉ được đọc thụ động; chưa chứng minh tương thích.'
                : ($available ? $key . ' sẵn sàng.' : $key . ' chưa sẵn sàng.');
            echo '<li><strong>' . $this->escapeHtml($key) . '</strong>: '
                . $this->escapeHtml($message) . '</li>';
        }
        foreach (['title_meta', 'json_ld', 'sitemap', 'ai_draft'] as $output) {
            $decision = is_array(($diagnostics['coexistence']['outputs'] ?? [])[$output] ?? null)
                ? $diagnostics['coexistence']['outputs'][$output] : [];
            $risk = in_array($decision['duplicate_risk'] ?? null, ['none', 'possible', 'high'], true)
                ? $decision['duplicate_risk'] : 'possible';
            echo '<li><strong>' . $this->escapeHtml($output) . '</strong>: '
                . $this->escapeHtml('duplicate_risk=' . $risk) . '</li>';
        }
        echo '</ul>';
        if (function_exists('settings_fields')) {
            settings_fields('myseo_for_all');
        }
        if (function_exists('submit_button')) {
            submit_button('Lưu cấu hình');
        }
        echo '</form></div>';
    }

    /**
     * Escape diagnostic text with WordPress escaping or a deterministic standalone fallback.
     * The fallback always encodes quotes, substitutes invalid UTF-8, and never returns raw input.
     * @param string $text
     * @return string
     */
    private function escapeHtml(string $text): string
    {
        if (function_exists('esc_html')) {
            return (string) esc_html($text);
        }
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
