<?php
/**
 * Business purpose: provide the authenticated Increment 3B Apply endpoint.
 * Technical description: enforce request gates, verify a server token and field-scoped fingerprint, then persist through approved adapters with read-back confirmation.
 */
declare(strict_types=1);
namespace MySeoForAll\SEO\Runtime;

final class ApplyController
{
    /** Business purpose: compose Apply dependencies. Technical description: retain platform seams for security, snapshots, ownership, and persistence. */
    public function __construct(
        private ApplyToken $tokens,
        private ApplyFingerprint $fingerprints,
        private $snapshotResolver,
        private $capabilityChecker = null,
        private $nonceVerifier = null,
        private $ownershipResolver = null,
        private $metaRepository = null,
        private $descriptionWriter = null
    ) {}

    /** Business purpose: process one controlled Apply request. Technical description: return a closed body and internal HTTP status. */
    public function handleApplyRequest($request): array
    {
        if (!is_array($request) || (($request['_method'] ?? null) !== 'POST')) return $this->error('malformed_request', 400, 'Yêu cầu Apply không hợp lệ.');
        $postId = $this->positiveId($request['post_id'] ?? null);
        if ($postId === null) return $this->error('invalid_post_id', 400, 'Mã đối tượng không hợp lệ.');
        if (!$this->canEdit($postId)) return $this->error('forbidden', 403, 'Bạn không có quyền chỉnh sửa đối tượng này.');
        if (!$this->validNonce($request['_ajax_nonce'] ?? ($request['_wpnonce'] ?? null))) return $this->error('invalid_nonce', 403, 'Phiên bảo mật không hợp lệ.');

        $resource = $request['resource_type'] ?? null;
        $field = $request['field'] ?? null;
        $task = $request['task'] ?? null;
        if (!is_string($resource) || !is_string($field) || !is_string($task)) return $this->error('malformed_request', 400, 'Envelope Apply không hợp lệ.');
        if (!in_array($resource, ['article', 'page', 'product'], true)) return $this->error('unsupported_resource', 400, 'Loại đối tượng không được hỗ trợ.');
        $protected = ['price', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'sku', 'reviews', 'orders'];
        if (str_starts_with($field, 'rank_math_')) return $this->error('ownership_conflict', 409, 'Trường thuộc hệ thống khác không được ghi.');
        if (in_array($field, $protected, true) || (is_array($request['commercial'] ?? null) && $request['commercial'] !== [])) {
            return $this->error('protected_field', 422, 'Trường thương mại được bảo vệ.');
        }
        if (!in_array($field, ['title', 'meta_description', 'canonical', 'description'], true)) return $this->error('unsupported_field', 400, 'Trường Apply không được hỗ trợ.');
        if ($resource === 'product' && ($field !== 'description' || $task !== 'product_description_draft')) return $this->error('unsupported_field', 400, 'Sản phẩm chỉ cho phép Apply mô tả.');
        if ($resource !== 'product' && ($field === 'description' || !in_array($task, ['title_meta_suggestion'], true))) return $this->error('unsupported_field', 400, 'Tác vụ không phù hợp với trường.');

        $proposal = $request['proposal'] ?? null;
        $value = is_array($proposal) ? ($proposal['value'] ?? ($proposal['content'] ?? null)) : null;
        if (!is_string($value)) return $this->error('invalid_proposal', 422, 'Đề xuất Apply không hợp lệ.');
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $value));
        $limit = $field === 'description' ? 2000 : ($field === 'canonical' ? 2048 : 300);
        $singleLine = $field !== 'description';
        $dangerous = $singleLine ? '/<[^>]*>|javascript:|[\x00-\x1F\x7F]/i' : '/<[^>]*>|javascript:|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/i';
        if ($normalized === '' || preg_match('//u', $normalized) !== 1 || preg_match($dangerous, $normalized) === 1 || ($singleLine && preg_match('/[\n\r\t]/', $normalized) === 1) || preg_match('/^.{1,' . $limit . '}$/us', $normalized) !== 1) {
            return $this->error('invalid_proposal', 422, 'Đề xuất Apply không hợp lệ.');
        }
        if ($field === 'canonical' && (filter_var($normalized, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $normalized))) {
            return $this->error('invalid_proposal', 422, 'Đề xuất Apply không hợp lệ.');
        }
        $fingerprint = $request['source_fingerprint'] ?? null;
        if (!is_string($fingerprint) || !preg_match('/^sha256:[a-f0-9]{64}$/', $fingerprint)) return $this->error('invalid_proposal', 422, 'Đề xuất Apply không hợp lệ.');
        $claims = ['post_id' => $postId, 'resource_type' => $resource, 'task' => $task, 'field' => $field, 'value' => $normalized, 'fingerprint' => $fingerprint];
        if (!$this->tokens->verify($request['apply_token'] ?? null, $claims)) return $this->error('invalid_proposal', 422, 'Đề xuất Apply không hợp lệ.');

        $snapshot = is_callable($this->snapshotResolver) ? ($this->snapshotResolver)($postId, $resource, $field, $task) : null;
        if (!is_array($snapshot) || ($snapshot['status'] ?? null) === 'not_found') return $this->error('not_found', 404, 'Không tìm thấy đối tượng.');
        if (($snapshot['status'] ?? null) !== 'ok') return $this->error('unsupported_resource', 400, 'Không thể đọc đối tượng.');
        $snapshotData = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : $snapshot;
        $currentFingerprint = is_array($snapshot['snapshot'] ?? null)
            ? $this->fingerprints->fingerprint($snapshotData)
            : ($snapshot['fingerprint'] ?? null);
        if ($fingerprint !== $currentFingerprint) return $this->error('stale_preview', 409, 'Dữ liệu đã thay đổi; hãy tạo bản xem trước mới rồi thử lại.');

        $ownership = is_callable($this->ownershipResolver)
            ? ($this->ownershipResolver)($postId, $resource, $field, $fingerprint, $snapshotData)
            : [];
        if (is_array($ownership) && ($ownership['owned'] ?? false) === true) {
            $confirmation = $request['confirmation'] ?? null;
            $token = $request['apply_token'] ?? null;
            $confirmationClaims = [
                'purpose' => 'ownership_confirmation',
                'post_id' => $postId,
                'resource_type' => $resource,
                'task' => $task,
                'field' => $field,
                'fingerprint' => $fingerprint,
                'apply_token_hash' => is_string($token) ? hash('sha256', $token) : '',
            ];
            if (!$this->tokens->verify($confirmation, $confirmationClaims)) {
                return $this->error('ownership_conflict', 409, 'Trường đang thuộc quyền sở hữu của hệ thống khác.');
            }
        }
        try {
            if ($field === 'description' && $resource === 'product') {
                if ($this->descriptionWriter === null) return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
                $ok = $this->descriptionWriter->write($postId, $normalized);
                if ($ok !== true) return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
                $read = $this->descriptionWriter->read($postId);
            } else {
                if ($this->metaRepository === null) return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
                $ok = $this->metaRepository->write($postId, $field, $normalized);
                if ($ok !== true) return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
                $read = $this->metaRepository->read($postId, $field);
            }
        } catch (\Throwable $e) {
            return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
        }
        if ($read !== $normalized) return $this->error('storage_failed', 502, 'Không thể xác nhận dữ liệu đã lưu.');
        return ['http_status' => 200, 'body' => ['status' => 'ok', 'code' => 'saved', 'message' => 'Đã áp dụng đề xuất đã xác nhận.']];
    }

    /** Business purpose: adapt the authenticated WordPress AJAX hook. Technical description: add method marker and emit only the closed body. */
    public function handleWordPressRequest(): void
    {
        $request = function_exists('wp_unslash') ? wp_unslash($_POST ?? []) : ($_POST ?? []);
        $request = is_array($request) ? $request : [];
        $request['_method'] = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $result = $this->handleApplyRequest($request);
        if (function_exists('wp_send_json')) wp_send_json($result['body'], $result['http_status']);
    }

    /** Business purpose: enforce object-specific authorization. Technical description: use the injected seam or WordPress capability API. */
    private function canEdit(int $postId): bool
    {
        return is_callable($this->capabilityChecker) ? ($this->capabilityChecker)('edit_post', $postId) === true : function_exists('current_user_can') && current_user_can('edit_post', $postId);
    }

    /** Business purpose: enforce the Apply nonce. Technical description: use the injected seam or WordPress nonce verification. */
    private function validNonce($nonce): bool
    {
        return is_string($nonce) && $nonce !== '' && (is_callable($this->nonceVerifier) ? ($this->nonceVerifier)('myseo_ai_apply', $nonce) === true : function_exists('wp_verify_nonce') && (bool) wp_verify_nonce($nonce, 'myseo_ai_apply'));
    }

    /** Business purpose: parse a strict positive object identity. Technical description: reject coercive strings and non-integer values. */
    private function positiveId($value): ?int
    {
        return is_int($value) && $value > 0 ? $value : (is_string($value) && ctype_digit($value) && (int) $value > 0 && (string) (int) $value === $value ? (int) $value : null);
    }

    /** Business purpose: return a closed Apply error. Technical description: keep transport status outside the three-key JSON body. */
    private function error(string $code, int $status, string $message): array
    {
        return ['http_status' => $status, 'body' => ['status' => 'error', 'code' => $code, 'message' => $message]];
    }
}
