<?php
// Resolves gateway hostnames through the platform DNS API for per-attempt public-IP validation.
namespace MySeoForAll\AI;

use MySeoForAll\AI\Contracts\GatewayResolver;

/** Resolves A and AAAA records without retaining the hostname after the call. */
final class NativeGatewayResolver implements GatewayResolver
{
    /** Return normalized IPv4 and IPv6 addresses from the platform resolver. */
    public function resolve(string $host): array
    {
        if ($host === '' || !function_exists('dns_get_record')) {
            return [];
        }
        if (function_exists('dns_get_record')) {
            $previous = set_error_handler(static fn (): bool => true);
            try { $records = dns_get_record($host, DNS_A | DNS_AAAA); } catch (\Throwable) { return []; } finally { if ($previous !== null) { restore_error_handler(); } }
            $ips = [];
            foreach (is_array($records) ? $records : [] as $record) {
                if (is_string($record['ip'] ?? null)) { $ips[] = $record['ip']; }
                if (is_string($record['ipv6'] ?? null)) { $ips[] = $record['ipv6']; }
            }
            return array_values(array_unique($ips));
        }
        return [];
    }
}
