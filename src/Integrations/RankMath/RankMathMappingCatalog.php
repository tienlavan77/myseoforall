<?php

/**
 * Versioned Rank Math field-mapping catalog isolated inside the Rank Math integration boundary.
 *
 * Business purpose: Keep Rank Math meta keys, API slot identifiers, precedence chains, and
 * version ranges strictly isolated from SEO Core while failing closed (`unsupported` /
 * `mapping_ambiguous`) when no verified range matches or when equal-precedence mappings collide.
 * Technical description: Stores immutable field mapping rules per version range with explicit
 * catalog identity, revision, normalization policy, and fallback chain definitions.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Resolves version-aware read mappings for Rank Math `title`, `meta_description`, and `canonical`.
 *
 * Business purpose: Prevent speculative Rank Math key lookups when runtime version ranges have
 * not yet been empirically proven, while enabling deterministic synthetic catalog verification.
 * Technical description: Ships an empty unproven default catalog (`shippedDefault()`) and resolves
 * version-bounded field chains with deterministic precedence and tie detection (`mapping_ambiguous`).
 */
final class RankMathMappingCatalog
{
    public const SCHEMA_VERSION = 'myseo.rank_math.mapping_catalog.v1';
    public const DEFAULT_CATALOG_ID = 'rank-math-mapping-catalog-d-r1';
    public const DEFAULT_REVISION = '2026-11-20.r1';
    public const DEFAULT_UPDATED_AT = '2026-11-20';
    public const ALLOWED_FIELDS = [
        'title',
        'meta_description',
        'canonical',
    ];

    private string $catalogId;
    private string $revision;
    private string $updatedAt;

    /** @var list<array<string, mixed>> */
    private array $entries;

    /**
     * Construct an immutable mapping catalog from explicit identity metadata and field entries.
     *
     * Business purpose: Bind every field read mapping to a traceable catalog revision.
     * Technical description: Validates metadata and normalizes all mapping rules without mutating inputs.
     *
     * @param list<array<string, mixed>> $entries
     */
    public function __construct(
        string $catalogId = self::DEFAULT_CATALOG_ID,
        string $revision = self::DEFAULT_REVISION,
        string $updatedAt = self::DEFAULT_UPDATED_AT,
        array $entries = []
    ) {
        if (trim($catalogId) === '' || trim($revision) === '' || trim($updatedAt) === '') {
            throw new \InvalidArgumentException('Mapping catalog identity metadata must be non-empty.');
        }

        $this->catalogId = trim($catalogId);
        $this->revision = trim($revision);
        $this->updatedAt = trim($updatedAt);
        $this->entries = $this->normalizeEntries($entries);
    }

    /**
     * Build the shipped default catalog with zero unproven version ranges.
     *
     * Business purpose: Keep shipped field reads fail-closed (`unsupported`) until real Rank Math
     * version ranges and meta/API mappings are verified with runtime evidence.
     * Technical description: Instantiates an empty rule catalog with canonical Increment D metadata.
     */
    public static function shippedDefault(): self
    {
        return new self();
    }

    /**
     * Return immutable metadata describing this mapping catalog.
     *
     * Business purpose: Expose catalog identity and revision for verification receipts and handoffs.
     * Technical description: Returns schema version, catalog ID, revision, update date, and entry count.
     *
     * @return array{schema:string,catalog_id:string,revision:string,updated_at:string,entry_count:int}
     */
    public function metadata(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'catalog_id' => $this->catalogId,
            'revision' => $this->revision,
            'updated_at' => $this->updatedAt,
            'entry_count' => count($this->entries),
        ];
    }

    /**
     * Resolve the mapping rule for one allowlisted field and Rank Math version.
     *
     * Business purpose: Select a single unambiguous mapping chain for `title`, `meta_description`,
     * or `canonical`, or fail closed with `unsupported` and a stable reason code.
     * Technical description: Validates version format, filters matching range rules, sorts by
     * descending precedence, and rejects ties with `mapping_ambiguous`.
     *
     * @return array{
     *     status:string,
     *     reason:string,
     *     mapping_revision:string,
     *     rule:?array<string, mixed>
     * }
     */
    public function resolveField(string $field, ?string $version): array
    {
        $revisionTag = $this->catalogId . '@' . $this->revision;
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            return [
                'status' => 'unsupported',
                'reason' => 'field_not_allowlisted',
                'mapping_revision' => $revisionTag,
                'rule' => null,
            ];
        }
        if ($version === null || trim($version) === '' || !RankMathCompatibilityMatrix::isValidVersion(trim($version))) {
            return [
                'status' => 'unsupported',
                'reason' => 'version_unsupported',
                'mapping_revision' => $revisionTag,
                'rule' => null,
            ];
        }
        if ($this->entries === []) {
            return [
                'status' => 'unsupported',
                'reason' => 'mapping_unproven_for_version',
                'mapping_revision' => $revisionTag,
                'rule' => null,
            ];
        }

        $cleanVersion = trim($version);
        $matching = [];
        foreach ($this->entries as $entry) {
            if ($entry['field'] === $field
                && $this->matchesRange($cleanVersion, $entry['min_version'], $entry['max_version'], $entry['max_inclusive'])) {
                $matching[] = $entry;
            }
        }

        if ($matching === []) {
            return [
                'status' => 'unsupported',
                'reason' => 'version_unsupported',
                'mapping_revision' => $revisionTag,
                'rule' => null,
            ];
        }

        usort($matching, static fn (array $a, array $b): int => $b['precedence'] <=> $a['precedence']);
        if (count($matching) > 1 && $matching[0]['precedence'] === $matching[1]['precedence']) {
            return [
                'status' => 'unsupported',
                'reason' => 'mapping_ambiguous',
                'mapping_revision' => $revisionTag,
                'rule' => null,
            ];
        }

        $selected = $matching[0];
        if (!$selected['supported']) {
            return [
                'status' => 'unsupported',
                'reason' => 'mapping_explicitly_unsupported',
                'mapping_revision' => $revisionTag . ':' . $selected['mapping_id'],
                'rule' => null,
            ];
        }

        return [
            'status' => 'resolved',
            'reason' => 'mapping_resolved',
            'mapping_revision' => $revisionTag . ':' . $selected['mapping_id'],
            'rule' => $selected,
        ];
    }

    /**
     * Validate and copy mapping entries at construction time.
     *
     * Business purpose: Enforce closed field names, typed normalization policies, and explicit fallback chains.
     * Technical description: Validates candidate source chains (`source` + `slot`) and fallback policy (`missing_only` or `none`).
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private function normalizeEntries(array $entries): array
    {
        if ($entries !== [] && array_keys($entries) !== range(0, count($entries) - 1)) {
            throw new \InvalidArgumentException('Mapping catalog entries must be a sequential list.');
        }

        $normalized = [];
        $seenIds = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Mapping catalog entry must be an array.');
            }
            $mappingId = isset($entry['mapping_id']) && is_string($entry['mapping_id']) ? trim($entry['mapping_id']) : '';
            $field = isset($entry['field']) && is_string($entry['field']) ? $entry['field'] : '';
            $minVersion = isset($entry['min_version']) && is_string($entry['min_version']) ? trim($entry['min_version']) : '';
            $maxVersion = array_key_exists('max_version', $entry) && $entry['max_version'] !== null
                ? (is_string($entry['max_version']) ? trim($entry['max_version']) : '')
                : null;
            $maxInclusive = ($entry['max_inclusive'] ?? true) === true;
            $precedence = $entry['precedence'] ?? 0;
            $supported = ($entry['supported'] ?? true) === true;
            $dataType = isset($entry['data_type']) && is_string($entry['data_type']) ? $entry['data_type'] : '';
            $normalization = isset($entry['normalization']) && is_string($entry['normalization']) ? $entry['normalization'] : '';
            $fallbackRule = isset($entry['fallback_rule']) && is_string($entry['fallback_rule']) ? $entry['fallback_rule'] : 'missing_only';
            $evidenceSource = isset($entry['evidence_source']) && is_string($entry['evidence_source']) ? trim($entry['evidence_source']) : '';
            $candidates = isset($entry['sources']) && is_array($entry['sources']) ? $this->normalizeSources($entry['sources']) : null;

            if ($mappingId === '' || in_array($mappingId, $seenIds, true)
                || !in_array($field, self::ALLOWED_FIELDS, true)
                || !RankMathCompatibilityMatrix::isValidVersion($minVersion)
                || ($maxVersion !== null && !RankMathCompatibilityMatrix::isValidVersion($maxVersion))
                || !is_int($precedence)
                || ($supported && (!in_array($dataType, ['plain_text', 'canonical_url'], true)
                    || !in_array($normalization, ['trim_collapse_whitespace', 'canonical_http_url'], true)
                    || !in_array($fallbackRule, ['missing_only', 'none'], true)
                    || $evidenceSource === '' || $candidates === null))) {
                throw new \InvalidArgumentException('Invalid mapping catalog entry definition.');
            }

            $seenIds[] = $mappingId;
            $normalized[] = [
                'mapping_id' => $mappingId,
                'field' => $field,
                'min_version' => $minVersion,
                'max_version' => $maxVersion,
                'max_inclusive' => $maxInclusive,
                'precedence' => $precedence,
                'supported' => $supported,
                'data_type' => $dataType,
                'normalization' => $normalization,
                'fallback_rule' => $fallbackRule,
                'evidence_source' => $evidenceSource,
                'sources' => $candidates ?? [],
            ];
        }

        return $normalized;
    }

    /**
     * Validate an ordered list of candidate source slots for a field mapping.
     *
     * Business purpose: Define deterministic primary-to-fallback read order per mapped field.
     * Technical description: Ensures non-empty sequential list of `{source, slot}` string pairs.
     *
     * @param array<mixed> $sources
     * @return ?list<array{source:string,slot:string}>
     */
    private function normalizeSources(array $sources): ?array
    {
        if ($sources === [] || array_keys($sources) !== range(0, count($sources) - 1)) {
            return null;
        }
        $clean = [];
        foreach ($sources as $candidate) {
            if (!is_array($candidate)) {
                return null;
            }
            $source = isset($candidate['source']) && is_string($candidate['source']) ? trim($candidate['source']) : '';
            $slot = isset($candidate['slot']) && is_string($candidate['slot']) ? trim($candidate['slot']) : '';
            if ($source === '' || $slot === '') {
                return null;
            }
            $clean[] = [
                'source' => $source,
                'slot' => $slot,
            ];
        }

        return $clean;
    }

    /**
     * Check whether a validated version falls within the entry's version bounds.
     *
     * Business purpose: Apply exact version range filtering to mapping rules.
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
}
