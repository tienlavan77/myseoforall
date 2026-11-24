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
use MySeoForAll\AI\CurlGatewayTransport;
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
}
