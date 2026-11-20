<?php

/**
 * Contract tests for Rank Math detector and compatibility matrix (Increment D — O1).
 *
 * Business purpose: Prove installation, activation, version, and compatibility states remain
 * orthogonal, fail-closed (`unknown` on missing/conflicting/unproven evidence), and scoped by
 * requested capabilities without equating `active` or `latest` with compatibility.
 * Technical description: Exercises RankMathDetector and RankMathCompatibilityMatrix across
 * shipped empty default rules, synthetic test-only matrices, invalid versions, ambiguous ranges,
 * probe conflicts, malformed inputs, and input non-mutation.
 */

declare(strict_types=1);

namespace MySeoForAll\Tests\Unit;

use MySeoForAll\Integrations\RankMath\RankMathCompatibilityMatrix;
use MySeoForAll\Integrations\RankMath\RankMathDetector;
use MySeoForAll\Integrations\RankMath\RankMathEnvironmentProbe;
use PHPUnit\Framework\TestCase;

/**
 * Verifies O1 detector and compatibility matrix decision contracts.
 *
 * Business purpose: Prevent false Rank Math compatibility claims when runtime evidence is absent.
 * Technical description: Uses pure in-memory probes and synthetic matrices without WordPress globals.
 */
final class RankMathDetectorContractTest extends TestCase
{
    /**
     * Verify shipped default matrix contains zero speculative ranges and returns unknown even for valid versions.
     *
     * Business purpose: Enforce Increment D rule that unproven runtime versions resolve to `unknown`.
     * Technical description: Evaluates version `1.0.220` with all capabilities true against shippedDefault().
     */
    public function testShippedDefaultMatrixKeepsUnprovenVersionUnknown(): void
    {
        $matrix = RankMathCompatibilityMatrix::shippedDefault();
        $meta = $matrix->metadata();

        self::assertSame(0, $meta['rule_count']);
        self::assertSame(RankMathCompatibilityMatrix::DEFAULT_MATRIX_ID, $meta['matrix_id']);
        self::assertSame(RankMathCompatibilityMatrix::DEFAULT_REVISION, $meta['revision']);

        $detector = new RankMathDetector($matrix);
        $result = $detector->evaluate($this->buildSignals('installed', 'active', '1.0.220', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));

        self::assertSame('installed', $result['installation']);
        self::assertSame('active', $result['activation']);
        self::assertSame('1.0.220', $result['version']);
        self::assertSame('known', $result['version_state']);
        self::assertSame('unknown', $result['compatibility']);
        self::assertSame('matrix_unproven_for_range', $result['reason']);
    }

    /**
     * Verify decision table across absent, installed-inactive, active-missing-install, unavailable/invalid version, and conflicts.
     *
     * Business purpose: Ensure every required O1 decision branch returns closed enums and stable reasons.
     * Technical description: Runs synthetic compatibility matrix against each orthogonal probe combination.
     */
    public function testDetectorDecisionTableAndOrthogonalDimensions(): void
    {
        $detector = new RankMathDetector($this->syntheticMatrix());

        $absent = $detector->evaluate($this->buildSignals('absent', 'inactive', null, []));
        self::assertSame('absent', $absent['installation']);
        self::assertSame('inactive', $absent['activation']);
        self::assertSame('unavailable', $absent['version_state']);
        self::assertSame('unknown', $absent['compatibility']);
        self::assertSame('plugin_absent', $absent['reason']);

        $inactive = $detector->evaluate($this->buildSignals('installed', 'inactive', '1.0.210', []));
        self::assertSame('installed', $inactive['installation']);
        self::assertSame('inactive', $inactive['activation']);
        self::assertSame('known', $inactive['version_state']);
        self::assertSame('unknown', $inactive['compatibility']);
        self::assertSame('installed_inactive', $inactive['reason']);

        $activeUnknownInstall = $detector->evaluate($this->buildSignals('unknown', 'active', '1.0.210', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));
        self::assertSame('unknown', $activeUnknownInstall['installation']);
        self::assertSame('active', $activeUnknownInstall['activation']);
        self::assertSame('compatible', $activeUnknownInstall['compatibility']);
        self::assertSame('active_installation_metadata_missing', $activeUnknownInstall['reason']);

        $activeUnavailableVersion = $detector->evaluate($this->buildSignals('installed', 'active', null, [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));
        self::assertSame('unavailable', $activeUnavailableVersion['version_state']);
        self::assertSame('unknown', $activeUnavailableVersion['compatibility']);
        self::assertSame('version_unavailable', $activeUnavailableVersion['reason']);

        $invalidVersionWithCap = $detector->evaluate($this->buildSignals('installed', 'active', 'latest', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));
        self::assertSame('invalid', $invalidVersionWithCap['version_state']);
        self::assertNull($invalidVersionWithCap['version']);
        self::assertSame('unknown', $invalidVersionWithCap['compatibility']);
        self::assertNotSame('incompatible', $invalidVersionWithCap['compatibility']);
        self::assertSame('version_invalid', $invalidVersionWithCap['reason']);

        $missingCap = $detector->evaluate($this->buildSignals('installed', 'active', '1.0.210', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => false,
        ]));
        self::assertSame('unknown', $missingCap['compatibility']);
        self::assertSame('capability_missing', $missingCap['reason']);

        $contradiction = $detector->evaluate($this->buildSignals('absent', 'active', '1.0.210', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));
        self::assertSame('unknown', $contradiction['installation']);
        self::assertSame('unknown', $contradiction['activation']);
        self::assertSame('installation_activation_conflict', $contradiction['reason']);
        self::assertSame('conflict', $contradiction['evidence_state']);
    }

    /**
     * Verify scope enforcement, incompatible rule, ambiguous precedence tie, and empty/invalid scope guards.
     *
     * Business purpose: Prove `compatible` applies strictly to the evaluated capability scope and
     * never permits empty/unknown scopes or ambiguous matrix matches.
     * Technical description: Tests partial scope `['read_title']`, incompatible range `<1.0.0`,
     * equal-precedence rule collision, and malformed scope inputs.
     */
    public function testMatrixScopeIncompatibleAndAmbiguousRules(): void
    {
        $detector = new RankMathDetector($this->syntheticMatrix());

        $scopedCompatible = $detector->evaluate(
            $this->buildSignals('installed', 'active', '1.0.210', ['read_title' => true]),
            ['read_title']
        );
        self::assertSame('compatible', $scopedCompatible['compatibility']);
        self::assertSame(['read_title'], $scopedCompatible['compatibility_scope']);

        $emptyScope = $detector->evaluate(
            $this->buildSignals('installed', 'active', '1.0.210', ['read_title' => true]),
            []
        );
        self::assertSame('unknown', $emptyScope['compatibility']);
        self::assertSame('invalid_compatibility_scope', $emptyScope['reason']);

        $unknownScope = $detector->evaluate(
            $this->buildSignals('installed', 'active', '1.0.210', ['read_title' => true]),
            ['write_post_meta']
        );
        self::assertSame('unknown', $unknownScope['compatibility']);
        self::assertSame('invalid_compatibility_scope', $unknownScope['reason']);

        $incompatible = $detector->evaluate(
            $this->buildSignals('installed', 'active', '0.9.5', ['read_title' => true]),
            ['read_title']
        );
        self::assertSame('incompatible', $incompatible['compatibility']);
        self::assertSame('legacy_pre_1_0_incompatible', $incompatible['reason']);

        $noMatch = $detector->evaluate(
            $this->buildSignals('installed', 'active', '2.5.0', ['read_title' => true]),
            ['read_title']
        );
        self::assertSame('unknown', $noMatch['compatibility']);
        self::assertSame('no_matching_matrix_rule', $noMatch['reason']);

        $ambiguousMatrix = new RankMathCompatibilityMatrix('syn-ambiguous', 'r1', '2026-11-20', [
            [
                'rule_id' => 'rule-a',
                'min_version' => '1.0.0',
                'max_version' => '1.9.9',
                'capabilities' => ['read_title'],
                'outcome' => 'compatible',
                'reason' => 'ok_a',
                'evidence_source' => 'synthetic:test_a',
                'precedence' => 10,
            ],
            [
                'rule_id' => 'rule-b',
                'min_version' => '1.0.100',
                'max_version' => '1.5.0',
                'capabilities' => ['read_title'],
                'outcome' => 'compatible',
                'reason' => 'ok_b',
                'evidence_source' => 'synthetic:test_b',
                'precedence' => 10,
            ],
        ]);
        $ambiguous = (new RankMathDetector($ambiguousMatrix))->evaluate(
            $this->buildSignals('installed', 'active', '1.0.210', ['read_title' => true]),
            ['read_title']
        );
        self::assertSame('unknown', $ambiguous['compatibility']);
        self::assertSame('ambiguous_matrix_match', $ambiguous['reason']);
    }

    /**
     * Verify injectable probe interface, passive detect(), malformed inputs, and input non-mutation.
     *
     * Business purpose: Confirm RankMathDetector works through RankMathEnvironmentProbe, without Rank Math loaded,
     * and never mutates caller arrays.
     * Technical description: Passes anonymous probe implementation with documented methods and checks array immutability.
     */
    public function testInjectableProbeMalformedInputsAndInputImmutability(): void
    {
        $passive = (new RankMathDetector())->detect();
        self::assertSame('unknown', $passive['compatibility']);

        $probe = new class () implements RankMathEnvironmentProbe {
            /**
             * Return synthetic installed probe state for detector testing.
             *
             * Business purpose: Simulate an observed Rank Math installation offline.
             * Technical description: Returns structured installation observation array.
             *
             * @return array<string, mixed>
             */
            public function observeInstallation(): array
            {
                return ['state' => 'installed', 'signal_source' => 'synthetic_fs', 'evidence_state' => 'observed', 'evidence_identity' => 'syn:inst'];
            }

            /**
             * Return synthetic active probe state for detector testing.
             *
             * Business purpose: Simulate an observed active Rank Math state offline.
             * Technical description: Returns structured activation observation array.
             *
             * @return array<string, mixed>
             */
            public function observeActivation(): array
            {
                return ['state' => 'active', 'signal_source' => 'synthetic_wp', 'evidence_state' => 'observed', 'evidence_identity' => 'syn:act'];
            }

            /**
             * Return synthetic version probe state for detector testing.
             *
             * Business purpose: Simulate an observed semantic version string offline.
             * Technical description: Returns structured version observation array.
             *
             * @return array<string, mixed>
             */
            public function observeVersion(): array
            {
                return ['state' => 'present', 'value' => '1.0.210', 'signal_source' => 'synthetic_header', 'evidence_state' => 'observed', 'evidence_identity' => 'syn:ver'];
            }

            /**
             * Return synthetic capability map for detector testing.
             *
             * Business purpose: Simulate observed read capability availability offline.
             * Technical description: Returns structured capability probe array.
             *
             * @return array<string, mixed>
             */
            public function observeCapabilities(): array
            {
                return ['capabilities' => ['read_title' => true], 'signal_source' => 'synthetic_cap', 'evidence_state' => 'observed', 'evidence_identity' => 'syn:cap'];
            }
        };

        $detected = (new RankMathDetector($this->syntheticMatrix(), $probe))->detect(['read_title']);
        self::assertSame('installed', $detected['installation']);
        self::assertSame('active', $detected['activation']);
        self::assertSame('compatible', $detected['compatibility']);

        $malformed = [
            'installation' => 'not-an-array',
            'activation' => ['state' => 'bogus'],
            'version' => ['state' => 'present', 'value' => ['bad']],
            'capabilities' => ['state' => 'conflict', 'capabilities' => []],
        ];
        $snapshot = $malformed;
        $malformedResult = (new RankMathDetector($this->syntheticMatrix()))->evaluate($malformed);

        self::assertSame($snapshot, $malformed);
        self::assertSame('unknown', $malformedResult['installation']);
        self::assertSame('unknown', $malformedResult['activation']);
        self::assertSame('invalid', $malformedResult['version_state']);
        self::assertSame('unknown', $malformedResult['compatibility']);
    }

    /**
     * Verify D2-1 cross-probe conflicts (`absent` + `known` version or positive capability), active-missing-install under default matrix, and invalid `evidence_state` rejection.
     *
     * Business purpose: Ensure `installation=absent` never evaluates to `compatible`/`incompatible`,
     * `active` + `installation=unknown` preserves `active_installation_metadata_missing` even with
     * `shippedDefault()`, and garbage `evidence_state` values never get promoted to `observed`.
     * Technical description: Exercises synthetic and default matrices across D2-1 regression cases.
     */
    public function testCrossProbeAbsentVersionConflictDefaultMissingInstallAndBogusEvidenceState(): void
    {
        $synDetector = new RankMathDetector($this->syntheticMatrix());

        $absentWithKnownVersion = $synDetector->evaluate($this->buildSignals('absent', 'inactive', '1.0.210', [
            'read_title' => true,
            'read_meta_description' => true,
            'read_canonical' => true,
        ]));
        self::assertSame('unknown', $absentWithKnownVersion['installation']);
        self::assertSame('unknown', $absentWithKnownVersion['compatibility']);
        self::assertSame('installation_version_conflict', $absentWithKnownVersion['reason']);
        self::assertSame('conflict', $absentWithKnownVersion['evidence_state']);

        $absentWithPositiveCap = $synDetector->evaluate($this->buildSignals('absent', 'inactive', null, [
            'read_title' => true,
        ]), ['read_title']);
        self::assertSame('unknown', $absentWithPositiveCap['installation']);
        self::assertSame('unknown', $absentWithPositiveCap['compatibility']);
        self::assertSame('installation_capability_conflict', $absentWithPositiveCap['reason']);
        self::assertSame('conflict', $absentWithPositiveCap['evidence_state']);

        $defaultDetector = new RankMathDetector(RankMathCompatibilityMatrix::shippedDefault());
        $activeMissingInstall = $defaultDetector->evaluate($this->buildSignals('unknown', 'active', '1.0.210', [
            'read_title' => true,
        ]), ['read_title']);
        self::assertSame('unknown', $activeMissingInstall['installation']);
        self::assertSame('active', $activeMissingInstall['activation']);
        self::assertSame('unknown', $activeMissingInstall['compatibility']);
        self::assertSame('active_installation_metadata_missing', $activeMissingInstall['reason']);
        self::assertSame('incomplete', $activeMissingInstall['evidence_state']);

        $bogusEvidenceSignals = [
            'installation' => ['state' => 'installed', 'evidence_state' => 'guessed'],
            'activation' => ['state' => 'active', 'evidence_state' => 123],
            'version' => ['state' => 'present', 'value' => '1.0.210', 'evidence_state' => 'bogus_state'],
            'capabilities' => ['capabilities' => ['read_title' => true], 'evidence_state' => 'bogus_cap'],
        ];
        $bogusRes = $synDetector->evaluate($bogusEvidenceSignals, ['read_title']);
        self::assertSame('unknown', $bogusRes['installation']);
        self::assertSame('unknown', $bogusRes['activation']);
        self::assertSame('invalid', $bogusRes['version_state']);
        self::assertSame('unknown', $bogusRes['compatibility']);
        self::assertSame('invalid', $bogusRes['signals']['installation']['evidence_state']);
        self::assertSame('invalid', $bogusRes['signals']['activation']['evidence_state']);
        self::assertSame('invalid', $bogusRes['signals']['version']['evidence_state']);
        self::assertSame('invalid', $bogusRes['signals']['capabilities']['evidence_state']);
    }

    /**
     * Build a synthetic test-only compatibility matrix to exercise compatible/incompatible branches.
     *
     * Business purpose: Test matrix evaluation branches offline without polluting shipped production defaults.
     * Technical description: Defines synthetic rules for `0.0.1..0.9.99` (incompatible) and `1.0.200..1.0.299` (compatible).
     */
    private function syntheticMatrix(): RankMathCompatibilityMatrix
    {
        return new RankMathCompatibilityMatrix('synthetic-test-matrix', 'test.r1', '2026-11-20', [
            [
                'rule_id' => 'syn-legacy-incompatible',
                'min_version' => '0.0.1',
                'max_version' => '1.0.0',
                'max_inclusive' => false,
                'capabilities' => ['read_title', 'read_meta_description', 'read_canonical'],
                'outcome' => 'incompatible',
                'reason' => 'legacy_pre_1_0_incompatible',
                'evidence_source' => 'synthetic:unit_test',
                'evidence_state' => 'verified',
                'precedence' => 20,
            ],
            [
                'rule_id' => 'syn-1-0-2xx-compatible',
                'min_version' => '1.0.200',
                'max_version' => '1.0.299',
                'max_inclusive' => true,
                'capabilities' => ['read_title', 'read_meta_description', 'read_canonical'],
                'outcome' => 'compatible',
                'reason' => 'synthetic_range_verified',
                'evidence_source' => 'synthetic:unit_test',
                'evidence_state' => 'verified',
                'precedence' => 10,
            ],
        ]);
    }

    /**
     * Build structured detector probe signal fixtures.
     *
     * Business purpose: Keep test cases concise while supplying explicit probe metadata.
     * Technical description: Constructs installation, activation, version, and capability probe arrays.
     *
     * @param array<string, mixed> $capabilities
     * @return array<string, mixed>
     */
    private function buildSignals(string $install, string $active, mixed $version, array $capabilities): array
    {
        return [
            'installation' => [
                'state' => $install,
                'signal_source' => 'fixture:install_probe',
                'evidence_state' => 'observed',
                'evidence_identity' => 'fixture:install',
            ],
            'activation' => [
                'state' => $active,
                'signal_source' => 'fixture:active_probe',
                'evidence_state' => 'observed',
                'evidence_identity' => 'fixture:active',
            ],
            'version' => [
                'state' => $version === null ? 'unavailable' : 'present',
                'value' => $version,
                'signal_source' => 'fixture:version_probe',
                'evidence_state' => $version === null ? 'missing' : 'observed',
                'evidence_identity' => 'fixture:version',
            ],
            'capabilities' => [
                'capabilities' => $capabilities,
                'signal_source' => 'fixture:cap_probe',
                'evidence_state' => 'observed',
                'evidence_identity' => 'fixture:capabilities',
            ],
        ];
    }
}
