<?php
// Provides module declarations and business logic for AiProvider.php.


declare(strict_types=1);

/**
 * AI provider contract for My SEO For All.
 * This boundary separates normalized SEO context from provider implementations and prevents implicit network behavior.
 */

namespace MySeoForAll\AI\Contracts;

/**
 * Identifies a provider implementation owned by the AI boundary.
 */
interface AiProvider
{
    public const REQUEST_SCHEMA = 'myseo.ai.request.v1';
    public const RESPONSE_SCHEMA = 'myseo.ai.response.v1';

    /**
     * Return the stable provider identifier.
     */
    public function name(): string;

    /**
     * Generate a deterministic response for a validated provider request.
     *
     * @param array{schema_version:string,task:string,context:array<string,mixed>} $request
     * @return array{schema_version:string,provider:string,task:string,status:string,output:array<string,mixed>}
     * @throws \InvalidArgumentException When the request is malformed or unsupported.
     */
    public function generate(array $request): array;
}
