<?php

/*
 * This detector reports Rank Math availability through a narrow integration
 * boundary without loading Rank Math classes or reading its private metadata.
 * It gives coexistence code a safe status object while leaving ownership writes
 * to a future adapter with an explicit contract.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

// Defines RankMathDetector class behavior for RankMathDetector.php.
final class RankMathDetector
{
    /**
     * Detect the active Rank Math installation without requiring its dependency.
     *
     * @return array{name:string,installed:bool,active:bool,version:?string,compatible:bool,ownership:string}
     */
    public function detect(): array
    {
        $active = defined('RANK_MATH_VERSION');
        $version = $active ? (string) constant('RANK_MATH_VERSION') : null;

        return $this->status($active, $version);
    }

    /**
     * Normalize externally supplied facts for deterministic adapter and unit testing.
     *
     * @return array{name:string,installed:bool,active:bool,version:?string,compatible:bool,ownership:string}
     */
    public function status(bool $active, ?string $version = null): array
    {
        $version = $version !== null && trim($version) !== '' ? trim($version) : null;

        return [
            'name' => 'rank_math',
            'installed' => $active,
            'active' => $active,
            'version' => $version,
            'compatible' => $active,
            'ownership' => $active ? 'rank_math' : 'unknown',
        ];
    }
}
