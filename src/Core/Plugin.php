<?php
/**
 * Composes and boots the My SEO For All foundation modules.
 *
 * This class is the single lifecycle owner: it loads configuration, registers
 * services, boots available modules once, and exposes safe activation health data.
 */

declare(strict_types=1);

namespace MySeoForAll\Core;

// Keep plugin bootstrap resilient when a deployed Composer class map predates HookDispatcher.
if (!class_exists(HookDispatcher::class)) {
    require_once __DIR__ . '/HookDispatcher.php';
}

use MySeoForAll\Admin\AdminModule;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Contracts\GatewayResolver;
use MySeoForAll\AI\Contracts\GatewayTransport;
use MySeoForAll\AI\GatewayAiProvider;
use MySeoForAll\AI\NativeGatewayResolver;
use MySeoForAll\AI\CurlGatewayTransport;
use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Core\Contracts\Module;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use MySeoForAll\Integrations\RankMath\OwnershipRegistry;
use MySeoForAll\Integrations\RankMath\RankMathModule;
use MySeoForAll\SEO\Context\ProviderPayloadBuilder;
use MySeoForAll\SEO\Runtime\SeoModule;
use MySeoForAll\WooCommerce\WooCommerceModule;

// Defines Plugin class behavior for Plugin.php.
final class Plugin
{
    private bool $booted = false;

    /** @var list<Module> */
    private array $modules;

    /**
     * Composes the foundation services and module list without contacting external APIs.
     */
    private Container $container;

    /**
     * Composes the foundation services and module list without contacting external APIs.
     */
    public function __construct(
        ?Container $container = null,
        ?Configuration $configuration = null,
        ?EnvironmentCheck $environment = null,
        ?OptionStore $options = null,
        ?array $modules = null,
        ?HookDispatcher $dispatcher = null
    ) {
        $this->container = $container ?? new Container();
        $options ??= new OptionStore();
        $configuration ??= new Configuration((array) $options->get(Configuration::OPTION_KEY, []));
        $environment ??= new EnvironmentCheck();
        $dispatcher ??= HookDispatcher::shared();

        $this->container->set(Configuration::class, $configuration);
        $this->container->set(EnvironmentCheck::class, $environment);
        $this->container->set(OptionStore::class, $options);
        $transport = $this->container->has(GatewayTransport::class)
            ? $this->container->get(GatewayTransport::class)
            : new CurlGatewayTransport();
        $resolver = $this->container->has(GatewayResolver::class)
            ? $this->container->get(GatewayResolver::class)
            : new NativeGatewayResolver();
        $aiSettings = $this->container->has(\MySeoForAll\AI\AiSettingsRepository::class)
            ? $this->container->get(\MySeoForAll\AI\AiSettingsRepository::class)
            : new \MySeoForAll\AI\AiSettingsRepository($options, null, null, false, $transport, $resolver);
        $gatewayProvider = $this->container->has(GatewayAiProvider::class)
            ? $this->container->get(GatewayAiProvider::class)
            : new GatewayAiProvider($aiSettings);
        $this->container->set(GatewayTransport::class, $transport);
        $this->container->set(GatewayResolver::class, $resolver);
        $this->container->set(\MySeoForAll\AI\AiSettingsRepository::class, $aiSettings);
        $this->container->set(GatewayAiProvider::class, $gatewayProvider);
        $this->container->set(AiProvider::class, $gatewayProvider);
        $this->container->set(
            \MySeoForAll\AI\AiSettingsController::class,
            new \MySeoForAll\AI\AiSettingsController($aiSettings)
        );
        $this->container->set(HookDispatcher::class, $dispatcher);
        $this->modules = $modules ?? [
            new AdminModule($configuration, $environment, $options, fn (): array => $this->health(), $dispatcher, null, $this->container->get(\MySeoForAll\AI\AiSettingsController::class)),
            new WooCommerceModule($dispatcher),
            new SeoModule($this->container, $dispatcher),
            new RankMathModule($this->container, $dispatcher),
        ];
    }

    /**
     * Registers services and boots each available module exactly once.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->modules as $module) {
            $module->register();
        }
        foreach ($this->modules as $module) {
            if ($module->isAvailable()) {
                $module->boot();
            }
        }

        $this->booted = true;
    }

    /**
     * Returns the composed configuration and module health for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $environment = $this->container->get(EnvironmentCheck::class);
        $configuration = $this->container->get(Configuration::class);
        $snapshot = [];
        if ($this->container->has(OwnershipRegistry::class)) {
            try {
                $snapshot = $this->container->get(OwnershipRegistry::class)->resolve();
            } catch (\Throwable) {
                $snapshot = [];
            }
        }

        return [
            'plugin' => [
                'name' => 'My SEO For All',
                'version' => defined('MYSEO_FOR_ALL_VERSION') && is_string(MYSEO_FOR_ALL_VERSION)
                    ? MYSEO_FOR_ALL_VERSION : '0.1.0',
            ],
            'configuration' => $this->safeConfiguration($configuration->all()),
            'environment' => $this->safeEnvironment($environment->evaluate()),
            'ai' => $this->safeAiHealth(),
            'modules' => array_map(fn (Module $module): array => $this->safeModule($module->health()), $this->modules),
            'coexistence' => $this->safeCoexistence($snapshot),
        ];
    }

    /**
     * Expose only the fixed AI readiness and credential enums required by health checks.
     * This method never returns gateway URLs, models, fingerprints, or encrypted envelope data.
     */
    private function safeAiHealth(): array
    {
        $repository = $this->container->get(\MySeoForAll\AI\AiSettingsRepository::class);
        $loaded = $repository->load();
        $credential = $repository->credentialStatus()['status'] ?? 'absent';
        $allowedCredential = ['absent', 'configured', 'environment', 'invalid_environment', 'corrupted', 'decryption_failed'];
        return [
            'ai_enabled' => $loaded['status'] === 'ok' && ($loaded['settings']['ai_enabled'] ?? false) === true,
            'ai_configured' => in_array($credential, ['configured', 'environment'], true),
            'ai_settings_state' => in_array($loaded['status'], ['absent', 'ok', 'corrupted'], true) ? $loaded['status'] : 'corrupted',
            'ai_credential_state' => in_array($credential, $allowedCredential, true) ? $credential : 'corrupted',
        ];
    }

    /**
     * Project environment records into the closed diagnostic schema.
     * Unknown source keys and caller messages are removed before health output.
     * @param array<string,mixed> $statuses
     * @return array<string,array<string,mixed>>
     */
    private function safeEnvironment(array $statuses): array
    {
        $result = [];
        foreach (['php', 'wordpress', 'woocommerce', 'mbstring'] as $name) {
            $status = is_array($statuses[$name] ?? null) ? $statuses[$name] : [];
            $installation = in_array($status['installation'] ?? null, ['installed', 'absent', 'unknown'], true)
                ? $status['installation'] : 'unknown';
            $activation = in_array($status['activation'] ?? null, ['active', 'inactive', 'unknown'], true)
                ? $status['activation'] : 'unknown';
            $compatible = in_array($status['compatible'] ?? null, [true, false, 'unknown'], true)
                ? $status['compatible'] : 'unknown';
            $result[$name] = [
                'name' => $name,
                'installed' => $installation === 'installed' ? true : ($installation === 'absent' ? false : 'unknown'),
                'active' => $activation === 'active' ? true : ($activation === 'inactive' ? false : 'unknown'),
                'installation' => $installation,
                'activation' => $activation,
                'compatible' => $compatible,
                'version' => isset($status['version']) && is_string($status['version'])
                    && preg_match('/^[0-9][A-Za-z0-9._+-]{0,31}$/', $status['version']) === 1
                    ? $status['version'] : null,
                'message' => $this->safeStatusMessage($name, $installation, $activation, $compatible),
            ];
            if ($name === 'mbstring') {
                $result[$name]['fallback'] = $installation !== 'installed';
            }
        }
        return $result;
    }

    /**
     * Build a deterministic status label from closed evidence enums.
     * @param string $name
     * @param string $installation
     * @param string $activation
     * @param bool|string $compatible
     * @return string
     */
    private function safeStatusMessage(string $name, string $installation, string $activation, $compatible): string
    {
        if ($compatible === false) {
            return $name . ' chưa tương thích.';
        }
        if ($installation === 'unknown' || $activation === 'unknown' || $compatible === 'unknown') {
            return $name . ' chưa đủ bằng chứng runtime.';
        }
        if ($activation === 'inactive') {
            return $name . ' đã cài nhưng chưa kích hoạt.';
        }
        return $name . ' sẵn sàng.';
    }

    /**
     * Project module health into fixed fields and stable enum values.
     * @param array<string,mixed> $module
     * @return array<string,mixed>
     */
    private function safeModule(array $module): array
    {
        $name = in_array($module['name'] ?? null, ['admin', 'woocommerce', 'seo', 'rank_math'], true)
            ? $module['name'] : 'unknown';
        return [
            'name' => $name,
            'available' => ($module['available'] ?? false) === true,
            'message' => $name === 'rank_math'
                ? 'Rank Math chỉ được đọc thụ động; chưa chứng minh tương thích.'
                : (($module['available'] ?? false) === true ? $name . ' sẵn sàng.' : $name . ' chưa sẵn sàng.'),
            'write_allowed' => false,
            'frontend_owned' => false,
            'dependency_ready' => $name === 'rank_math' && (($module['dependency_ready'] ?? false) === true),
            'compatibility' => in_array($module['compatibility'] ?? null, ['compatible', 'incompatible', 'unknown'], true)
                ? $module['compatibility'] : 'unknown',
        ];
    }

    /**
     * Project ownership decisions without exposing raw registry records.
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function safeCoexistence(array $snapshot): array
    {
        $allowedOutputs = ['title_meta', 'json_ld', 'sitemap', 'ai_draft'];
        $outputs = [];
        $sourceOutputs = is_array($snapshot['outputs'] ?? null) ? $snapshot['outputs'] : [];
        foreach ($allowedOutputs as $output) {
            $decision = is_array($sourceOutputs[$output] ?? null) ? $sourceOutputs[$output] : [];
            $outputs[$output] = [
                'owner' => in_array($decision['owner'] ?? null, [
                    'unknown', 'myseo_for_all', 'rank_math', 'yoast_seo', 'aioseo', 'seopress', 'the_seo_framework',
                ], true) ? $decision['owner'] : 'unknown',
                'state' => in_array($decision['state'] ?? null, ['owned', 'unknown', 'conflict'], true)
                    ? $decision['state'] : 'unknown',
                'duplicate_risk' => in_array($decision['duplicate_risk'] ?? null, ['none', 'possible', 'blocked'], true)
                    ? $decision['duplicate_risk'] : 'possible',
                'reason' => in_array($decision['reason'] ?? null, [
                    'identity_collision', 'assertion_capability_conflict', 'conflicting_evidence',
                    'overlapping_claims', 'split_ownership', 'partial_ownership', 'no_valid_claim',
                    'other_emitters_excluded', 'other_emitters_not_excluded', 'invalid_context_scope', 'unavailable',
                ], true) ? $decision['reason'] : 'unavailable',
                'write_allowed' => false,
            ];
        }
        return ['outputs' => $outputs, 'write_allowed' => false];
    }

    /**
     * Return only the fixed scalar configuration summary allowed in diagnostics.
     * Unknown keys and non-scalar values are excluded so raw options cannot reach health output.
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function safeConfiguration(array $values): array
    {
        $summary = [];
        $version = $values['version'] ?? $values['schema_version'] ?? null;
        if (is_int($version) && $version > 0) {
            $version = (string) $version;
        }
        if (is_string($version) && preg_match('/^[0-9][A-Za-z0-9._+-]{0,31}$/', $version) === 1) {
            $summary['version'] = $version;
        }
        $locale = $values['site_language'] ?? $values['locale'] ?? null;
        if (is_string($locale) && preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $locale) === 1) {
            $summary['site_language'] = $locale;
        }
        if (in_array($values['rank_math_mode'] ?? null, ['observe', 'disabled', 'unknown'], true)) {
            $summary['rank_math_mode'] = $values['rank_math_mode'];
        }
        if (in_array($values['sitemap_owner'] ?? null, ['myseo_for_all', 'rank_math', 'unknown'], true)) {
            $summary['sitemap_owner'] = $values['sitemap_owner'];
        }
        ksort($summary);
        return $summary;
    }

    /**
     * Creates the namespaced site configuration during plugin activation without heavy work.
     */
    public static function activate(): void
    {
        $options = new OptionStore();
        $existing = $options->get(Configuration::OPTION_KEY, null);
        $repository = new \MySeoForAll\AI\AiSettingsRepository($options);
        if ($existing === null || $existing === false || $existing === []) {
            $options->update(Configuration::OPTION_KEY, Configuration::defaults());
        } else {
            $repository->purgeLegacyCoreKeys(is_array($existing) ? $existing : null);
        }
    }

    /**
     * Stops runtime behavior on deactivation while preserving every user option.
     */
    public static function deactivate(): void
    {
        // Deactivation deliberately preserves configuration and user data.
    }
}
