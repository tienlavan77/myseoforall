<?php
/**
 * Business purpose: bind a Preview proposal to one authorized Apply request.
 * Technical description: issue and verify an expiring HMAC token without storing AI payloads or secrets.
 */
declare(strict_types=1);
namespace MySeoForAll\SEO\Runtime;

final class ApplyToken
{
    /** Business purpose: configure token signing. Technical description: require a deployment-provided secret and bounded lifetime. */
    public function __construct(private string $secret, private int $ttl = 900, private $clock = null)
    {
        if ($secret === '') throw new \InvalidArgumentException('token_secret_required');
        if ($clock !== null && !is_callable($clock)) throw new \InvalidArgumentException('token_clock_invalid');
    }

    /** Business purpose: issue a server-bound Apply token. Technical description: encode claims and append an HMAC signature. */
    public function issue(array $claims): string
    {
        $now = is_callable($this->clock) ? (int) ($this->clock)() : time();
        $claims['exp'] = $now + max(60, $this->ttl);
        $json = json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $payload . '.' . $this->signature($payload);
    }

    /** Business purpose: validate an Apply token. Technical description: verify signature, expiry, and exact expected claims. */
    public function verify($token, array $expected): bool
    {
        if (!is_string($token) || substr_count($token, '.') !== 1) return false;
        [$payload, $signature] = explode('.', $token, 2);
        if ($payload === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $payload) || !preg_match('/^[A-Za-z0-9_-]+$/', $signature)) return false;
        if (!hash_equals($this->signature($payload), $signature)) return false;
        $padding = (4 - (strlen($payload) % 4)) % 4;
        $decoded = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', $padding), true);
        $claims = is_string($decoded) ? json_decode($decoded, true) : null;
        $now = is_callable($this->clock) ? (int) ($this->clock)() : time();
        if (!is_array($claims) || !is_int($claims['exp'] ?? null) || $claims['exp'] < $now) return false;
        foreach ($expected as $key => $value) if (($claims[$key] ?? null) !== $value) return false;
        return true;
    }

    /** Business purpose: authenticate token claims. Technical description: calculate a binary-safe HMAC digest encoded for transport. */
    private function signature(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }
}
