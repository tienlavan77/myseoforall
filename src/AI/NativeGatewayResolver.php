<?php
declare(strict_types=1);

namespace MySeoForAll\AI;

/**
 * Resolve public gateway addresses with a bounded UDP DNS wire client.
 * The resolver owns one monotonic deadline across A and AAAA queries and
 * exposes narrow constructor seams so protocol parsing remains offline-testable.
 */
final class NativeGatewayResolver implements \MySeoForAll\AI\Contracts\GatewayResolver
{
    private int $transactionId = 0;

    /** Compose optional deterministic nameserver and packet-query seams. */
    public function __construct(
        private readonly ?\Closure $nameserverProvider = null,
        private readonly ?\Closure $queryExecutor = null,
    ) {}

    /** Resolve literal or DNS hostnames without falling back to blocking platform DNS. */
    public function resolve(string $host, int $timeoutSeconds = 5): array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || $timeoutSeconds <= 0 || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [];
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label) !== 1) {
                return [];
            }
        }
        $nameservers = $this->nameservers();
        if ($nameservers === [] || !function_exists('hrtime')) {
            return [];
        }
        $deadline = hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $ips = [];
        foreach ([1, 28] as $type) {
            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                break;
            }
            try {
                $response = $this->query($nameservers[0], $host, $type, min(2.0, $remaining));
                if (is_string($response)) {
                    $ips = array_merge($ips, $this->parseResponse($response, $type));
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $valid = [];
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $ip;
            }
        }
        return array_values(array_unique($valid));
    }

    /** Return at most two valid nameservers from the configured provider or resolv.conf. */
    private function nameservers(): array
    {
        $provided = $this->nameserverProvider !== null ? ($this->nameserverProvider)() : null;
        if (!is_array($provided)) {
            $provided = [];
            $contents = @file_get_contents('/etc/resolv.conf');
            if (is_string($contents)) {
                foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                    if (preg_match('/^\s*nameserver\s+([^\s#]+)/i', $line, $match) === 1) {
                        $provided[] = $match[1];
                    }
                }
            }
        }
        $result = [];
        foreach ($provided as $server) {
            if (is_string($server) && filter_var($server, FILTER_VALIDATE_IP) !== false) {
                $result[] = $server;
            }
            if (count($result) === 2) {
                break;
            }
        }
        return array_values(array_unique($result));
    }

    /** Execute one bounded UDP packet query through the production or test seam. */
    private function query(string $nameserver, string $host, int $type, float $timeout): ?string
    {
        $packet = $this->packet($host, $type);
        if ($this->queryExecutor !== null) {
            $result = ($this->queryExecutor)($nameserver, $packet, $timeout);
            return is_string($result) ? $result : null;
        }
        if (!function_exists('stream_socket_client')) {
            return null;
        }
        $target = str_contains($nameserver, ':') ? 'udp://[' . $nameserver . ']:53' : 'udp://' . $nameserver . ':53';
        $errno = 0; $error = '';
        $socket = @stream_socket_client($target, $errno, $error, max(0.1, $timeout));
        if (!is_resource($socket)) {
            return null;
        }
        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($socket, $seconds, $microseconds);
        fwrite($socket, $packet);
        $response = fread($socket, 512);
        fclose($socket);
        $meta = error_get_last();
        return is_array($meta) && ($meta['message'] ?? '') !== '' && $response === false ? null : ($response === false ? null : $response);
    }

    /** Build a DNS A or AAAA question with recursion desired. */
    private function packet(string $host, int $type): string
    {
        $id = random_int(0, 65535);
        $this->transactionId = $id;
        $qname = '';
        foreach (explode('.', $host) as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $qname . chr(0) . pack('nn', $type, 1);
    }

    /** Parse matching DNS answers while bounding compression traversal. */
    private function parseResponse(string $response, int $wantedType): array
    {
        if (strlen($response) < 12) {
            return [];
        }
        $header = unpack('nid/nflags/nqdcount/nancount', substr($response, 0, 8));
        if (!is_array($header) || (int) ($header['id'] ?? -1) !== $this->transactionId || (($header['flags'] & 0x8000) === 0) || (($header['flags'] & 0x000f) !== 0)) {
            return [];
        }
        $offset = 12;
        for ($i = 0; $i < (int) $header['qdcount']; $i++) {
            $offset = $this->skipName($response, $offset);
            if ($offset < 0 || $offset + 4 > strlen($response)) {
                return [];
            }
            $offset += 4;
        }
        $ips = [];
        for ($i = 0; $i < (int) $header['ancount']; $i++) {
            $offset = $this->skipName($response, $offset);
            if ($offset < 0 || $offset + 10 > strlen($response)) {
                return $ips;
            }
            $record = unpack('ntype/nclass/Nttl/nlength', substr($response, $offset, 10));
            $offset += 10;
            $length = (int) ($record['length'] ?? 0);
            if ($offset + $length > strlen($response)) {
                return $ips;
            }
            if (($record['type'] ?? 0) === $wantedType && ($record['class'] ?? 0) === 1) {
                $raw = substr($response, $offset, $length);
                $ip = $wantedType === 1 && $length === 4 ? inet_ntop($raw) : ($wantedType === 28 && $length === 16 ? inet_ntop($raw) : false);
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
            $offset += $length;
        }
        return $ips;
    }

    /** Skip a DNS name and reject malformed or looping compression pointers. */
    private function skipName(string $response, int $offset): int
    {
        $length = strlen($response); $jumps = 0;
        while ($offset < $length) {
            $byte = ord($response[$offset]);
            if (($byte & 0xc0) === 0xc0) {
                return $offset + 2 <= $length ? $offset + 2 : -1;
            }
            if ($byte === 0) {
                return $offset + 1;
            }
            if ($byte > 63 || ++$jumps > 16 || $offset + 1 + $byte > $length) {
                return -1;
            }
            $offset += 1 + $byte;
        }
        return -1;
    }
}
