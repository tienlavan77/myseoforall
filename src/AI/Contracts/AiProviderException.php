<?php
/**
 * Defines the offline AI provider exception contract so callers can distinguish malformed requests,
 * unsupported tasks, and invalid responses without receiving secrets or the rejected payload.
 */

declare(strict_types=1);

namespace MySeoForAll\AI\Contracts;

/**
 * Carries a safe, machine-readable category for offline provider contract failures.
 */
final class AiProviderException extends \InvalidArgumentException
{
    public const MALFORMED_REQUEST = 'malformed_request';
    public const UNSUPPORTED_TASK = 'unsupported_task';
    public const INVALID_RESPONSE = 'invalid_response';
    public const PROVIDER_DISABLED = 'provider_disabled';
    public const AUTHENTICATION_FAILED = 'authentication_failed';
    public const GATEWAY_POLICY_REJECTED = 'gateway_policy_rejected';
    public const RATE_LIMITED = 'rate_limited';
    public const TRANSPORT_FAILED = 'transport_failed';

    private string $category;

    /**
     * Store a validated error category and a safe message without retaining rejected input.
     */
    public function __construct(string $category, string $message)
    {
        if (!in_array($category, [
            self::MALFORMED_REQUEST,
            self::UNSUPPORTED_TASK,
            self::INVALID_RESPONSE,
            self::PROVIDER_DISABLED,
            self::AUTHENTICATION_FAILED,
            self::GATEWAY_POLICY_REJECTED,
            self::RATE_LIMITED,
            self::TRANSPORT_FAILED,
        ], true)) {
            $category = self::MALFORMED_REQUEST;
        }

        $this->category = $category;
        parent::__construct($message);
    }

    /**
     * Return the stable error category without exposing input data.
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * Return the stable error category for callers using getter conventions.
     */
    public function getCategory(): string
    {
        return $this->category();
    }
}
