<?php

/**
 * Compatibility matrix artifact and scoped evaluator for Rank Math integration.
 *
 * Business purpose: Determine whether observed Rank Math versions and capabilities
 * are proven compatible for a specific read scope without guessing from "latest" or "active".
 * Technical description: Stores a versioned, immutable rule catalog with evidence provenance
 * and evaluates semver constraints, required capabilities, and rule precedence fail-closed.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Evaluates Rank Math compatibility against an explicit, evidence-backed rule matrix.
 *
 * Business purpose: Prevent false compatibility assumptions when runtime evidence is missing.
 * Technical description: Ships with an empty unproven production catalog so real evaluations
 * resolve to unknown until runtime evidence is verified, while accepting injected synthetic
 * catalogs for deterministic contract testing.
 */
final class RankMathCompatibilityMatrix
{
    public const SCHEMA_VERSION = 'myseo.rank_math.compatibility_matrix.v1';
    public const DEFAULT_MATRIX_ID = 'rank-math-compatibility-matrix-d-r1';
    public const DEFAULT_REVISION = '2026-11-20.r1';
    public const DEFAULT_UPDATED_AT = '2026-11-20';
    public const ALLOWED_CAPABILITIES = [
        'read_title',
        'read_meta_description',
        'read_canonical',
    ];

    private string $matrixId;
    private string $revision;
    private string $updatedAt;

    /** @var list<array<string, mixed>> */
    private array $rules;

    /**
     * Create a compatibility matrix from explicit metadata and rule entries.
     *
     * Business purpose: Bind every compatibility decision to a traceable artifact identity.
     * Technical description: Validates metadata strings and copies rule definitions without
     * mutating caller arrays.
     *
     * @param list<array<string, mixed>> $rules
     */
    public function __construct(
        string $matrixId = self::DEFAULT_MATRIX_ID,
        string $revision = self::DEFAULT_REVISION,
        string $updatedAt = self::DEFAULT_UPDATED_AT,
        array $rules = []
    ) {
        if (trim($matrixId) === '' || trim($revision) === '' || trim($updatedAt) === '') {
            throw new \InvalidArgumentException('Compatibility matrix identity metadata must be non-empty.');
        }

        $this->matrixId = trim($matrixId);
        $this->revision = trim($revision);
        $this->updatedAt = trim($updatedAt);
        $this->rules = $this->normalizeRules($rules);
    }

    /**
     * Build the shipped default compatibility matrix with zero unproven version ranges.
     *
     * Business purpose: Keep shipped compatibility fail-closed (`unknown`) until real Rank Math
     * version ranges are empirically verified and approved.
     * Technical description: Instantiates the matrix with canonical Increment D identifiers and
     * an empty rule list.
     */
    public static function shippedDefault(): self
    {
        return new self();
    }

    /**
     * Return immutable metadata describing this matrix artifact.
     *
     * Business purpose: Expose matrix identity, revision, and update guidance for audit handoffs.
     * Technical description: Returns a closed associative array with schema and rule count.
     *
     * @return array{schema:string,matrix_id:string,revision:string,updated_at:string,update_policy:string,rule_count:int}
     */
    public function metadata(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'matrix_id' => $this->matrixId,
            'revision' => $this->revision,
            'updated_at' => $this->updatedAt,
            'update_policy' => 'Add ranges only with recorded runtime verification receipt and revision bump.',
            'rule_count' => count($this->rules),
        ];
    }

    /**
     * Evaluate compatibility for a normalized version, capability map, and requested scope.
     *
     * Business purpose: Decide whether every requested capability is backed by non-ambiguous
     * compatibility evidence for the detected Rank Math version.
     * Technical description: Validates requested scope against ALLOWED_CAPABILITIES, matches
     * version ranges, resolves precedence ties fail-closed, and checks capability probes.
     *
     * @param array<string, mixed> $capabilities
     * @param list<mixed> $scope
     * @return array{
     *     state:string,
     *     scope:list<string>,
     *     reason:string,
     *     evidence_state:string,
     *     evidence_source:string,
     *     matched_rule_id:?string,
     *     matrix_id:string,
     *     matrix_revision:string,
     *     updated_at:string
     * }
     */
    public function evaluate(
        string $versionState,
        ?string $normalizedVersion,
        array $capabilities,
        array $scope
    ): array {
        $validatedScope = $this->validateScope($scope);
        if ($validatedScope === null) {
            return $this->result('unknown', [], 'invalid_compatibility_scope', 'invalid', 'matrix_scope_guard', null);
        }

        if ($versionState === 'invalid') {
            return $this->result('unknown', $validatedScope, 'version_invalid', 'invalid', 'version_probe', null);
        }
        if ($versionState !== 'known' || $normalizedVersion === null) {
            return $this->result('unknown', $validatedScope, 'version_unavailable', 'missing', 'version_probe', null);
        }
        if ($this->rules === []) {
            return $this->result('unknown', $validatedScope, 'matrix_unproven_for_range', 'unverified', 'compatibility_matrix', null);
        }

        $matching = [];
        foreach ($this->rules as $rule) {
            if ($this->matchesRange($normalizedVersion, $rule['min_version'], $rule['max_version'], $rule['max_inclusive'])
                && $this->coversScope($rule['capabilities'], $validatedScope)) {
                $matching[] = $rule;
            }
        }

        if ($matching === []) {
            return $this->result('unknown', $validatedScope, 'no_matching_matrix_rule', 'unverified', 'compatibility_matrix', null);
        }

        usort($matching, static fn (array $a, array $b): int => $b['precedence'] <=> $a['precedence']);
        if (count($matching) > 1 && $matching[0]['precedence'] === $matching[1]['precedence']) {
            return $this->result('unknown', $validatedScope, 'ambiguous_matrix_match', 'conflict', 'compatibility_matrix', null);
        }

        $selected = $matching[0];
        if ($selected['outcome'] === 'incompatible') {
            return $this->result(
                'incompatible',
                $validatedScope,
                $selected['reason'],
                $selected['evidence_state'],
                $selected['evidence_source'],
                $selected['rule_id']
            );
        }
        if ($selected['outcome'] !== 'compatible') {
            return $this->result(
                'unknown',
                $validatedScope,
                'unsupported_matrix_rule',
                $selected['evidence_state'],
                $selected['evidence_source'],
                $selected['rule_id']
            );
        }

        foreach ($validatedScope as $capability) {
            if (!array_key_exists($capability, $capabilities)) {
                return $this->result('unknown', $validatedScope, 'capability_probe_missing', 'incomplete', $selected['evidence_source'], $selected['rule_id']);
            }
            $probe = $capabilities[$capability];
            if ($probe === false || $probe === 'unsupported' || $probe === 'disabled') {
                return $this->result('unknown', $validatedScope, 'capability_missing', 'incomplete', $selected['evidence_source'], $selected['rule_id']);
            }
            if ($probe === 'conflict') {
                return $this->result('unknown', $validatedScope, 'capability_probe_conflict', 'conflict', $selected['evidence_source'], $selected['rule_id']);
            }
            if ($probe !== true && $probe !== 'supported') {
                return $this->result('unknown', $validatedScope, 'capability_unverified', 'incomplete', $selected['evidence_source'], $selected['rule_id']);
            }
        }

        return $this->result(
            'compatible',
            $validatedScope,
            $selected['reason'],
            $selected['evidence_state'],
            $selected['evidence_source'],
            $selected['rule_id']
        );
    }

    /**
     * Validate and canonicalize the requested capability scope.
     *
     * Business purpose: Reject empty or out-of-scope capability requests fail-closed.
     * Technical description: Ensures sequential list of non-empty allowed capability names
     * and deduplicates while preserving deterministic order.
     *
     * @param list<mixed> $scope
     * @return ?list<string>
     */
    private function validateScope(array $scope): ?array
    {
        if ($scope === [] || array_keys($scope) !== range(0, count($scope) - 1)) {
            return null;
        }

        $normalized = [];
        foreach ($scope as $item) {
            if (!is_string($item) || !in_array($item, self::ALLOWED_CAPABILITIES, true)) {
                return null;
            }
            if (!in_array($item, $normalized, true)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * Normalize and validate matrix rules at construction time.
     *
     * Business purpose: Ensure every matrix rule carries deterministic range, precedence, and provenance.
     * Technical description: Validates required keys, version syntax, capability list, and rule outcome.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<array{
     *     rule_id:string,
     *     min_version:string,
     *     max_version:?string,
     *     max_inclusive:bool,
     *     capabilities:list<string>,
     *     outcome:string,
     *     reason:string,
     *     evidence_source:string,
     *     evidence_state:string,
     *     precedence:int
     * }>
     */
    private function normalizeRules(array $rules): array
    {
        if ($rules !== [] && array_keys($rules) !== range(0, count($rules) - 1)) {
            throw new \InvalidArgumentException('Compatibility matrix rules must be a sequential list.');
        }

        $normalized = [];
        $seenIds = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Compatibility matrix rule must be an array.');
            }
            $ruleId = isset($rule['rule_id']) && is_string($rule['rule_id']) ? trim($rule['rule_id']) : '';
            $minVersion = isset($rule['min_version']) && is_string($rule['min_version']) ? trim($rule['min_version']) : '';
            $maxVersion = array_key_exists('max_version', $rule) && $rule['max_version'] !== null
                ? (is_string($rule['max_version']) ? trim($rule['max_version']) : '')
                : null;
            $maxInclusive = ($rule['max_inclusive'] ?? true) === true;
            $outcome = isset($rule['outcome']) && is_string($rule['outcome']) ? $rule['outcome'] : '';
            $reason = isset($rule['reason']) && is_string($rule['reason']) ? trim($rule['reason']) : '';
            $evidenceSource = isset($rule['evidence_source']) && is_string($rule['evidence_source']) ? trim($rule['evidence_source']) : '';
            $evidenceState = isset($rule['evidence_state']) && is_string($rule['evidence_state']) ? trim($rule['evidence_state']) : 'verified';
            $precedence = $rule['precedence'] ?? 0;
            $capabilities = isset($rule['capabilities']) && is_array($rule['capabilities'])
                ? $this->validateScope($rule['capabilities'])
                : null;

            if ($ruleId === '' || in_array($ruleId, $seenIds, true)
                || !self::isValidVersion($minVersion)
                || ($maxVersion !== null && !self::isValidVersion($maxVersion))
                || !in_array($outcome, ['compatible', 'incompatible', 'unsupported'], true)
                || $reason === '' || $evidenceSource === '' || $evidenceState === ''
                || !is_int($precedence) || $capabilities === null) {
                throw new \InvalidArgumentException('Invalid compatibility matrix rule definition.');
            }

            $seenIds[] = $ruleId;
            $normalized[] = [
                'rule_id' => $ruleId,
                'min_version' => $minVersion,
                'max_version' => $maxVersion,
                'max_inclusive' => $maxInclusive,
                'capabilities' => $capabilities,
                'outcome' => $outcome,
                'reason' => $reason,
                'evidence_source' => $evidenceSource,
                'evidence_state' => $evidenceState,
                'precedence' => $precedence,
            ];
        }

        return $normalized;
    }

    /**
     * Validate canonical dotted numeric/pre-release version strings.
     *
     * Business purpose: Prevent labels such as "latest" or malformed strings from matching ranges.
     * Technical description: Accepts major.minor[.patch[.build]] with optional alphanumeric suffix.
     */
    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+(?:\.\d+){0,2}(?:-[0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    /**
     * Check whether a known version falls inside the rule's lower and upper bounds.
     *
     * Business purpose: Apply deterministic version range bounds for compatibility rules.
     * Technical description: Uses PHP version_compare on validated version strings.
     */
    private function matchesRange(string $version, string $min, ?string $max, bool $maxInclusive): bool
    {
        if (version_compare($version, $min, '<')) {
            return false;
        }
        if ($max === null) {
            return true;
        }

        return $maxInclusive
            ? version_compare($version, $max, '<=')
            : version_compare($version, $max, '<');
    }

    /**
     * Check whether a rule covers every capability in the requested scope.
     *
     * Business purpose: Require full scope coverage before considering a compatibility rule.
     * Technical description: Verifies set inclusion of requested capabilities in rule capabilities.
     *
     * @param list<string> $ruleCapabilities
     * @param list<string> $requestedScope
     */
    private function coversScope(array $ruleCapabilities, array $requestedScope): bool
    {
        foreach ($requestedScope as $capability) {
            if (!in_array($capability, $ruleCapabilities, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Assemble a closed compatibility evaluation result.
     *
     * Business purpose: Guarantee consistent fields across all compatibility outcomes.
     * Technical description: Returns exact state, scope, reason, evidence, and matrix identifiers.
     *
     * @param list<string> $scope
     * @return array{
     *     state:string,
     *     scope:list<string>,
     *     reason:string,
     *     evidence_state:string,
     *     evidence_source:string,
     *     matched_rule_id:?string,
     *     matrix_id:string,
     *     matrix_revision:string,
     *     updated_at:string
     * }
     */
    private function result(
        string $state,
        array $scope,
        string $reason,
        string $evidence_state,
        string $evidence_source,
        ?string $matchedRuleId
    ): array {
        return [
            'state' => $state,
            'scope' => $scope,
            'reason' => $reason,
            'evidence_state' => $evidence_state,
            'evidence_source' => $evidence_source,
            'matched_rule_id' => $matchedRuleId,
            'matrix_id' => $this->matrixId,
            'matrix_revision' => $this->revision,
            'updated_at' => $this->updatedAt,
        ];
    }
}
