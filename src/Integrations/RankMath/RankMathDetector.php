<?php

/**
 * Offline, fail-closed detector for Rank Math installation, activation, version, and compatibility.
 *
 * Business purpose: Provide orthogonal, evidence-backed status signals for Rank Math without
 * equating activation with installation, compatibility, or SEO output ownership.
 * Technical description: Evaluates injectable probe observations or raw signal maps against
 * RankMathCompatibilityMatrix using closed state enums (`absent|installed|unknown`,
 * `inactive|active|unknown`, `known|unavailable|invalid`, `compatible|incompatible|unknown`).
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

final class RankMathDetector
{
    public const CONTRACT_VERSION = 'myseo.rank_math.detector.v1';
    public const DEFAULT_SCOPE = [
        'read_title',
        'read_meta_description',
        'read_canonical',
    ];

    private RankMathCompatibilityMatrix $matrix;
    private ?RankMathEnvironmentProbe $probe;
    private RankMathProbeNormalizer $normalizer;

    /**
     * Construct the Rank Math detector with an optional compatibility matrix and environment probe.
     *
     * Business purpose: Enable standalone testing and future adapter composition without static globals.
     * Technical description: Uses RankMathCompatibilityMatrix::shippedDefault() when no matrix is supplied
     * and falls back to safe passive constant inspection only when no probe is injected.
     */
    public function __construct(
        ?RankMathCompatibilityMatrix $matrix = null,
        ?RankMathEnvironmentProbe $probe = null
    ) {
        $this->matrix = $matrix ?? RankMathCompatibilityMatrix::shippedDefault();
        $this->probe = $probe;
        $this->normalizer = new RankMathProbeNormalizer();
    }

    /**
     * Detect Rank Math state from the injected probe or safe passive environment observation.
     *
     * @param list<mixed> $scope
     * @return array<string, mixed>
     */
    public function detect(array $scope = self::DEFAULT_SCOPE): array
    {
        if ($this->probe !== null) {
            return $this->evaluate([
                'installation' => $this->probe->observeInstallation(),
                'activation' => $this->probe->observeActivation(),
                'version' => $this->probe->observeVersion(),
                'capabilities' => $this->probe->observeCapabilities(),
            ], $scope);
        }

        $constantDefined = defined('RANK_MATH_VERSION');
        $rawVersion = $constantDefined ? constant('RANK_MATH_VERSION') : null;

        return $this->evaluate([
            'installation' => [
                'state' => 'unknown',
                'signal_source' => 'passive_constant_guard',
                'evidence_state' => 'unobserved',
                'evidence_identity' => 'env:passive_installation_unobserved',
            ],
            'activation' => [
                'state' => $constantDefined ? 'active' : 'unknown',
                'signal_source' => 'rank_math_version_constant',
                'evidence_state' => $constantDefined ? 'observed' : 'unobserved',
                'evidence_identity' => 'env:rank_math_version_constant',
            ],
            'version' => [
                'state' => $constantDefined ? 'present' : 'unavailable',
                'value' => $rawVersion,
                'signal_source' => 'rank_math_version_constant',
                'evidence_state' => $constantDefined ? 'observed' : 'unobserved',
                'evidence_identity' => 'env:rank_math_version_constant',
            ],
            'capabilities' => [
                'capabilities' => [],
                'signal_source' => 'passive_capability_guard',
                'evidence_state' => 'unobserved',
                'evidence_identity' => 'env:passive_capabilities_unobserved',
            ],
        ], $scope);
    }

    /**
     * Normalize externally supplied probe facts for deterministic unit and contract testing.
     *
     * @param array<string, mixed>|bool $signalsOrActive
     * @param list<mixed> $scope
     * @return array<string, mixed>
     */
    public function status(array|bool $signalsOrActive, ?string $version = null, array $scope = self::DEFAULT_SCOPE): array
    {
        if (is_bool($signalsOrActive)) {
            return $this->evaluate([
                'installation' => [
                    'state' => 'unknown',
                    'signal_source' => 'caller_boolean_shorthand',
                    'evidence_state' => 'incomplete',
                    'evidence_identity' => 'caller:boolean_installation_unobserved',
                ],
                'activation' => [
                    'state' => $signalsOrActive ? 'active' : 'inactive',
                    'signal_source' => 'caller_boolean_shorthand',
                    'evidence_state' => 'observed',
                    'evidence_identity' => 'caller:boolean_activation',
                ],
                'version' => [
                    'state' => $version !== null ? 'present' : 'unavailable',
                    'value' => $version,
                    'signal_source' => 'caller_version_argument',
                    'evidence_state' => $version !== null ? 'observed' : 'missing',
                    'evidence_identity' => 'caller:version_argument',
                ],
                'capabilities' => [
                    'capabilities' => [],
                    'signal_source' => 'caller_boolean_shorthand',
                    'evidence_state' => 'missing',
                    'evidence_identity' => 'caller:capabilities_missing',
                ],
            ], $scope);
        }

        return $this->evaluate($signalsOrActive, $scope);
    }

    /**
     * Evaluate orthogonal installation, activation, version, and compatibility dimensions without mutating input.
     *
     * Business purpose: Enforce the Increment D decision table so conflicting, partial, or unproven
     * signals resolve to explicit `unknown` or `invalid` states with stable reason codes.
     * Technical description: Normalizes four independent probe inputs, detects cross-probe contradictions
     * (`absent` vs `active`, `known` version, or positive capabilities), prevents matrix evaluation when
     * `installation = absent`, and summarizes provenance.
     *
     * @param array<string, mixed> $signals
     * @param list<mixed> $scope
     * @return array<string, mixed>
     */
    public function evaluate(array $signals, array $scope = self::DEFAULT_SCOPE): array
    {
        $installation = $this->normalizer->installation($signals['installation'] ?? null);
        $activation = $this->normalizer->activation($signals['activation'] ?? null);
        $version = $this->normalizer->version($signals['version'] ?? null);
        $capabilitiesProbe = $this->normalizer->capabilities($signals['capabilities'] ?? null);

        $crossConflictReason = null;
        if ($installation['state'] === 'absent') {
            if ($activation['state'] === 'active') {
                $crossConflictReason = 'installation_activation_conflict';
                $installation = array_merge($installation, ['state' => 'unknown', 'reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
                $activation = array_merge($activation, ['state' => 'unknown', 'reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
            } elseif ($version['state'] === 'known') {
                $crossConflictReason = 'installation_version_conflict';
                $installation = array_merge($installation, ['state' => 'unknown', 'reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
                $version = array_merge($version, ['reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
            } elseif ($this->hasPositiveCapability($capabilitiesProbe['capabilities'])) {
                $crossConflictReason = 'installation_capability_conflict';
                $installation = array_merge($installation, ['state' => 'unknown', 'reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
                $capabilitiesProbe = array_merge($capabilitiesProbe, ['reason' => $crossConflictReason, 'evidence_state' => 'conflict']);
            }
        }

        $meta = $this->matrix->metadata();
        $validatedScope = $this->extractScopeList($scope);
        if ($validatedScope === []) {
            $compatibility = $this->customCompatibility('unknown', [], 'invalid_compatibility_scope', 'invalid', 'matrix_scope_guard', $meta);
        } elseif ($crossConflictReason !== null) {
            $compatibility = $this->customCompatibility('unknown', $validatedScope, $crossConflictReason, 'conflict', $installation['signal_source'], $meta);
        } elseif ($capabilitiesProbe['evidence_state'] === 'conflict' || $capabilitiesProbe['evidence_state'] === 'invalid') {
            $compatibility = $this->customCompatibility('unknown', $validatedScope, $capabilitiesProbe['reason'], $capabilitiesProbe['evidence_state'], $capabilitiesProbe['signal_source'], $meta);
        } elseif ($installation['state'] === 'absent') {
            $reason = $version['state'] === 'invalid' ? 'version_invalid' : 'plugin_absent';
            $evidence = $version['state'] === 'invalid' ? 'invalid' : 'missing';
            $compatibility = $this->customCompatibility('unknown', $validatedScope, $reason, $evidence, $installation['signal_source'], $meta);
        } else {
            $compatibility = $this->matrix->evaluate($version['state'], $version['value'], $capabilitiesProbe['capabilities'], $scope);
        }

        $summary = $this->summarizeDecision($installation, $activation, $version, $capabilitiesProbe, $compatibility);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'name' => 'rank_math',
            'installation' => $installation['state'],
            'activation' => $activation['state'],
            'version' => $version['value'],
            'version_state' => $version['state'],
            'compatibility' => $compatibility['state'],
            'compatibility_scope' => $compatibility['scope'],
            'reason' => $summary['reason'],
            'signal_source' => $summary['signal_source'],
            'evidence_state' => $summary['evidence_state'],
            'evidence_identity' => $summary['evidence_identity'],
            'signals' => [
                'installation' => $installation,
                'activation' => $activation,
                'version' => $version,
                'capabilities' => [
                    'reason' => $capabilitiesProbe['reason'],
                    'signal_source' => $capabilitiesProbe['signal_source'],
                    'evidence_state' => $capabilitiesProbe['evidence_state'],
                    'evidence_identity' => $capabilitiesProbe['evidence_identity'],
                ],
                'compatibility' => $compatibility,
            ],
        ];
    }

    /**
     * Derive top-level reason, signal source, and evidence state from orthogonal probe results.
     *
     * Business purpose: Provide deterministic decision traceability across all detector table cases,
     * including active-with-missing-installation-metadata even under unproven default matrices.
     * Technical description: Prioritizes invalid scope, probe conflicts/invalidity, plugin absence,
     * inactive state, missing installation metadata when active, and compatibility evaluation reason.
     *
     * @param array<string, mixed> $installation
     * @param array<string, mixed> $activation
     * @param array<string, mixed> $version
     * @param array<string, mixed> $capabilitiesProbe
     * @param array<string, mixed> $compatibility
     * @return array{reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    private function summarizeDecision(
        array $installation,
        array $activation,
        array $version,
        array $capabilitiesProbe,
        array $compatibility
    ): array {
        $matrixIdentity = 'matrix:' . $compatibility['matrix_id'] . '@' . $compatibility['matrix_revision'];
        if ($compatibility['reason'] === 'invalid_compatibility_scope') {
            return [
                'reason' => 'invalid_compatibility_scope',
                'signal_source' => (string) $compatibility['evidence_source'],
                'evidence_state' => 'invalid',
                'evidence_identity' => $matrixIdentity,
            ];
        }

        foreach ([$installation, $activation, $version, $capabilitiesProbe] as $probe) {
            if ($probe['evidence_state'] === 'conflict' || $probe['evidence_state'] === 'invalid') {
                $reason = ($probe === $version && $version['state'] === 'invalid') ? 'version_invalid' : (string) $probe['reason'];
                return [
                    'reason' => $reason,
                    'signal_source' => (string) $probe['signal_source'],
                    'evidence_state' => (string) $probe['evidence_state'],
                    'evidence_identity' => (string) $probe['evidence_identity'],
                ];
            }
        }

        if ($installation['state'] === 'absent' && $activation['state'] === 'inactive') {
            return [
                'reason' => 'plugin_absent',
                'signal_source' => (string) $installation['signal_source'],
                'evidence_state' => (string) $installation['evidence_state'],
                'evidence_identity' => (string) $installation['evidence_identity'],
            ];
        }
        if ($installation['state'] === 'installed' && $activation['state'] === 'inactive' && $compatibility['state'] === 'unknown') {
            return [
                'reason' => 'installed_inactive',
                'signal_source' => (string) $activation['signal_source'],
                'evidence_state' => (string) $compatibility['evidence_state'],
                'evidence_identity' => (string) $activation['evidence_identity'],
            ];
        }
        if ($activation['state'] === 'active' && $installation['state'] === 'unknown') {
            return [
                'reason' => 'active_installation_metadata_missing',
                'signal_source' => (string) $installation['signal_source'],
                'evidence_state' => 'incomplete',
                'evidence_identity' => (string) $installation['evidence_identity'],
            ];
        }

        return [
            'reason' => (string) $compatibility['reason'],
            'signal_source' => (string) $compatibility['evidence_source'],
            'evidence_state' => (string) $compatibility['evidence_state'],
            'evidence_identity' => $matrixIdentity,
        ];
    }

    /**
     * Check whether any capability probe entry reports positive support while installation is absent.
     *
     * Business purpose: Catch cross-probe contradictions between absent installation and positive capability probes.
     * Technical description: Returns true if any capability value equals `true` or `'supported'`.
     *
     * @param array<string, mixed> $capabilities
     */
    private function hasPositiveCapability(array $capabilities): bool
    {
        foreach ($capabilities as $value) {
            if ($value === true || $value === 'supported') {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract valid scope capabilities for reporting when matrix evaluation is bypassed.
     *
     * Business purpose: Keep `compatibility_scope` consistently typed as `list<string>`.
     * Technical description: Returns canonicalized capability list or empty list if invalid.
     *
     * @param list<mixed> $scope
     * @return list<string>
     */
    private function extractScopeList(array $scope): array
    {
        if ($scope === [] || array_keys($scope) !== range(0, count($scope) - 1)) {
            return [];
        }
        $clean = [];
        foreach ($scope as $item) {
            if (!is_string($item) || !in_array($item, RankMathCompatibilityMatrix::ALLOWED_CAPABILITIES, true)) {
                return [];
            }
            if (!in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /**
     * Construct a closed compatibility record when pre-matrix guards trigger.
     *
     * Business purpose: Keep compatibility signal structure uniform even when matrix lookup is skipped.
     * Technical description: Populates matrix metadata alongside guard state, scope, and reason.
     *
     * @param list<string> $scope
     * @param array{matrix_id:string,revision:string,updated_at:string} $meta
     * @return array<string, mixed>
     */
    private function customCompatibility(string $state, array $scope, string $reason, string $evidence, string $source, array $meta): array
    {
        return [
            'state' => $state,
            'scope' => $scope,
            'reason' => $reason,
            'evidence_state' => $evidence,
            'evidence_source' => $source,
            'matched_rule_id' => null,
            'matrix_id' => $meta['matrix_id'],
            'matrix_revision' => $meta['revision'],
            'updated_at' => $meta['updated_at'],
        ];
    }
}
