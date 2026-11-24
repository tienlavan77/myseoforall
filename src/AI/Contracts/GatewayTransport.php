<?php
// Defines the trusted gateway transport boundary for one controlled HTTPS attempt.
namespace MySeoForAll\AI\Contracts;

/**
 * Sends one already-authorized gateway request and returns only sanitized transport data.
 */
interface GatewayTransport
{
    /** @param array<string,string> $headers @param array<string,mixed> $body @param list<string> $ips @return array{status:int,body:string,sent:bool} */
    public function send(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] array $body, int $timeout, array $ips): array;
}
