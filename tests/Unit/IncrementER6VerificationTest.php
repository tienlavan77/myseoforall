<?php

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use PHPUnit\Framework\TestCase;
use MySeoForAll\Integrations\RankMath\WordPressRankMathProbe;
use MySeoForAll\Integrations\RankMath\WordPressRankMathSourceObserver;

final class IncrementER6VerificationTest extends TestCase
{
    /**
     * Confirms Rank Math installation, activation, version, and malformed option states.
     */
    public function testRankMathProbeMatrix(): void
    {
        $outside = new WordPressRankMathProbe();
        self::assertSame('unobserved', $outside->observeActivation()['evidence_state']);

        $absent = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [],
            static fn (): array => []
        );
        self::assertSame('inactive', $absent->observeActivation()['state']);
        self::assertSame('absent', $absent->observeInstallation()['state']);

        $installedInactive = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [],
            static fn (): array => ['seo-by-rank-math/rank-math.php' => []]
        );
        self::assertSame('installed', $installedInactive->observeInstallation()['state']);
        self::assertSame('inactive', $installedInactive->observeActivation()['state']);

        $active = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => ['seo-by-rank-math/rank-math.php'],
            static fn (): array => ['seo-by-rank-math/rank-math.php' => []],
            static fn (): string => '1.0.210'
        );
        self::assertSame('active', $active->observeActivation()['state']);
        self::assertSame('present', $active->observeVersion()['state']);
        self::assertSame('1.0.210', $active->observeVersion()['value']);

        $invalid = new WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [123],
            static fn (): array => []
        );
        $invalidActivation = $invalid->observeActivation();
        self::assertSame('invalid', $invalidActivation['evidence_state']);
        self::assertSame('wordpress:rank_math:active_plugins', $invalidActivation['evidence_identity']);
    }

    /**
     * Confirms unmapped, malformed, and unauthorized source requests never call metadata bridges.
     */
    public function testObserverZeroMetaCallsForRejectedSources(): void
    {
        $metaCalls = 0;
        $exists = static function (string $objectType, int $postId, string $slot) use (&$metaCalls): bool {
            $metaCalls++;
            return true;
        };
        $reader = static function (int $postId, string $slot, bool $single) use (&$metaCalls): string {
            $metaCalls++;
            return 'value';
        };

        foreach ([
            [[], 'post:7', 'rank_math_title', null],
            [['rank_math_title'], 'bad', 'rank_math_title', true],
            [['rank_math_title'], 'post:7', 'other_slot', true],
            [['rank_math_title'], 'post:7', 'rank_math_title', false],
        ] as [$slots, $sourceId, $slot, $allowed]) {
            $observer = new WordPressRankMathSourceObserver(
                $slots,
                $exists,
                $reader,
                $allowed === null ? null : static fn (string $capability, int $postId): bool => $allowed
            );
            $observer->observe($sourceId, $slot);
        }
        self::assertSame(0, $metaCalls);
    }

    /**
     * Confirms authorized mapped reads distinguish missing and present metadata and preserve provenance.
     */
    public function testObserverAuthorizedPresenceAndUnavailableBridge(): void
    {
        $missing = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static fn (string $objectType, int $postId, string $slot): bool => false,
            static fn (int $postId, string $slot, bool $single): string => 'unused',
            static fn (string $capability, int $postId): bool => true
        );
        $missingResult = $missing->observe('post:7', 'rank_math_title');
        self::assertSame('missing', $missingResult['presence']);
        self::assertSame('runtime_verified', $missingResult['provenance']);

        $present = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static fn (string $objectType, int $postId, string $slot): bool => true,
            static fn (int $postId, string $slot, bool $single): string => 'observed',
            static fn (string $capability, int $postId): bool => true
        );
        $presentResult = $present->observe('post:7', 'rank_math_title');
        self::assertSame('present', $presentResult['presence']);
        self::assertSame('observed', $presentResult['value']);
        self::assertSame('runtime_verified', $presentResult['provenance']);

        $unavailable = new WordPressRankMathSourceObserver(
            ['rank_math_title'],
            null,
            null,
            static fn (string $capability, int $postId): bool => true
        );
        $unavailableResult = $unavailable->observe('post:7', 'rank_math_title');
        self::assertSame('unreadable', $unavailableResult['presence']);
        self::assertFalse($unavailableResult['can_confirm_presence']);
        self::assertSame('synthetic', $unavailableResult['provenance']);
    }

    /**
     * Confirms admin capability gating and coexistence labels reject untrusted owner/state values.
     */
    public function testAdminCapabilityGateAndCoexistenceRedaction(): void
    {
        $healthCalls = 0;
        $environmentCalls = 0;
        $healthProvider = static function () use (&$healthCalls): array {
            $healthCalls++;
            return [
                'environment' => [
                    'php' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'active'],
                    'wordpress' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'active'],
                    'woocommerce' => ['compatible' => true, 'installation' => 'installed', 'activation' => 'inactive'],
                    'mbstring' => ['compatible' => true, 'installation' => 'absent', 'activation' => 'unknown', 'fallback' => true],
                ],
                'coexistence' => [
                    'outputs' => [
                        'title_meta' => [
                            'owner' => '<script>bad</script>',
                            'state' => '<script>bad</script>',
                            'duplicate_risk' => 'blocked',
                        ],
                    ],
                ],
                'modules' => [],
            ];
        };
        $admin = new \MySeoForAll\Admin\AdminModule(
            new \MySeoForAll\Core\Configuration\Configuration([]),
            new \MySeoForAll\Core\Environment\EnvironmentCheck(
                static function () use (&$environmentCalls): array {
                    $environmentCalls++;
                    return [];
                }
            ),
            new \MySeoForAll\Infrastructure\WordPress\OptionStore(),
            $healthProvider,
            null,
            static fn (string $capability): bool => true
        );
        ob_start();
        $admin->renderPage();
        $html = ob_get_clean();
        self::assertSame(1, $healthCalls);
        self::assertSame(0, $environmentCalls);
        self::assertStringContainsString('duplicate_risk=blocked', $html);
        self::assertStringContainsString('owner=unknown; state=unknown', $html);
        self::assertStringContainsString('fallback UTF-8 deterministic', $html);
        self::assertStringNotContainsString('<script>bad</script>', $html);
    }
}
