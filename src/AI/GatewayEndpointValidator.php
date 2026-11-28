<?php

declare(strict_types=1);

namespace MySeoForAll\AI;

final class GatewayEndpointValidator
{
    /** @var list<string> */
    private array $allowedInternalHosts;

    /** @param list<string> $allowedInternalHosts */
    public function __construct(array $allowedInternalHosts = [])
    {
        if ($allowedInternalHosts === [] && defined('MYSEO_AI_ALLOWED_GATEWAY_HOSTS') && is_array(MYSEO_AI_ALLOWED_GATEWAY_HOSTS)) {
            $allowedInternalHosts = MYSEO_AI_ALLOWED_GATEWAY_HOSTS;
        }
        $this->allowedInternalHosts = array_values(array_filter(
            array_map(static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '', $allowedInternalHosts),
            static fn (string $host): bool => $host !== '' && $host !== '*' && !str_contains($host, '*') && filter_var($host, FILTER_VALIDATE_IP) === false && $host !== 'anthropic.com' && !str_ends_with($host, '.anthropic.com')
        ));
    }

    /** @return array{status:string,url?:string,reason?:string} */
    public function validateStaticUrl(string $url): array
    {
        if ($url === '') {
            return ['status' => 'ok', 'url' => ''];
        }
        if (strlen($url) > 512 || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || strpbrk($url, '?#') !== false) {
            return ['status' => 'invalid', 'reason' => 'url_policy'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return ['status' => 'invalid', 'reason' => 'https_or_credentials'];
        }
        if (array_key_exists('port', $parts) && $parts['port'] !== 443) {
            return ['status' => 'invalid', 'reason' => 'invalid_port'];
        }
        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return ['status' => 'invalid', 'reason' => 'missing_host'];
        }
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (str_ends_with($host, '.') || str_contains($host, '%')) {
            return ['status' => 'invalid', 'reason' => 'host_policy'];
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1 || str_contains($host, 'xn--')) {
            if (!function_exists('idn_to_ascii')) {
                return ['status' => 'invalid', 'reason' => 'idn_unavailable'];
            }
            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($converted) || $converted === '') {
                return ['status' => 'invalid', 'reason' => 'idn_invalid'];
            }
            $host = strtolower($converted);
            if (str_ends_with($host, '.') || preg_match('/[^\x00-\x7F]/', $host) === 1) {
                return ['status' => 'invalid', 'reason' => 'host_policy'];
            }
        }
        if ($this->isBlockedProvider($host)) {
            return ['status' => 'invalid', 'reason' => 'provider_host_blocked'];
        }
        $canonicalHost = $host;
        if ($this->isIpLiteral($host)) {
            $packed = inet_pton($host);
            if ($packed === false || !$this->isPublicIp($host)) {
                return ['status' => 'invalid', 'reason' => 'non_public_ip'];
            }
            $canonicalHost = strtolower((string) inet_ntop($packed));
        } else {
            if ($this->isObfuscatedIpHost($host)) {
                return ['status' => 'invalid', 'reason' => 'obfuscated_ip_host'];
            }
            if (!$this->validDnsHost($host)) {
                return ['status' => 'invalid', 'reason' => 'host_policy'];
            }
            if ($this->isInternalHost($host) && !in_array($host, $this->allowedInternalHosts, true)) {
                return ['status' => 'invalid', 'reason' => 'internal_host'];
            }
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if (str_contains($path, '\\') || preg_match('/[\x00-\x20\x7F]/', $path) === 1 || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1 || (str_contains($path, '//') && $path !== '/')) {
            return ['status' => 'invalid', 'reason' => 'encoded_path_traversal'];
        }
        $trimmed = trim($path, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return ['status' => 'invalid', 'reason' => 'encoded_path_traversal'];
            }
            for ($pass = 0; $pass < 3; $pass++) {
                $decoded = rawurldecode($segment);
                if ($decoded === $segment) {
                    break;
                }
                $segment = $decoded;
                if ($segment === '.' || $segment === '..' || strpbrk($segment, '/\\?#') !== false || preg_match('/[\x00-\x20\x7F]/', $segment) === 1) {
                    return ['status' => 'invalid', 'reason' => 'encoded_path_traversal'];
                }
            }
            if (str_contains($segment, '%')) {
                return ['status' => 'invalid', 'reason' => 'residual_percent'];
            }
            $normalized[] = rawurlencode($segment);
        }
        $canonicalPath = $normalized === [] ? '' : '/' . implode('/', $normalized);
        $displayHost = str_contains($canonicalHost, ':') ? '[' . $canonicalHost . ']' : $canonicalHost;
        return ['status' => 'ok', 'url' => 'https://' . $displayHost . $canonicalPath];
    }

    /** @return array{status:string,ips?:list<string>,reason?:string} */
    public function validateResolvedIps(string $url, callable $resolver): array
    {
        $static = $this->validateStaticUrl($url);
        if ($static['status'] !== 'ok' || ($static['url'] ?? '') === '') {
            return ['status' => 'invalid', 'reason' => $static['reason'] ?? 'empty_url'];
        }
        $parts = parse_url($static['url']);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? trim($parts['host'], '[]') : '';
        if ($this->isIpLiteral($host)) {
            return ['status' => 'ok', 'ips' => [$host]];
        }
        try {
            $result = $resolver($host);
        } catch (\Throwable) {
            return ['status' => 'invalid', 'reason' => 'resolver_failed'];
        }
        if (!is_array($result) || $result === [] || count($result) > 32) {
            return ['status' => 'invalid', 'reason' => 'resolver_result'];
        }
        $ips = [];
        foreach ($result as $ip) {
            if (!is_string($ip) || !$this->isPublicIp($ip)) {
                return ['status' => 'invalid', 'reason' => 'non_public_ip'];
            }
            $packed = inet_pton($ip);
            $ips[] = $packed === false ? strtolower($ip) : strtolower((string) inet_ntop($packed));
        }
        $ips = array_values(array_unique($ips));
        sort($ips, SORT_STRING);
        return ['status' => 'ok', 'ips' => $ips];
    }

    /** Identify provider-owned hosts, including every anthropic.com subdomain. */
    private function isBlockedProvider(string $host): bool
    {
        return $host === 'anthropic.com' || str_ends_with($host, '.anthropic.com');
    }

    /** Identify local, single-label, and reserved internal host names. */
    private function isInternalHost(string $host): bool
    {
        return $host === 'localhost' || !str_contains($host, '.') || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal');
    }

    /** Identify an IPv4 or IPv6 literal before hostname policy is applied. */
    private function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** Reject numeric and hexadecimal host spellings that can disguise an IP literal. */
    private function isObfuscatedIpHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $host);
            return count($parts) !== 4 || (bool) preg_match('/^0[0-9]+$/', $parts[0] ?? '');
        }
        $labels = explode('.', $host);
        return (count($labels) === 1 && (preg_match('/^\d+$/', $host) === 1 || preg_match('/^0x[0-9a-f]+$/i', $host) === 1))
            || (count($labels) > 1 && count(array_filter($labels, static fn (string $label): bool => preg_match('/^(?:\d+|0x[0-9a-f]+)$/i', $label) === 1)) === count($labels));
    }

    /** Validate DNS labels and require a non-numeric top-level domain. */
    private function validDnsHost(string $host): bool
    {
        $labels = explode('.', $host);
        $allowedSingleLabel = in_array($host, $this->allowedInternalHosts, true);
        if ((!$allowedSingleLabel && count($labels) < 2) || preg_match('/^\d+$/', (string) end($labels)) === 1) {
            return false;
        }
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** Reject private, reserved, documentation, mapped, and carrier-grade addresses. */
    private function isPublicIp(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            $octets = array_map('intval', explode('.', $ip));
            $first = $octets[0] ?? -1;
            $second = $octets[1] ?? -1;
            $third = $octets[2] ?? -1;
            return !(($first === 100 && $second >= 64 && $second <= 127)
                || ($first === 198 && $second >= 18 && $second <= 19)
                || ($first === 192 && $second === 0 && ($third === 0 || $third === 2))
                || ($first === 198 && $second === 51 && $third === 100)
                || ($first === 203 && $second === 0 && $third === 113));
        }
        $hex = bin2hex($packed);
        return !str_starts_with($hex, 'fc') && !str_starts_with($hex, 'fd') && !str_starts_with($hex, 'fe8') && !str_starts_with($hex, 'fe9') && !str_starts_with($hex, 'fea') && !str_starts_with($hex, 'feb') && !str_starts_with($hex, '00000000000000000000ffff') && !str_starts_with($hex, '0064ff9b') && !str_starts_with($hex, '20010db8');
    }
}
