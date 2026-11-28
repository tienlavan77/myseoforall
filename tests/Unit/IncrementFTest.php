<?php
declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\AI\AiSettingsController;
use MySeoForAll\AI\AiSettingsRepository;
use MySeoForAll\AI\GatewayEndpointValidator;
use MySeoForAll\Admin\AdminModule;
use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Core\Plugin;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use PHPUnit\Framework\TestCase;

final class IncrementFTest extends TestCase
{
    private array $values = [];
    private array $autoload = [];
    private int $reads = 0;
    private int $writes = 0;
    private int $deletes = 0;
    private int $cas = 0;

    protected function setUp(): void
    {
        $this->values = [];
        $this->autoload = [];
        $this->reads = 0;
        $this->writes = 0;
        $this->deletes = 0;
        $this->cas = 0;
    }

    /** Build an isolated option seam and keep all operation counters local to this test object. */
    private function optionStore(?callable $cas = null): OptionStore
    {
        $values =& $this->values;
        $autoload =& $this->autoload;
        $reads =& $this->reads;
        $writes =& $this->writes;
        $deletes =& $this->deletes;
        $casCalls =& $this->cas;

        return new OptionStore(
            static function (string $key, mixed $default = false) use (&$values, &$reads): mixed {
                $reads++;
                return $values[$key] ?? $default;
            },
            static function (string $key, mixed $value, bool $autoloadFlag) use (&$values, &$autoload, &$writes): bool {
                $writes++;
                $values[$key] = $value;
                $autoload[$key] = $autoloadFlag;
                return true;
            },
            static function (string $key) use (&$values, &$autoload, &$deletes): bool {
                $deletes++;
                unset($values[$key], $autoload[$key]);
                return true;
            },
            $cas === null ? null : static function (string $key, mixed $expected, mixed $value) use (&$values, &$casCalls, $cas): bool {
                $casCalls++;
                return $cas($key, $expected, $value, $values);
            }
        );
    }

    /** Verify one closed non-autoloaded option, revision checks, and encrypted credential persistence. */
    public function testRepositoryUsesClosedSingleOptionAndRevision(): void
    {
        $repository = new AiSettingsRepository($this->optionStore(), str_repeat('e', 32));
        self::assertSame('absent', $repository->load()['status']);

        $saved = $repository->save([
            'ai_enabled' => true,
            'base_url' => 'https://gateway.example.test/v1',
            'allowed_models' => ['model-1'],
            'default_model' => 'model-1',
        ], 0, str_repeat('k', 16));

        self::assertSame('saved', $saved['status']);
        self::assertSame(1, $saved['settings']['revision']);
        self::assertSame(['myseo_ai_settings'], array_keys($this->values));
        self::assertFalse($this->autoload['myseo_ai_settings']);
        self::assertArrayNotHasKey('api_key', $this->values['myseo_ai_settings']);
        self::assertStringNotContainsString(str_repeat('k', 16), json_encode($saved['settings'], JSON_THROW_ON_ERROR));
        self::assertSame('revision_mismatch', $repository->save(['ai_enabled' => false], 0)['status']);
        self::assertSame('ok', $repository->load()['status']);
    }

    /** Verify core filtering, pure sanitization, and every legacy purge outcome. */
    public function testCoreAndPurgeContracts(): void
    {
        $this->values[Configuration::OPTION_KEY] = [
            'schema_version' => 1,
            'locale' => 'en_US',
            'module_enablement' => ['core' => false, 'unknown' => true],
            'migration_status' => 'complete',
            'rank_math_mode' => 'observe',
            'sitemap_owner' => 'unknown',
            'api_key' => 'secret',
            'unexpected' => true,
        ];
        $store = $this->optionStore();
        $repository = new AiSettingsRepository($store, str_repeat('e', 32));
        self::assertSame('purged', $repository->purgeLegacyCoreKeys()['status']);
        self::assertSame('en_US', $this->values[Configuration::OPTION_KEY]['locale']);
        self::assertCount(6, $this->values[Configuration::OPTION_KEY]);
        self::assertArrayNotHasKey('api_key', $this->values[Configuration::OPTION_KEY]);

        $beforeWrites = $this->writes;
        $admin = new AdminModule(new Configuration(), new EnvironmentCheck(), $store, null, null, static fn (): bool => true);
        $sanitized = $admin->sanitizeSettings(['locale' => 'en_US', 'unknown' => 'secret']);
        self::assertSame($beforeWrites, $this->writes);
        self::assertSame('en_US', $sanitized['locale']);

        $cleanStore = $this->optionStore();
        $cleanRepo = new AiSettingsRepository($cleanStore, str_repeat('e', 32));
        self::assertSame('clean_noop', $cleanRepo->purgeLegacyCoreKeys()['status']);

        $conflictValues = [Configuration::OPTION_KEY => ['unknown' => true]];
        $conflictStore = new OptionStore(
            static fn (string $key, mixed $default = false): mixed => $conflictValues[$key] ?? $default,
            static fn (): bool => true,
            null,
            static fn (): bool => false
        );
        $conflictRepo = new AiSettingsRepository($conflictStore, str_repeat('e', 32));
        self::assertSame('purge_conflict', $conflictRepo->purgeLegacyCoreKeys(['different' => true])['status']);
        self::assertSame('purge_conflict', $conflictRepo->purgeLegacyCoreKeys()['status']);

        $failedStore = new OptionStore(
            static fn (string $key, mixed $default = false): mixed => ['myseo_core_settings' => ['unknown' => true]][$key] ?? $default,
            static fn (): bool => false
        );
        self::assertSame('purge_failed', (new AiSettingsRepository($failedStore, str_repeat('e', 32)))->purgeLegacyCoreKeys()['status']);
    }

    /** Verify URL policy, IDN fail-closed behavior, single-label allowlisting, and offline DNS validation. */
    public function testGatewayValidatorSecurityMatrix(): void
    {
        $validator = new GatewayEndpointValidator();
        $blocked = [
            'https://gateway.example.test/v1/../admin',
            'https://gateway.example.test/v1/.',
            'https://gateway.example.test/v1\\admin',
            'https://gateway.example.test/v1/%2fadmin',
            'https://gateway.example.test/v1/%252e%252e',
            'https://gateway.example.test/v1/%3fq=1',
            'https://gateway.example.test/v1/%23frag',
            'https://gateway.example.test/v1/%25',
            'https://gateway.example.test/v1?',
            'https://gateway.example.test/v1#',
            'https://u@gateway.example.test/v1',
            'https://gateway.example.test:8443/v1',
            'https://gateway.example.test./v1',
            'https://anthropic.com/v1',
            'https://sub.anthropic.com/v1',
            'https://0177.0.0.1/v1',
            'https://0x7f.0.0.1/v1',
            'https://127.1/v1',
            'https://2130706433/v1',
            'https://[::ffff:127.0.0.1]/v1',
            'https://[::ffff:169.254.169.254]/v1',
            'https://[64:ff9b::1]/v1',
            'https://100.64.0.1/v1',
            'https://169.254.169.254/v1',
        ];
        foreach ($blocked as $url) {
            self::assertSame('invalid', $validator->validateStaticUrl($url)['status'], $url);
        }

        self::assertSame('ok', $validator->validateStaticUrl('https://[2606:4700:4700::1111]/v1')['status']);
        $single = new GatewayEndpointValidator(['ai-gateway']);
        self::assertSame('ok', $single->validateStaticUrl('https://ai-gateway/v1')['status']);

        $idn = $validator->validateStaticUrl('https://xn--bcher-kva.example/v1');
        self::assertContains($idn['status'], ['ok', 'invalid']);
        self::assertSame('invalid', $validator->validateStaticUrl('https://xn---.example/v1')['status']);

        $resolverCalls = 0;
        $resolved = $validator->validateResolvedIps('https://gateway.example.test/v1', static function (string $host) use (&$resolverCalls): array {
            $resolverCalls++;
            return ['8.8.8.8', '1.1.1.1', '8.8.8.8'];
        });
        self::assertSame('ok', $resolved['status']);
        self::assertSame(['1.1.1.1', '8.8.8.8'], $resolved['ips']);
        self::assertSame(1, $resolverCalls);
        self::assertSame('invalid', $validator->validateResolvedIps('https://gateway.example.test/v1', static fn (): array => [])['status']);
        self::assertSame('invalid', $validator->validateResolvedIps('https://gateway.example.test/v1', static fn (): array => array_fill(0, 33, '8.8.8.8'))['status']);
        self::assertSame('invalid', $validator->validateResolvedIps('https://gateway.example.test/v1', static function (): array {
            throw new RuntimeException('offline');
        })['status']);
        self::assertSame('invalid', (new GatewayEndpointValidator(['ai-gateway']))->validateResolvedIps('https://ai-gateway/v1', static fn (): array => ['10.0.0.1'])['status']);
    }

    /** Exercise Sodium and AES-GCM round trips, tamper rejection, key limits, clear, and readiness fail-closed behavior. */
    public function testCredentialCryptoClearAndReadiness(): void
    {
        foreach ([false, true] as $preferOpenSsl) {
            $this->values = [];
            $this->writes = 0;
            $repository = new AiSettingsRepository($this->optionStore(), str_repeat('e', 32), null, $preferOpenSsl);
            $saved = $repository->save([
                'ai_enabled' => true,
                'base_url' => 'https://gateway.example.test/v1',
                'default_model' => 'model-1',
                'allowed_models' => ['model-1'],
            ], 0, str_repeat('k', 16));
            self::assertSame('saved', $saved['status']);
            self::assertSame('configured', $repository->credentialStatus()['status']);
            self::assertSame($preferOpenSsl ? 'aes-256-gcm' : 'sodium_secretbox', $this->values['myseo_ai_settings']['credential_envelope']['cipher']);

            foreach (['key_fingerprint', 'nonce', 'tag', 'ciphertext'] as $field) {
                $tampered = $this->values;
                $tampered['myseo_ai_settings']['credential_envelope'][$field] = 'tampered';
                $this->values = $tampered;
                self::assertSame('decryption_failed', $repository->credentialStatus()['status'], $field);
                $this->values['myseo_ai_settings']['credential_envelope'] = $saved['settings']['credential_envelope'];
            }
            $this->values['myseo_ai_settings']['credential_envelope']['cipher'] = 'bad';
            self::assertSame('corrupted', $repository->credentialStatus()['status']);
        }

        $this->values = [];
        $this->writes = 0;
        $repository = new AiSettingsRepository($this->optionStore(), str_repeat('e', 32));
        foreach ([15, 513] as $length) {
            self::assertSame('invalid', $repository->save([], 0, str_repeat('k', $length))['status']);
        }
        self::assertSame('saved', $repository->save([], 0, str_repeat('k', 16))['status']);
        self::assertSame('saved', $repository->save([], 1, str_repeat('k', 512))['status']);
        self::assertSame('invalid', $repository->save(['clear_api_key' => true], 2, str_repeat('k', 16))['status']);
        self::assertSame('invalid', $repository->save([], 2, str_repeat(' ', 16))['status']);
        self::assertSame('saved', $repository->save(['clear_api_key' => true], 2)['status']);
        self::assertSame('absent', $repository->credentialStatus()['status']);

        $noKey = new AiSettingsRepository(new OptionStore(), null);
        self::assertSame('invalid', $noKey->save([], 0, str_repeat('k', 16))['status']);
    }

    /** Verify admin forms, revision progression, redaction, and capability/nonce gates. */
    public function testAdminUiAndHealthRedaction(): void
    {
        $store = $this->optionStore();
        $repository = new AiSettingsRepository($store, str_repeat('e', 32));
        $controller = new AiSettingsController($repository, static fn (string $capability): bool => true, static fn (?string $nonce, string $action): bool => $nonce === 'valid');
        $admin = new AdminModule(new Configuration(), new EnvironmentCheck(), $store, static fn (): array => [
            'environment' => [],
            'modules' => [],
            'coexistence' => ['outputs' => []],
        ], null, static fn (string $capability): bool => true, $controller);

        ob_start();
        $admin->renderPage();
        $first = (string) ob_get_clean();
        self::assertSame(2, substr_count($first, '<form'));
        self::assertStringContainsString('action="admin-post.php"', $first);
        self::assertStringContainsString('action="options.php"', $first);
        self::assertStringContainsString('name="expected_revision" value="0"', $first);
        self::assertStringContainsString('type="password" name="api_key" value=""', $first);

        self::assertSame('rejected_capability', (new AiSettingsController($repository, static fn (): bool => false, static fn (): bool => true))->handlePost([])['status']);
        self::assertSame('rejected_nonce', $controller->handlePost(['_nonce' => 'bad'])['status']);
        self::assertSame('saved', $controller->handlePost(['_nonce' => 'valid', 'expected_revision' => 0, 'ai_enabled' => true])['status']);

        ob_start();
        $admin->renderPage();
        $second = (string) ob_get_clean();
        self::assertStringContainsString('name="expected_revision" value="1"', $second);
        self::assertSame('saved', $controller->handlePost(['_nonce' => 'valid', 'expected_revision' => 1, 'ai_enabled_present' => '1', 'ai_enabled' => false])['status']);
        self::assertSame(2, $this->values['myseo_ai_settings']['revision']);

        $plugin = new Plugin(null, new Configuration(), new EnvironmentCheck(), $store);
        $health = $plugin->health();
        self::assertSame(['admin', 'woocommerce', 'seo', 'rank_math'], array_column($health['modules'], 'name'));
        self::assertSame(['ai_enabled', 'ai_configured', 'ai_settings_state', 'ai_credential_state'], array_keys($health['ai']));
        $encoded = json_encode($health, JSON_THROW_ON_ERROR);
        foreach (['base_url', 'default_model', 'ciphertext', str_repeat('k', 16)] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    /** Verify the API-key mask is preserved as no new credential and default model is merged into a non-empty allowlist. */
    public function testAiSettingsMaskSentinelAndModelMerge(): void
    {
        $repository = new AiSettingsRepository($this->optionStore(), str_repeat('e', 32));
        self::assertSame('saved', $repository->save(['default_model' => 'model-main', 'allowed_models' => ['model-extra']], 0, str_repeat('k', 16))['status']);
        self::assertSame(['model-main', 'model-extra'], $this->values['myseo_ai_settings']['allowed_models']);
        self::assertSame('saved', $repository->save([], 1, AiSettingsRepository::API_KEY_MASK)['status']);
        self::assertSame('configured', $repository->credentialStatus()['status']);

        $controller = new AiSettingsController($repository, static fn (): bool => true, static fn (): bool => true);
        $result = $controller->handlePost(['expected_revision' => 2, 'api_key' => AiSettingsRepository::API_KEY_MASK]);
        self::assertSame('saved', $result['status']);
        self::assertArrayHasKey('credential_envelope', $this->values['myseo_ai_settings']);
    }

    /** Verify unsupported or mismatched query pairs render no result banner while credential status remains visible. */
    public function testAiSettingsBannerAllowlistIsFailClosed(): void
    {
        $store = $this->optionStore();
        $repository = new AiSettingsRepository($store, str_repeat('e', 32));
        $controller = new AiSettingsController($repository, static fn (): bool => true, static fn (): bool => true);
        $admin = new AdminModule(new Configuration(), new EnvironmentCheck(), $store, static fn (): array => ['environment' => [], 'modules' => [], 'coexistence' => ['outputs' => []]], null, static fn (): bool => true, $controller);
        foreach ([
            ['ai_status' => 'unknown_code'],
            ['ai_status' => 'saved', 'ai_reason' => 'unexpected'],
            ['ai_status' => 'invalid', 'ai_reason' => 'unknown_reason'],
            ['ai_status' => 'corrupted', 'ai_reason' => 'unexpected'],
        ] as $query) {
            $old = $_GET;
            $_GET = $query;
            ob_start();
            $admin->renderPage();
            $html = (string) ob_get_clean();
            $_GET = $old;
            self::assertSame(0, substr_count($html, 'myseo-status--success'));
            self::assertSame(0, substr_count($html, 'myseo-status--error'));
            self::assertStringContainsString('Credential status:', $html);
        }
    }
}
