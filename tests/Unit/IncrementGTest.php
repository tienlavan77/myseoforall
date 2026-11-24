<?php
/*
 * This focused suite verifies gateway transport security and fail-closed behavior
 * without opening a network socket or retaining request credentials.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\AI\AiSettingsRepository;
use MySeoForAll\AI\Contracts\GatewayResolver;
use MySeoForAll\AI\Contracts\GatewayTransport;
use MySeoForAll\AI\Contracts\AiProviderException;
use MySeoForAll\AI\CurlGatewayTransport;
use MySeoForAll\AI\GatewayAiProvider;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use PHPUnit\Framework\TestCase;

final class IncrementGTest extends TestCase
{
    // Confirm secure cURL options, IPv6 pin syntax, and the 64 KiB response boundary.
    public function testTransportBuildsPinnedSecureOptionsAndBoundsResponseBody(): void
    {
        $captured = [];
        $transport = new CurlGatewayTransport(static function (string $url, array $options) use (&$captured): array {
            $captured = [$url, $options];
            $write = $options[CURLOPT_WRITEFUNCTION];
            self::assertSame(65536, $write(null, str_repeat('a', 65536)));
            // Overflow is covered by the dedicated executor-boundary test.
            return ['status' => 200, 'body' => '{"ok":true}', 'sent' => true];
        });

        $result = $transport->send(
            'https://gw.example.test/v1/messages',
            ['x-api-key' => 'redacted'],
            ['model' => 'm'],
            5,
            ['2606:4700:4700::1111']
        );

        self::assertSame(200, $result['status']);
        self::assertSame('https://gw.example.test/v1/messages', $captured[0]);
        self::assertSame('', $captured[1][CURLOPT_PROXY]);
        self::assertFalse($captured[1][CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($captured[1][CURLOPT_FRESH_CONNECT]);
        self::assertSame('gw.example.test:443:[2606:4700:4700::1111]', $captured[1][CURLOPT_RESOLVE][0]);
    }

    // Confirm a hostname with no approved DNS address fails before the executor is called.
    public function testTransportRejectsEmptyDnsResultBeforeExecutor(): void
    {
        $calls = 0;
        $transport = new CurlGatewayTransport(static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{}', 'sent' => true];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gateway_policy_rejected');
        try {
            $transport->send('https://gw.example.test/v1/messages', [], [], 5, []);
        } finally {
            self::assertSame(0, $calls);
        }
    }

    // Confirm the injected executor applies the same 65,536-byte response ceiling as native cURL.
    public function testExecutorRejectsResponseOverflow(): void
    {
        $transport = new CurlGatewayTransport(static function (string $url, array $options): array {
            $write = $options[CURLOPT_WRITEFUNCTION];
            $write(null, str_repeat('a', 65537));
            return ['status' => 200, 'body' => '{}', 'sent' => true];
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response_too_large');
        $transport->send('https://gw.example.test/v1/messages', [], [], 5, ['1.1.1.1']);
    }

    // Confirm Messages headers, task/context envelope, replay-safe key, and remaining timeout propagation.
    public function testRepositoryBuildsReplaySafeMessagesRequest(): void
    {
        $values = [];
        $captured = [];
        $resolver = new class implements GatewayResolver {
            // Resolve the fixture hostname to one public address without network access.
            public function resolve(string $host): array { return ['1.1.1.1']; }
        };
        $transport = new class($captured) implements GatewayTransport {
            // Capture transport arguments so protocol construction can be asserted offline.
            public function __construct(private array &$captured) {}
            // Return a successful bounded response after recording the request.
            public function send(string $url, array $headers, array $body, int $timeout, array $ips): array
            {
                $this->captured = [$url, $headers, $body, $timeout, $ips];
                return ['status' => 200, 'body' => '{}', 'sent' => true];
            }
        };
        $store = new OptionStore(
            static function (string $key, mixed $default = false) use (&$values): mixed { return $values[$key] ?? $default; },
            static function (string $key, mixed $value, bool $autoload) use (&$values): bool { $values[$key] = $value; return true; }
        );
        $repo = new AiSettingsRepository($store, str_repeat('e', 32), null, false, $transport, $resolver, static fn (): float => 100.0, static function (): void {}, true);
        self::assertSame('saved', $repo->save(['ai_enabled' => true, 'base_url' => 'https://gw.example.test/v1', 'default_model' => 'model-1', 'allowed_models' => ['model-1'], 'timeout_seconds' => 5, 'max_retries' => 0], 0, str_repeat('k', 16))['status']);
        $repo->executeGatewayRequest('seo_analysis', ['type' => 'article']);
        self::assertSame('https://gw.example.test/v1/messages', $captured[0]);
        self::assertSame('application/json; charset=utf-8', $captured[1]['Content-Type']);
        self::assertSame('2023-06-01', $captured[1]['anthropic-version']);
        self::assertArrayHasKey('Idempotency-Key', $captured[1]);
        self::assertSame(['task' => 'seo_analysis', 'context' => ['type' => 'article']], json_decode($captured[2]['messages'][0]['content'], true));
        self::assertSame(5, $captured[3]);
    }

    // Confirm missing credentials fail closed before any resolver or transport call.
    public function testMissingEnvelopeMapsToProviderDisabledBeforeNetwork(): void
    {
        $settings = $this->gatewaySettings(['credential_envelope' => null]);
        $resolverCalls = 0;
        $transportCalls = 0;
        $resolver = new class($resolverCalls) implements GatewayResolver {
            public function __construct(private int &$calls) {}
            public function resolve(string $host): array { $this->calls++; return ['1.1.1.1']; }
        };
        $transport = new class($transportCalls) implements GatewayTransport {
            public function __construct(private int &$calls) {}
            public function send(string $url, array $headers, array $body, int $timeout, array $ips): array { $this->calls++; return ['status' => 200, 'body' => '{}', 'sent' => true]; }
        };
        $provider = new GatewayAiProvider(new AiSettingsRepository($this->storeFor($settings), str_repeat('e', 32), null, false, $transport, $resolver));
        try {
            $provider->generate($this->requestFixture());
            self::fail('Expected provider_disabled.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::PROVIDER_DISABLED, $exception->getCategory());
        }
        self::assertSame(0, $resolverCalls);
        self::assertSame(0, $transportCalls);
    }

    // Confirm an explicitly empty environment key blocks a valid database fallback.
    public function testEmptyEnvironmentCredentialDoesNotFallbackToDatabase(): void
    {
        $values = [];
        $store = new OptionStore(
            static function (string $key, mixed $default = false) use (&$values): mixed { return $values[$key] ?? $default; },
            static function (string $key, mixed $value, bool $autoload) use (&$values): bool { $values[$key] = $value; return true; }
        );
        $seed = new AiSettingsRepository($store, str_repeat('e', 32));
        self::assertSame('saved', $seed->save([
            'ai_enabled' => true,
            'base_url' => 'https://gw.example.test',
            'default_model' => 'model-1',
            'allowed_models' => ['model-1'],
            'timeout_seconds' => 5,
            'max_retries' => 0,
        ], 0, 'sk-db-valid-123456789')['status']);
        putenv('MYSEO_AI_GATEWAY_API_KEY=');
        try {
            $provider = new GatewayAiProvider(new AiSettingsRepository($store, str_repeat('e', 32)));
            $provider->generate($this->requestFixture());
            self::fail('Expected authentication_failed.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::AUTHENTICATION_FAILED, $exception->getCategory());
        } finally {
            putenv('MYSEO_AI_GATEWAY_API_KEY');
        }
    }

    // Confirm invalid resolved addresses map to policy rejection without opening transport.
    public function testPrivateResolvedIpMapsToPolicyRejectionBeforeTransport(): void
    {
        putenv('MYSEO_AI_GATEWAY_API_KEY=sk-policy-test-123456');
        $transportCalls = 0;
        $resolver = new class implements GatewayResolver {
            public function resolve(string $host): array { return ['1.1.1.1', '10.0.0.1']; }
        };
        $transport = new class($transportCalls) implements GatewayTransport {
            public function __construct(private int &$calls) {}
            public function send(string $url, array $headers, array $body, int $timeout, array $ips): array { $this->calls++; return ['status' => 200, 'body' => '{}', 'sent' => true]; }
        };
        try {
            $repo = new AiSettingsRepository($this->storeFor($this->gatewaySettings()), str_repeat('e', 32), null, false, $transport, $resolver);
            (new GatewayAiProvider($repo))->generate($this->requestFixture());
            self::fail('Expected gateway_policy_rejected.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::GATEWAY_POLICY_REJECTED, $exception->getCategory());
        } finally {
            putenv('MYSEO_AI_GATEWAY_API_KEY');
        }
        self::assertSame(0, $transportCalls);
    }

    // Confirm a received 429 survives an exhausted retry budget and keeps its caller category.
    public function testRateLimitResponseIsPreservedWhenBackoffBudgetIsExhausted(): void
    {
        putenv('MYSEO_AI_GATEWAY_API_KEY=sk-rate-limit-test-123456');
        $clockValues = [100.0, 100.0, 100.0, 104.95];
        $clock = static function () use (&$clockValues): float { return array_shift($clockValues) ?? 104.95; };
        $resolver = new class implements GatewayResolver {
            public function resolve(string $host): array { return ['1.1.1.1']; }
        };
        $transport = new class implements GatewayTransport {
            public function send(string $url, array $headers, array $body, int $timeout, array $ips): array { return ['status' => 429, 'body' => '{}', 'sent' => true]; }
        };
        $settings = $this->gatewaySettings(['max_retries' => 1, 'timeout_seconds' => 5]);
        try {
            $repo = new AiSettingsRepository($this->storeFor($settings), str_repeat('e', 32), null, false, $transport, $resolver, $clock, static function (int $ms): void {});
            (new GatewayAiProvider($repo))->generate($this->requestFixture());
            self::fail('Expected rate_limited.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::RATE_LIMITED, $exception->getCategory());
        } finally {
            putenv('MYSEO_AI_GATEWAY_API_KEY');
        }
    }

    // Build a normalized enabled gateway fixture for offline tests.
    private function gatewaySettings(array $overrides = []): array
    {
        return array_replace(AiSettingsRepository::defaults(), [
            'ai_enabled' => true,
            'base_url' => 'https://gw.example.test',
            'default_model' => 'model-1',
            'allowed_models' => ['model-1'],
            'timeout_seconds' => 5,
            'max_retries' => 0,
        ], $overrides);
    }

    // Build an isolated option store that exposes only the supplied fixture.
    private function storeFor(array $settings): OptionStore
    {
        $values = [AiSettingsRepository::OPTION_KEY => $settings];
        return new OptionStore(
            static function (string $key, mixed $default = false) use (&$values): mixed { return $values[$key] ?? $default; },
            static function (string $key, mixed $value, bool $autoload) use (&$values): bool { $values[$key] = $value; return true; }
        );
    }

    // Build the canonical request envelope accepted by GatewayAiProvider.
    private function requestFixture(): array
    {
        return [
            'schema_version' => 'myseo.ai.request.v2',
            'task' => 'seo_analysis',
            'context' => (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build(['title' => 'Article', 'content' => 'Body'], 'article'),
        ];
    }
}
