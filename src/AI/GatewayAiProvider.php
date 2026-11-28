<?php
// Implements the controlled gateway provider while keeping request validation and response parsing fail-closed.
namespace MySeoForAll\AI;

use MySeoForAll\AI\Contracts\AiOutputValidatorContract;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Contracts\AiProviderException;

/**
 * Validates caller payloads, delegates one authorized request, and accepts only the closed Messages response shape.
 */
final class GatewayAiProvider implements AiProvider
{
    private const TASKS = ['seo_analysis', 'title_meta_suggestion', 'product_description_draft'];

    /** Compose the provider with the settings executor and response boundary used for every gateway attempt. */
    public function __construct(private readonly AiSettingsRepository $settings, private readonly AiOutputValidatorContract $validator = new \MySeoForAll\AI\Contracts\AiOutputValidator()) {}

    /** Return the stable production provider identifier used in response envelopes. */
    public function name(): string { return 'gateway'; }

    /** Validate one request, execute it through the closed settings boundary, and return a schema-checked proposal. @param array<string,mixed> $request */
    public function generate(#[\SensitiveParameter] array $request): array
    {
        $this->validateRequest($request);
        $task = $request['task'];
        try { $result = $this->settings->executeGatewayRequest($task, $request['context']); }
        catch (\RuntimeException $error) { $this->throwCategory($error->getMessage()); }
        $status = (int) ($result['status'] ?? 0);
        if ($status === 401 || $status === 403) { throw new AiProviderException(AiProviderException::AUTHENTICATION_FAILED, 'Gateway authentication failed.'); }
        if ($status === 429) { throw new AiProviderException(AiProviderException::RATE_LIMITED, 'Gateway rate limit reached.'); }
        if (in_array($status, [502, 503, 504], true)) { throw new AiProviderException(AiProviderException::TRANSPORT_FAILED, 'Gateway transport failed.'); }
        if (($status >= 400 && $status <= 599) || !is_string($result['body'] ?? null)) { throw new AiProviderException(AiProviderException::TRANSPORT_FAILED, 'Gateway transport failed.'); }
        if ($status !== 200) { throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Gateway response is invalid.'); }
        try { $decoded = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Gateway response is invalid.'); }
        if (!is_array($decoded) || ($decoded['role'] ?? null) !== 'assistant' || ($decoded['stop_reason'] ?? null) !== 'end_turn' || !is_array($decoded['content'] ?? null) || count($decoded['content']) !== 1 || ($decoded['content'][0]['type'] ?? null) !== 'text' || !is_string($decoded['content'][0]['text'] ?? null)) { throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Gateway response is invalid.'); }
        try { $output = json_decode($decoded['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Gateway output is invalid.'); }
        if (!is_array($output)) { throw new AiProviderException(AiProviderException::INVALID_RESPONSE, 'Gateway output is invalid.'); }
        return $this->validator->validate(['schema_version' => AiProvider::RESPONSE_SCHEMA, 'provider' => $this->name(), 'task' => $task, 'status' => 'ok', 'output' => $output], $task, $this->name());
    }

    /** Validate the closed request envelope and provider payload before any settings or network work. @param array<string,mixed> $request */
    private function validateRequest(#[\SensitiveParameter] array $request): void
    {
        $expected = ['schema_version', 'task', 'context'];
        if (array_diff(array_keys($request), $expected) !== [] || array_diff($expected, array_keys($request)) !== [] || $request['schema_version'] !== AiProvider::REQUEST_SCHEMA || !is_string($request['task']) || !is_array($request['context'])) { throw new AiProviderException(AiProviderException::MALFORMED_REQUEST, 'Provider request is malformed.'); }
        if (!in_array($request['task'], self::TASKS, true)) { throw new AiProviderException(AiProviderException::UNSUPPORTED_TASK, 'Provider task is unsupported.'); }
        try { (new \MySeoForAll\SEO\Context\ProviderPayloadBuilder())->validatePayload($request['context']); }
        catch (\Throwable) { throw new AiProviderException(AiProviderException::MALFORMED_REQUEST, 'Provider context is invalid.'); }
    }

    /** Map internal failure reasons to the stable caller categories without retaining upstream details. */
    private function throwCategory(string $reason): never
    {
        $category = match ($reason) {
            'provider_disabled' => AiProviderException::PROVIDER_DISABLED,
            'transport_unavailable', 'tls_failed', 'connect_failed', 'connect_timeout_unsent',
            'timeout_after_send', 'deadline_exceeded' => AiProviderException::TRANSPORT_FAILED,
            'authentication_failed' => AiProviderException::AUTHENTICATION_FAILED,
            'gateway_policy_rejected' => AiProviderException::GATEWAY_POLICY_REJECTED,
            'response_too_large' => AiProviderException::INVALID_RESPONSE,
            default => AiProviderException::TRANSPORT_FAILED,
        };
        throw new AiProviderException($category, 'Gateway request was not completed.');
    }
}
