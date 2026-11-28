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
use MySeoForAll\AI\NativeGatewayResolver;
use MySeoForAll\Core\Plugin;
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
            self::assertSame(32768, $write(null, str_repeat('a', 32768)));
            self::assertSame(32768, $write(null, str_repeat('b', 32768)));
            self::assertSame(0, $write(null, 'c'));
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
            public function resolve(string $host, int $timeoutSeconds = 5): array { return ['1.1.1.1']; }
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
        self::assertSame(0.2, $captured[2]['temperature']);
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
            public function resolve(string $host, int $timeoutSeconds = 5): array { $this->calls++; return ['1.1.1.1']; }
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
        if (!function_exists('putenv')) { self::markTestSkipped('Environment mutation is unavailable in this PHP runtime.'); }
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
        $transportCalls = 0;
        $resolver = new class implements GatewayResolver {
            public function resolve(string $host, int $timeoutSeconds = 5): array { return ['1.1.1.1', '10.0.0.1']; }
        };
        $transport = new class($transportCalls) implements GatewayTransport {
            public function __construct(private int &$calls) {}
            public function send(string $url, array $headers, array $body, int $timeout, array $ips): array { $this->calls++; return ['status' => 200, 'body' => '{}', 'sent' => true]; }
        };
        try {
            $repo = new AiSettingsRepository($this->configuredStore($this->gatewaySettings()), str_repeat('e', 32), null, false, $transport, $resolver);
            (new GatewayAiProvider($repo))->generate($this->requestFixture());
            self::fail('Expected gateway_policy_rejected.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::GATEWAY_POLICY_REJECTED, $exception->getCategory());
        }
        self::assertSame(0, $transportCalls);
    }

    // Confirm a received 429 survives an exhausted retry budget and keeps its caller category.
    public function testRateLimitResponseIsPreservedWhenBackoffBudgetIsExhausted(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float { return $now; };
        $resolver = new class implements GatewayResolver {
            public function resolve(string $host, int $timeoutSeconds = 5): array { return ['1.1.1.1']; }
        };
        $transport = new class($now) implements GatewayTransport {
            public int $calls = 0;
            // Let the first response arrive with less than 100 ms left in the budget.
            public function __construct(private float &$now) {}
            public function send(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] array $body, int $timeout, array $ips): array
            {
                $this->calls++;
                $this->now += 4.95;
                return ['status' => 429, 'body' => '{}', 'sent' => true];
            }
        };
        $settings = $this->gatewaySettings(['max_retries' => 1, 'timeout_seconds' => 5]);
        try {
            $repo = new AiSettingsRepository($this->configuredStore($settings), str_repeat('e', 32), null, false, $transport, $resolver, $clock, static function (int $ms): void {
                self::fail('Backoff exceeds the remaining request budget.');
            }, true);
            (new GatewayAiProvider($repo))->generate($this->requestFixture());
            self::fail('Expected rate_limited.');
        } catch (AiProviderException $exception) {
            self::assertSame(AiProviderException::RATE_LIMITED, $exception->getCategory());
        }
        self::assertSame(1, $transport->calls);
    }

    // Exercise caller error mapping and verify retry decisions with a fixed clock.
    public function testCallerErrorAndRetryMatrix(): void
    {
        $auth = AiProviderException::AUTHENTICATION_FAILED;
        $rate = AiProviderException::RATE_LIMITED;
        $failed = AiProviderException::TRANSPORT_FAILED;
        $invalid = AiProviderException::INVALID_RESPONSE;
        $rows = [
        [401, $auth, false, 2, 1], [403, $auth, true, 2, 1],
        [429, $rate, false, 2, 1], [429, $rate, true, 2, 3],
        [502, $failed, true, 2, 3], [503, $failed, false, 2, 1],
        [503, $failed, true, 2, 3], [504, $failed, true, 2, 3],
        [400, $failed, true, 2, 1], [500, $failed, true, 2, 1],
        [0, $invalid, true, 2, 1], [101, $invalid, true, 2, 1],
        [201, $invalid, true, 2, 1], [302, $invalid, true, 2, 1],
        ['tls_failed', $failed, true, 2, 1],
        ['transport_unavailable', $failed, true, 2, 1],
        ['timeout_after_send', $failed, true, 2, 1],
        ['transport_failed', $failed, true, 2, 1],
        ['connect_failed', $failed, false, 0, 1],
        ['connect_failed', $failed, false, 1, 2],
        ['connect_failed', $failed, false, 2, 3],
        ['connect_timeout_unsent', $failed, false, 2, 3],
        ];
        foreach ($rows as [$result, $category, $replay, $retries, $attempts]) {
            $pins = $keys = $sleeps = [];
            $resolver = new class implements GatewayResolver {
                public int $calls = 0;
                public function resolve(string $host, int $timeoutSeconds = 5): array
                {
                    return [++$this->calls === 1 ? '1.1.1.1' : '1.0.0.1'];
                }
            };
            $transport = $this->callbackTransport(static function (array $headers, array $body, array $ips) use (&$pins, &$keys, $result): array {
                $pins[] = $ips;
                $keys[] = $headers['Idempotency-Key'] ?? null;
                if (is_string($result)) {
                    throw new \RuntimeException($result);
                }
                return ['status' => $result, 'body' => '{}', 'sent' => true];
            });
            $repo = new AiSettingsRepository(
                $this->configuredStore($this->gatewaySettings(['max_retries' => $retries])),
                str_repeat('e', 32), null, false, $transport, $resolver, static fn (): float => 100.0,
                static function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; }, $replay
            );
            $provider = new GatewayAiProvider($repo);
            $this->assertGatewayFailure($provider, $this->requestFixture(), $category);
            self::assertSame($attempts, $resolver->calls, (string) $result);
            self::assertSame(array_slice([100, 200], 0, $attempts - 1), $sleeps, (string) $result);
            self::assertSame(array_slice([['1.1.1.1'], ['1.0.0.1'], ['1.0.0.1']], 0, $attempts), $pins);
            self::assertSame(array_fill(0, $attempts, $keys[0] ?? null), $keys);
            if ($replay) {
                self::assertNotEmpty($keys[0]);
            } else {
                self::assertNull($keys[0]);
            }
            if ($replay && in_array($result, [429, 503], true)) {
                $previous = $keys[0];
                $this->assertGatewayFailure($provider, $this->requestFixture(), $category);
                self::assertNotSame($previous, $keys[$attempts]);
            }
        }
    }

    // Verify the Messages boundary for all tasks, output rejection and phase-specific retries.
    public function testMessagesProtocolAndOutputMatrix(): void
    {
        $outputs = [
            'seo_analysis' => ['findings' => []],
            'title_meta_suggestion' => ['target_field' => 'title', 'value' => 'Clear title', 'language' => 'en', 'provenance' => 'editor'],
            'product_description_draft' => ['target' => 'product', 'content' => 'A clear draft', 'language' => 'en', 'provenance' => 'editor', 'uses_commercial_facts' => false],
        ];
        foreach ($outputs as $task => $output) {
            foreach (['', '/v1/', '/v1/messages/'] as $path) {
                $request = array_replace($this->requestFixture(), ['task' => $task]);
                $calls = 0;
                $transport = $this->callbackTransport(static function (array $headers, array $body, array $ips, string $url) use (&$calls, $request, $output): array {
                    self::assertSame('https://gw.example.test/v1/messages', $url);
                    self::assertSame(0.2, $body['temperature']);
                    self::assertSame(['task' => $request['task'], 'context' => $request['context']], json_decode($body['messages'][0]['content'], true, 32, JSON_THROW_ON_ERROR));
                    if (++$calls === 1) {
                        throw new \RuntimeException('connect_failed');
                    }
                    return self::messages($output);
                });
                $repo = new AiSettingsRepository($this->configuredStore($this->gatewaySettings(['base_url' => 'https://gw.example.test' . $path, 'max_retries' => 2])), str_repeat('e', 32), null, false, $transport, $this->publicResolver(), static fn (): float => 100.0, static function (): void {});
                $result = (new GatewayAiProvider($repo))->generate($request);
                self::assertSame(['gateway', 'ok', $task, $output], [$result['provider'], $result['status'], $result['task'], $result['output']]);
                self::assertSame(2, $calls);
            }
        }
        $okText = json_encode($outputs['seo_analysis'], JSON_THROW_ON_ERROR);
        $text = ['type' => 'text', 'text' => $okText];
        $bad = [
            self::messages([], ['content' => [$text, $text]])['body'],
            self::messages([], ['content' => [['type' => 'tool_use']]])['body'],
            self::messages([], ['content' => [['type' => 'text']]])['body'],
            self::messages([], ['stop_reason' => 'max_tokens'])['body'],
            self::messages([], ['content' => [['type' => 'text', 'text' => '~~~json' . $okText . '~~~']]])['body'],
            self::messages([], ['content' => [['type' => 'text', 'text' => $okText . '{}']]])['body'],
            json_encode(['schema_version' => 'myseo.ai.response.v1', 'provider' => 'gateway', 'task' => 'seo_analysis', 'status' => 'ok', 'output' => $outputs['seo_analysis']], JSON_THROW_ON_ERROR),
            '{',
            self::messages(['findings' => [], 'extra' => true])['body'],
        ];
        foreach ($bad as $raw) {
            $calls = 0;
            $transport = $this->callbackTransport(static function () use (&$calls, $raw): array {
                $calls++;
                return ['status' => 200, 'body' => $raw, 'sent' => true];
            });
            $repo = new AiSettingsRepository($this->configuredStore($this->gatewaySettings(['max_retries' => 2])), str_repeat('e', 32), null, false, $transport, $this->publicResolver());
            $this->assertGatewayFailure(new GatewayAiProvider($repo), $this->requestFixture(), AiProviderException::INVALID_RESPONSE);
            self::assertSame(1, $calls);
        }
    }

    // Confirm literal addresses bypass DNS and invalid deadlines fail closed.
    public function testNativeResolverRejectsUnboundedInputsOffline(): void
    {
        $resolver = new NativeGatewayResolver(static fn (): array => ['8.8.8.8'], static function (): string {
            self::fail('DNS query must not run for an invalid deadline.');
        });
        self::assertSame(['2606:4700:4700::1111'], $resolver->resolve('2606:4700:4700::1111', 5));
        self::assertSame([], $resolver->resolve('bad..host', 5));
        self::assertSame([], $resolver->resolve('gateway.example.test', 0));
    }

    // Confirm reflection rejects plaintext access and caller-supplied header callbacks.
    public function testCredentialBoundaryHasNoPlaintextOrHeaderCallbackApi(): void
    {
        $reflection = new \ReflectionClass(AiSettingsRepository::class);
        self::assertFalse($reflection->hasMethod('readPlaintext'));
        self::assertFalse($reflection->hasMethod('withAuthorizedHeaders'));
    }

    // Confirm plugin lifecycle and health never invoke gateway I/O.
    public function testReadinessPrecedenceAndZeroNetworkLifecycle(): void
    {
        $plugin = new Plugin();
        $plugin->boot();
        $health = $plugin->health();
        self::assertSame(['ai_enabled', 'ai_configured', 'ai_settings_state', 'ai_credential_state'], array_keys($health['ai']));
        self::assertSame(['admin', 'woocommerce', 'seo', 'rank_math'], array_column($health['modules'], 'name'));
    }

    // Adapt a scripted offline response to the actual transport contract.
    private function callbackTransport(\Closure $callback): GatewayTransport
    {
        return new class($callback) implements GatewayTransport {
            public function __construct(private readonly \Closure $callback) {}
            public function send(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] array $body, int $timeout, array $ips): array
            {
                return ($this->callback)($headers, $body, $ips, $url);
            }
        };
    }

    // Supply an approved public fixture address without invoking the network.
    private function publicResolver(): GatewayResolver
    {
        return new class implements GatewayResolver {
            public function resolve(string $host, int $timeoutSeconds = 5): array { return ['1.1.1.1']; }
        };
    }

    // Wrap task output in the upstream protocol and allow precise validation fixtures.
    private static function messages(array $output, array $overrides = []): array
    {
        $body = array_replace(['role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode($output, JSON_THROW_ON_ERROR)]]], $overrides);
        return ['status' => 200, 'body' => json_encode($body, JSON_THROW_ON_ERROR), 'sent' => true];
    }

    // Compare the closed category rather than an upstream or internal error message.
    private function assertGatewayFailure(GatewayAiProvider $provider, array $request, string $category): AiProviderException
    {
        try {
            $provider->generate($request);
            self::fail('Expected a gateway failure: ' . $category);
        } catch (AiProviderException $e) {
            self::assertSame($category, $e->getCategory());
            return $e;
        }
    }

    // Seed a synthetic credential through the same encrypted option path used by the plugin.
    private function configuredStore(array $settings): OptionStore
    {
        $store = $this->storeFor($settings);
        $repo = new AiSettingsRepository($store, str_repeat('e', 32));
        self::assertSame('saved', $repo->save([], 0, 'sk-offline-fixture-123456')['status']);
        return $store;
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
