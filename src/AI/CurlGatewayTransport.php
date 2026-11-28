<?php

declare(strict_types=1);

namespace MySeoForAll\AI;

use Closure;
use MySeoForAll\AI\Contracts\GatewayTransport;

final class CurlGatewayTransport implements GatewayTransport
{
    /**
     * @param null|Closure(string,array<string,mixed>):array{status:int,body:string,sent:bool} $curlExecutor
     */
    public function __construct(private readonly ?Closure $curlExecutor = null)
    {
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     * @param list<string> $ips
     * @return array{status:int,body:string,sent:bool}
     */
    public function send(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] array $body, int $timeout, array $ips): array
    {
        if (!function_exists('curl_init') && $this->curlExecutor === null) {
            throw new \RuntimeException('transport_unavailable');
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 443;
        if ($scheme !== 'https' || $host === '') {
            throw new \RuntimeException('gateway_policy_rejected');
        }
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('gateway_policy_rejected');
        }
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false && $ips === []) {
            throw new \RuntimeException('gateway_policy_rejected');
        }

        $pin = null;
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $ip = (string) ($ips[0] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException('gateway_policy_rejected');
            }
            $pin = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }

        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $received = '';
        $overflow = false;
        $write = static function (mixed $ch, string $chunk) use (&$received, &$overflow): int {
            if (strlen($received) + strlen($chunk) > 65536) {
                $overflow = true;
                return 0;
            }
            $received .= $chunk;
            return strlen($chunk);
        };

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $k, string $v): string => $k . ': ' . $v,
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_CONNECTTIMEOUT => max(1, min(10, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_WRITEFUNCTION => $write,
        ];
        if (defined('CURLOPT_NOPROXY')) {
            $options[CURLOPT_NOPROXY] = '*';
        }
        if ($pin !== null) {
            $options[CURLOPT_RESOLVE] = [$pin];
        }

        if ($this->curlExecutor !== null) {
            try {
                $result = ($this->curlExecutor)($url, $options);
            } finally {
                unset($payload, $headers, $body);
            }
            if ($overflow) {
                throw new \RuntimeException('response_too_large');
            }
            return [
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'sent' => (bool) ($result['sent'] ?? true),
            ];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('connect_failed');
        }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $requestSize = (int) curl_getinfo($handle, CURLINFO_REQUEST_SIZE);
        $errorCode = (int) curl_errno($handle);
        curl_close($handle);
        unset($payload, $headers, $body);

        if ($overflow) {
            throw new \RuntimeException('response_too_large');
        }
        if ($ok === false || $errorCode !== 0) {
            if (in_array($errorCode, [35, 51, 53, 58, 59, 60, 64], true)) {
                throw new \RuntimeException('tls_failed');
            }
            if ($errorCode === 7) {
                throw new \RuntimeException('connect_failed');
            }
            if ($errorCode === 28) {
                throw new \RuntimeException($requestSize > 0 ? 'timeout_after_send' : 'connect_timeout_unsent');
            }
            throw new \RuntimeException('transport_failed');
        }

        return ['status' => $status, 'body' => $received, 'sent' => true];
    }
}
