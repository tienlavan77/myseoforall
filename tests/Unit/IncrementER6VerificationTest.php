<?php

/**
 * Verifies that malformed Rank Math metadata is classified safely and unauthorized
 * source observations stop before any WordPress metadata read.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IncrementER6VerificationTest extends TestCase
{
    /**
     * Confirms invalid plugin metadata and capability denial are both fail-closed.
     */
    public function testRankMathObservationGates(): void
    {
        $probe = new \MySeoForAll\Integrations\RankMath\WordPressRankMathProbe(
            static fn (string $key, $default = null): array => [123],
            static fn (): array => []
        );
        $activation = $probe->observeActivation();
        self::assertSame('invalid', $activation['evidence_state']);

        $metaCalls = 0;
        $observer = new \MySeoForAll\Integrations\RankMath\WordPressRankMathSourceObserver(
            ['rank_math_title'],
            static function (string $objectType, int $postId, string $slot) use (&$metaCalls): bool {
                $metaCalls++;
                return true;
            },
            static function (int $postId, string $slot, bool $single) use (&$metaCalls): string {
                $metaCalls++;
                return 'secret';
            },
            static fn (string $capability, int $postId): bool => false
        );
        $result = $observer->observe('post:7', 'rank_math_title');
        self::assertSame('unauthorized_source', $result['reason']);
        self::assertSame(0, $metaCalls);
    }
}
