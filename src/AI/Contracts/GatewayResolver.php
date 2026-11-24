<?php
// Defines the bounded DNS resolution seam used to verify every gateway connection.
namespace MySeoForAll\AI\Contracts;

/**
 * Resolves one hostname to candidate addresses without retaining request payloads.
 */
interface GatewayResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
