<?php
/**
 * Ownership registry resolves SEO output ownership from verified integration facts without claiming ownership when state is unknown or conflicting.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

// Defines OwnershipRegistry class behavior for OwnershipRegistry.php.
final class OwnershipRegistry
{
    /** Resolve output owners while keeping public SEO writes disabled until ownership is explicit. */
    public function resolve(array $rankMath, array $activePlugins = []): array
    {
        $active = ($rankMath['active'] ?? false) === true;
        $compatible = ($rankMath['compatible'] ?? false) === true;
        $otherPlugins = array_values(array_filter($activePlugins, static function (mixed $plugin): bool {
            return is_string($plugin) && !in_array(strtolower($plugin), ['rank_math', 'wordpress'], true);
        }));

        if ($active && $otherPlugins !== []) {
            $state = 'conflict';
        } elseif ($active && $compatible) {
            $state = 'rank_math';
        } else {
            $state = 'unknown';
        }

        $owners = [
            'title_meta' => $state === 'rank_math' ? 'rank_math' : $state,
            'json_ld' => $state === 'rank_math' ? 'rank_math' : $state,
            'sitemap' => $state === 'rank_math' ? 'rank_math' : $state,
            'ai_draft' => 'myseo_for_all',
        ];

        return [
            'state' => $state,
            'ownership' => $owners,
            'write_allowed' => false,
        ];
    }
}
