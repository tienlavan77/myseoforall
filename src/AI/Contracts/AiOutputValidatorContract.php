<?php
/**
 * This contract keeps AI response validation independent from any provider implementation.
 * It requires callers to validate a complete bounded response envelope and preserves the
 * validated value so providers cannot bypass the shared response boundary.
 */

declare(strict_types=1);

namespace MySeoForAll\AI\Contracts;

/**
 * Define the shared response boundary required by every AI provider.
 */
interface AiOutputValidatorContract
{
    /**
     * Validate the complete response envelope before a caller can consume it.
     *
     * Business purpose: prevent every provider from returning unbounded or malformed AI output.
     * Technical behavior: reject invalid values and return the original validated array without mutation.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public function validate(array $response, ?string $expectedTask = null, ?string $expectedProvider = null): array;
}
