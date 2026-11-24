<?php

/**
 * Edge-case regression tests for OwnershipRegistry and OwnershipObservationNormalizer (Increment D r2 — D2-3..D2-6).
 *
 * Business purpose: Prove global `observation_id` collision detection across outputs and scopes,
 * distinct malformed-record collision detection, non-interference of `declared`/`missing` denials
 * with `observed` claims, absence of wildcard `['*']` field expansion on `title_meta`, preservation
 * of `evidence_state = conflict` over sub-field errors, strict scope validation, and canonical producer slugs.
 * Technical description: Exercises OwnershipRegistry across A-B / B-A permutations and boundary payloads
 * identified during Architecture review r2.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Integrations\RankMath\OwnershipRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies D2-3 through D2-6 fail-closed coexistence contracts on OwnershipRegistry.
 *
 * Business purpose: Prevent cross-bucket identity reuse, false `conflicting_evidence` blocks from
 * unverified assertions, wildcard field conflicts, downgraded conflict signals, and scope coercion.
 * Technical description: Pure unit tests covering permutation invariance and single-producer inventories.
 */
final class OwnershipRegistryEdgeContractTest extends TestCase
{
    /**
     * Verify D2-3: `observation_id` collisions across different outputs, sites/snapshots, or distinct malformed shapes.
     *
     * Business purpose: Ensure reusing an `observation_id` with any differing fact (another output,
     * another site/snapshot, an invalid output, or two differently malformed records) triggers
     * `identity_collision` in both A-B and B-A orders without letting either claim win `owned`.
     * Technical description: Tests cross-output (`json_ld` vs `sitemap`), cross-site (`site-1` vs `site-2`),
     * and differently malformed records sharing `obs-malformed-1`.
     */
    public function testGlobalObservationIdCollisionsAcrossOutputsScopesAndMalformedShapes(): void
    {
        $registry = new OwnershipRegistry();

        $jsonLdObs = [
            'observation_id' => 'shared-obs-cross-output',
            'producer' => 'rank_math',
            'output' => 'json_ld',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $sitemapObs = [
            'observation_id' => 'shared-obs-cross-output',
            'producer' => 'rank_math',
            'output' => 'sitemap',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];

        $crossAb = $registry->resolve(['observations' => [$jsonLdObs, $sitemapObs]]);
        $crossBa = $registry->resolve(['observations' => [$sitemapObs, $jsonLdObs]]);
        self::assertSame($crossAb, $crossBa);
        self::assertSame('identity_collision', $crossAb['outputs']['json_ld']['reason']);
        self::assertSame('identity_collision', $crossAb['outputs']['sitemap']['reason']);
        self::assertSame('unknown', $crossAb['outputs']['ai_draft']['state']);

        $inScopeSite = [
            'observation_id' => 'shared-obs-cross-site',
            'producer' => 'rank_math',
            'site' => 'site-a',
            'snapshot' => 'snap-1',
            'output' => 'json_ld',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $otherSite = [
            'observation_id' => 'shared-obs-cross-site',
            'producer' => 'rank_math',
            'site' => 'site-b',
            'snapshot' => 'snap-1',
            'output' => 'json_ld',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];

        $siteAb = $registry->resolve(['site' => 'site-a', 'snapshot' => 'snap-1', 'observations' => [$inScopeSite, $otherSite]]);
        $siteBa = $registry->resolve(['site' => 'site-a', 'snapshot' => 'snap-1', 'observations' => [$otherSite, $inScopeSite]]);
        self::assertSame($siteAb, $siteBa);
        self::assertSame('conflict', $siteAb['outputs']['json_ld']['state']);
        self::assertSame('identity_collision', $siteAb['outputs']['json_ld']['reason']);

        $malformedOne = [
            'observation_id' => 'obs-malformed-1',
            'producer' => '',
            'output' => 'sitemap',
            'assertion' => 'bad_one',
            'can_emit' => 123,
            'source' => 'fixture:a',
            'evidence_state' => 'observed',
        ];
        $malformedTwo = [
            'observation_id' => 'obs-malformed-1',
            'producer' => 'UPPERCASE_INVALID',
            'output' => 'sitemap',
            'assertion' => 'bad_two',
            'can_emit' => 'wrong',
            'source' => 'fixture:b',
            'evidence_state' => 'observed',
        ];
        $malAb = $registry->resolve(['observations' => [$malformedOne, $malformedTwo]]);
        $malBa = $registry->resolve(['observations' => [$malformedTwo, $malformedOne]]);
        self::assertSame($malAb, $malBa);
        self::assertSame('conflict', $malAb['outputs']['sitemap']['state']);
        self::assertSame('identity_collision', $malAb['outputs']['sitemap']['reason']);
    }

    /**
     * Verify D2-4 & D2-5: `declared`/`missing` denials do not block `observed` claims, empty `title_meta` fields do not wildcard, and `conflict` evidence is never downgraded to `invalid`.
     *
     * Business purpose: Protect valid `observed` owners from stale `declared`/`missing` records, prevent
     * fieldless `title_meta` records from triggering false `assertion_capability_conflict`, and preserve
     * explicit `evidence_state = conflict` even when sub-fields are malformed.
     * Technical description: Evaluates all four scenarios against OwnershipRegistry::resolve().
     */
    public function testDeclaredDenyDoesNotBlockObservedClaimNoWildcardFieldsAndConflictPreservation(): void
    {
        $registry = new OwnershipRegistry();

        $declaredDenyAndThirdPartyDeclaredPair = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'rm-valid-claim',
                    'producer' => 'rank_math',
                    'output' => 'json_ld',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'rm-stale-declared-deny',
                    'producer' => 'rank_math',
                    'output' => 'json_ld',
                    'assertion' => 'deny',
                    'can_emit' => true,
                    'source' => 'fixture:rm_manifest',
                    'evidence_state' => 'declared',
                ],
                [
                    'observation_id' => 'rm-missing-deny',
                    'producer' => 'rank_math',
                    'output' => 'json_ld',
                    'assertion' => 'deny',
                    'can_emit' => true,
                    'source' => 'fixture:rm_legacy',
                    'evidence_state' => 'missing',
                ],
                [
                    'observation_id' => 'other-declared-claim',
                    'producer' => 'other_seo',
                    'output' => 'json_ld',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:other',
                    'evidence_state' => 'declared',
                ],
                [
                    'observation_id' => 'other-declared-deny',
                    'producer' => 'other_seo',
                    'output' => 'json_ld',
                    'assertion' => 'deny',
                    'can_emit' => true,
                    'source' => 'fixture:other',
                    'evidence_state' => 'declared',
                ],
            ],
        ]);
        self::assertSame('rank_math', $declaredDenyAndThirdPartyDeclaredPair['outputs']['json_ld']['owner']);
        self::assertSame('owned', $declaredDenyAndThirdPartyDeclaredPair['outputs']['json_ld']['state']);
        self::assertSame('other_emitters_not_excluded', $declaredDenyAndThirdPartyDeclaredPair['outputs']['json_ld']['reason']);

        $fieldlessTitleMetaClaim = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'rm-empty-fields-claim',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'rm-canonical-disabled',
                    'producer' => 'rank_math',
                    'output' => 'title_meta',
                    'fields' => ['canonical'],
                    'assertion' => 'absent',
                    'can_emit' => false,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('unknown', $fieldlessTitleMetaClaim['outputs']['title_meta']['state']);
        self::assertSame('no_valid_claim', $fieldlessTitleMetaClaim['outputs']['title_meta']['reason']);
        self::assertNotSame('assertion_capability_conflict', $fieldlessTitleMetaClaim['outputs']['title_meta']['reason']);

        $conflictWithMalformedSubfields = $registry->resolve([
            'observations' => [[
                'observation_id' => 'explicit-conflict-malformed',
                'producer' => '',
                'output' => 'title_meta',
                'fields' => ['not_allowed_field'],
                'assertion' => 'invalid_assertion',
                'can_emit' => 'invalid_bool',
                'source' => '',
                'evidence_state' => 'conflict',
            ]],
        ]);
        self::assertSame('conflict', $conflictWithMalformedSubfields['outputs']['title_meta']['state']);
        self::assertSame('blocked', $conflictWithMalformedSubfields['outputs']['title_meta']['duplicate_risk']);
        self::assertSame('conflicting_evidence', $conflictWithMalformedSubfields['outputs']['title_meta']['reason']);

        $missingFieldsObserved = [
            'observation_id' => 'title-meta-missing-fields',
            'producer' => 'rank_math',
            'output' => 'title_meta',
            'assertion' => 'claim',
            'can_emit' => true,
            'source' => 'fixture:rm',
            'evidence_state' => 'observed',
        ];
        $missingFieldsDeclared = $missingFieldsObserved;
        $missingFieldsDeclared['evidence_state'] = 'declared';
        $missingFieldsCollision = $registry->resolve([
            'observations' => [$missingFieldsObserved, $missingFieldsDeclared],
        ]);
        self::assertSame('conflict', $missingFieldsCollision['outputs']['title_meta']['state']);
        self::assertSame('identity_collision', $missingFieldsCollision['outputs']['title_meta']['reason']);
    }

    /**
     * Verify D2-6: malformed `site`/`snapshot` never coerce into `default_site`/`default_snapshot`, canonical producer slugs are preserved, and 1-producer inventory + `invalid` observation resolves cleanly.
     *
     * Business purpose: Prevent scope-corrupted observations from polluting default scope, fail closed
     * when `resolve()` receives malformed scope keys, avoid silent `strtolower` producer mutation,
     * and verify single-owner inventory with extraneous invalid observations yields `duplicate_risk=none`.
     * Technical description: Tests malformed observation scope, malformed context scope, uppercase producer rejection, and 1-producer inventory.
     */
    public function testScopeValidationCanonicalProducerSlugsAndSingleProducerInventory(): void
    {
        $registry = new OwnershipRegistry();

        $pollutedScopeObs = $registry->resolve([
            'observations' => [
                [
                    'observation_id' => 'bad-site-empty',
                    'producer' => 'rank_math',
                    'site' => '',
                    'output' => 'sitemap',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'bad-snapshot-int',
                    'producer' => 'rank_math',
                    'snapshot' => 123,
                    'output' => 'sitemap',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
            ],
        ]);
        self::assertSame('unknown', $pollutedScopeObs['outputs']['sitemap']['owner']);
        self::assertSame('no_valid_claim', $pollutedScopeObs['outputs']['sitemap']['reason']);

        $malformedContextScope = $registry->resolve([
            'site' => '   ',
            'snapshot' => "bad\x00snap",
            'observations' => [[
                'observation_id' => 'clean-obs',
                'producer' => 'rank_math',
                'output' => 'sitemap',
                'assertion' => 'claim',
                'can_emit' => true,
                'source' => 'fixture:rm',
                'evidence_state' => 'observed',
            ]],
        ]);
        self::assertSame('unknown', $malformedContextScope['outputs']['sitemap']['state']);
        self::assertSame('possible', $malformedContextScope['outputs']['sitemap']['duplicate_risk']);
        self::assertSame('invalid_scope', $malformedContextScope['outputs']['sitemap']['reason']);
        self::assertFalse($malformedContextScope['outputs']['ai_draft']['frontend_owned']);
        self::assertFalse($malformedContextScope['outputs']['ai_draft']['publish_capability']);

        $nonCanonicalProducer = $registry->resolve([
            'observations' => [[
                'observation_id' => 'upper-producer',
                'producer' => 'RankMath_MixedCase',
                'output' => 'sitemap',
                'assertion' => 'claim',
                'can_emit' => true,
                'source' => 'fixture:rm',
                'evidence_state' => 'observed',
            ]],
        ]);
        self::assertSame('unknown', $nonCanonicalProducer['outputs']['sitemap']['owner']);
        self::assertSame('no_valid_claim', $nonCanonicalProducer['outputs']['sitemap']['reason']);

        $singleOwnerInventory = $registry->resolve([
            'site' => 'site-solo',
            'snapshot' => 'snap-solo',
            'observations' => [
                [
                    'observation_id' => 'solo-owner-claim',
                    'producer' => 'rank_math',
                    'site' => 'site-solo',
                    'snapshot' => 'snap-solo',
                    'output' => 'sitemap',
                    'assertion' => 'claim',
                    'can_emit' => true,
                    'source' => 'fixture:rm',
                    'evidence_state' => 'observed',
                ],
                [
                    'observation_id' => 'extraneous-invalid-obs',
                    'producer' => 'unlisted_plugin',
                    'site' => 'site-solo',
                    'snapshot' => 'snap-solo',
                    'output' => 'sitemap',
                    'assertion' => 'not_an_assertion',
                    'can_emit' => true,
                    'source' => 'fixture:noise',
                    'evidence_state' => 'observed',
                ],
            ],
            'inventories' => [
                'sitemap' => [
                    'source_id' => 'fixture:solo_inventory',
                    'revision' => 'inv.r2',
                    'site' => 'site-solo',
                    'snapshot' => 'snap-solo',
                    'output' => 'sitemap',
                    'evidence_state' => 'observed',
                    'complete' => true,
                    'producers' => ['rank_math'],
                ],
            ],
        ]);
        self::assertSame('rank_math', $singleOwnerInventory['outputs']['sitemap']['owner']);
        self::assertSame('owned', $singleOwnerInventory['outputs']['sitemap']['state']);
        self::assertSame('none', $singleOwnerInventory['outputs']['sitemap']['duplicate_risk']);
        self::assertSame('other_emitters_excluded', $singleOwnerInventory['outputs']['sitemap']['reason']);
    }
}
