<?php

/**
 * Pure, offline, allowlisted Rank Math read adapter for title, meta_description, and canonical.
 *
 * Business purpose: Read and validate Rank Math SEO configuration fields without leaking meta keys
 * into SEO Core, without rendering raw templates, and without mutating, persisting, or publishing data.
 * Technical description: Resolves versioned mappings via RankMathMappingCatalog, consumes presence-aware
 * observations via RankMathSourceObserver, enforces closed field states (`value|missing|explicit_null|invalid|unsupported|unreadable`),
 * and restricts fallback strictly to confirmed `missing` observations.
 */

declare(strict_types=1);

namespace MySeoForAll\Integrations\RankMath;

/**
 * Reads allowlisted Rank Math SEO fields with typed validation, normalization, and provenance guards.
 *
 * Business purpose: Deliver safe status/configuration evidence for downstream stages while rejecting
 * raw Rank Math templates, invalid URLs, control characters, secrets, and extra unapproved fields.
 * Technical description: Pure standalone adapter with zero WordPress hooks, network calls, or writes.
 */
final class RankMathReadAdapter
{
    public const CONTRACT_VERSION = 'myseo.rank_math.read_adapter.v1';
    public const ALLOWED_FIELDS = [
        'title',
        'meta_description',
        'canonical',
    ];
    public const FIELD_STATES = [
        'value',
        'missing',
        'explicit_null',
        'invalid',
        'unsupported',
        'unreadable',
    ];

    public const MAX_TITLE_CHARS = 300;
    public const MAX_DESCRIPTION_CHARS = 1000;
    public const MAX_CANONICAL_BYTES = 2048;

    private RankMathMappingCatalog $catalog;

    /**
     * Construct the read adapter with an explicit or shipped-default mapping catalog.
     *
     * Business purpose: Decouple adapter validation logic from Rank Math version/key catalog revisions.
     * Technical description: Uses RankMathMappingCatalog::shippedDefault() when no catalog is supplied.
     */
    public function __construct(?RankMathMappingCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? RankMathMappingCatalog::shippedDefault();
    }

    /**
     * Read all allowlisted Rank Math fields (`title`, `meta_description`, `canonical`) for a version.
     *
     * Business purpose: Provide a closed three-field read snapshot without exposing extra metadata.
     * Technical description: Evaluates only ALLOWED_FIELDS, ignoring any extra slots in the source observer.
     *
     * @param list<mixed>|null $requestedFields
     * @return array{
     *     contract_version:string,
     *     catalog:array{schema:string,catalog_id:string,revision:string,updated_at:string,entry_count:int},
     *     fields:array<string, array<string, mixed>>
     * }
     */
    public function readAll(
        RankMathSourceObserver $observer,
        ?string $rankMathVersion,
        ?array $requestedFields = null
    ): array {
        if ($requestedFields !== null) {
            $this->assertAllowlistedRequest($requestedFields);
        }

        $fieldsToRead = $requestedFields ?? self::ALLOWED_FIELDS;
        $results = [];
        foreach (self::ALLOWED_FIELDS as $allowedField) {
            if (in_array($allowedField, $fieldsToRead, true)) {
                $results[$allowedField] = $this->readField($allowedField, $observer, $rankMathVersion);
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'catalog' => $this->catalog->metadata(),
            'fields' => $results,
        ];
    }

    /**
     * Read and validate a single allowlisted Rank Math field.
     *
     * Business purpose: Return a closed field-state record with `value` present exclusively when `state=value`.
     * Technical description: Resolves catalog rule, walks candidate sources with fallback allowed only
     * on confirmed `missing`, and validates scalar type, UTF-8, length, templates, secrets, and URL syntax.
     *
     * @return array<string, mixed>
     */
    public function readField(string $field, RankMathSourceObserver $observer, ?string $rankMathVersion): array
    {
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new \InvalidArgumentException('Requested field is outside the Rank Math read allowlist.');
        }

        $resolution = $this->catalog->resolveField($field, $rankMathVersion);
        if ($resolution['status'] !== 'resolved' || $resolution['rule'] === null) {
            return $this->closedFieldResult(
                $field,
                'unsupported',
                'mapping_catalog',
                $resolution['reason'],
                'catalog:' . $resolution['mapping_revision'],
                $resolution['mapping_revision'],
                'catalog'
            );
        }

        $rule = $resolution['rule'];
        $mappingRevision = $resolution['mapping_revision'];
        $allowFallback = $rule['fallback_rule'] === 'missing_only';
        $sources = $rule['sources'];
        $lastMissing = null;

        foreach ($sources as $index => $candidate) {
            $rawObs = $observer->observe($candidate['source'], $candidate['slot']);
            $obs = $this->normalizeObservation($rawObs, $candidate['source'], $candidate['slot']);

            if ($obs['presence'] === 'unreadable' || !$obs['can_confirm_presence']) {
                return $this->closedFieldResult(
                    $field,
                    'unreadable',
                    $obs['source'],
                    $obs['presence'] === 'unreadable' ? $obs['reason'] : 'presence_unconfirmed',
                    $obs['evidence_identity'],
                    $mappingRevision,
                    $obs['provenance']
                );
            }

            if ($obs['presence'] === 'missing') {
                if (!$obs['can_confirm_presence']) {
                    return $this->closedFieldResult(
                        $field,
                        'unreadable',
                        $obs['source'],
                        'presence_unconfirmed',
                        $obs['evidence_identity'],
                        $mappingRevision,
                        $obs['provenance']
                    );
                }

                $lastMissing = $obs;
                if (!$allowFallback || $index === count($sources) - 1) {
                    return $this->closedFieldResult(
                        $field,
                        'missing',
                        $obs['source'],
                        'field_missing',
                        $obs['evidence_identity'],
                        $mappingRevision,
                        $obs['provenance']
                    );
                }

                continue;
            }

            if ($obs['value'] === null) {
                return $this->closedFieldResult(
                    $field,
                    'explicit_null',
                    $obs['source'],
                    'explicit_null_value',
                    $obs['evidence_identity'],
                    $mappingRevision,
                    $obs['provenance']
                );
            }

            $validation = $this->validateAndNormalizeValue($field, $obs['value'], $rule['data_type']);
            if ($validation['valid'] === false) {
                return $this->closedFieldResult(
                    $field,
                    'invalid',
                    $obs['source'],
                    $validation['reason'],
                    $obs['evidence_identity'],
                    $mappingRevision,
                    $obs['provenance']
                );
            }

            return $this->closedFieldResult(
                $field,
                'value',
                $obs['source'],
                'valid_value',
                $obs['evidence_identity'],
                $mappingRevision,
                $obs['provenance'],
                $validation['value']
            );
        }

        return $this->closedFieldResult(
            $field,
            'missing',
            $lastMissing['source'] ?? 'mapping_catalog',
            'field_missing',
            $lastMissing['evidence_identity'] ?? ('catalog:' . $mappingRevision),
            $mappingRevision,
            $lastMissing['provenance'] ?? 'catalog'
        );
    }

    /**
     * Reject field requests outside the strict three-field allowlist.
     *
     * Business purpose: Prevent callers from probing arbitrary Rank Math meta keys through `readAll`.
     * Technical description: Validates sequential list structure and membership in ALLOWED_FIELDS.
     *
     * @param list<mixed> $requestedFields
     */
    private function assertAllowlistedRequest(array $requestedFields): void
    {
        if ($requestedFields === [] || array_keys($requestedFields) !== range(0, count($requestedFields) - 1)) {
            throw new \InvalidArgumentException('Requested fields must be a non-empty sequential list.');
        }
        foreach ($requestedFields as $field) {
            if (!is_string($field) || !in_array($field, self::ALLOWED_FIELDS, true)) {
                throw new \InvalidArgumentException('Requested field is outside the Rank Math read allowlist.');
            }
        }
    }

    /**
     * Normalize a raw source observation into presence-aware adapter metadata without mutating input.
     *
     * Business purpose: Fail closed as `unreadable` whenever an observation omits presence or provenance.
     * Technical description: Validates presence enum, presence-confirmation flag, safe provenance label,
     * and sanitized identifiers.
     *
     * @param array<string, mixed> $raw
     * @return array{
     *     presence:string,
     *     can_confirm_presence:bool,
     *     value:mixed,
     *     source:string,
     *     evidence_identity:string,
     *     provenance:string,
     *     reason:string
     * }
     */
    private function normalizeObservation(array $raw, string $defaultSource, string $slot): array
    {
        $source = $this->cleanIdentifier($raw['source'] ?? null, $defaultSource);
        $evidence = $this->cleanIdentifier($raw['evidence_identity'] ?? null, 'obs:' . $source . ':' . $slot);
        $provenanceRaw = array_key_exists('provenance', $raw) ? $raw['provenance'] : null;
        $hasValidProvenance = is_string($provenanceRaw)
            && in_array($provenanceRaw, RankMathSourceObserver::ALLOWED_PROVENANCE, true);
        $provenance = $hasValidProvenance ? $provenanceRaw : 'unverified_source';

        if (!$hasValidProvenance) {
            return [
                'presence' => 'unreadable',
                'can_confirm_presence' => false,
                'value' => null,
                'source' => $source,
                'evidence_identity' => $evidence,
                'provenance' => $provenance,
                'reason' => 'invalid_source_provenance',
            ];
        }

        $presence = $raw['presence'] ?? null;
        $canConfirm = array_key_exists('can_confirm_presence', $raw) ? $raw['can_confirm_presence'] === true : false;
        if (!is_string($presence) || !in_array($presence, ['present', 'missing', 'unreadable'], true)
            || ($presence === 'present' && !array_key_exists('value', $raw))) {
            return [
                'presence' => 'unreadable',
                'can_confirm_presence' => false,
                'value' => null,
                'source' => $source,
                'evidence_identity' => $evidence,
                'provenance' => $provenance,
                'reason' => 'malformed_source_observation',
            ];
        }

        if ($presence === 'unreadable') {
            $reason = $this->cleanReason($raw['reason'] ?? null, 'source_unreadable');
            return [
                'presence' => 'unreadable',
                'can_confirm_presence' => $canConfirm,
                'value' => null,
                'source' => $source,
                'evidence_identity' => $evidence,
                'provenance' => $provenance,
                'reason' => $reason,
            ];
        }

        return [
            'presence' => $presence,
            'can_confirm_presence' => $canConfirm,
            'value' => $presence === 'present' ? $raw['value'] : null,
            'source' => $source,
            'evidence_identity' => $evidence,
            'provenance' => $provenance,
            'reason' => $this->cleanReason($raw['reason'] ?? null, 'observed'),
        ];
    }

    /**
     * Validate and normalize a non-null raw value according to field policy.
     *
     * Business purpose: Reject non-strings, empty/whitespace values, broken UTF-8, control characters,
     * template tokens (`%title%`, `{{...}}`), embedded secrets, oversized text, and invalid canonical URLs.
     * Technical description: Returns `{valid:true, value:string}` or `{valid:false, reason:string}` without casting.
     *
     * @return array{valid:bool,reason:string,value:?string}
     */
    private function validateAndNormalizeValue(string $field, mixed $raw, string $dataType): array
    {
        if (!is_string($raw)) {
            return ['valid' => false, 'reason' => 'invalid_type', 'value' => null];
        }
        if (preg_match('//u', $raw) !== 1) {
            return ['valid' => false, 'reason' => 'invalid_utf8', 'value' => null];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw) === 1) {
            return ['valid' => false, 'reason' => 'control_character_rejected', 'value' => null];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['valid' => false, 'reason' => 'empty_or_whitespace', 'value' => null];
        }
        if ($this->containsTemplateMarker($trimmed)) {
            return ['valid' => false, 'reason' => 'unrendered_template_rejected', 'value' => null];
        }
        if ($this->containsSecretMaterial($trimmed)) {
            return ['valid' => false, 'reason' => 'secret_or_credential_rejected', 'value' => null];
        }

        if ($dataType === 'canonical_url' || $field === 'canonical') {
            return $this->validateCanonicalUrl($trimmed);
        }

        $normalized = preg_replace('/\s+/u', ' ', $trimmed);
        if (!is_string($normalized) || $normalized === '') {
            return ['valid' => false, 'reason' => 'normalization_failed', 'value' => null];
        }

        $maxChars = $field === 'title' ? self::MAX_TITLE_CHARS : self::MAX_DESCRIPTION_CHARS;
        if (preg_match_all('/./us', $normalized) > $maxChars) {
            return ['valid' => false, 'reason' => 'exceeds_max_length', 'value' => null];
        }

        return ['valid' => true, 'reason' => 'valid_value', 'value' => $normalized];
    }

    /**
     * Validate and normalize an HTTP(S) canonical URL without performing network or DNS operations.
     *
     * Business purpose: Enforce deterministic canonical syntax rules (HTTP/HTTPS, valid host, valid port,
     * no credentials, no fragment, no whitespace) purely offline.
     * Technical description: Parses URL components via parse_url/filter_var, lowercases scheme and host,
     * and strips redundant default ports (`:80` for http, `:443` for https).
     *
     * @return array{valid:bool,reason:string,value:?string}
     */
    private function validateCanonicalUrl(string $candidate): array
    {
        if (strlen($candidate) > self::MAX_CANONICAL_BYTES || preg_match('/\s/u', $candidate) === 1) {
            return ['valid' => false, 'reason' => 'invalid_canonical_url', 'value' => null];
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return ['valid' => false, 'reason' => 'invalid_canonical_url', 'value' => null];
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ['valid' => false, 'reason' => 'invalid_canonical_scheme_or_host', 'value' => null];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['valid' => false, 'reason' => 'canonical_userinfo_rejected', 'value' => null];
        }
        if (array_key_exists('fragment', $parts) || str_contains($candidate, '#')) {
            return ['valid' => false, 'reason' => 'canonical_fragment_rejected', 'value' => null];
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return ['valid' => false, 'reason' => 'invalid_canonical_port', 'value' => null];
        }

        $portPart = '';
        if (isset($parts['port'])) {
            $isDefaultPort = ($scheme === 'http' && $parts['port'] === 80)
                || ($scheme === 'https' && $parts['port'] === 443);
            if (!$isDefaultPort) {
                $portPart = ':' . $parts['port'];
            }
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return [
            'valid' => true,
            'reason' => 'valid_value',
            'value' => $scheme . '://' . $host . $portPart . $path . $query,
        ];
    }

    /**
     * Detect unrendered Rank Math / placeholder template syntax inside raw strings.
     *
     * Business purpose: Prevent raw SEO templates such as `%title% %sep% %sitename%` or `{{post.title}}`
     * from passing as concrete frontend values.
     * Technical description: Matches Rank Math `%variable%` tokens and mustache-style `{{...}}` placeholders.
     */
    private function containsTemplateMarker(string $value): bool
    {
        return preg_match('/%[a-zA-Z][a-zA-Z0-9_()-]*%|\{\{[^}]+\}\}/', $value) === 1;
    }

    /**
     * Detect recognizable credentials or secret tokens inside raw field strings.
     *
     * Business purpose: Ensure adapter results never carry API keys, bearer tokens, private keys, or URL credentials.
     * Technical description: Applies offline regex checks on decoded text without exposing matched secrets.
     */
    private function containsSecretMaterial(string $value): bool
    {
        $patterns = [
            '~https?://[^\s/?#]*@~i',
            '/(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|password|secret)\s*[=:]\s*\S+/i',
            '/\bBearer\s+\S+|\bsk-(?:ant-)?[A-Za-z0-9_-]{8,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a closed field-read result record, omitting `value` unless `state === 'value'`.
     *
     * Business purpose: Enforce exact O2 field contract and provenance separation.
     * Technical description: Includes `value` key strictly when `state === 'value'`.
     *
     * @return array<string, mixed>
     */
    private function closedFieldResult(
        string $field,
        string $state,
        string $source,
        string $reason,
        string $evidenceIdentity,
        string $mappingRevision,
        string $provenance,
        ?string $value = null
    ): array {
        $record = [
            'field' => $field,
            'state' => $state,
            'source' => $source,
            'reason' => $reason,
            'evidence_identity' => $evidenceIdentity,
            'mapping_revision' => $mappingRevision,
            'provenance' => $provenance,
        ];
        if ($state === 'value' && $value !== null) {
            $record['value'] = $value;
        }

        return $record;
    }

    /**
     * Sanitize provenance or source identifiers with a deterministic fallback.
     *
     * Business purpose: Prevent control characters or empty identifiers in audit records.
     * Technical description: Validates UTF-8 and non-empty printable content.
     */
    private function cleanIdentifier(mixed $value, string $fallback): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return $fallback;
        }
        $trimmed = trim($value);

        return $trimmed === '' || preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1 ? $fallback : $trimmed;
    }

    /**
     * Sanitize error/reason codes to safe machine-readable tokens.
     *
     * Business purpose: Avoid leaking raw storage payloads or credentials inside `reason`.
     * Technical description: Allows `[a-z0-9_:-]` tokens up to 80 bytes, otherwise returns fallback.
     */
    private function cleanReason(mixed $value, string $fallback): string
    {
        if (!is_string($value)) {
            return $fallback;
        }
        $trimmed = trim($value);

        return preg_match('/^[a-z0-9_:-]{1,80}$/', $trimmed) === 1 ? $trimmed : $fallback;
    }
}
