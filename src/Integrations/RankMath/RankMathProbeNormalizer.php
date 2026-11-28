<?php

/**
 * Normalizer for raw Rank Math environment probe inputs.
 *
 * Business purpose: Keep installation, activation, version, and capability observations
 * strictly separated and fail-closed on missing, malformed, or conflicting probe data.
 * Technical description: Validates raw probe arrays into closed state records without mutating
 * caller arrays and without promoting invalid evidence_state values to `observed`.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Normalizes raw Rank Math probe payloads into closed state structures.
 *
 * Business purpose: Prevent invalid version labels, missing installation metadata, or malformed
 * evidence_state labels from silently promoting Rank Math to installed, observed, or compatible.
 * Technical description: Pure helper used by RankMathDetector to normalize four independent probe dimensions.
 */
final class RankMathProbeNormalizer
{
    private const EVIDENCE_STATES = [
        'observed',
        'verified',
        'unobserved',
        'missing',
        'incomplete',
        'unverified',
        'conflict',
        'invalid',
    ];

    /**
     * Normalize installation probe input into `absent`, `installed`, or `unknown`.
     *
     * Business purpose: Preserve installation uncertainty and conflicts independently of activation.
     * Technical description: Validates installation state enum and sanitizes source and evidence identifiers.
     *
     * @return array{state:string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    public function installation(mixed $raw): array
    {
        return $this->binaryDimension($raw, 'installation', ['absent', 'installed', 'unknown'], [
            'installed' => 'installation_confirmed',
            'absent' => 'installation_absent',
        ]);
    }

    /**
     * Normalize activation probe input into `inactive`, `active`, or `unknown`.
     *
     * Business purpose: Represent activation status without assuming plugin files or capabilities exist.
     * Technical description: Validates activation state enum and sanitizes source and evidence identifiers.
     *
     * @return array{state:string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    public function activation(mixed $raw): array
    {
        return $this->binaryDimension($raw, 'activation', ['inactive', 'active', 'unknown'], [
            'active' => 'activation_confirmed',
            'inactive' => 'activation_inactive',
        ]);
    }

    /**
     * Normalize version probe input into `known`, `unavailable`, or `invalid`.
     *
     * Business purpose: Ensure non-version strings like `latest` or invalid evidence_state values
     * fail closed rather than becoming `known` or `observed`.
     * Technical description: Distinguishes omitted `evidence_state` (`observed` default) from explicit
     * invalid `evidence_state` (`invalid`), and validates version syntax via RankMathCompatibilityMatrix.
     *
     * @return array{state:string,value:?string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    public function version(mixed $raw): array
    {
        if ($raw === null) {
            return $this->versionRecord('unavailable', null, 'version_probe_missing', 'none', 'missing', 'probe:version_missing');
        }
        if (!is_array($raw)) {
            return $this->versionRecord('invalid', null, 'version_probe_malformed', 'malformed_input', 'invalid', 'probe:version_malformed');
        }

        $source = $this->cleanId($raw['signal_source'] ?? null, 'version_probe');
        $identity = $this->cleanId($raw['evidence_identity'] ?? null, 'probe:version');
        $evidence = $this->resolveEvidenceState($raw, 'observed');
        $probeState = $raw['state'] ?? 'present';

        if ($probeState === 'conflict' || $evidence === 'conflict') {
            return $this->versionRecord('unavailable', null, 'version_probe_conflict', $source, 'conflict', $identity);
        }
        if ($evidence === 'invalid') {
            return $this->versionRecord('invalid', null, 'version_probe_invalid', $source, 'invalid', $identity);
        }
        if ($probeState === 'unavailable' || $probeState === 'unknown') {
            $state = $evidence === 'observed' ? 'missing' : $evidence;
            return $this->versionRecord('unavailable', null, 'version_unavailable', $source, $state, $identity);
        }
        if ($probeState !== 'present') {
            return $this->versionRecord('invalid', null, 'version_probe_state_invalid', $source, 'invalid', $identity);
        }

        $value = $raw['value'] ?? null;
        if ($value === null) {
            return $this->versionRecord('unavailable', null, 'version_unavailable', $source, 'missing', $identity);
        }
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return $this->versionRecord('invalid', null, 'version_invalid_type', $source, 'invalid', $identity);
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !RankMathCompatibilityMatrix::isValidVersion($trimmed)) {
            return $this->versionRecord('invalid', null, 'version_invalid_format', $source, 'invalid', $identity);
        }

        return $this->versionRecord('known', $trimmed, 'version_parsed', $source, $evidence, $identity);
    }

    /**
     * Normalize capability probe map for scoped compatibility evaluation.
     *
     * Business purpose: Preserve partial, missing, invalid, or conflicting capability probes safely.
     * Technical description: Rejects explicit invalid `evidence_state` values as `invalid` instead of
     * promoting them to `observed`, and copies string-keyed capability entries.
     *
     * @return array{capabilities:array<string, mixed>,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    public function capabilities(mixed $raw): array
    {
        if ($raw === null) {
            return [
                'capabilities' => [],
                'reason' => 'capability_probe_missing',
                'signal_source' => 'none',
                'evidence_state' => 'missing',
                'evidence_identity' => 'probe:capabilities_missing',
            ];
        }
        if (!is_array($raw)) {
            return [
                'capabilities' => [],
                'reason' => 'capability_probe_malformed',
                'signal_source' => 'malformed_input',
                'evidence_state' => 'invalid',
                'evidence_identity' => 'probe:capabilities_malformed',
            ];
        }

        $source = $this->cleanId($raw['signal_source'] ?? null, 'capability_probe');
        $identity = $this->cleanId($raw['evidence_identity'] ?? null, 'probe:capabilities');
        $evidence = $this->resolveEvidenceState($raw, 'observed');
        $map = $raw['capabilities'] ?? null;

        if ($evidence === 'conflict' || ($raw['state'] ?? null) === 'conflict') {
            return [
                'capabilities' => [],
                'reason' => 'capability_probe_conflict',
                'signal_source' => $source,
                'evidence_state' => 'conflict',
                'evidence_identity' => $identity,
            ];
        }
        if ($evidence === 'invalid' || !is_array($map)) {
            return [
                'capabilities' => [],
                'reason' => $evidence === 'invalid' ? 'capability_probe_invalid' : 'capability_probe_malformed',
                'signal_source' => $source,
                'evidence_state' => 'invalid',
                'evidence_identity' => $identity,
            ];
        }

        $copied = [];
        foreach ($map as $key => $val) {
            if (is_string($key)) {
                $copied[$key] = $val;
            }
        }

        return [
            'capabilities' => $copied,
            'reason' => 'capabilities_observed',
            'signal_source' => $source,
            'evidence_state' => $evidence,
            'evidence_identity' => $identity,
        ];
    }

    /**
     * Normalize installation or activation state probes with shared conflict/invalid handling.
     *
     * Business purpose: Apply uniform fail-closed rules to discrete installation and activation probes.
     * Technical description: Maps valid enums to reason codes and converts malformed or conflicting inputs to `unknown`.
     *
     * @param list<string> $allowedStates
     * @param array<string, string> $reasons
     * @return array{state:string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    private function binaryDimension(mixed $raw, string $name, array $allowedStates, array $reasons): array
    {
        if ($raw === null) {
            return $this->signal('unknown', $name . '_probe_missing', 'none', 'missing', 'probe:' . $name . '_missing');
        }
        if (!is_array($raw)) {
            return $this->signal('unknown', $name . '_probe_malformed', 'malformed_input', 'invalid', 'probe:' . $name . '_malformed');
        }

        $source = $this->cleanId($raw['signal_source'] ?? null, $name . '_probe');
        $identity = $this->cleanId($raw['evidence_identity'] ?? null, 'probe:' . $name);
        $evidence = $this->resolveEvidenceState($raw, 'observed');
        $state = $raw['state'] ?? null;

        if ($state === 'conflict' || $evidence === 'conflict') {
            return $this->signal('unknown', $name . '_probe_conflict', $source, 'conflict', $identity);
        }
        if ($evidence === 'invalid' || !is_string($state) || !in_array($state, $allowedStates, true)) {
            return $this->signal('unknown', $name . '_probe_invalid', $source, 'invalid', $identity);
        }
        if ($state === 'unknown') {
            $normEvidence = $evidence === 'observed' ? 'incomplete' : $evidence;
            return $this->signal('unknown', $name . '_unverified', $source, $normEvidence, $identity);
        }

        return $this->signal($state, $reasons[$state], $source, $evidence, $identity);
    }

    /**
     * Build a normalized installation/activation probe signal array.
     *
     * Business purpose: Keep probe output structures consistent across dimensions.
     * Technical description: Returns exact five-key associative array.
     *
     * @return array{state:string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    private function signal(string $state, string $reason, string $source, string $evidence, string $identity): array
    {
        return [
            'state' => $state,
            'reason' => $reason,
            'signal_source' => $source,
            'evidence_state' => $evidence,
            'evidence_identity' => $identity,
        ];
    }

    /**
     * Build a normalized version probe signal array.
     *
     * Business purpose: Keep version probe output consistent across known, unavailable, and invalid cases.
     * Technical description: Returns exact six-key associative array.
     *
     * @return array{state:string,value:?string,reason:string,signal_source:string,evidence_state:string,evidence_identity:string}
     */
    private function versionRecord(string $state, ?string $value, string $reason, string $source, string $evidence, string $identity): array
    {
        return [
            'state' => $state,
            'value' => $value,
            'reason' => $reason,
            'signal_source' => $source,
            'evidence_state' => $evidence,
            'evidence_identity' => $identity,
        ];
    }

    /**
     * Sanitize identifier strings with a safe fallback when missing or invalid.
     *
     * Business purpose: Ensure provenance identifiers are always safe, non-empty UTF-8 strings.
     * Technical description: Trims valid strings and rejects ASCII control characters.
     */
    private function cleanId(mixed $value, string $fallback): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return $fallback;
        }
        $trimmed = trim($value);

        return $trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1 ? $fallback : $trimmed;
    }

    /**
     * Distinguish an omitted `evidence_state` key from an explicit invalid `evidence_state` value.
     *
     * Business purpose: Prevent garbage `evidence_state` values (such as `guessed` or `123`) from
     * being silently elevated to `observed`.
     * Technical description: Uses `$whenOmitted` only when the key is absent; returns `'invalid'`
     * whenever the key is present with a non-string or out-of-enum value.
     *
     * @param array<string, mixed> $raw
     */
    private function resolveEvidenceState(array $raw, string $whenOmitted): string
    {
        if (!array_key_exists('evidence_state', $raw)) {
            return $whenOmitted;
        }
        $value = $raw['evidence_state'];

        return is_string($value) && in_array($value, self::EVIDENCE_STATES, true) ? $value : 'invalid';
    }
}
