<?php
/**
 * Summary: This controller is the Increment F admin write gate, enforcing capability and nonce checks before validating request fields and delegating one atomic AI settings save without rendering, redirecting, or exposing credentials.
 */
declare(strict_types=1);

namespace MySeoForAll\AI;

use MySeoForAll\Core\HookDispatcher;

final class AiSettingsController
{
    private readonly AiSettingsRepository $repository;
    private $capabilityChecker;
    private $nonceVerifier;

    /**
     * Construct the admin gate with injectable policy callbacks for deterministic offline tests.
     *
     * The callbacks return only booleans and never receive or retain the plaintext credential.
     */
    public function __construct(
        AiSettingsRepository $repository,
        mixed $capabilityChecker = null,
        mixed $nonceVerifier = null,
    ) {
        $this->repository = $repository;
        $this->capabilityChecker = $capabilityChecker;
        $this->nonceVerifier = $nonceVerifier;
    }

    /**
     * Register the named WordPress admin action when the dispatcher has a live hook API.
     */
    public function register(HookDispatcher $dispatcher): void
    {
        $dispatcher->addAction('admin_post_myseo_save_ai_settings', [$this, 'handleWordPressPost']);
    }

    /** Return a redacted settings snapshot for rendering the administrative form. */
    public function viewState(): array
    {
        $loaded = $this->repository->load();
        $settings = is_array($loaded['settings'] ?? null) ? $loaded['settings'] : [];
        $settings['credential_status'] = $this->repository->credentialStatus()['status'] ?? 'unknown';
        $settings['credential_envelope'] = null;
        return $settings;
    }

    /**
     * Validate gates and request types before delegating exactly one repository save call.
     *
     * @param mixed $request
     * @return array{status:string,reason?:string,settings?:array<string,mixed>}
     */
    public function handlePost(mixed $request): array
    {
        if (!$this->checkCapability()) {
            return ['status' => 'rejected_capability', 'reason' => 'capability'];
        }
        if (!$this->checkNonce($request)) {
            return ['status' => 'rejected_nonce', 'reason' => 'nonce'];
        }
        if (!is_array($request)) {
            return ['status' => 'invalid', 'reason' => 'request_type'];
        }
        if (!array_key_exists('expected_revision', $request)) {
            return ['status' => 'invalid', 'reason' => 'expected_revision'];
        }
        $expectedRevision = $this->parseInt($request['expected_revision']);
        if ($expectedRevision === null || $expectedRevision < 0) {
            return ['status' => 'invalid', 'reason' => 'expected_revision'];
        }
        $apiKey = $request['api_key'] ?? null;
        if ($apiKey !== null && !is_string($apiKey)) {
            return ['status' => 'invalid', 'reason' => 'invalid_key_type'];
        }
        if ($apiKey === '' || $apiKey === AiSettingsRepository::API_KEY_MASK) {
            $apiKey = null;
        }
        $settings = [];
        foreach (['gateway_mode', 'base_url', 'auth_scheme', 'default_model'] as $key) {
            if (array_key_exists($key, $request)) {
                $settings[$key] = $request[$key];
            }
        }
        if (array_key_exists('ai_enabled_present', $request) || array_key_exists('ai_enabled', $request)) {
            $settings['ai_enabled'] = array_key_exists('ai_enabled', $request)
                ? $this->parseBool($request['ai_enabled'])
                : false;
            if ($settings['ai_enabled'] === null) {
                return ['status' => 'invalid', 'reason' => 'ai_enabled'];
            }
        }
        foreach (['timeout_seconds', 'max_retries'] as $key) {
            if (array_key_exists($key, $request)) {
                $parsed = $this->parseInt($request[$key]);
                if ($parsed === null) {
                    return ['status' => 'invalid', 'reason' => $key];
                }
                $settings[$key] = $parsed;
            }
        }
        if (array_key_exists('allowed_models', $request)) {
            $models = $request['allowed_models'];
            if (is_string($models)) {
                $models = preg_split('/[,\r\n]+/', $models) ?: [];
            }
            if (!is_array($models)) {
                return ['status' => 'invalid', 'reason' => 'allowed_models'];
            }
            $settings['allowed_models'] = array_values(array_filter(array_map(
                static fn (mixed $model): string => is_string($model) ? trim($model) : '',
                $models
            ), static fn (string $model): bool => $model !== ''));
        }
        if (array_key_exists('clear_api_key', $request)) {
            $clear = $this->parseBool($request['clear_api_key']);
            if ($clear !== true) {
                return ['status' => 'invalid', 'reason' => 'clear_api_key'];
            }
            $settings['clear_api_key'] = true;
        }
        return $this->repository->save($settings, $expectedRevision, $apiKey);
    }

    /**
     * Adapt the WordPress POST superglobal to the same closed gate without emitting a response body.
     */
    public function handleWordPressPost(): void
    {
        $request = $_POST;
        if (function_exists('wp_unslash')) {
            $request = wp_unslash($request);
        }
        $result = $this->handlePost($request);
        if (function_exists('wp_safe_redirect') && function_exists('admin_url')) {
            $status = is_string($result['status'] ?? null) ? $result['status'] : 'invalid';
            $reason = is_string($result['reason'] ?? null) ? $result['reason'] : '';
            $query = 'ai_status=' . rawurlencode($status);
            if ($reason !== '') {
                $query .= '&ai_reason=' . rawurlencode($reason);
            }
            wp_safe_redirect((string) admin_url('options-general.php?page=myseo-for-all&' . $query));
            exit;
        }
    }

    /** Parse a strict integer from typed or HTML form input. */
    private function parseInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    /** Parse the closed boolean forms accepted by HTML checkboxes. */
    private function parseBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return null;
    }

    /**
     * Evaluate the injected capability policy or the normal WordPress capability API.
     */
    private function checkCapability(): bool
    {
        if (is_callable($this->capabilityChecker)) {
            return ($this->capabilityChecker)('manage_options') === true;
        }
        return function_exists('current_user_can') && current_user_can('manage_options');
    }

    /**
     * Evaluate the injected nonce policy or the normal WordPress nonce API.
     *
     * @param mixed $request
     */
    private function checkNonce(mixed $request): bool
    {
        $nonce = is_array($request) ? ($request['_wpnonce'] ?? $request['_nonce'] ?? null) : null;
        if (is_callable($this->nonceVerifier)) {
            return ($this->nonceVerifier)($nonce, 'myseo_save_ai_settings') === true;
        }
        return is_string($nonce)
            && function_exists('check_admin_referer')
            && check_admin_referer('myseo_save_ai_settings', '_wpnonce') !== false;
    }
}
