<?php

/**
 * Coexistence, precedence, permutation, and negative-boundary tests for OwnershipRegistry (Increment D — O3).
 *
 * Business purpose: Verify every row of the O3 decision table (`identity_collision`,
 * `assertion_capability_conflict`, `conflicting_evidence`, `overlapping_claims`, `split_ownership`,
 * `partial_ownership`, `no_valid_claim`, `other_emitters_excluded`, `other_emitters_not_excluded`),
 * absence of `decision_state`, `write_allowed=false`, `ai_draft` internal flags, and runtime isolation.
 * Technical description: Exercises OwnershipRegistry with A-B / B-A / A-B-A permutations, complete vs
 * incomplete inventories, detector-to-registry integration, input immutability, and absence of Plugin wiring.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Core\Plugin;
use MySeoForAll\Integrations\RankMath\OwnershipRegistry;
use MySeoForAll\Integrations\RankMath\RankMathDetector;
use PHPUnit\Framework\TestCase;

/**
 * Verifies O3 ownership registry coexistence rules and Increment D negative boundaries.
 *
 * Business purpose: Guarantee deterministic single-owner evaluation and duplicate-output protection
 * without inferring ownership from active plugins or enabling public SEO writes.
 * Technical description: Runs pure unit scenarios across all four output surfaces (`title_meta`,
 * `json_ld`, `sitemap`, `ai_draft`) and verifies out-of-scope isolation (`open_graph`, `robots`).
 */
final class OwnershipRegistryCoexistenceTest extends TestCase
{
    /**
     * Verify identity collision permutations A-B, B-A, and A-B-A yield identical results without leaking across outputs.
     *
     * Business purpose: Prove O3 requirement #8 — colliding observations with the same `observation_id`
     * but opposite `can_emit` always resolve to `unknown/conflict/blocked/identity_collision` in any order,
     * while exact duplicates merge cleanly and unrelated outputs remain unaffected.
     * Technical description: Evaluates A-B, B-A, and A-B-A on `json_ld` alongside a valid claim on `sitemap`.
     */
    public function testIdentityCollisionIsPermutationAndRepetitionInvariantAndOutputIsolated(): void
    {
        $registry = new OwnershipRegistry();

        $obsA = [
            'observation_id' => 'colliding-id-1',
            'producer' => 'rank_math',
            'output' => 'json_ld',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $obsB = [
            'observation_id' => 'colliding-id-1',
            'producer' => 'rank_math',
            'output' => 'json_ld',
            'assertion' => 'claim',
            'can_emit' => false,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $unrelatedSitemap = [
            'observation_id' => 'clean-sitemap-1',
            'producer' => 'rank_math',
            'output' => 'sitemap',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];

        $ab = $registry->resolve(['observations' => [$obsA, $obsB, $unrelatedSitemap]]);
        $ba = $registry->resolve(['observations' => [$obsB, $obsA, $unrelatedSitemap]]);
        $aba = $registry->resolve(['observations' => [$obsA, $obsB, $obsA, $unrelatedSitemap, $unrelatedSitemap]]);

        self::assertSame($ab, $ba);
        self::assertSame($ab, $aba);

        self::assertSame('unknown', $ab['outputs']['json_ld']['owner']);
        self::assertSame('conflict', $ab['outputs']['json_ld']['state']);
        self::assertSame('blocked', $ab['outputs']['json_ld']['duplicate_risk']);
        self::assertSame('identity_collision', $ab['outputs']['json_ld']['reason']);
        self::assertFalse($ab['outputs']['json_ld']['write_allowed']);
        self::assertArrayNotHasKey('decision_state', $ab['outputs']['json_ld']);

        self::assertSame('rank_math', $ab['outputs']['sitemap']['owner']);
        self::assertSame('owned', $ab['outputs']['sitemap']['state']);
        self::assertSame('possible', $ab['outputs']['sitemap']['duplicate_risk']);
        self::assertSame('other_emitters_not_excluded', $ab['outputs']['sitemap']['reason']);
    }

    /**
     * Verify rows 2–7 of the O3 decision table: capability conflict, conflicting evidence, overlap, split, partial, and no valid claim.
     *
     * Business purpose: Confirm strict top-down precedence and field-level `title_meta` coverage rules.
     * Technical description: Exercises each row on `title_meta` and verifies `declared`/`missing`/`invalid`
     * observations never establish ownership.
     */
    public function testPrecedenceRowsTwoThroughSevenAndTitleMetaCoverage(): void
    {
        $registry = new OwnershipRegistry();

        $capConflict = $registry->resolve([
            'observations' => [[
                'observation_id' => 'cap-conf-1',
                'producer' => 'rank_math',
                'output' => 'title_meta',
                'fields' => ['title', 'meta_description', 'canonical'],
                'assertion' => 'claim',
                'can_emit' => false,
                'source' => 'fixture:rm',
                'evidence_state' => 'observed',
            ]],
        ]);
        self::assertSame('conflict', $capConflict['outputs']['title_meta']['state']);
        self::assertSame('blocked', $capConflict['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('assertion_capability_conflict', $capConflict['outputs']['title_meta']['reason']);

        $sameProducerClaimDeny = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'claim-1',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'fields' => ['title', 'meta_description', 'canonical'],
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'deny-1',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'fields' => ['canonical'],
                    'assertion' => 'deny',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('conflict', $sameProducerClaimDeny['outputs']['title_meta']['state']);
        self::assertSame('blocked', $sameProducerClaimDeny['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('conflicting_evidence', $sameProducerClaimDeny['outputs']['title_meta']['reason']);

        $overlapping = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'rm-title-canon',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'fields' => ['title', 'canonical'],
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'yoast-canon-desc',
                    'producer' => 'yoast_seo',
                    'output' => 'title_meta',
                    'fields' => ['canonical', 'meta_description'],
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:yoast',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('conflict', $overlapping['outputs']['title_meta']['state']);
        self::assertSame('blocked', $overlapping['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('overlapping_claims', $overlapping['outputs']['title_meta']['reason']);

        $split = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'rm-title-desc',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'fields' => ['title', 'meta_description'],
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'theme-canon',
                    'producer' => 'theme_seo',
                    'output' => 'title_meta',
                    'fields' => ['canonical'],
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:theme',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('unknown', $split['outputs']['title_meta']['owner']);
        self::assertSame('unknown', $split['outputs']['title_meta']['state']);
        self::assertSame('possible', $split['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('split_ownership', $split['outputs']['title_meta']['reason']);

        $partial = $registry->resolve([
            'observations' => [[
                'observation_id' => 'rm-partial',
                'producer' => 'rank_math',
                'output' => 'title_meta',
                'fields' => ['title', 'meta_description'],
                'assertion' => 'claim',
                'can_emit' => true,
                'source' => 'fixture:rm',
                'evidence_state' => 'observed',
            ]],
        ]);
        self::assertSame('unknown', $partial['outputs']['title_meta']['owner']);
        self::assertSame('unknown', $partial['outputs']['title_meta']['state']);
        self::assertSame('possible', $partial['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('partial_ownership', $partial['outputs']['title_meta']['reason']);

        $declaredAndInvalidOnly = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'declared-only',
                    'producer' => 'rank_math',
                    'output' => 'json_ld',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'declared',
                ],
                [
                    'observation_id' => 'invalid-only',
                    'producer' => 'other_seo',
                    'output' => 'json_ld',
                    'assertion' => 'bogus_assertion',
                    'can_emit' => 'not_bool',
                    'source' => 'fixture:other',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('unknown', $declaredAndInvalidOnly['outputs']['json_ld']['owner']);
        self::assertSame('unknown', $declaredAndInvalidOnly['outputs']['json_ld']['state']);
        self::assertSame('no_valid_claim', $declaredAndInvalidOnly['outputs']['json_ld']['reason']);
    }

    /**
     * Verify rows 8 and 9: complete inventory + observed non-emission (`none`) vs deny-with-can_emit=true or bare flag (`possible`).
     *
     * Business purpose: Prove `duplicate_risk=none` requires a scoped, identified producer inventory AND
     * observed `can_emit=false` on every required field for all other producers; `deny` alone keeps `possible`.
     * Technical description: Tests A claims all + B denies with `can_emit=true` (`possible`), bare flag without
     * source/revision (`possible`), and verified inventory + B `can_emit=false` across all 3 fields (`none`).
     */
    public function testDuplicateRiskNoneRequiresScopedInventoryAndConfirmedDisabledEmission(): void
    {
        $registry = new OwnershipRegistry();

        $claimA = [
            'observation_id' => 'a-claim-all',
            'producer' => 'rank_math',
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'output' => 'title_meta',
            'fields' => ['title', 'meta_description', 'canonical'],
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $denyBStillCanEmit = [
            'observation_id' => 'b-deny-can-emit',
            'producer' => 'myseo_for_all',
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'output' => 'title_meta',
            'fields' => ['title', 'meta_description', 'canonical'],
            'assertion' => 'deny',
            'can_emit' => true,
            'source' => 'fixture:myseo',
            'evidence_state' => 'observed',
        ];
        $validInventory = [
            'source_id' => 'fixture:plugin_inventory',
            'revision' => 'inv.r1',
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'output' => 'title_meta',
            'evidence_state' => 'observed',
            'complete' => true,
            'producers' => ['rank_math', 'myseo_for_all'],
        ];

        $denyButCanEmit = $registry->resolve([
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'observations' => [$claimA, $denyBStillCanEmit],
            'inventories' => ['title_meta' => $validInventory],
        ]);
        self::assertSame('rank_math', $denyButCanEmit['outputs']['title_meta']['owner']);
        self::assertSame('owned', $denyButCanEmit['outputs']['title_meta']['state']);
        self::assertSame('possible', $denyButCanEmit['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('other_emitters_not_excluded', $denyButCanEmit['outputs']['title_meta']['reason']);

        $disabledBAllFields = [
            'observation_id' => 'b-disabled-all',
            'producer' => 'myseo_for_all',
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'output' => 'title_meta',
            'fields' => ['title', 'meta_description', 'canonical'],
            'assertion' => 'absent',
            'can_emit' => false,
            'source' => 'fixture:myseo',
            'evidence_state' => 'observed',
        ];

        $bareFlagOnly = $registry->resolve([
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'observations' => [$claimA, $disabledBAllFields],
            'inventories' => ['title_meta' => ['complete' => true]],
        ]);
        self::assertSame('possible', $bareFlagOnly['outputs']['title_meta']['duplicate_risk']);

        $excluded = $registry->resolve([
            'site' => 'site-1',
            'snapshot' => 'snap-1',
            'observations' => [$claimA, $disabledBAllFields],
            'inventories' => ['title_meta' => $validInventory],
        ]);
        self::assertSame('rank_math', $excluded['outputs']['title_meta']['owner']);
        self::assertSame('owned', $excluded['outputs']['title_meta']['state']);
        self::assertSame('none', $excluded['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('other_emitters_excluded', $excluded['outputs']['title_meta']['reason']);
        self::assertFalse($excluded['outputs']['title_meta']['write_allowed']);
    }

    /**
     * Verify detector->registry migration contract, ai_draft internal flags, out-of-scope surfaces, and Plugin non-wiring.
     *
     * Business purpose: Confirm active detector status never implies ownership, `ai_draft` stays internal
     * (`frontend_owned=false`, `publish_capability=false`, `write_allowed=false`), Open Graph / robots stay out
     * of scope, inputs are never mutated, and `Plugin::boot()` remains untouched by Increment D.
     * Technical description: Feeds detector output into registry, checks `ai_draft` flags, verifies input
     * preservation, and inspects `Plugin` health modules.
     */
    public function testDetectorToRegistryMigrationAiDraftFlagsAndNegativeRuntimeBoundaries(): void
    {
        $detector = new RankMathDetector();
        $detectorResult = $detector->status(true, '1.0.210');

        $inputContext = [
            'detector' => $detectorResult,
            'observations' => [[
                'observation_id' => 'draft-internal-1',
                'producer' => 'myseo_for_all',
                'output' => 'ai_draft',
                'assertion' => 'claim',
                'can_emit' => true,
                'source' => 'fixture:internal_draft_module',
                'evidence_state' => 'observed',
            ]],
        ];
        $copyBefore = $inputContext;

        $registry = new OwnershipRegistry();
        $resolved = $registry->resolve($inputContext, ['rank_math', 'yoast_seo']);

        self::assertSame($copyBefore, $inputContext);
        self::assertSame('unknown', $resolved['outputs']['title_meta']['owner']);
        self::assertSame('unknown', $resolved['outputs']['title_meta']['state']);
        self::assertSame('no_valid_claim', $resolved['outputs']['title_meta']['reason']);

        self::assertSame('myseo_for_all', $resolved['outputs']['ai_draft']['owner']);
        self::assertSame('owned', $resolved['outputs']['ai_draft']['state']);
        self::assertFalse($resolved['outputs']['ai_draft']['frontend_owned']);
        self::assertFalse($resolved['outputs']['ai_draft']['publish_capability']);
        self::assertFalse($resolved['outputs']['ai_draft']['write_allowed']);

        self::assertSame(['open_graph', 'robots'], $resolved['out_of_scope_outputs']);
        self::assertArrayNotHasKey('open_graph', $resolved['outputs']);
        self::assertArrayNotHasKey('robots', $resolved['outputs']);
        self::assertFalse($resolved['write_allowed']);

        $plugin = new Plugin();
        $plugin->boot();
        $health = $plugin->health();
        self::assertArrayNotHasKey('rank_math', $health['modules'] ?? []);
    }
}
