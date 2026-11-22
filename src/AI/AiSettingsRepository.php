<?php

declare(strict_types=1);

namespace MySeoForAll\AI;

use MySeoForAll\Core\Configuration\Configuration;
use MySeoForAll\Infrastructure\WordPress\OptionStore;
use RuntimeException;

/**
 * Owns the closed single-option AI settings contract, encrypted credential envelope, and fail-closed readiness state.
 */
final class AiSettingsRepository
{
    public const OPTION_KEY = 'myseo_ai_settings';
    private const SCHEMA_VERSION = 1;
    private const ALLOWED_KEYS = ['schema_version', 'revision', 'ai_enabled', 'gateway_mode', 'base_url', 'auth_scheme', 'allowed_models', 'default_model', 'timeout_seconds', 'max_retries', 'credential_envelope'];
    private GatewayEndpointValidator $gateway;

    public function __construct(
        private readonly OptionStore $options,
        private readonly ?string $encryptionKey = null,
        ?GatewayEndpointValidator $gateway = null,
        private readonly bool $preferOpenSsl = false,
    ) {
        $this->gateway = $gateway ?? new GatewayEndpointValidator();
    }

    /** @return array{status:string,settings:array<string,mixed>,corruption_reason:?string} */
    public function load(): array
    {
        $raw = $this->options->get(self::OPTION_KEY, null);
        if ($raw === null || $raw === false || $raw === []) {
            return ['status' => 'absent', 'settings' => self::defaults(), 'corruption_reason' => null];
        }
        if (!is_array($raw)) {
            return $this->corrupted('option_not_array');
        }
        try {
            return ['status' => 'ok', 'settings' => $this->normalize($raw, true), 'corruption_reason' => null];
        } catch (RuntimeException $exception) {
            return $this->corrupted($exception->getMessage());
        }
    }

    /**
     * Validate and persist one complete option. Empty API keys preserve the stored credential; clear_api_key is explicit.
     *
     * @param array<string,mixed> $input
     * @return array{status:string,settings?:array<string,mixed>,reason?:string}
     */
    public function save(array $input, ?int $expectedRevision = null, ?string $apiKey = null): array
    {
        $current = $this->load();
        if ($current['status'] === 'corrupted') {
            return ['status' => 'corrupted', 'reason' => (string) $current['corruption_reason']];
        }
        $currentSettings = $current['settings'];
        $currentRevision = (int) $currentSettings['revision'];
        if ($expectedRevision !== null && $expectedRevision !== $currentRevision) {
            return ['status' => 'revision_mismatch', 'reason' => 'revision_mismatch'];
        }
        $clear = ($input['clear_api_key'] ?? false) === true;
        unset($input['clear_api_key']);
        $environment = $this->environmentCredential();
        if ($environment !== null && (($apiKey !== null && $apiKey !== '') || $clear)) {
            return ['status' => 'read_only_environment', 'reason' => 'read_only_environment'];
        }
        if ($clear && $apiKey !== null && $apiKey !== '') {
            return ['status' => 'invalid', 'reason' => 'clear_and_api_key'];
        }
        try {
            $candidate = $this->normalize(array_merge($currentSettings, $input), false);
            $candidate['revision'] = $currentRevision + 1;
            if ($apiKey !== null && $apiKey !== '') {
                $candidate['credential_envelope'] = $this->encryptCredential($apiKey);
            } elseif ($clear) {
                $candidate['credential_envelope'] = null;
            } else {
                $candidate['credential_envelope'] = $currentSettings['credential_envelope'];
            }
            $candidate = $this->normalize($candidate, true);
            if ($candidate['ai_enabled'] === true && ($candidate['base_url'] === '' || $candidate['default_model'] === '' || !$this->hasUsableCredential($candidate['credential_envelope'], $environment))) {
                $candidate['ai_enabled'] = false;
            }
        } catch (RuntimeException $exception) {
            return ['status' => 'invalid', 'reason' => $exception->getMessage()];
        }
        if (!$this->options->update(self::OPTION_KEY, $candidate)) {
            return ['status' => 'storage_failed', 'reason' => 'storage_failed'];
        }
        $purge = $this->purgeLegacyCoreKeys();
        return ['status' => 'saved', 'settings' => $candidate, 'purge_status' => $purge['status']];
    }

    /** @return array{status:string} */
    public function credentialStatus(): array
    {
        $environment = $this->environmentCredential();
        if ($environment !== null) {
            return ['status' => 'environment'];
        }
        if (defined('MYSEO_AI_GATEWAY_API_KEY')) {
            return ['status' => 'invalid_environment'];
        }
        $rawEnvironment = function_exists('getenv') ? getenv('MYSEO_AI_GATEWAY_API_KEY') : false;
        if ($rawEnvironment !== false && !self::validApiKey($rawEnvironment)) {
            return ['status' => 'invalid_environment'];
        }
        $loaded = $this->load();
        if ($loaded['status'] === 'corrupted') {
            return ['status' => 'corrupted'];
        }
        $envelope = $loaded['settings']['credential_envelope'];
        if ($envelope === null) {
            return ['status' => 'absent'];
        }
        try {
            $this->decryptCredential($envelope);
            return ['status' => 'configured'];
        } catch (RuntimeException $exception) {
            return ['status' => $exception->getMessage() === 'encryption_unavailable' ? 'decryption_failed' : 'decryption_failed'];
        }
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'revision' => 0, 'ai_enabled' => false, 'gateway_mode' => 'custom_base_url', 'base_url' => '', 'auth_scheme' => 'x_api_key', 'allowed_models' => [], 'default_model' => '', 'timeout_seconds' => 20, 'max_retries' => 1, 'credential_envelope' => null];
    }

    /**
     * Remove unrelated keys from the core option as a separate, compare-before-write operation.
     *
     * @param array<string,mixed>|null $expected
     * @return array{status:string}
     */
    public function purgeLegacyCoreKeys(?array $expected = null): array
    {
        $raw = $this->options->get(Configuration::OPTION_KEY, null);
        if ($raw === null || $raw === false) {
            return ['status' => 'clean_noop'];
        }
        if ($expected !== null && $raw !== $expected) {
            return ['status' => 'purge_conflict'];
        }
        if (!is_array($raw)) {
            return ['status' => 'purge_failed'];
        }
        $clean = (new Configuration($raw))->all();
        if ($clean === $raw) {
            return ['status' => 'clean_noop'];
        }
        if ($this->options->hasCas()) {
            return $this->options->compareAndSwap(Configuration::OPTION_KEY, $raw, $clean)
                ? ['status' => 'purged'] : ['status' => 'purge_conflict'];
        }
        return $this->options->update(Configuration::OPTION_KEY, $clean) ? ['status' => 'purged'] : ['status' => 'purge_failed'];
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function normalize(array $value, bool $strict): array
    {
        if ($strict && array_diff(array_keys($value), self::ALLOWED_KEYS) !== []) {
            throw new RuntimeException('unknown_key');
        }
        $merged = array_merge(self::defaults(), $value);
        if ($merged['schema_version'] !== self::SCHEMA_VERSION || !is_int($merged['revision']) || $merged['revision'] < 0) {
            throw new RuntimeException('invalid_revision_or_schema');
        }
        if (!is_bool($merged['ai_enabled']) || $merged['gateway_mode'] !== 'custom_base_url' || !in_array($merged['auth_scheme'], ['x_api_key', 'bearer'], true)) {
            throw new RuntimeException('invalid_settings_type');
        }
        if (!is_int($merged['timeout_seconds']) || $merged['timeout_seconds'] < 5 || $merged['timeout_seconds'] > 60 || !is_int($merged['max_retries']) || $merged['max_retries'] < 0 || $merged['max_retries'] > 2) {
            throw new RuntimeException('invalid_limits');
        }
        if (!is_array($merged['allowed_models']) || count($merged['allowed_models']) > 20) {
            throw new RuntimeException('invalid_model_allowlist');
        }
        foreach ($merged['allowed_models'] as $model) {
            if (!is_string($model) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $model) !== 1) {
                throw new RuntimeException('invalid_model_id');
            }
        }
        if (!is_string($merged['default_model']) || ($merged['default_model'] !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $merged['default_model']) !== 1) || ($merged['default_model'] !== '' && $merged['allowed_models'] !== [] && !in_array($merged['default_model'], $merged['allowed_models'], true))) {
            throw new RuntimeException('invalid_default_model');
        }
        if (!is_string($merged['base_url'])) {
            throw new RuntimeException('invalid_base_url');
        }
        $url = $this->gateway->validateStaticUrl($merged['base_url']);
        if ($url['status'] !== 'ok') {
            throw new RuntimeException('invalid_base_url');
        }
        $merged['base_url'] = $url['url'];
        $this->validateEnvelope($merged['credential_envelope']);
        return $merged;
    }

    /** Validate the exact encrypted envelope shape before persistence or decryption. */
    private function validateEnvelope(mixed $envelope): void
    {
        if ($envelope === null) {
            return;
        }
        if (!is_array($envelope) || array_keys($envelope) !== ['schema_version', 'cipher', 'key_fingerprint', 'nonce', 'tag', 'ciphertext', 'updated_at'] || $envelope['schema_version'] !== 1 || !in_array($envelope['cipher'], ['sodium_secretbox', 'aes-256-gcm'], true)) {
            throw new RuntimeException('invalid_credential_envelope');
        }
        foreach (['key_fingerprint', 'nonce', 'tag', 'ciphertext', 'updated_at'] as $key) {
            if (!is_string($envelope[$key]) || $envelope[$key] === '') {
                throw new RuntimeException('invalid_credential_envelope');
            }
        }
    }

    /** Encrypt a validated API key without returning plaintext to callers. */
    private function encryptCredential(string $apiKey): array
    {
        if (!self::validApiKey($apiKey)) {
            throw new RuntimeException('invalid_api_key');
        }
        $key = $this->resolvedEncryptionKey();
        if ($key === null) {
            throw new RuntimeException('encryption_unavailable');
        }
        $plaintext = json_encode(['v' => 1, 'k' => $apiKey], JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $key);
        $cipher = $this->preferOpenSsl || !function_exists('sodium_crypto_secretbox') ? 'aes-256-gcm' : 'sodium_secretbox';
        $aad = self::OPTION_KEY . '|v1|' . $cipher . '|' . $fingerprint;
        if ($cipher === 'aes-256-gcm') {
            if (!function_exists('openssl_encrypt')) {
                throw new RuntimeException('encryption_unavailable');
            }
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
            if (!is_string($ciphertext) || strlen($tag) !== 16) {
                throw new RuntimeException('encryption_unavailable');
            }
            return ['schema_version' => 1, 'cipher' => $cipher, 'key_fingerprint' => $fingerprint, 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext), 'updated_at' => gmdate('c')];
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $derived = hash_hkdf('sha256', $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $aad, '');
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $derived);
        $tag = substr(hash_hmac('sha256', $ciphertext, $key . $aad, true), 0, 16);
        return ['schema_version' => 1, 'cipher' => $cipher, 'key_fingerprint' => $fingerprint, 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag), 'ciphertext' => base64_encode($ciphertext), 'updated_at' => gmdate('c')];
    }

    /** Decrypt only to establish configured status; plaintext never crosses this method boundary. */
    private function decryptCredential(array $envelope): string
    {
        $this->validateEnvelope($envelope);
        $key = $this->resolvedEncryptionKey();
        if ($key === null || !hash_equals($envelope['key_fingerprint'], hash('sha256', $key))) {
            throw new RuntimeException('decryption_failed');
        }
        $cipher = $envelope['cipher'];
        $aad = self::OPTION_KEY . '|v1|' . $cipher . '|' . $envelope['key_fingerprint'];
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        $nonce = base64_decode($envelope['nonce'], true);
        $tag = base64_decode($envelope['tag'], true);
        if (!is_string($ciphertext) || !is_string($nonce) || !is_string($tag)) {
            throw new RuntimeException('decryption_failed');
        }
        if ($cipher === 'aes-256-gcm') {
            if (strlen($nonce) !== 12 || strlen($tag) !== 16 || !function_exists('openssl_decrypt')) {
                throw new RuntimeException('decryption_failed');
            }
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        } else {
            if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || strlen($tag) !== 16 || !function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('decryption_failed');
            }
            $expectedTag = substr(hash_hmac('sha256', $ciphertext, $key . $aad, true), 0, 16);
            if (!hash_equals($expectedTag, $tag)) {
                throw new RuntimeException('decryption_failed');
            }
            $derived = hash_hkdf('sha256', $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $aad, '');
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $derived);
        }
        $decoded = is_string($plaintext) ? json_decode($plaintext, true) : null;
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1 || !is_string($decoded['k'] ?? null) || !self::validApiKey($decoded['k'])) {
            throw new RuntimeException('decryption_failed');
        }
        return $decoded['k'];
    }

    /** Resolve explicit, environment, or WordPress salt material through HKDF. */
    private function resolvedEncryptionKey(): ?string
    {
        if ($this->encryptionKey !== null) {
            return strlen($this->encryptionKey) === 32 ? $this->encryptionKey : null;
        }
        $material = defined('MYSEO_AI_SECRET_KEY') && is_string(MYSEO_AI_SECRET_KEY) ? MYSEO_AI_SECRET_KEY : null;
        if ($material === null || $material === '') {
            $env = function_exists('getenv') ? getenv('MYSEO_AI_SECRET_KEY') : false;
            $material = is_string($env) && $env !== '' ? $env : null;
        }
        if ($material === null) {
            $parts = [];
            foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $constant) {
                if (!defined($constant) || !is_string(constant($constant)) || constant($constant) === '') {
                    return null;
                }
                $parts[] = constant($constant);
            }
            $material = implode('|', $parts);
        }
        return hash_hkdf('sha256', $material, 32, 'myseo-ai-settings-v1', '') ?: null;
    }

    /** Resolve environment precedence without exposing the key. */
    private function environmentCredential(): ?string
    {
        $constant = defined('MYSEO_AI_GATEWAY_API_KEY') ? constant('MYSEO_AI_GATEWAY_API_KEY') : null;
        if ($constant !== null) {
            return self::validApiKey($constant) ? (string) $constant : null;
        }
        $environment = function_exists('getenv') ? getenv('MYSEO_AI_GATEWAY_API_KEY') : false;
        return is_string($environment) && self::validApiKey($environment) ? $environment : null;
    }

    /** Determine whether an enabled configuration has a usable credential. */
    private function hasUsableCredential(mixed $envelope, ?string $environment): bool
    {
        if ($environment !== null) {
            return true;
        }
        if (!is_array($envelope)) {
            return false;
        }
        try {
            $this->decryptCredential($envelope);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Check the printable ASCII API key and byte limits. */
    private static function validApiKey(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 16 && strlen($value) <= 512 && preg_match('/^[\x21-\x7E]+$/', $value) === 1;
    }

    /** Build a stable fail-closed read result. */
    private function corrupted(string $reason): array
    {
        return ['status' => 'corrupted', 'settings' => self::defaults(), 'corruption_reason' => $reason];
    }
}
