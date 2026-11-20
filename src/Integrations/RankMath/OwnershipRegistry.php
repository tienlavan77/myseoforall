<?php

/**
 * Deterministic per-output SEO ownership and duplicate-emission policy registry.
 *
 * Business purpose: Evaluate ownership and duplicate-output risk independently for `title_meta`,
 * `json_ld`, `sitemap`, and `ai_draft` without inferring ownership from plugin activation booleans,
 * without registering hooks or renderers, and with `write_allowed=false` across all outputs.
 * Technical description: Applies the exact 9-row O3 precedence table over normalized producer
 * observations and verified site/output/snapshot producer inventories while keeping Open Graph,
 * robots, and unlisted surfaces explicitly out of scope.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

final class OwnershipRegistry
{
    public const CONTRACT_VERSION = 'myseo.ownership.registry.v1';
    public const OUTPUTS = ['title_meta', 'json_ld', 'sitemap', 'ai_draft'];

    private OwnershipObservationNormalizer $normalizer;

    /**
     * Construct the ownership registry with a deterministic observation normalizer.
     *
     * Business purpose: Provide standalone coexistence evaluation with zero WordPress runtime coupling.
     * Technical description: Instantiates OwnershipObservationNormalizer for pure input normalization.
     */
    public function __construct(?OwnershipObservationNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new OwnershipObservationNormalizer();
    }

    /** Resolve output owners while keeping public SEO writes disabled until ownership is explicit. */
    public function resolve(array $context = [], array $activePlugins = []): array
    {
        unset($activePlugins);

        $siteInfo = $this->resolveContextDimension($context, 'site', 'default_site');
        $snapshotInfo = $this->resolveContextDimension($context, 'snapshot', 'default_snapshot');
        if (!$siteInfo['valid'] || !$snapshotInfo['valid']) {
            $outputs = [];
            foreach (self::OUTPUTS as $output) {
                $outputs[$output] = $this->formatDecision($output, 'unknown', 'unknown', 'possible', 'invalid_scope');
            }

            return [
                'contract_version' => self::CONTRACT_VERSION,
                'site' => $siteInfo['valid'] ? $siteInfo['value'] : 'invalid_site',
                'snapshot' => $snapshotInfo['valid'] ? $snapshotInfo['value'] : 'invalid_snapshot',
                'outputs' => $outputs,
                'out_of_scope_outputs' => ['open_graph', 'robots'],
                'write_allowed' => false,
            ];
        }

        $site = $siteInfo['value'];
        $snapshot = $snapshotInfo['value'];
        $rawObservations = isset($context['observations']) && is_array($context['observations'])
            ? $context['observations']
            : [];
        $rawInventories = isset($context['inventories']) && is_array($context['inventories'])
            ? $context['inventories']
            : [];

        $partitioned = $this->normalizer->partitionByOutput($rawObservations, $site, $snapshot);
        $outputs = [];
        foreach (self::OUTPUTS as $output) {
            $bucket = $partitioned[$output];
            $inventory = $this->normalizeInventory($rawInventories[$output] ?? null, $site, $snapshot, $output);
            $outputs[$output] = $this->evaluateOutput($output, $bucket['collision'], $bucket['records'], $inventory);
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'site' => $site,
            'snapshot' => $snapshot,
            'outputs' => $outputs,
            'out_of_scope_outputs' => ['open_graph', 'robots'],
            'write_allowed' => false,
        ];
    }

    /**
     * Validate `site` or `snapshot` in `resolve()` context without coercing malformed values to defaults.
     *
     * Business purpose: Distinguish omitted context scope (`default_site`/`default_snapshot`) from
     * invalid caller scope values so malformed scopes fail closed across all outputs.
     * Technical description: Returns `{valid:true, value:$default}` when key is omitted and `{valid:false}` when invalid.
     *
     * @param array<string, mixed> $context
     * @return array{valid:bool,value:string}
     */
    private function resolveContextDimension(array $context, string $key, string $default): array
    {
        if (!array_key_exists($key, $context)) {
            return ['valid' => true, 'value' => $default];
        }
        $clean = $this->normalizer->cleanToken($context[$key]);
        if ($clean === null) {
            return ['valid' => false, 'value' => ''];
        }

        return ['valid' => true, 'value' => $clean];
    }

    /**
     * Evaluate a single output surface strictly following the 9-row O3 precedence table.
     *
     * Business purpose: Produce deterministic `owner`, `state`, `reason`, `duplicate_risk`, and
     * `write_allowed=false` per output, plus internal-draft flags on `ai_draft`.
     * Technical description: Checks (1) identity collision, (2) assertion-capability conflict,
     * (3) conflicting evidence / same-producer observed claim+deny, (4) overlapping claims,
     * (5) split ownership on `title_meta`, (6) partial ownership on `title_meta`,
     * (7) no valid claim, (8) other emitters excluded, and (9) other emitters not excluded.
     *
     * @param list<array<string, mixed>> $records
     * @param array{verified:bool,producers:list<string>} $inventory
     * @return array<string, mixed>
     */
    private function evaluateOutput(string $output, bool $hasCollision, array $records, array $inventory): array
    {
        if ($hasCollision) {
            return $this->formatDecision($output, 'unknown', 'conflict', 'blocked', 'identity_collision');
        }

        if ($this->hasAssertionCapabilityConflict($records)) {
            return $this->formatDecision($output, 'unknown', 'conflict', 'blocked', 'assertion_capability_conflict');
        }

        if ($this->hasConflictingEvidence($records)) {
            return $this->formatDecision($output, 'unknown', 'conflict', 'blocked', 'conflicting_evidence');
        }

        $requiredFields = $output === 'title_meta'
            ? OwnershipObservationNormalizer::TITLE_META_FIELDS
            : [$output];

        $claimsByProducer = $this->collectValidClaimsByProducer($records);
        if ($this->hasOverlappingClaims($claimsByProducer)) {
            return $this->formatDecision($output, 'unknown', 'conflict', 'blocked', 'overlapping_claims');
        }

        if ($output === 'title_meta' && count($claimsByProducer) > 1) {
            return $this->formatDecision($output, 'unknown', 'unknown', 'possible', 'split_ownership');
        }

        if ($output === 'title_meta' && count($claimsByProducer) === 1) {
            $claimedFields = reset($claimsByProducer);
            if (is_array($claimedFields) && count($claimedFields) < count($requiredFields)) {
                return $this->formatDecision($output, 'unknown', 'unknown', 'possible', 'partial_ownership');
            }
        }

        if ($claimsByProducer === []) {
            return $this->formatDecision($output, 'unknown', 'unknown', 'possible', 'no_valid_claim');
        }

        $owner = (string) array_key_first($claimsByProducer);
        if ($this->areOtherEmittersExcluded($owner, $requiredFields, $records, $inventory)) {
            return $this->formatDecision($output, $owner, 'owned', 'none', 'other_emitters_excluded');
        }

        return $this->formatDecision($output, $owner, 'owned', 'possible', 'other_emitters_not_excluded');
    }

    /**
     * Detect observed claim assertions on explicit fields that contradict observed disabled emission capability.
     *
     * Business purpose: Enforce row 2 (`assertion_capability_conflict`) strictly over explicitly stated
     * fields without turning missing/empty `fields` into a wildcard `['*']`.
     * Technical description: Inspects `evidence_state === 'observed'` records with non-empty `fields`
     * for single-record `claim + can_emit=false` or cross-record `claim` vs `can_emit=false` on the same producer/field.
     *
     * @param list<array<string, mixed>> $records
     */
    private function hasAssertionCapabilityConflict(array $records): bool
    {
        $disabledByProducer = [];
        $claimedByProducer = [];

        foreach ($records as $record) {
            if ($record['evidence_state'] !== 'observed' || $record['fields'] === []) {
                continue;
            }
            $producer = $record['producer'];

            if ($record['assertion'] === 'claim' && $record['can_emit'] === false) {
                return true;
            }
            if ($record['can_emit'] === false) {
                foreach ($record['fields'] as $field) {
                    $disabledByProducer[$producer][$field] = true;
                }
            }
            if ($record['assertion'] === 'claim') {
                foreach ($record['fields'] as $field) {
                    $claimedByProducer[$producer][$field] = true;
                }
            }
        }

        foreach ($claimedByProducer as $producer => $claimedFields) {
            if (!isset($disabledByProducer[$producer])) {
                continue;
            }
            foreach (array_keys($claimedFields) as $field) {
                if (isset($disabledByProducer[$producer][$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect explicit `evidence_state === 'conflict'` or same-producer observed `claim` + `deny` contradiction.
     *
     * Business purpose: Enforce row 3 (`conflicting_evidence`) while ensuring `declared`, `missing`,
     * and `invalid` records never block a valid `observed` claim or manufacture cross-assertion conflicts.
     * Technical description: Returns true immediately on `evidence_state === 'conflict'`; compares
     * same-producer `claim` vs `deny` exclusively across records with `evidence_state === 'observed'`.
     *
     * @param list<array<string, mixed>> $records
     */
    private function hasConflictingEvidence(array $records): bool
    {
        $claims = [];
        $denies = [];

        foreach ($records as $record) {
            if ($record['evidence_state'] === 'conflict') {
                return true;
            }
            if ($record['evidence_state'] !== 'observed') {
                continue;
            }
            $producer = $record['producer'];
            foreach ($record['fields'] as $field) {
                if ($record['assertion'] === 'claim') {
                    $claims[$producer][$field] = true;
                } elseif ($record['assertion'] === 'deny') {
                    $denies[$producer][$field] = true;
                }
            }
        }

        foreach ($claims as $producer => $fields) {
            if (!isset($denies[$producer])) {
                continue;
            }
            foreach (array_keys($fields) as $field) {
                if (isset($denies[$producer][$field])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Collect valid field claims grouped by producer (`assertion=claim`, `can_emit=true`, `evidence_state=observed`).
     *
     * Business purpose: Enforce O3 rule that `declared`, `missing`, `invalid`, or `can_emit != true`
     * cannot establish ownership.
     * Technical description: Aggregates sorted unique claimed fields per producer in canonical producer order.
     *
     * @param list<array<string, mixed>> $records
     * @return array<string, list<string>>
     */
    private function collectValidClaimsByProducer(array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            if ($record['assertion'] !== 'claim'
                || $record['can_emit'] !== true
                || $record['evidence_state'] !== 'observed'
                || $record['fields'] === []) {
                continue;
            }
            $producer = $record['producer'];
            foreach ($record['fields'] as $field) {
                $grouped[$producer][$field] = true;
            }
        }

        ksort($grouped);
        $result = [];
        foreach ($grouped as $producer => $fieldMap) {
            $fields = array_keys($fieldMap);
            sort($fields);
            $result[$producer] = $fields;
        }

        return $result;
    }

    /**
     * Check whether two or more producers hold valid claims on at least one shared field.
     *
     * Business purpose: Enforce row 4 (`overlapping_claims`) before split/partial ownership checks.
     * Technical description: Tracks first claimant per field and returns true on any duplicate claimant.
     *
     * @param array<string, list<string>> $claimsByProducer
     */
    private function hasOverlappingClaims(array $claimsByProducer): bool
    {
        $fieldOwners = [];
        foreach ($claimsByProducer as $producer => $fields) {
            foreach ($fields as $field) {
                if (isset($fieldOwners[$field]) && $fieldOwners[$field] !== $producer) {
                    return true;
                }
                $fieldOwners[$field] = $producer;
            }
        }

        return false;
    }

    /**
     * Verify whether every non-owner producer is proven incapable of emitting the output in this snapshot.
     *
     * Business purpose: Enforce strict `duplicate_risk=none` criteria: requires a verified producer
     * inventory (with source identity, revision, matching site/output/snapshot, non-empty canonical
     * producer list containing the owner) AND observed `can_emit=false` across all required fields
     * for every other listed or observed non-invalid producer. `invalid` observations neither add
     * nor exclude producers, and a single-producer verified inventory containing only the owner resolves cleanly.
     * Technical description: Ignores `invalid` records, rejects unlisted non-invalid producers, and checks
     * complete observed `can_emit === false` coverage for every other producer.
     *
     * @param list<string> $requiredFields
     * @param list<array<string, mixed>> $records
     * @param array{verified:bool,producers:list<string>} $inventory
     */
    private function areOtherEmittersExcluded(string $owner, array $requiredFields, array $records, array $inventory): bool
    {
        if (!$inventory['verified'] || !in_array($owner, $inventory['producers'], true)) {
            return false;
        }

        $otherProducers = [];
        foreach ($inventory['producers'] as $producer) {
            if ($producer !== $owner) {
                $otherProducers[$producer] = true;
            }
        }
        foreach ($records as $record) {
            if ($record['evidence_state'] !== 'invalid' && $record['producer'] !== $owner) {
                if (!in_array($record['producer'], $inventory['producers'], true)) {
                    return false;
                }
                $otherProducers[$record['producer']] = true;
            }
        }

        foreach (array_keys($otherProducers) as $other) {
            $disabledFields = [];
            foreach ($records as $record) {
                if ($record['producer'] !== $other || $record['evidence_state'] !== 'observed') {
                    continue;
                }
                if ($record['can_emit'] === true || $record['can_emit'] === 'unknown') {
                    return false;
                }
                if ($record['can_emit'] === false) {
                    foreach ($record['fields'] as $field) {
                        $disabledFields[$field] = true;
                    }
                }
            }
            foreach ($requiredFields as $reqField) {
                if (!isset($disabledFields[$reqField])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate a site/output/snapshot producer inventory record using exact canonical producer slugs.
     *
     * Business purpose: Reject bare `complete=true` flags, empty producer lists, non-canonical producer
     * slugs, missing source identity/revision, or mismatched site/output/snapshot scopes.
     * Technical description: Validates producer tokens via OwnershipObservationNormalizer::cleanProducerSlug().
     *
     * @return array{verified:bool,producers:list<string>}
     */
    private function normalizeInventory(mixed $raw, string $site, string $snapshot, string $output): array
    {
        if (!is_array($raw)) {
            return ['verified' => false, 'producers' => []];
        }

        $sourceId = $this->normalizer->cleanToken($raw['source_id'] ?? null);
        $revision = $this->normalizer->cleanToken($raw['revision'] ?? null);
        $invSite = $this->normalizer->cleanToken($raw['site'] ?? null);
        $invSnapshot = $this->normalizer->cleanToken($raw['snapshot'] ?? null);
        $invOutput = $this->normalizer->cleanToken($raw['output'] ?? null);
        $evidenceState = $raw['evidence_state'] ?? null;
        $complete = ($raw['complete'] ?? false) === true;
        $producersRaw = $raw['producers'] ?? null;

        if ($sourceId === null || $revision === null || !$complete
            || $invSite !== $site || $invSnapshot !== $snapshot || $invOutput !== $output
            || $evidenceState !== 'observed'
            || !is_array($producersRaw) || $producersRaw === []
            || array_keys($producersRaw) !== range(0, count($producersRaw) - 1)) {
            return ['verified' => false, 'producers' => []];
        }

        $producers = [];
        foreach ($producersRaw as $item) {
            $slug = $this->normalizer->cleanProducerSlug($item);
            if ($slug === null) {
                return ['verified' => false, 'producers' => []];
            }
            if (!in_array($slug, $producers, true)) {
                $producers[] = $slug;
            }
        }
        sort($producers);

        return ['verified' => true, 'producers' => $producers];
    }

    /**
     * Format a closed output ownership decision without `decision_state` and with `write_allowed=false`.
     *
     * Business purpose: Guarantee exact O3 schema compliance, including explicit internal-draft
     * flags (`frontend_owned=false`, `publish_capability=false`, `write_allowed=false`) on `ai_draft`.
     * Technical description: Returns associative output record with closed field names.
     *
     * @return array<string, mixed>
     */
    private function formatDecision(
        string $output,
        string $owner,
        string $state,
        string $duplicateRisk,
        string $reason
    ): array {
        $decision = [
            'owner' => $owner,
            'state' => $state,
            'reason' => $reason,
            'duplicate_risk' => $duplicateRisk,
            'write_allowed' => false,
        ];

        if ($output === 'ai_draft') {
            $decision['frontend_owned'] = false;
            $decision['publish_capability'] = false;
        }

        return $decision;
    }
}
