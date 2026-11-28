# Increment 3A — CSS/UI Polish cho Editor Metabox

## 1. Mục tiêu

Cải thiện khả năng đọc của metabox **My SEO For All — Trợ lý SEO & AI** trên màn hình sửa `post`, `page` và WooCommerce `product`. Đây là thay đổi presentation trong phạm vi 3A; không đổi public contract, AI transport, ownership, payload policy hoặc Increment 3B.

## 2. Hiện trạng cần sửa

Metabox đã hiển thị Ownership, bộ đếm `blocker/warning/info`, findings Local SEO và trạng thái AI. Tuy nhiên findings hiện là chuỗi dài dạng `warning: field — message — suggested_fix`, bộ đếm khó quét và các khu vực chưa được phân tách rõ.

## 3. Guardrails bất biến

1. Không sửa `myseoforall.php`.
2. Không thêm database write/delete/CAS, network khi chỉ mở Edit, Auto-Apply hoặc Publish.
3. Không nới lỏng `ProviderPayloadRules`, không bỏ `payload_status` gate và không gửi payload chưa được chấp nhận.
4. Không hiển thị API key, credential, gateway secret, raw exception hoặc raw payload.
5. Dynamic PHP phải escape; JavaScript tiếp tục dùng `createElement`, `textContent`, `appendChild`; không dùng `innerHTML` cho dữ liệu động.
6. CSS phải scope dưới `.myseo-for-all-metabox`, không ảnh hưởng Gutenberg/plugin khác.
7. Giữ accessibility: heading hợp lý, `aria-live="polite"`, trạng thái không chỉ truyền bằng màu, nút `type="button"`.

## 4. Markup semantic bắt buộc

Refactor `EditorPreviewController::renderMetabox()` để có ba section riêng (có thể dùng tên tương đương):

```html
<div class="myseo-for-all-metabox">
  <section class="myseo-for-all-metabox__section myseo-for-all-metabox__ownership">
    <div class="myseo-for-all-metabox__section-header">
      <h3 class="myseo-for-all-metabox__title">Ownership / Coexistence</h3>
      <span class="myseo-for-all-metabox__status">...</span>
    </div>
    <p class="myseo-for-all-metabox__description">...</p>
  </section>
  <section class="myseo-for-all-metabox__section myseo-for-all-metabox__local-seo">
    <h3 class="myseo-for-all-metabox__title">Local SEO</h3>
    <div class="myseo-for-all-metabox__counts" aria-label="Tóm tắt mức độ ưu tiên">...</div>
    <ul class="myseo-for-all-metabox__findings">
      <li class="myseo-for-all-metabox__finding myseo-for-all-metabox__finding--warning">
        <div class="myseo-for-all-metabox__finding-meta"><span class="myseo-for-all-metabox__severity">Cảnh báo</span><code class="myseo-for-all-metabox__field">meta_description</code></div>
        <p class="myseo-for-all-metabox__finding-message">...</p>
        <p class="myseo-for-all-metabox__finding-fix">...</p>
      </li>
    </ul>
  </section>
  <section class="myseo-for-all-metabox__section myseo-for-all-metabox__ai">
    <h3 class="myseo-for-all-metabox__title">Trợ lý AI</h3>
    <!-- disclosure, actions, payload alert and preview panel -->
  </section>
</div>
```

Mỗi finding phải tách metadata (severity + field), message chính và suggested fix. Mapping: `blocker` → `Cần xử lý ngay`, `warning` → `Cảnh báo`, `info` → `Gợi ý`. Không in raw severity tiếng Anh nếu có label tiếng Việt.

AI-disabled vẫn hiện Local SEO và link Settings. Payload-rejected vẫn chặn nút AI nhưng hiển thị alert scoped, ví dụ:

```html
<div class="myseo-for-all-metabox__alert myseo-for-all-metabox__alert--warning" role="status">Dữ liệu nguồn hiện tại không vượt qua bộ lọc an toàn.</div>
```

Panel kết quả giữ `aria-live="polite"`; task `product_description_draft` chỉ hiện trên product.

## 5. CSS contract

Chèn style scoped (giá trị có thể tinh chỉnh nhỏ theo WordPress Admin):

```css
.myseo-for-all-metabox{--myseo-border:#dcdcde;--myseo-muted:#646970;--myseo-blue:#2271b1;--myseo-warning:#996800;--myseo-danger:#b32d2e;color:#1d2327;font-size:13px;line-height:1.5}
.myseo-for-all-metabox__section{background:#fff;border:1px solid var(--myseo-border);border-radius:6px;margin:0 0 16px;padding:16px}
.myseo-for-all-metabox__section-header{align-items:center;display:flex;gap:8px;justify-content:space-between;margin-bottom:12px}
.myseo-for-all-metabox__title{font-size:14px;line-height:1.4;margin:0}
.myseo-for-all-metabox__description,.myseo-for-all-metabox__finding-fix{color:var(--myseo-muted)}
.myseo-for-all-metabox__counts,.myseo-for-all-metabox__actions{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.myseo-for-all-metabox__badge{border-radius:999px;font-size:12px;font-weight:600;padding:4px 10px}
.myseo-for-all-metabox__badge--blocker{background:#fbeaea;color:var(--myseo-danger)}
.myseo-for-all-metabox__badge--warning{background:#fff8e5;color:var(--myseo-warning)}
.myseo-for-all-metabox__badge--info{background:#eef6fc;color:#135e96}
.myseo-for-all-metabox__findings{list-style:none;margin:0;padding:0}
.myseo-for-all-metabox__finding{background:#f6f7f7;border-left:4px solid var(--myseo-blue);border-radius:4px;margin:8px 0;padding:10px 12px}
.myseo-for-all-metabox__finding--blocker{border-left-color:var(--myseo-danger)}
.myseo-for-all-metabox__finding--warning{border-left-color:var(--myseo-warning)}
.myseo-for-all-metabox__finding--info{border-left-color:var(--myseo-blue)}
.myseo-for-all-metabox__finding-meta{align-items:center;display:flex;flex-wrap:wrap;gap:8px}
.myseo-for-all-metabox__severity{font-size:11px;font-weight:700;text-transform:uppercase}
.myseo-for-all-metabox__field{color:#50575e;font-size:12px}
.myseo-for-all-metabox__finding-message,.myseo-for-all-metabox__finding-fix{margin:6px 0 0}
.myseo-for-all-metabox__finding-fix{font-size:12px}
.myseo-for-all-metabox__alert{border-left:4px solid var(--myseo-warning);border-radius:4px;margin-top:12px;padding:12px}
.myseo-for-all-metabox__alert--warning{background:#fff8e5;color:#5c4813}
.myseo-for-all-metabox__alert--error{background:#fbeaea;border-left-color:var(--myseo-danger);color:#7a2020}
.myseo-for-all-metabox__preview-panel{margin-top:16px}
@media (max-width:782px){.myseo-for-all-metabox__section{padding:12px}.myseo-for-all-metabox__section-header{align-items:flex-start;flex-direction:column}}
```

Không dùng broad selectors như `.button`, `h3`, `p`, `ul` ngoài root scoped.

## 6. Acceptance criteria

### AC-UI-1 — Readability

- Ownership, Local SEO và AI là ba khu vực nhìn rõ ràng.
- Không finding nào còn là một dòng dài không cấu trúc.
- Badge/card dễ đọc desktop và breakpoint WordPress admin mobile.
- CSS chỉ áp dụng dưới `.myseo-for-all-metabox`.

### AC-UI-2 — Functional preservation

- `post`, `page`, `product` vẫn render đúng.
- AI-disabled vẫn hiển thị Local SEO + link Settings.
- Payload-rejected vẫn chặn AI và hiển thị warning dễ hiểu.
- Product task vẫn chỉ dành cho product.

### AC-UI-3 — Security/read-only

- Mở Edit không DNS/HTTP; render không write/delete/CAS.
- Không secret/raw exception/raw payload.
- Dynamic HTML escaped; JS dynamic dùng DOM API/textContent.
- Không Auto-Apply, Publish hoặc persistence.

### AC-UI-4 — Regression

Cập nhật `tests/Unit/Increment3ATest.php` để assert semantic section classes, badge classes, finding field/message/fix, Ownership, AI-disabled/payload-rejected alert, product-only task, không `.innerHTML` động và zero-write spy.

Chạy và báo cáo:

```text
php vendor/bin/phpunit --do-not-cache-result tests/Unit/Increment3ATest.php
php vendor/bin/phpunit --do-not-cache-result tests/Unit
```

Báo cáo test count, assertions, checksum file và giới hạn offline; không tuyên bố WordPress production acceptance chỉ từ PHPUnit.

## 7. Scope và handoff

**In scope:** markup/style trong `EditorPreviewController::renderMetabox()` và focused UI assertions trong `Increment3ATest.php`.

**Out of scope:** payload size/policy, Gateway/credential/DNS/TLS, Ownership decision, analyzer rules, Apply/publish/persistence, `myseoforall.php`.

SE triển khai presentation-only, giữ nguyên gate ordering/security boundary và xuất implementation report sau khi hai lệnh PHPUnit pass.
