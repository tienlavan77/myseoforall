<?php

/**
 * Increment E r2 safety tests protect the read-only runtime boundary.
 * They verify that environment probes and health diagnostics use closed,
 * typed projections, that readiness requires explicit positive evidence, and
 * that Rank Math source reads stop before any platform API call when the
 * requested slot is outside the approved mapping. The suite is fully offline
 * and does not persist, emit frontend SEO, or contact a network service.
 */

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Core\Environment\EnvironmentCheck;
use MySeoForAll\Core\HookDispatcher;
use MySeoForAll\Core\Plugin;
use MySeoForAll\Integrations\RankMath\WordPressRankMathSourceObserver;
use PHPUnit\Framework\TestCase;

final class IncrementER2SafetyTest extends TestCase
{
    /**
     * Verify injected unknown probe data cannot add fields or caller-controlled messages.
     */
    public function testEnvironmentProbeIsClosedAndReadinessRequiresTrue(): void
    {
        $environment = new EnvironmentCheck(static fn (): array => [
            'php' => [
                'installation' => 'installed',
                'activation' => 'active',
                'compatible' => 'unknown',
                'message' => 'sentinel-secret',
                'unexpected' => 'sentinel-secret',
            ],
            'wordpress' => [
                'installation' => 'unknown',
                'activation' => 'unknown',
                'compatible' => 'unknown',
            ],
        ]);

        $result = $environment->evaluate();

        self::assertSame('unknown', $result['php']['compatible']);
        self::assertArrayNotHasKey('unexpected', $result['php']);
        self::assertStringNotContainsString('sentinel-secret', json_encode($result));
        self::assertFalse($environment->isReady());
    }

    /**
     * Verify health output accepts only typed configuration and closed module/environment fields.
     */
    public function testHealthRedactsUnknownAndInvalidValues(): void
    {
        $configuration = new Configuration([
            'version' => '/etc/passwd',
            'locale' => 'vi_VN',
            'ai_enabled' => 'yes',
            'rank_math_mode' => 'secret-token',
            'unknown' => 'sentinel-secret',
        ]);
        $environment = new EnvironmentCheck(static fn (): array => [
            'php' => ['installation' => 'installed', 'activation' => 'active', 'compatible' => true],
            'wordpress' => ['installation' => 'unknown', 'activation' => 'unknown', 'compatible' => 'unknown'],
        ]);
        $plugin = new Plugin(null, $configuration, $environment, null, null, new HookDispatcher());

        $health = $plugin->health();
        $encoded = json_encode($health);

        self::assertIsString($encoded);
        self::assertStringNotContainsString('sentinel-secret', $encoded);
        self::assertStringNotContainsString('/etc/passwd', $encoded);
        self::assertArrayHasKey('coexistence', $health);
        self::assertFalse($health['coexistence']['write_allowed']);
    }

    /**
     * Verify unmapped Rank Math source requests never reach WordPress metadata APIs.
     */
    public function testRankMathObserverFailsClosedBeforePlatformRead(): void
    {
        $observer = new WordPressRankMathSourceObserver(['rank_math_title']);

        $result = $observer->observe('post:7', 'rank_math_description');

        self::assertSame('unreadable', $result['presence']);
        self::assertFalse($result['can_confirm_presence']);
        self::assertSame('unmapped_source', $result['reason']);
        self::assertSame('synthetic', $result['provenance']);
    }
}
