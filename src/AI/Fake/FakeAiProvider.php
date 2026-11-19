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

    private \MySeoForAll\AI\Contracts\AiOutputValidator $outputValidator;

    /**
     * Inject the shared response boundary while retaining a no-argument offline provider.
     */
    public function __construct(?\MySeoForAll\AI\Contracts\AiOutputValidator $outputValidator = null)
    {
        $this->outputValidator = $outputValidator ?? new \MySeoForAll\AI\Contracts\AiOutputValidator();
    }

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
        $response = [
            'schema_version' => AiProvider::RESPONSE_SCHEMA,
            'provider' => $this->name(),
            'task' => $task,
            'status' => 'ok',
            'output' => $output,
        ];

        return $this->outputValidator->validate($response, $task);
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
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
                'Provider request envelope is malformed.'
            );
        }
        if ($request['schema_version'] !== AiProvider::REQUEST_SCHEMA) {
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
                'Provider request schema is unsupported.'
            );
        }
        if (!is_string($request['task'])) {
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
                'Provider task is malformed.'
            );
        }
        if (!in_array($request['task'], self::TASKS, true)) {
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::UNSUPPORTED_TASK,
                'Provider task is unsupported.'
            );
        }
        if (!is_array($request['context'])) {
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
                'Provider context is malformed.'
            );
        }

        try {
            (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($request['context']);
        } catch (\Throwable) {
            throw new \MySeoForAll\AI\Contracts\AiProviderException(
                \MySeoForAll\AI\Contracts\AiProviderException::MALFORMED_REQUEST,
                'Provider context is invalid.'
            );
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
        $language = (string) ($context['language'] ?? 'vi');

        return match ($task) {
            'seo_analysis' => [
                'findings' => [],
            ],
            'title_meta_suggestion' => [
                'target_field' => 'title',
                'value' => trim((string) ($context['title'] ?? $context['product_name'] ?? 'Suggested SEO title')),
                'language' => $language,
                'provenance' => 'editor',
            ],
            'product_description_draft' => [
                'target' => 'product',
                'content' => trim((string) ($context['description'] ?? $context['short_description'] ?? $context['product_name'] ?? 'Product description draft')),
                'language' => $language,
                'provenance' => 'woocommerce',
                'uses_commercial_facts' => false,
            ],
        };
    }
}
