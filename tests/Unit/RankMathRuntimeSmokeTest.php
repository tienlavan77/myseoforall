<?php
/*
 * Exercises the Increment E passive Rank Math runtime bridge without WordPress.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Integrations\RankMath\RankMathCompatibilityMatrix;
use MySeoForAll\Integrations\RankMath\RankMathDetector;
use MySeoForAll\Integrations\RankMath\WordPressRankMathProbe;
use PHPUnit\Framework\TestCase;

final class RankMathRuntimeSmokeTest extends TestCase
{
    /**
     * Verify absent WordPress APIs produce unknown compatibility and unobserved evidence.
     * @return void
     */
    public function testProbeFailsClosed(): void
    {
        $probe = new WordPressRankMathProbe();
        $detector = new RankMathDetector(RankMathCompatibilityMatrix::shippedDefault(), $probe);
        $result = $detector->detect();

        self::assertSame('unknown', $result['compatibility']);
        self::assertSame('unobserved', $result['signals']['installation']['evidence_state']);
    }
}
