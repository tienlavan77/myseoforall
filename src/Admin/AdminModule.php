<?php
/**
 * Registers the Vietnamese settings and health screen for site administrators.
 *
 * Hooks are attached only when WordPress is loaded, and every rendered value is
 * escaped at output so the foundation remains safe before feature modules exist.
 */

declare(strict_types=1);

namespace MySeoForAll\Admin;

use MySeoForAll\AI\AiSettingsController;
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
    private $capabilityChecker;
    private ?AiSettingsController $aiController;

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
        ?\MySeoForAll\Core\HookDispatcher $dispatcher = null,
        ?callable $capabilityChecker = null,
        ?AiSettingsController $aiController = null
    ) {
        $this->aiController = $aiController;
        $this->healthProvider = is_callable($healthProvider) ? $healthProvider : null;
        $this->dispatcher = $dispatcher;
        $this->capabilityChecker = $capabilityChecker;
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
        if ($this->dispatcher === null || !$this->dispatcher->canDispatch()) {
            return;
        }

        if ($this->dispatcher !== null) {
            $this->dispatcher->addAction('admin_menu', [$this, 'registerMenu']);
            $this->dispatcher->addAction('admin_init', [$this, 'registerSettings']);
            if ($this->aiController !== null) {
                $this->aiController->register($this->dispatcher);
            }
        }
    }

    /**
     * Reports whether the admin module can provide its settings and health screens.
     */
    public function isAvailable(): bool
    {
        return $this->dispatcher !== null ? $this->dispatcher->canDispatch() : function_exists('add_action');
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
        $allowed = is_callable($this->capabilityChecker)
            ? (($this->capabilityChecker)('manage_options') === true)
            : (function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$allowed) {
            return;
        }

        $diagnostics = is_callable($this->healthProvider) ? ($this->healthProvider)() : [];
        $statuses = is_array($diagnostics['environment'] ?? null)
            ? $diagnostics['environment'] : $this->environment->evaluate();
        $aiState = $this->aiController !== null ? $this->aiController->viewState() : [];

        echo '<div class="wrap"><h1>' . $this->escapeHtml('My SEO For All') . '</h1>';
        echo '<form method="post" action="' . $this->escapeHtml(function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="myseo_save_ai_settings">';
        echo '<input type="hidden" name="ai_enabled_present" value="1">';
        echo '<input type="hidden" name="expected_revision" value="' . $this->escapeHtml((string) ($aiState['revision'] ?? 0)) . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field('myseo_save_ai_settings');
        }
        echo '<label><input type="checkbox" name="ai_enabled" value="1" ' . (($aiState['ai_enabled'] ?? false) === true ? 'checked' : '') . '> AI enabled</label>';
        echo '<label>Base URL <input type="url" name="base_url" value="' . $this->escapeHtml((string) ($aiState['base_url'] ?? '')) . '"></label>';
        echo '<label>Auth scheme <input type="text" name="auth_scheme" value="' . $this->escapeHtml((string) ($aiState['auth_scheme'] ?? 'x_api_key')) . '"></label>';
        echo '<label>Default model <input type="text" name="default_model" value="' . $this->escapeHtml((string) ($aiState['default_model'] ?? '')) . '"></label>';
        echo '<label>Allowed models <input type="text" name="allowed_models" value="' . $this->escapeHtml(implode(',', is_array($aiState['allowed_models'] ?? null) ? $aiState['allowed_models'] : [])) . '"></label>';
        echo '<label>Timeout <input type="number" name="timeout_seconds" value="' . $this->escapeHtml((string) ($aiState['timeout_seconds'] ?? 20)) . '"></label>';
        echo '<label>Retries <input type="number" name="max_retries" value="' . $this->escapeHtml((string) ($aiState['max_retries'] ?? 1)) . '"></label>';
        echo '<label>API key <input type="password" name="api_key" value="" autocomplete="new-password"></label>';
        echo '<label><input type="checkbox" name="clear_api_key" value="1"> Xóa API key đã lưu</label>';
        echo '<p>' . $this->escapeHtml('Credential status: ' . (string) ($aiState['credential_status'] ?? 'unknown')) . '</p>';
        if (function_exists('submit_button')) {
            submit_button('Lưu AI settings');
        }
        echo '</form>';
        echo '<p>' . $this->escapeHtml('Cấu hình nền tảng theo phạm vi site.') . '</p>';
        echo '<h2>' . $this->escapeHtml('Health Check') . '</h2><ul>';
        foreach (['php', 'wordpress', 'woocommerce', 'mbstring'] as $key) {
            $status = is_array($statuses[$key] ?? null) ? $statuses[$key] : [];
            $label = $key;
            $compatible = in_array($status['compatible'] ?? null, [true, false, 'unknown'], true)
                ? $status['compatible'] : 'unknown';
            $installation = in_array($status['installation'] ?? null, ['installed', 'absent', 'unknown'], true)
                ? $status['installation'] : 'unknown';
            $activation = in_array($status['activation'] ?? null, ['active', 'inactive', 'unknown'], true)
                ? $status['activation'] : 'unknown';
            $fallback = ($status['fallback'] ?? false) === true;
            if ($compatible === false) {
                $message = $key . ' chưa tương thích.';
            } elseif ($key === 'mbstring' && ($fallback || $installation === 'absent')) {
                $message = 'mbstring thiếu; đang dùng fallback UTF-8 deterministic.';
            } elseif ($installation === 'unknown' || $activation === 'unknown' || $compatible === 'unknown') {
                $message = $key . ' chưa đủ bằng chứng runtime.';
            } elseif ($activation === 'inactive') {
                $message = $key . ' đã cài nhưng chưa kích hoạt.';
            } else {
                $message = $key . ' sẵn sàng.';
            }
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
            $risk = in_array($decision['duplicate_risk'] ?? null, ['none', 'possible', 'blocked'], true)
                ? $decision['duplicate_risk'] : 'possible';
            $owner = in_array($decision['owner'] ?? null, [
                'unknown', 'myseo_for_all', 'rank_math', 'yoast_seo', 'aioseo', 'seopress', 'the_seo_framework',
            ], true) ? $decision['owner'] : 'unknown';
            $state = in_array($decision['state'] ?? null, ['owned', 'unknown', 'conflict'], true)
                ? $decision['state'] : 'unknown';
            echo '<li><strong>' . $this->escapeHtml($output) . '</strong>: '
                . $this->escapeHtml('owner=' . $owner . '; state=' . $state . '; duplicate_risk=' . $risk) . '</li>';
        }
        echo '</ul>';
        echo '<form method="post" action="' . $this->escapeHtml(function_exists('admin_url') ? (string) admin_url('options.php') : 'options.php') . '">';
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
