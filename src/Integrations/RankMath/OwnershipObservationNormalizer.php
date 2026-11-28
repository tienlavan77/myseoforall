<?php

/**
 * Normalizer and global collision detector for ownership coexistence observations in Increment D.
 *
 * Business purpose: Ensure producer capability and ownership assertion records are validated,
 * canonically ordered, and deduplicated strictly when both observation identity and every
 * decision-bearing fact match across the entire input stream, flagging any same-identity
 * discrepancy (including cross-output, cross-site/snapshot, or distinct malformed shapes)
 * as `identity_collision`.
 * Technical description: Tracks keyed `observation_id` fingerprints globally before scope/output
 * filtering, preserves explicit `evidence_state = conflict` over field-level `invalid` errors,
 * distinguishes omitted `site`/`snapshot` from malformed values, and enforces canonical producer slugs.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Normalizes and deduplicates producer ownership observations for OwnershipRegistry.
 *
 * Business purpose: Guarantee permutation-invariant and repetition-invariant ownership decisions
 * without wildcard field expansion, without scope coercion, and without silent producer renaming.
 * Technical description: Two-pass pure normalizer over raw observations returning per-output
 * collision flags and canonically sorted observation records.
 */
final class OwnershipObservationNormalizer
{
    public const OUTPUTS = ['title_meta', 'json_ld', 'sitemap', 'ai_draft'];
    public const TITLE_META_FIELDS = ['title', 'meta_description', 'canonical'];
    private const EVIDENCE_STATES = ['observed', 'declared', 'missing', 'invalid', 'conflict'];
    private const ASSERTIONS = ['claim', 'deny', 'absent'];

    /**
     * Normalize, detect global `observation_id` collisions, and partition records by output.
     *
     * Business purpose: Enforce O3 deduplication and identity-collision rules across the entire
     * input list before filtering by `expectedSite`/`expectedSnapshot` or bucketing by `output`.
     * Technical description: Pass 1 inspects every array item carrying a valid `observation_id`
     * and records its normalized-or-raw collision fingerprint; any ID with >1 distinct fingerprint
     * is marked globally colliding. Pass 2 collects in-scope records per output, flags outputs
     * touched by a colliding ID, deduplicates exact matches, and sorts surviving records canonically.
     *
     * @param array<mixed> $rawObservations
     * @return array<string, array{collision:bool,records:list<array<string, mixed>>}>
     */
    public function partitionByOutput(array $rawObservations, string $expectedSite, string $expectedSnapshot): array
    {
        $buckets = [];
        foreach (self::OUTPUTS as $output) {
            $buckets[$output] = [
                'collision' => false,
                'by_id' => [],
            ];
        }

        $parsedItems = [];
        $fingerprintsByObsId = [];

        foreach ($rawObservations as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $parsed = $this->parseObservation($raw, $index);
            $parsedItems[] = $parsed;

            if ($parsed['explicit_id'] !== null) {
                $obsId = $parsed['explicit_id'];
                $fingerprintsByObsId[$obsId][$parsed['collision_fingerprint']] = true;
            }
        }

        $collidingIds = [];
        foreach ($fingerprintsByObsId as $obsId => $fpSet) {
            if (count($fpSet) > 1) {
                $collidingIds[$obsId] = true;
            }
        }

        foreach ($parsedItems as $parsed) {
            $output = $parsed['output'];
            if ($output === null || !$parsed['scope_valid']) {
                continue;
            }
            if ($parsed['site'] !== $expectedSite || $parsed['snapshot'] !== $expectedSnapshot) {
                continue;
            }

            $record = $parsed['record'];
            $obsId = $record['observation_id'];
            if ($parsed['explicit_id'] !== null && isset($collidingIds[$parsed['explicit_id']])) {
                $buckets[$output]['collision'] = true;
                continue;
            }

            $fp = $this->decisionFingerprint($record);
            if (!isset($buckets[$output]['by_id'][$obsId])) {
                $buckets[$output]['by_id'][$obsId] = [
                    'fingerprint' => $fp,
                    'record' => $record,
                ];
            } elseif ($buckets[$output]['by_id'][$obsId]['fingerprint'] !== $fp) {
                $buckets[$output]['collision'] = true;
            }
        }

        $result = [];
        foreach (self::OUTPUTS as $output) {
            $records = array_map(
                static fn (array $item): array => $item['record'],
                array_values($buckets[$output]['by_id'])
            );
            usort(
                $records,
                fn (array $a, array $b): int => $this->decisionFingerprint($a) <=> $this->decisionFingerprint($b)
            );
            $result[$output] = [
                'collision' => $buckets[$output]['collision'],
                'records' => $records,
            ];
        }

        return $result;
    }

    /**
     * Validate a canonical producer identifier slug without case coercion.
     *
     * Business purpose: Keep producer/owner identifiers exact and consistent between observations
     * and inventories without silently rewriting caller casing.
     * Technical description: Accepts lowercase alphanumeric slugs (`[a-z0-9][a-z0-9._:-]{0,63}`).
     */
    public function cleanProducerSlug(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }
        $trimmed = trim($value);

        return preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * Validate a non-empty UTF-8 scope or provenance token without control characters.
     *
     * Business purpose: Reject blank, non-string, invalid UTF-8, or control-character tokens fail-closed.
     * Technical description: Trims valid UTF-8 strings and checks ASCII control character absence.
     */
    public function cleanToken(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1 ? null : $trimmed;
    }

    /**
     * Parse one raw observation array into normalized record metadata and a global collision fingerprint.
     *
     * Business purpose: Preserve raw malformed differences for collision checks, protect explicit
     * `conflict` evidence from being downgraded to `invalid`, and prevent malformed `site`/`snapshot`
     * values from defaulting into `default_site`/`default_snapshot`.
     * Technical description: Returns parsed metadata containing `explicit_id`, `output`, `scope_valid`,
     * `site`, `snapshot`, `record`, and `collision_fingerprint`.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *     explicit_id:?string,
     *     output:?string,
     *     scope_valid:bool,
     *     site:string,
     *     snapshot:string,
     *     record:array<string, mixed>,
     *     collision_fingerprint:string
     * }
     */
    private function parseObservation(array $raw, int $fallbackIndex): array
    {
        $explicitId = array_key_exists('observation_id', $raw) ? $this->cleanToken($raw['observation_id']) : null;
        $hasMalformedExplicitId = array_key_exists('observation_id', $raw) && $explicitId === null;

        $outputRaw = $raw['output'] ?? null;
        $output = is_string($outputRaw) && in_array($outputRaw, self::OUTPUTS, true) ? $outputRaw : null;

        $siteScope = $this->resolveScopeDimension($raw, 'site', 'default_site');
        $snapshotScope = $this->resolveScopeDimension($raw, 'snapshot', 'default_snapshot');
        $scopeValid = $siteScope['valid'] && $snapshotScope['valid'] && !$hasMalformedExplicitId;

        $evidenceRaw = array_key_exists('evidence_state', $raw) ? $raw['evidence_state'] : 'missing';
        $evidenceState = is_string($evidenceRaw) && in_array($evidenceRaw, self::EVIDENCE_STATES, true)
            ? $evidenceRaw
            : 'invalid';
        $hadStructureError = !is_string($evidenceRaw) || !in_array($evidenceRaw, self::EVIDENCE_STATES, true);

        $producer = $this->cleanProducerSlug($raw['producer'] ?? null);
        $source = $this->cleanToken($raw['source'] ?? null);
        if ($producer === null || $source === null) {
            $producer = $producer ?? 'invalid_producer';
            $source = $source ?? 'invalid_source';
            $evidenceState = $this->escalateInvalid($evidenceState);
            $hadStructureError = true;
        }

        $assertionRaw = array_key_exists('assertion', $raw) ? $raw['assertion'] : 'absent';
        if (!is_string($assertionRaw) || !in_array($assertionRaw, self::ASSERTIONS, true)) {
            $assertion = 'absent';
            $evidenceState = $this->escalateInvalid($evidenceState);
            $hadStructureError = true;
        } else {
            $assertion = $assertionRaw;
        }

        $canEmitRaw = array_key_exists('can_emit', $raw) ? $raw['can_emit'] : 'unknown';
        if (is_bool($canEmitRaw)) {
            $canEmit = $canEmitRaw;
        } elseif ($canEmitRaw === null || $canEmitRaw === 'unknown') {
            $canEmit = 'unknown';
        } else {
            $canEmit = 'unknown';
            $evidenceState = $this->escalateInvalid($evidenceState);
            $hadStructureError = true;
        }

        $fieldsInfo = $output !== null
            ? $this->normalizeFields($output, array_key_exists('fields', $raw), $raw['fields'] ?? null, $evidenceState)
            : ['fields' => [], 'evidence_state' => $this->escalateInvalid($evidenceState), 'had_error' => true];

        $hadStructureError = $hadStructureError || $fieldsInfo['had_error'] || !$scopeValid || $output === null;
        $obsId = $explicitId ?? ('unkeyed:' . ($output ?? 'unknown') . ':' . $fallbackIndex);

        $record = [
            'observation_id' => $obsId,
            'producer' => $producer,
            'site' => $siteScope['value'],
            'snapshot' => $snapshotScope['value'],
            'output' => $output ?? 'invalid_output',
            'fields' => $fieldsInfo['fields'],
            'assertion' => $assertion,
            'can_emit' => $canEmit,
            'source' => $source,
            'evidence_state' => $fieldsInfo['evidence_state'],
        ];

        $collisionFingerprint = $hadStructureError
            ? 'malformed:' . $this->rawFingerprint($raw)
            : 'valid:' . $this->decisionFingerprint($record);

        return [
            'explicit_id' => $explicitId,
            'output' => $output,
            'scope_valid' => $scopeValid,
            'site' => $siteScope['value'],
            'snapshot' => $snapshotScope['value'],
            'record' => $record,
            'collision_fingerprint' => $collisionFingerprint,
        ];
    }

    /**
     * Resolve `site` or `snapshot` scope on an observation without coercing invalid values to defaults.
     *
     * Business purpose: Allow omitted `site`/`snapshot` to use default scope while rejecting malformed values.
     * Technical description: Returns `{valid:true, value:$default}` when omitted, `{valid:true, value:$clean}`
     * when valid, and `{valid:false, value:'__invalid_scope__'}` when present but invalid.
     *
     * @param array<string, mixed> $raw
     * @return array{valid:bool,value:string}
     */
    private function resolveScopeDimension(array $raw, string $key, string $default): array
    {
        if (!array_key_exists($key, $raw)) {
            return ['valid' => true, 'value' => $default];
        }
        $clean = $this->cleanToken($raw[$key]);
        if ($clean === null) {
            return ['valid' => false, 'value' => '__invalid_scope__'];
        }

        return ['valid' => true, 'value' => $clean];
    }

    /**
     * Normalize covered fields per output without wildcard fallback and preserving `conflict` priority.
     *
     * Business purpose: Enforce explicit non-empty field coverage on `title_meta` (`title`,
     * `meta_description`, `canonical`) so missing/empty `fields` become `invalid` (or stay `conflict`)
     * rather than expanding to all fields.
     * Technical description: Validates sequential string lists and escalates invalid shapes via escalateInvalid().
     *
     * @return array{fields:list<string>,evidence_state:string,had_error:bool}
     */
    private function normalizeFields(string $output, bool $hasFieldsKey, mixed $rawFields, string $evidenceState): array
    {
        $allowed = $output === 'title_meta' ? self::TITLE_META_FIELDS : [$output];

        if (!$hasFieldsKey || $rawFields === null) {
            if ($output === 'title_meta') {
                return [
                    'fields' => [],
                    'evidence_state' => $this->escalateInvalid($evidenceState),
                    'had_error' => true,
                ];
            }

            return ['fields' => [$output], 'evidence_state' => $evidenceState, 'had_error' => false];
        }

        if (!is_array($rawFields) || $rawFields === [] || array_keys($rawFields) !== range(0, count($rawFields) - 1)) {
            return [
                'fields' => [],
                'evidence_state' => $this->escalateInvalid($evidenceState),
                'had_error' => true,
            ];
        }

        $normalized = [];
        foreach ($rawFields as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                return [
                    'fields' => [],
                    'evidence_state' => $this->escalateInvalid($evidenceState),
                    'had_error' => true,
                ];
            }
            if (!in_array($field, $normalized, true)) {
                $normalized[] = $field;
            }
        }
        sort($normalized);

        return ['fields' => $normalized, 'evidence_state' => $evidenceState, 'had_error' => false];
    }

    /**
     * Escalate evidence state to `invalid` while preserving higher-priority `conflict` state.
     *
     * Business purpose: Prevent sub-field validation errors from downgrading explicit `conflict` evidence.
     * Technical description: Returns `'conflict'` if already `'conflict'`, otherwise `'invalid'`.
     */
    private function escalateInvalid(string $currentEvidenceState): string
    {
        return $currentEvidenceState === 'conflict' ? 'conflict' : 'invalid';
    }

    /**
     * Compute a canonical JSON fingerprint of normalized decision-bearing observation facts.
     *
     * Business purpose: Merge repeated records strictly when identity and all decision facts match.
     * Technical description: Encodes ordered normalized fields with JSON_UNESCAPED_UNICODE.
     *
     * @param array<string, mixed> $record
     */
    private function decisionFingerprint(array $record): string
    {
        return (string) json_encode([
            $record['observation_id'],
            $record['producer'],
            $record['site'],
            $record['snapshot'],
            $record['output'],
            $record['fields'],
            $record['assertion'],
            $record['can_emit'],
            $record['source'],
            $record['evidence_state'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Compute a canonical type-and-value fingerprint of a raw malformed observation array.
     *
     * Business purpose: Ensure two differently malformed observations sharing an `observation_id`
     * produce distinct fingerprints and trigger `identity_collision` instead of merging silently.
     * Technical description: Recursively canonicalizes associative key order and encodes value types.
     */
    private function rawFingerprint(mixed $value, int $depth = 0): string
    {
        if ($depth > 5) {
            return '"max_depth"';
        }
        if (is_array($value)) {
            $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $items = array_map(fn (mixed $v): string => $this->rawFingerprint($v, $depth + 1), $value);
                return 'list:[' . implode(',', $items) . ']';
            }
            $copy = $value;
            ksort($copy);
            $pairs = [];
            foreach ($copy as $k => $v) {
                $pairs[] = json_encode((string) $k) . ':' . $this->rawFingerprint($v, $depth + 1);
            }
            return 'map:{' . implode(',', $pairs) . '}';
        }
        if (is_string($value)) {
            return 'str:' . bin2hex($value);
        }
        if (is_bool($value)) {
            return $value ? 'bool:true' : 'bool:false';
        }
        if (is_int($value) || is_float($value)) {
            return get_debug_type($value) . ':' . (string) $value;
        }
        if ($value === null) {
            return 'null';
        }

        return 'obj:' . get_debug_type($value);
    }
}
