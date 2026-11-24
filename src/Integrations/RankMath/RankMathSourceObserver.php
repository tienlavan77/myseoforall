<?php

/**
 * Injectable source-observation contract for the Rank Math read adapter boundary.
 *
 * Business purpose: Preserve explicit distinction between `present` (including explicit null),
 * `missing`, and `unreadable` observations while preventing sources that cannot confirm presence
 * or carry unverified provenance from triggering silent fallbacks or masquerading as runtime reads.
 * Technical description: Defines RankMathSourceObserver and its closed provenance vocabulary
 * (`fixture|synthetic|runtime_verified`) matched strictly by RankMathReadAdapter.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Contract for reading raw Rank Math source keys with explicit presence and provenance semantics.
 *
 * Business purpose: Allow RankMathReadAdapter to read allowlisted Rank Math slots without
 * coupling to WordPress runtime functions or losing `missing` vs `explicit_null` semantics.
 * Technical description: Implementations return structured observation arrays carrying presence,
 * explicit value key when present, source identifier, evidence identity, closed provenance kind,
 * and safe error reason.
 */
interface RankMathSourceObserver
{
    public const ALLOWED_PROVENANCE = [
        'fixture',
        'synthetic',
        'runtime_verified',
    ];

    /**
     * Observe a single mapped Rank Math source slot without mutating underlying storage.
     *
     * Business purpose: Supply presence-aware read evidence so fallback only runs on confirmed `missing`.
     * Technical description: Returns associative observation with `presence` (`present|missing|unreadable`),
     * `can_confirm_presence` (`bool`), `value` (`mixed`, required key when `presence=present`),
     * `source` (`string`), `evidence_identity` (`string`), `provenance` (`fixture|synthetic|runtime_verified`),
     * and optional `reason` (`string`).
     *
     * @return array<string, mixed>
     */
    public function observe(string $sourceId, string $slotKey): array;
}
