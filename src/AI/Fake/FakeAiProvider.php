<?php
// Provides module declarations and business logic for FakeAiProvider.php.


declare(strict_types=1);

/**
 * Offline fake AI provider for My SEO For All.
 * It provides deterministic preview behavior for contract tests without network calls, credentials, persistence, logging, or external provider dependencies.
 */

namespace MySeoForAll\AI\Fake;

use MySeoForAll\AI\Contracts\AiProvider;

// Defines FakeAiProvider class behavior for FakeAiProvider.php.
final class FakeAiProvider implements AiProvider
{
    private const TASKS = [
        'seo_analysis',
        'title_meta_suggestion',
        'product_description_draft',
    ];

    private const CONTEXT_FIELDS = [
        'title',
        'meta_description',
        'content',
        'url',
        'canonical',
        'headings',
        'images',
        'internal_links',
        'type',
        'language',
        'product_name',
        'short_description',
        'description',
    ];

    /**
     * Identify the offline provider so callers can expose its status without network behavior.
     */
    public function name(): string
    {
        return 'fake';
    }

    /**
     * Validate an allowlisted request and return a stable preview response without retaining input.
     *
     * @param array<string,mixed> $request
     * @return array{schema_version:string,provider:string,task:string,status:string,output:array<string,mixed>}
     */
    public function generate(array $request): array
    {
        $this->validateRequest($request);
        $task = $request['task'];
        $context = $request['context'];

        $output = $this->buildOutput($task, $context);
        $this->validateOutput($task, $output);

        return [
            'schema_version' => AiProvider::RESPONSE_SCHEMA,
            'provider' => $this->name(),
            'task' => $task,
            'status' => 'ok',
            'output' => $output,
        ];
    }

    /**
     * Reject malformed envelopes, unsupported tasks, secrets, and unknown nested context fields.
     *
     * @param array<string,mixed> $request
     */
    private function validateRequest(array $request): void
    {
        $expected = ['schema_version', 'task', 'context'];
        if (array_diff(array_keys($request), $expected) !== [] || array_diff($expected, array_keys($request)) !== []) {
            throw new \InvalidArgumentException('Provider request must contain only schema_version, task, and context.');
        }
        if ($request['schema_version'] !== AiProvider::REQUEST_SCHEMA) {
            throw new \InvalidArgumentException('Unsupported provider request schema.');
        }
        if (!is_string($request['task']) || !in_array($request['task'], self::TASKS, true)) {
            throw new \InvalidArgumentException('Unsupported provider task.');
        }
        if (!is_array($request['context'])) {
            throw new \InvalidArgumentException('Provider context must be an array.');
        }
        (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->build($request['context'], 'generic');
        $this->validateContext($request['context']);
    }

    /**
     * Enforce the provider privacy allowlist recursively while preserving scalar context values.
     *
     * @param array<string,mixed> $context
     */
    private function validateContext(array $context): void
    {
        foreach ($context as $key => $value) {
            if (!is_string($key) || !in_array($key, self::CONTEXT_FIELDS, true)) {
                throw new \InvalidArgumentException('Provider context contains a forbidden field.');
            }
            if (in_array($key, ['headings', 'images', 'internal_links'], true)) {
                $this->validateList($key, $value);
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Provider context contains an invalid value type.');
            }
        }
    }

    /**
     * Validate structured lists using a narrow item-specific allowlist with no arbitrary nesting.
     *
     * @param mixed $value
     */
    private function validateList(string $field, mixed $value): void
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Provider structured context must be a list.');
        }
        $keys = array_keys($value);
        if ($keys !== [] && $keys !== range(0, count($value) - 1)) {
            throw new \InvalidArgumentException('Provider structured context must be a list.');
        }
        $allowed = match ($field) {
            'headings' => ['level', 'text'],
            'images' => ['src', 'alt'],
            'internal_links' => ['url', 'anchor'],
        };
        foreach ($value as $item) {
            if (!is_array($item) || array_diff(array_keys($item), $allowed) !== []) {
                throw new \InvalidArgumentException('Provider structured context contains a forbidden nested field.');
            }
            foreach ($item as $itemValue) {
                if (!is_scalar($itemValue) && $itemValue !== null) {
                    throw new \InvalidArgumentException('Provider structured context contains an invalid nested value.');
                }
            }
        }
    }

    /** Validate task-specific output fields before exposing the offline response to callers. */
    private function validateOutput(string $task, array $output): void
    {
        if ($task === 'seo_analysis') {
            if (!is_array($output['findings'] ?? null)) {
                throw new \InvalidArgumentException('SEO analysis output findings must be a list.');
            }
            foreach ($output['findings'] as $finding) {
                if (!is_array($finding) || array_diff(['code', 'severity', 'message', 'field', 'evidence', 'suggested_fix'], array_keys($finding)) !== []) {
                    throw new \InvalidArgumentException('SEO analysis output contains an invalid finding.');
                }
            }
            return;
        }

        if ($task === 'title_meta_suggestion') {
            if (!is_string($output['title'] ?? null) || strlen($output['title']) > 60
                || !is_string($output['meta_description'] ?? null) || strlen($output['meta_description']) > 160
                || !is_string($output['language'] ?? null)
                || ($output['target_fields'] ?? null) !== ['title', 'meta_description']) {
                throw new \InvalidArgumentException('Title/meta suggestion output is outside its schema.');
            }
            return;
        }

        if ($task === 'product_description_draft'
            && (!is_string($output['description'] ?? null) || strlen($output['description']) > 5000
                || ($output['target_field'] ?? null) !== 'description'
                || !is_string($output['language'] ?? null)
                || !is_string($output['provenance'] ?? null)
                || ($output['uses_commercial_facts'] ?? null) !== false)) {
            throw new \InvalidArgumentException('Product description output is outside its schema.');
        }
    }

    /**
     * Build task-specific deterministic output from the already validated context.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildOutput(string $task, array $context): array
    {
        return match ($task) {
            'seo_analysis' => [
                'findings' => [],
                'language' => (string) ($context['language'] ?? 'vi'),
                'message' => 'Offline provider preview; run local analysis for findings.',
            ],
            'title_meta_suggestion' => [
                'title' => trim((string) ($context['title'] ?? $context['product_name'] ?? 'Suggested SEO title')),
                'meta_description' => trim((string) ($context['meta_description'] ?? 'Suggested meta description')),
                'language' => (string) ($context['language'] ?? 'vi'),
                'target_fields' => ['title', 'meta_description'],
            ],
            'product_description_draft' => [
                'description' => trim((string) ($context['description'] ?? $context['short_description'] ?? $context['product_name'] ?? 'Product description draft')),
                'target_field' => 'description',
                'language' => (string) ($context['language'] ?? 'vi'),
                'provenance' => (string) ($context['source'] ?? 'offline_context'),
                'uses_commercial_facts' => false,
            ],
        };
    }
}
