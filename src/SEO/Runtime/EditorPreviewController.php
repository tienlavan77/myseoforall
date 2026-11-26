<?php
/**
 * This controller provides the read-only editor metabox and authenticated AI preview boundary.
 * It validates requests before inspection or provider calls, renders escaped diagnostics, and never writes or retains data.
 */
declare(strict_types=1);

namespace MySeoForAll\SEO\Runtime;

use MySeoForAll\AI\AiSettingsRepository;
use MySeoForAll\AI\Contracts\AiProvider;
use MySeoForAll\AI\Contracts\AiProviderException;

final class EditorPreviewController
{
    private AiProvider $aiProvider;
    private AiSettingsRepository $settings;
    private ContentSeoInspector $contentInspector;
    private ProductSeoInspector $productInspector;
    private $capabilityChecker;
    private $nonceVerifier;
    private $wooAvailability;
    private $resourceResolver;
    private $ownershipResolver;
    private $nonceFactory;

    /** Construct the read-only controller with platform, security, and optional display seams. */
    public function __construct(AiProvider $aiProvider, AiSettingsRepository $settings, ContentSeoInspector $contentInspector, ProductSeoInspector $productInspector, $capabilityChecker = null, $nonceVerifier = null, $wooAvailability = null, $resourceResolver = null, $ownershipResolver = null, $nonceFactory = null)
    {
        $this->aiProvider = $aiProvider;
        $this->settings = $settings;
        $this->contentInspector = $contentInspector;
        $this->productInspector = $productInspector;
        $this->capabilityChecker = is_callable($capabilityChecker) ? $capabilityChecker : null;
        $this->nonceVerifier = is_callable($nonceVerifier) ? $nonceVerifier : null;
        $this->wooAvailability = is_callable($wooAvailability) ? $wooAvailability : null;
        $this->resourceResolver = is_callable($resourceResolver) ? $resourceResolver : null;
        $this->ownershipResolver = is_callable($ownershipResolver) ? $ownershipResolver : null;
        $this->nonceFactory = is_callable($nonceFactory) ? $nonceFactory : null;
    }

    /** Validate the closed request contract and return a seven-key success or three-key error body. */
    public function handlePreviewRequest($request): array
    {
        if (!is_array($request) || (($request['_method'] ?? null) !== 'POST')) {
            return $this->error('malformed_request', 'Yêu cầu xem trước không hợp lệ.');
        }
        $postId = $this->parsePositiveId($request['post_id'] ?? null);
        if ($postId === null) return $this->error('invalid_post_id', 'Mã đối tượng không hợp lệ.');
        if (!$this->canEdit($postId)) return $this->error('forbidden', 'Bạn không có quyền chỉnh sửa đối tượng này.');
        if (!$this->validNonce($request['_ajax_nonce'] ?? ($request['_wpnonce'] ?? null))) return $this->error('invalid_nonce', 'Phiên bảo mật không hợp lệ.');
        $task = $request['task'] ?? null;
        if (!is_string($task) || $task === '') return $this->error('malformed_request', 'Yêu cầu phân tích AI không hợp lệ cho đối tượng này.');
        if (!in_array($task, ['seo_analysis', 'title_meta_suggestion', 'product_description_draft'], true)) {
            return $this->error('unsupported_task', 'Tác vụ AI không được hỗ trợ.');
        }

        $postType = $this->resolvePostType($postId);
        if ($postType === null) return $this->error('not_found', 'Không tìm thấy đối tượng.');
        if (!in_array($postType, ['post', 'page', 'product'], true)) return $this->error('unsupported_resource', 'Loại đối tượng không được hỗ trợ.');
        if ($postType === 'product' && !$this->wooAvailable()) return $this->error('unsupported_resource', 'Loại đối tượng không được hỗ trợ.');
        if ($task === 'product_description_draft' && $postType !== 'product') return $this->error('unsupported_task_for_resource', 'Tác vụ này chỉ áp dụng cho sản phẩm.');

        $resourceType = $postType === 'page' ? 'page' : ($postType === 'post' ? 'article' : 'product');
        $inspection = $postType === 'product' ? $this->productInspector->inspect($postId) : $this->contentInspector->inspect($postId);
        if (($inspection['status'] ?? null) === 'not_found') return $this->error('not_found', 'Không tìm thấy đối tượng.');
        if (($inspection['status'] ?? null) !== 'ok') return $this->error('unsupported_resource', 'Loại đối tượng không được hỗ trợ.');
        if (($inspection['payload_status'] ?? null) !== 'accepted' || !is_array($inspection['provider_payload'] ?? null)) {
            return $this->error('payload_rejected', 'Dữ liệu nguồn không vượt qua bộ lọc an toàn.');
        }

        try {
            $response = $this->aiProvider->generate([
                'schema_version' => AiProvider::REQUEST_SCHEMA,
                'task' => $task,
                'context' => $inspection['provider_payload'],
            ]);
            $response = (new \MySeoForAll\AI\Contracts\AiOutputValidator())->validate($response, $task, $this->aiProvider->name());
            return ['http_status' => 200, 'body' => [
                'status' => 'ok',
                'post_id' => $postId,
                'resource_type' => $resourceType,
                'task' => $task,
                'current' => $this->currentValues($inspection['provider_payload'], $resourceType),
                'proposal' => $response['output'],
                'read_only' => true,
            ]];
        } catch (AiProviderException $exception) {
            return $this->providerError($exception->category());
        } catch (\Throwable) {
            return $this->error('invalid_response', 'Phản hồi từ AI Gateway không đạt chuẩn kiểm định an toàn nên đã bị từ chối.');
        }
    }

    /** Adapt the WordPress AJAX hook and attach the internal HTTP status outside the JSON body. */
    public function handleWordPressRequest(): void
    {
        $request = function_exists('wp_unslash') ? wp_unslash($_POST ?? []) : ($_POST ?? []);
        $request = is_array($request) ? $request : [];
        $request['_method'] = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $result = $this->handlePreviewRequest($request);
        if (function_exists('wp_send_json')) {
            wp_send_json($result['body'], $result['http_status']);
        }
    }

    /** Render ownership, findings, disclosure, nonce, and a DOM-safe fetch preview without writes. */
    public function renderMetabox($post): void
    {
        if (!is_object($post)) return;
        if (!isset($post->ID)) {
            echo '<p>' . $this->escape('Hãy lưu bản nháp trước khi chạy phân tích SEO & AI.') . '</p>';
            return;
        }
        $postId = $this->parsePositiveId($post->ID);
        if ($postId === null) {
            echo '<p>' . $this->escape('Hãy lưu bản nháp trước khi chạy phân tích SEO & AI.') . '</p>';
            return;
        }
        $type = is_string($post->post_type ?? null) ? $post->post_type : '';
        if (!in_array($type, ['post', 'page', 'product'], true) || ($type === 'product' && !$this->wooAvailable()) || !$this->canEdit($postId)) return;
        $resourceType = $type === 'page' ? 'page' : ($type === 'post' ? 'article' : 'product');
        $inspection = $type === 'product' ? $this->productInspector->inspect($postId) : $this->contentInspector->inspect($postId);
        if (($inspection['status'] ?? null) !== 'ok') {
            echo '<p>' . $this->escape('Không thể đọc dữ liệu hiện tại.') . '</p>';
            return;
        }
        $ownership = $this->ownership();
        echo '<div class="myseo-for-all-metabox" data-ajax-url="' . $this->escape($this->ajaxUrl()) . '" data-nonce="' . $this->escape($this->nonce()) . '" data-post-id="' . $postId . '">';
        echo '<style>';
        echo '.myseo-for-all-metabox{--myseo-border:#dcdcde;--myseo-muted:#646970;--myseo-blue:#2271b1;--myseo-warning:#996800;--myseo-danger:#b32d2e;color:#1d2327;font-size:13px;line-height:1.5}';
        echo '.myseo-for-all-metabox__section{background:#fff;border:1px solid var(--myseo-border);border-radius:6px;margin:0 0 16px;padding:16px}';
        echo '.myseo-for-all-metabox__section-header{align-items:center;display:flex;gap:8px;justify-content:space-between;margin-bottom:12px}';
        echo '.myseo-for-all-metabox__title{font-size:14px;line-height:1.4;margin:0}';
        echo '.myseo-for-all-metabox__description,.myseo-for-all-metabox__finding-fix{color:var(--myseo-muted)}';
        echo '.myseo-for-all-metabox__counts,.myseo-for-all-metabox__actions{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}';
        echo '.myseo-for-all-metabox__badge{border-radius:999px;font-size:12px;font-weight:600;padding:4px 10px}';
        echo '.myseo-for-all-metabox__badge--blocker{background:#fbeaea;color:var(--myseo-danger)}';
        echo '.myseo-for-all-metabox__badge--warning{background:#fff8e5;color:var(--myseo-warning)}';
        echo '.myseo-for-all-metabox__badge--info{background:#eef6fc;color:#135e96}';
        echo '.myseo-for-all-metabox__findings{list-style:none;margin:0;padding:0}';
        echo '.myseo-for-all-metabox__finding{background:#f6f7f7;border-left:4px solid var(--myseo-blue);border-radius:4px;margin:8px 0;padding:10px 12px}';
        echo '.myseo-for-all-metabox__finding--blocker{border-left-color:var(--myseo-danger)}';
        echo '.myseo-for-all-metabox__finding--warning{border-left-color:var(--myseo-warning)}';
        echo '.myseo-for-all-metabox__finding--info{border-left-color:var(--myseo-blue)}';
        echo '.myseo-for-all-metabox__finding-meta{align-items:center;display:flex;flex-wrap:wrap;gap:8px}';
        echo '.myseo-for-all-metabox__severity{font-size:11px;font-weight:700;text-transform:uppercase}';
        echo '.myseo-for-all-metabox__field{color:#50575e;font-size:12px}';
        echo '.myseo-for-all-metabox__finding-message,.myseo-for-all-metabox__finding-fix{margin:6px 0 0}';
        echo '.myseo-for-all-metabox__finding-fix{font-size:12px}';
        echo '.myseo-for-all-metabox__alert{border-left:4px solid var(--myseo-warning);border-radius:4px;margin-top:12px;padding:12px}';
        echo '.myseo-for-all-metabox__alert--warning{background:#fff8e5;color:#5c4813}';
        echo '.myseo-for-all-metabox__alert--error{background:#fbeaea;border-left-color:var(--myseo-danger);color:#7a2020}';
        echo '.myseo-for-all-metabox__preview-panel{margin-top:16px}';
        echo '@media (max-width:782px){.myseo-for-all-metabox__section{padding:12px}.myseo-for-all-metabox__section-header{align-items:flex-start;flex-direction:column}}';
        echo '</style>';
        echo '<section class="myseo-for-all-metabox__section myseo-for-all-metabox__ownership"><div class="myseo-for-all-metabox__section-header"><h3 class="myseo-for-all-metabox__title">' . $this->escape('Ownership / Coexistence') . '</h3><span class="myseo-for-all-metabox__status">' . $this->escape($ownership['message']) . '</span></div><p class="myseo-for-all-metabox__description">' . $this->escape($ownership['message']) . '</p></section>';
        $findings = is_array($inspection['findings'] ?? null) ? $inspection['findings'] : [];
        $counts = ['blocker' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) if (is_array($finding) && isset($counts[$finding['severity'] ?? ''])) $counts[$finding['severity']]++;
        echo '<section class="myseo-for-all-metabox__section myseo-for-all-metabox__local-seo"><h3 class="myseo-for-all-metabox__title">Local SEO</h3><div class="myseo-for-all-metabox__counts" aria-label="Tóm tắt mức độ ưu tiên">';
        foreach (['blocker' => 'Cần xử lý ngay', 'warning' => 'Cảnh báo', 'info' => 'Gợi ý'] as $severity => $label) {
            echo '<span class="myseo-for-all-metabox__badge myseo-for-all-metabox__badge--' . $severity . '">' . $this->escape($label . ': ' . $counts[$severity]) . '</span> ';
        }
        echo '</div>';
        if ($findings === []) {
            echo '<p class="myseo-for-all-metabox__description">' . $this->escape('Không phát hiện vấn đề SEO cục bộ nào trên dữ liệu hiện tại.') . '</p>';
        } else {
            echo '<ul class="myseo-for-all-metabox__findings">';
            foreach ($findings as $finding) {
                if (!is_array($finding)) continue;
                $severity = in_array($finding['severity'] ?? null, ['blocker', 'warning', 'info'], true) ? $finding['severity'] : 'info';
                $labels = ['blocker' => 'Cần xử lý ngay', 'warning' => 'Cảnh báo', 'info' => 'Gợi ý'];
                echo '<li class="myseo-for-all-metabox__finding myseo-for-all-metabox__finding--' . $severity . '"><div class="myseo-for-all-metabox__finding-meta"><span class="myseo-for-all-metabox__severity">' . $this->escape($labels[$severity]) . '</span><code class="myseo-for-all-metabox__field">' . $this->escape((string) ($finding['field'] ?? '')) . '</code></div><p class="myseo-for-all-metabox__finding-message">' . $this->escape((string) ($finding['message'] ?? '')) . '</p><p class="myseo-for-all-metabox__finding-fix">' . $this->escape((string) ($finding['suggested_fix'] ?? '')) . '</p></li>';
            }
            echo '</ul>';
        }
        echo '</section>';
        $loaded = $this->settings->load();
        $settings = is_array($loaded['settings'] ?? null) ? $loaded['settings'] : [];
        $ready = ($loaded['status'] ?? null) === 'ok' && ($settings['ai_enabled'] ?? false) === true && (string) ($settings['base_url'] ?? '') !== '' && (string) ($settings['default_model'] ?? '') !== '' && (($settings['allowed_models'] ?? []) === [] || in_array($settings['default_model'], $settings['allowed_models'], true)) && in_array($this->settings->credentialStatus()['status'] ?? null, ['configured', 'environment'], true);
        echo '<section class="myseo-for-all-metabox__section myseo-for-all-metabox__ai"><h3 class="myseo-for-all-metabox__title">Trợ lý AI</h3>';
        if (!$ready) {
            echo '<p class="myseo-for-all-metabox__alert myseo-for-all-metabox__alert--warning" role="status">' . $this->escape('Trợ lý AI chưa được cấu hình đầy đủ.') . ' <a href="' . $this->escape($this->settingsUrl()) . '">' . $this->escape('Mở Cài đặt') . '</a></p>';
        } elseif (($inspection['payload_status'] ?? null) !== 'accepted' || !is_array($inspection['provider_payload'] ?? null)) {
            echo '<p class="myseo-for-all-metabox__alert myseo-for-all-metabox__alert--warning" role="status">' . $this->escape('Dữ liệu nguồn hiện tại không vượt qua bộ lọc an toàn.') . '</p>';
        } else {
            $provenance = $inspection['provider_payload']['provenance'] ?? [];
            $fields = is_array($provenance) ? array_filter(
                array_column($provenance, 'field'),
                static fn ($field): bool => is_string($field) && $field !== ''
            ) : [];
            echo '<p class="myseo-for-all-metabox__description">' . $this->escape('Chỉ gửi các trường công khai: ' . implode(', ', $fields)) . '</p><div class="myseo-for-all-metabox__actions">';
            foreach (['seo_analysis' => 'Phân tích SEO bằng AI', 'title_meta_suggestion' => 'Gợi ý Title/Meta'] as $task => $label) echo '<button type="button" class="button myseo-ai-preview" data-task="' . $this->escape($task) . '">' . $this->escape($label) . '</button> ';
            if ($resourceType === 'product') echo '<button type="button" class="button myseo-ai-preview" data-task="product_description_draft">' . $this->escape('Soạn nháp mô tả sản phẩm') . '</button>';
            echo '</div><div class="myseo-for-all-metabox__preview-panel myseo-ai-preview-panel" aria-live="polite"></div>';
            echo <<<'HTML'
<script>
(() => {
    const box = document.currentScript.parentElement;
    const panel = box.querySelector('.myseo-ai-preview-panel');
    const buttons = box.querySelectorAll('.myseo-ai-preview');
    const append = (parent, tag, value) => {
        const node = document.createElement(tag);
        node.textContent = value;
        parent.appendChild(node);
        return node;
    };
    const showError = message => {
        panel.textContent = '';
        append(panel, 'p', message).style.color = '#b32d2e';
    };
    buttons.forEach(button => button.addEventListener('click', async () => {
        buttons.forEach(control => { control.disabled = true; });
        panel.textContent = 'Đang tải...';
        try {
            const response = await fetch(box.dataset.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: new URLSearchParams({
                    action: 'myseo_ai_preview', post_id: box.dataset.postId,
                    _ajax_nonce: box.dataset.nonce, task: button.dataset.task
                })
            });
            const data = await response.json();
            if (!response.ok || data.status !== 'ok') {
                showError(data.message || 'Yêu cầu bị từ chối.');
                return;
            }
            panel.textContent = '';
            const proposal = data.proposal;
            if (data.task === 'seo_analysis') {
                const list = append(panel, 'ul', '');
                const colors = {error: '#b32d2e', warning: '#785000', info: '#135e96'};
                proposal.findings.forEach(finding => {
                    const item = append(list, 'li', '');
                    append(item, 'strong', finding.severity + ': ').style.color = colors[finding.severity];
                    append(item, 'span', [finding.field, finding.message, finding.suggested_fix].filter(Boolean).join(' — '));
                });
                if (!proposal.findings.length) append(panel, 'p', 'Không có gợi ý SEO bổ sung.');
                return;
            }
            const product = data.task === 'product_description_draft';
            const value = product ? proposal.content : proposal.value;
            const row = append(append(panel, 'table', ''), 'tr', '');
            const current = append(row, 'td', '');
            append(current, 'strong', product ? 'Mô tả hiện tại' : 'Hiện tại');
            append(current, 'p', data.current[product ? 'description' : proposal.target_field] ?? 'Chưa có dữ liệu');
            const proposed = append(row, 'td', '');
            append(proposed, 'strong', product ? 'Bản nháp AI' : 'Đề xuất AI');
            append(proposed, 'p', value);
            const copy = append(panel, 'button', product ? 'Sao chép bản nháp' : 'Sao chép đề xuất');
            copy.type = 'button';
            copy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(value);
                    copy.textContent = 'Đã sao chép';
                } catch {
                    copy.textContent = 'Không thể sao chép tự động';
                }
            });
        } catch {
            showError('Không thể tải bản xem trước.');
        } finally {
            buttons.forEach(control => { control.disabled = false; });
        }
    }));
})();
</script>
HTML;
        }
        echo '</section></div>';
    }

    /** Resolve post type without invoking an inspector. */
    private function resolvePostType(int $postId): ?string
    {
        if ($this->resourceResolver !== null) {
            $value = ($this->resourceResolver)($postId);
            return is_string($value) && $value !== '' ? $value : null;
        }
        $type = function_exists('get_post_type') ? get_post_type($postId) : null;
        return is_string($type) && $type !== '' ? $type : null;
    }

    /** Resolve the configured ownership state into a safe display message. */
    private function ownership(): array
    {
        try {
            $state = is_callable($this->ownershipResolver) ? ($this->ownershipResolver)() : [];
            if (is_array($state) && (($state['rank_math_active'] ?? false) === true)) return ['message' => 'Rank Math đang hoạt động — My SEO For All đang ở chế độ đọc thụ động và xem trước.'];
            $owner = is_string($state['owner'] ?? null) ? $state['owner'] : 'unknown';
            $decision = is_string($state['state'] ?? null) ? $state['state'] : 'unknown';
            return ['message' => 'Chế độ xem trước an toàn (Read-Only) — title_meta: ' . $owner . ' / ' . $decision . '.'];
        } catch (\Throwable) {
            return ['message' => 'Chế độ xem trước an toàn (Read-Only).'];
        }
    }

    /** Return whether WooCommerce is available through the seam or guarded platform function. */
    private function wooAvailable(): bool { return $this->wooAvailability === null ? function_exists('wc_get_product') : ($this->wooAvailability)() === true; }

    /** Check object-specific edit capability through the seam or WordPress. */
    private function canEdit(int $postId): bool { return $this->capabilityChecker !== null ? ($this->capabilityChecker)('edit_post', $postId) === true : function_exists('current_user_can') && current_user_can('edit_post', $postId); }

    /** Check the preview nonce through the seam or WordPress. */
    private function validNonce($nonce): bool { return is_string($nonce) && $nonce !== '' && ($this->nonceVerifier !== null ? ($this->nonceVerifier)('myseo_ai_preview', $nonce) === true : function_exists('wp_verify_nonce') && (bool) wp_verify_nonce($nonce, 'myseo_ai_preview')); }

    /** Parse an exact positive integer identity. */
    private function parsePositiveId($value): ?int { return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 && (string) (int) $value === (string) $value ? (int) $value : null; }

    /** Build current values from the provider payload with null for missing or empty values. */
    private function currentValues(array $payload, string $resourceType): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $value = static fn ($v): ?string => is_string($v) && trim($v) !== '' ? $v : null;
        if ($resourceType === 'product') {
            $description = $value($payload['description'] ?? null);
            return [
                'title' => $value($seo['title'] ?? ($payload['product_name'] ?? null)),
                'meta_description' => $value($seo['meta_description'] ?? null),
                'description' => $description !== null && preg_match('/\A.{0,2000}/us', $description, $match) === 1 ? $match[0] : null,
            ];
        }
        return ['title' => $value($seo['title'] ?? ($payload['title'] ?? null)), 'meta_description' => $value($seo['meta_description'] ?? ($payload['meta_description'] ?? null))];
    }

    /** Map provider categories to static redacted three-key error bodies. */
    private function providerError(string $category): array
    {
        $map = [
            AiProviderException::PROVIDER_DISABLED => ['provider_disabled', 'Trợ lý AI đang tắt hoặc chưa cấu hình đầy đủ trong trang Cài đặt.'],
            AiProviderException::AUTHENTICATION_FAILED => ['authentication_failed', 'Xác thực với AI Gateway thất bại; vui lòng kiểm tra lại API key.'],
            AiProviderException::GATEWAY_POLICY_REJECTED => ['gateway_policy_rejected', 'Địa chỉ AI Gateway bị từ chối bởi chính sách bảo mật mạng.'],
            AiProviderException::RATE_LIMITED => ['rate_limited', 'AI Gateway đang giới hạn tần suất yêu cầu; vui lòng thử lại sau ít phút.'],
            AiProviderException::TRANSPORT_FAILED => ['transport_failed', 'Không thể kết nối tới AI Gateway hoặc yêu cầu đã hết thời gian chờ.'],
            AiProviderException::INVALID_RESPONSE => ['invalid_response', 'Phản hồi từ AI Gateway không đạt chuẩn kiểm định an toàn nên đã bị từ chối.'],
            AiProviderException::UNSUPPORTED_TASK => ['unsupported_task', 'Tác vụ AI được yêu cầu không nằm trong danh mục hỗ trợ.'],
            AiProviderException::MALFORMED_REQUEST => ['malformed_request', 'Yêu cầu gửi tới trợ lý AI không đúng định dạng hợp lệ.'],
        ];
        return $this->error(...($map[$category] ?? $map[AiProviderException::MALFORMED_REQUEST]));
    }

    /** Build a closed error body without transport metadata or provider internals. */
    private function error(string $code, string $message): array { return ['http_status' => $this->statusForCode($code), 'body' => ['status' => 'error', 'code' => $code, 'message' => $message]]; }

    /** Map a stable error code to its HTTP response status. */
    private function statusForCode(string $code): int { return ['forbidden' => 403, 'invalid_nonce' => 403, 'invalid_post_id' => 400, 'malformed_request' => 400, 'unsupported_task' => 400, 'unsupported_task_for_resource' => 400, 'unsupported_resource' => 400, 'not_found' => 404, 'payload_rejected' => 422, 'provider_disabled' => 409, 'rate_limited' => 429][$code] ?? 502; }

    /** Return the AJAX endpoint URL without exposing configuration. */
    private function ajaxUrl(): string { return function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : 'admin-ajax.php'; }

    /** Return the settings URL without exposing configuration. */
    private function settingsUrl(): string { return function_exists('admin_url') ? (string) admin_url('options-general.php?page=myseo-for-all') : 'options-general.php?page=myseo-for-all'; }

    /** Return a nonce from the injected seam or WordPress. */
    private function nonce(): string { return $this->nonceFactory !== null ? (string) ($this->nonceFactory)('myseo_ai_preview') : (function_exists('wp_create_nonce') ? (string) wp_create_nonce('myseo_ai_preview') : ''); }

    /** Escape dynamic HTML text. */
    private function escape(string $value): string { return function_exists('esc_html') ? (string) esc_html($value) : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
