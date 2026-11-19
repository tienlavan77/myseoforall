<?php
/**
 * Implements the shared offline AI response boundary for the three approved tasks.
 * It validates exact envelopes, task schemas, Unicode text limits, source labels, response depth,
 * and deterministic JSON size without mutating, storing, logging, or transmitting response data.
 */

declare(strict_types=1);

namespace MySeoForAll\AI\Contracts;

/**
 * Validates bounded, closed response envelopes before an AI result reaches a caller.
 */
final class AiOutputValidator
{
    public const RESPONSE_SCHEMA = 'myseo.ai.response.v1';
    public const MAX_FINDINGS = 50;
    public const MAX_TEXT_CODE_POINTS = 2000;
    public const MAX_DRAFT_CODE_POINTS = 4000;
    public const MAX_RESPONSE_BYTES = 12000;
    public const MAX_DEPTH = 4;

    private const TASKS = [
        'seo_analysis',
        'title_meta_suggestion',
        'product_description_draft',
    ];

    private const FINDING_CODES = [
        'title_missing',
        'title_length',
        'meta_description_missing',
        'meta_description_length',
        'content_missing',
        'heading_primary_count',
        'heading_structure_missing',
        'canonical_missing',
        'image_alt_missing',
        'internal_links_missing',
        'product_fields_missing',
    ];

    private const FINDING_FIELDS = [
        'title',
        'meta_description',
        'content',
        'canonical',
        'images',
        'internal_links',
        'product',
    ];

    private const SEVERITIES = ['info', 'warning', 'error'];
    private const LANGUAGES = ['vi', 'en'];
    private const PROVENANCE = ['wordpress', 'woocommerce', 'rank_math', 'editor'];

    /**
     * Validate one complete response envelope and return the original value unchanged.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public function validate(array $response, ?string $expectedTask = null): array
    {
        $this->assertExactKeys($response, ['schema_version', 'provider', 'task', 'status', 'output']);
        if ($response['schema_version'] !== self::RESPONSE_SCHEMA) {
            $this->fail('Response schema version is invalid.');
        }
        if (!is_string($response['provider']) || $response['provider'] === '') {
            $this->fail('Response provider is invalid.');
        }
        $this->assertSafeText($response['provider'], self::MAX_TEXT_CODE_POINTS, false);
        if (!is_string($response['task']) || !in_array($response['task'], self::TASKS, true)) {
            $this->fail('Response task is invalid.');
        }
        if ($expectedTask !== null && $response['task'] !== $expectedTask) {
            $this->fail('Response task does not match the requested task.');
        }
        if ($response['status'] !== 'ok') {
            $this->fail('Response status is invalid.');
        }
        if (!is_array($response['output'])) {
            $this->fail('Response output must be an object.');
        }

        $this->validateOutput($response['task'], $response['output']);
        $this->assertDepth($response);
        $this->assertEncodedSize($response);

        return $response;
    }

    /**
     * Validate the exact output schema selected by the task identifier.
     *
     * @param array<string,mixed> $output
     */
    private function validateOutput(string $task, array $output): void
    {
        if ($task === 'seo_analysis') {
            $this->validateSeoAnalysis($output);
            return;
        }
        if ($task === 'title_meta_suggestion') {
            $this->assertExactKeys($output, ['target_field', 'value', 'language', 'provenance']);
            if (!is_string($output['target_field']) || !in_array($output['target_field'], ['title', 'meta_description'], true)) {
                $this->fail('Title suggestion target is invalid.');
            }
            $this->assertSafeText($output['value'], self::MAX_TEXT_CODE_POINTS, true);
            $this->assertLanguage($output['language']);
            $this->assertProvenance($output['provenance']);
            return;
        }

        $this->assertExactKeys($output, ['target', 'content', 'language', 'provenance', 'uses_commercial_facts']);
        if ($output['target'] !== 'product') {
            $this->fail('Product draft target is invalid.');
        }
        $this->assertSafeText($output['content'], self::MAX_DRAFT_CODE_POINTS, true);
        $this->assertLanguage($output['language']);
        $this->assertProvenance($output['provenance']);
        if ($output['uses_commercial_facts'] !== false) {
            $this->fail('Product draft commercial-facts guard is invalid.');
        }
    }

    /**
     * Validate the closed findings list and each finding's scalar contract.
     *
     * @param array<string,mixed> $output
     */
    private function validateSeoAnalysis(array $output): void
    {
        $this->assertExactKeys($output, ['findings']);
        $findings = $output['findings'];
        if (!is_array($findings) || !$this->isList($findings) || count($findings) > self::MAX_FINDINGS) {
            $this->fail('SEO findings must be a bounded list.');
        }

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                $this->fail('SEO finding must be an object.');
            }
            $this->assertExactKeys($finding, ['code', 'severity', 'message', 'field', 'evidence', 'suggested_fix']);
            if (!is_string($finding['code']) || !in_array($finding['code'], self::FINDING_CODES, true)) {
                $this->fail('SEO finding code is invalid.');
            }
            if (!is_string($finding['severity']) || !in_array($finding['severity'], self::SEVERITIES, true)) {
                $this->fail('SEO finding severity is invalid.');
            }
            if (!is_string($finding['field']) || !in_array($finding['field'], self::FINDING_FIELDS, true)) {
                $this->fail('SEO finding field is invalid.');
            }
            $this->assertSafeText($finding['message'], self::MAX_TEXT_CODE_POINTS, true);
            $this->assertSafeText($finding['evidence'], self::MAX_TEXT_CODE_POINTS, false);
            $this->assertSafeText($finding['suggested_fix'], self::MAX_TEXT_CODE_POINTS, false);
        }
    }

    /**
     * Validate a lower-case language code from the small offline response policy.
     */
    private function assertLanguage(mixed $language): void
    {
        if (!is_string($language) || !in_array($language, self::LANGUAGES, true)) {
            $this->fail('Response language is invalid.');
        }
    }

    /**
     * Validate an approved provenance label without treating it as source evidence.
     */
    private function assertProvenance(mixed $provenance): void
    {
        if (!is_string($provenance) || !in_array($provenance, self::PROVENANCE, true)) {
            $this->fail('Response provenance is invalid.');
        }
    }

    /**
     * Validate a bounded UTF-8 scalar and reject credentials, contacts, and control characters.
     */
    private function assertSafeText(mixed $value, int $maximum, bool $required): void
    {
        if (!is_string($value)) {
            $this->fail('Response text has an invalid type.');
        }
        if ($required && $value === '') {
            $this->fail('Response text is required.');
        }
        if (preg_match('//u', $value) !== 1) {
            $this->fail('Response text encoding is invalid.');
        }
        preg_match_all('/./us', $value, $matches);
        if (count($matches[0]) > $maximum) {
            $this->fail('Response text exceeds its limit.');
        }
        if (preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value) === 1) {
            $this->fail('Response text contains a disallowed control character.');
        }
        if (preg_match('/(?:api[\\s_-]*key|bearer\\s+|password\\s*[:=]|token\\s*[:=]|secret\\s*[:=])/i', $value) === 1
            || preg_match('/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i', $value) === 1
        ) {
            $this->fail('Response text contains private data.');
        }
    }

    /**
     * Reject extra or missing keys while preserving the caller's original array.
     *
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        $wanted = $expected;
        sort($wanted);
        if ($actual !== $wanted) {
            $this->fail('Response contains an unexpected or missing field.');
        }
    }

    /**
     * Enforce the maximum nested array depth before serialization.
     */
    private function assertDepth(mixed $value, int $depth = 0): void
    {
        if (!is_array($value)) {
            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $this->fail('Response nesting exceeds its limit.');
        }
        foreach ($value as $child) {
            $this->assertDepth($child, $depth + 1);
        }
    }

    /**
     * Enforce deterministic UTF-8 JSON size without retaining an encoded response.
     *
     * @param array<string,mixed> $response
     */
    private function assertEncodedSize(array $response): void
    {
        try {
            $encoded = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->fail('Response cannot be encoded safely.');
        }
        if (strlen($encoded) > self::MAX_RESPONSE_BYTES) {
            $this->fail('Response exceeds its byte limit.');
        }
    }

    /**
     * Distinguish sequential JSON lists from associative objects.
     *
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Throw only a safe category-neutral response error.
     */
    private function fail(string $message): never
    {
        throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $message);
    }
}
