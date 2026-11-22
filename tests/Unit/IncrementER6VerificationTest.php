<?php

/**
 * Verifies Increment E6 safety seams: capability gating, typed runtime observations,
 * strict source identifiers, and fail-closed Rank Math metadata access.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IncrementER6VerificationTest extends TestCase
{
    /**
     * Confirms malformed plugin metadata is invalid and unauthorized observers never read meta.
     */
    public function testRankMathInvalidAndObserverGates(): void
    {
        $probe = new \MySeoForAll\Integrations\RankMath\WordPressRankMathProbe(
            static fn (string $key, $default = null) => [123],
            static fn (): array => []
        );
        $activation = $probe->observeActivation();
        self::assertSame('invalid', $activation['evidence_state']);
        self::assertSame('wordpress:rank_math:active_plugins', $activation['evidence_identity']);

        $metaCalls = 0;
        $observer = new \MySeoForAll\Integrations\RankMath\WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static function (int $postId, string $slot) use (&$metaCalls): bool {
                $metaCalls++;
                return true;
            },
            static function (int $postId, string $slot) use (&$metaCalls): string {
                $metaCalls++;
                return 'secret';
            },
            static fn (string $capability, int $postId): bool => false
        );
        $result = $observer->observe('post:7', 'rank_math_title');
        self::assertSame('unauthorized_source', $result['reason']);
        self::assertSame(0, $metaCalls);
    }

    /**
     * Confirms denied admin access returns before invoking health or environment probes.
     */
    public function testAdminCapabilityGateDoesNotProbeHealth(): void
    {
        $healthCalls = 0;
        $environmentCalls = 0;
        $admin = new \MySeoForAll\Admin\AdminModule(
            new \MySeoForAll\Core\Configuration([]),
            new \MySeoForAll\Core\Environment\EnvironmentCheck(static function () use (&$environmentCalls): array {
                $environmentCalls++;
                return [];
            }),
            new \MySeoForAll\Infrastructure\WordPress\OptionStore(),
            static function () use (&$healthCalls): array {
                $healthCalls++;
                return [];
            },
            null,
            static fn (string $capability): bool => false
        );
        ob_start();
        $admin->renderPage();
        $html = ob_get_clean();
        self::assertSame('', $html);
        self::assertSame(0, $healthCalls);
        self::assertSame(0, $environmentCalls);
    }

    /**
     * Confirms malformed object identifiers fail before permalink helpers are called.
     */
    public function testDirtyObjectIdFailsBeforePermalink(): void
    {
        $permalinkCalls = 0;
        $reader = new \MySeoForAll\SEO\Runtime\WordPressContentReader(
            static function (int $id): object {
                return (object) [
                    'ID' => '7abc',
                    'post_type' => 'post',
                    'post_title' => 'Title',
                    'post_content' => 'Body',
                    'post_excerpt' => '',
                ];
            },
            null,
            null,
            static function (int $id) use (&$permalinkCalls): string {
                $permalinkCalls++;
                return 'https://example.test/post';
            }
        );
        $result = $reader->readPost(7);
        self::assertSame('invalid_post_source', $result['reason']);
        self::assertSame(0, $permalinkCalls);
    }

    /**
     * Confirms positive integer versions are converted to bounded diagnostic strings.
     */
    public function testVersionIntegerIsNormalizedSafely(): void
    {
        $check = new \MySeoForAll\Core\Environment\EnvironmentCheck(static function (): array {
            return ['php' => ['version' => 1], 'wordpress' => ['version' => '6.0']];
        });
        $status = $check->evaluate();
        self::assertSame('1', $status['php']['version']);
        self::assertSame('6.0', $status['wordpress']['version']);
    }
}
