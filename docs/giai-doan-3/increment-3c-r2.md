# Giai Đoạn 3 — Increment 3C (Revision 2): Sửa các lỗi nghiêm trọng từ báo cáo `increment-3c-rp.md` và Prompt Hardening

## 1. Kết luận rà soát `increment-3c-rp.md`

Bản triển khai `docs/giai-doan-3/increment-3c-rp.md` (`sha256:2b9666c73cd921dc3084eaa49f2f9b53ba3130e686be4143ac76210e6fb0c181`) **CHƯA ĐẠT NGHIỆM THU**. Kiểm tra trực tiếp 6 file nguồn và `tests/Unit` phát hiện:

- **1 lỗi Fatal Error runtime ngay khi mở Edit Post** (`EditorPreviewController::renderMetabox()` gọi `$applySnapshotResolver($postId)` với 1 tham số trong khi closure trong `SeoModule` yêu cầu 3 tham số bắt buộc).
- **2 lỗi Critical ở `FrontendSeoEmitter` & `SeoModule`** (regex escape sai xóa mất chữ hoa/số và chặn sạch mọi URL `https://`; `SeoModule` chưa nối seams nên `shouldEmit()` luôn `false`).
- **4 lỗi ở `WooCommerceProductReader` & `WooCommerceModule`** (cắt 2.000 ký tự trước privacy gate, bỏ sót `short_description`/local URL, từ chối sản phẩm `variable`, constructor `WooCommerceModule` chưa nhận seam test).
- **Toàn bộ JS UI Polish trong `EditorPreviewController`** mới thêm CSS nhưng **chưa gắn class vào HTML/JS động**, khung `saved-seo` đặt ra ngoài `.myseo-for-all-metabox` và chưa cập nhật live sau Apply.
- **Thiếu toàn bộ `tests/Unit/Increment3CTest.php`** (0 test cho `3C-O1`, `3C-O2` HPOS/privacy-after-2000, `3C-O3` saved-seo/CSS polish), báo cáo thiếu exact commands, test/assertion counts và skipped/error counts.
- **Cần kèm Prompt Hardening** cho `AiSettingsRepository::executeGatewayRequest()` để xử lý lỗi `invalid_response` thực tế từ AI Gateway.

R2 xử lý dứt điểm toàn bộ các lỗi trên trong phạm vi Increment 3C. Không sửa `myseoforall.php`, không đổi schema `_myseo_*`, không ghi key Rank Math và không nới lỏng `GatewayAiProvider` / `AiOutputValidator`.

---

## 2. Yêu cầu sửa bắt buộc

### `3C2-1` — Vá các lỗi Critical/High ở `FrontendSeoEmitter` và `SeoModule` (`3C-O1`)

1. **Sửa double-escaped regex trong `src/SEO/Runtime/FrontendSeoEmitter.php:103-110`:**
   - Trong `plainText()`: sửa `'/[\\\\x00-\\\\x1F\\\\x7F]+/u'` thành `'/[\x00-\x1F\x7F]+/u'` và `'/\\\\s+/u'` thành `'/\s+/u'` (hiện tại `\\\\x00-\\\\x1F` tạo dải ký tự `0` tới `\`, làm xóa sạch chữ hoa `A-Z` và chữ số `0-9` khỏi title/description!).
   - Trong `publicUrl()`: sửa `'/[\\\\x00-\\\\x20\\\\x7F]/'` thành `'/[\x00-\x20\x7F]/'` (hiện tại nó chặn luôn dấu `:` nên từ chối 100% URL `http://`/`https://`!).
   - Trong `publicUrl()`: sửa `isset($parts['user'], $parts['pass'])` thành `isset($parts['user']) || isset($parts['pass'])` để chặn cả URL dạng `https://admin@example.com`, và chặn thêm `.local` / private/reserved IP nhất quán với `WordPressContentReader`.
2. **Sửa REST guard & fail-closed trong `FrontendSeoEmitter::shouldEmit()` (`lines 59-72`):**
   - Thay `function_exists('is_rest_request') && is_rest_request()` (hàm không tồn tại trong WordPress core) bằng `(defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_is_serving_rest_request') && wp_is_serving_rest_request())`.
   - Sửa `($context['singular'] ?? true) !== true` thành `($context['singular'] ?? false) !== true` (fail-closed khi thiếu key).
   - Khi `post_type === 'product'`, bắt buộc kiểm tra WooCommerce đang active (`($context['woo_available'] ?? false) === true` hoặc seam tương đương).
   - Sửa kiểm tra Rank Math activation: phải fail-closed nếu `$activation` không thuộc `['absent', 'inactive']` (kể cả khi `$activation === null` do probe lỗi).
   - Sửa kiểm tra `$config`: phải fail-closed khi `!is_array($config)`.
3. **Nối đầy đủ seams của `FrontendSeoEmitter` trong `src/SEO/Runtime/SeoModule.php:118-120`:**
   - Truyền đủ `$context`, `$ownership`, `$rankMath`, `$configuration`, `$competitors` cho `FrontendSeoEmitter` trong `SeoModule::register()` (resolve lazy từ `$this->container` vì `RankMathModule` đăng ký sau `SeoModule`):
     - `$rankMath`: gọi `RankMathDetector::detect()` từ container;
     - `$configuration`: đọc `Configuration::all()` từ container;
     - `$ownership`: dựng observation + verified inventory từ `RankMathDetector` + competing SEO plugins + `Configuration`, gọi `OwnershipRegistry::resolve()` và trả về quyết định `outputs.title_meta` (không sửa lỏng `write_allowed = false` của `OwnershipRegistry`).

---

### `3C2-2` — Vá các lỗi `WooCommerceModule` và `WooCommerceProductReader` (`3C-O2`)

1. **`src/WooCommerce/WooCommerceModule.php:30-36`:**
   - Mở rộng constructor theo hướng backward-compatible (`?HookDispatcher $dispatcher = null, ?callable $compatibilityDeclarer = null, ?string $pluginFile = null`) để `declareHposCompatibility()` kiểm thử được bằng seam offline, mặc định vẫn giữ `$pluginFile = dirname(__DIR__, 2) . '/myseoforall.php'`.
2. **`src/SEO/Runtime/WooCommerceProductReader.php`:**
   - Sửa regex double-escape trong `projectionText()` (`lines 267, 271`: `'/[...]/'` và `'/\s+/u'` cho nhất quán, tránh sai chuẩn PCRE).
   - **Chạy privacy check TRƯỚC khi cắt 2.000 code points** (tương tự `WordPressContentReader::containsPrivateText()` ở R6) trên toàn bộ văn bản đã normalize của `product_name`, `description`, `short_description`, `seo.title`, `seo.meta_description` → nếu có email/phone/API key/token sau ký tự 2.000 phải reject `privacy_policy`.
   - Áp dụng projection text-only + giới hạn 2.000 code points cho **cả `description` lẫn `short_description`** (và `seo.title`/`seo.meta_description` nếu dài).
   - Lọc URL local/private không hợp lệ khỏi `url` và `seo.canonical` trong `payloadSource()` (tương tự `WordPressContentReader::isPublicUrl()`).
   - Hỗ trợ sản phẩm cha dạng **`variable`** trong `normalize()` (map `'simple'` và `'variable'` sang `'product'`, giữ `'variation'` và fail-closed `parent_product_missing` cho variation mồ côi).

---

### `3C2-3` — Vá lỗi Fatal Error & hoàn thiện Saved SEO Display + UI Polish trong `EditorPreviewController` (`3C-O3`)

1. **Sửa lỗi Fatal Error đọc Saved SEO (`src/SEO/Runtime/EditorPreviewController.php:184` & `SeoModule.php`):**
   - Hiện dòng `184` gọi `($this->applySnapshotResolver)($postId)` với **1 tham số**, nhưng trong `SeoModule::register()` closure `$resolver` khai báo `function (int $postId, string $resource, string $field, string $task = '')` → gọi trên WordPress thật sẽ ném ngay `ArgumentCountError` (Fatal Error)!
   - **Cách sửa sạch:** Truyền seam/repository đọc canonical snapshot riêng (ví dụ `?CanonicalSeoMetaRepository $metaRepository = null` hoặc `?callable $savedSeoReader = null` nhận `(int $postId): array`) vào `EditorPreviewController::__construct()` (tham số tùy chọn ở cuối để backward-compatible) và nối từ `SeoModule::register()` bằng `$this->container->get(CanonicalSeoMetaRepository::class)`.
2. **Đặt `.myseo-for-all-metabox__saved-seo` ĐÚNG vị trí bên trong `.myseo-for-all-metabox`:**
   - Di chuyển `<section class="myseo-for-all-metabox__section myseo-for-all-metabox__saved-seo">` vào **bên trong `<div class="myseo-for-all-metabox">`** ngay **sau Local SEO và trước Trợ lý AI** (để kế thừa CSS variables và để `box.querySelector('[data-seo-field="..."]')` tìm thấy được!).
   - Gắn class `myseo-for-all-metabox__saved-item--empty` khi giá trị chưa thiết lập (`Chưa thiết lập`).
3. **Nâng cấp HTML/JS động bên trong `.myseo-ai-preview-panel` (`EditorPreviewController.php:289-341`) khớp với CSS đã khai báo:**
   - Loading: render `<p class="myseo-for-all-metabox__loading">Đang tải...</p>`.
   - Error: dùng `className = 'myseo-for-all-metabox__alert myseo-for-all-metabox__alert--error'` (bỏ `style.color = '#b32d2e'`).
   - `seo_analysis`: dùng `.myseo-for-all-metabox__findings`, `.myseo-for-all-metabox__finding`, `.myseo-for-all-metabox__finding--blocker|warning|info` (map `error -> blocker`), `.myseo-for-all-metabox__finding-meta`, `.myseo-for-all-metabox__severity`, `.myseo-for-all-metabox__field`, `.myseo-for-all-metabox__finding-message`, `.myseo-for-all-metabox__finding-fix`.
   - Compare 2 cột: thay `<table><tr><td>` bằng `<div class="myseo-for-all-metabox__compare">` chứa 2 card `.myseo-for-all-metabox__compare-card` và `.myseo-for-all-metabox__compare-card myseo-for-all-metabox__compare-card--proposed`.
   - Ownership confirmation checkbox: gắn `className = 'myseo-for-all-metabox__confirm'` cho `<label>`.
   - Nhóm nút hành động: bọc nút Copy (`className = 'button myseo-for-all-metabox__copy'`) và nút Apply (`className = 'button button-primary myseo-ai-apply'`) trong `<div class="myseo-for-all-metabox__preview-actions">` (không để 2 nút dính nhau).
   - Phản hồi Apply & cập nhật Live Saved SEO: dùng một `<div class="myseo-for-all-metabox__apply-feedback">` duy nhất (thay thế nội dung mỗi lần bấm, gắn `.myseo-for-all-metabox__alert--success` khi `res.ok && body.status === 'ok'`, ngược lại `.myseo-for-all-metabox__alert--error`); khi thành công, cập nhật ngay `[data-seo-field="<field>"] .myseo-for-all-metabox__saved-value` bằng `textContent` và gỡ class `--empty`.
   - Giữ nguyên `closest('.myseo-for-all-metabox')` và cấm tuyệt đối `.innerHTML`.

---

### `3C2-4` — Prompt Hardening trong `AiSettingsRepository::executeGatewayRequest()` (chống `invalid_response` trên runtime thật)

Cập nhật `$prompts` tại `src/AI/AiSettingsRepository.php:144` (chỉ sửa system prompt, **không** nới lỏng `GatewayAiProvider` hay `AiOutputValidator`):

1. Cả 3 task đều khẳng định: chỉ trả về **duy nhất 1 JSON object thô** (bắt đầu `{`, kết thúc `}`), **cấm bọc Markdown code fence** (```` ```json ```` / ```` ``` ````), cấm lời dẫn trước/sau JSON, không thêm key thừa, không chứa email/phone/token/secret.
2. `seo_analysis`: liệt kê tường minh đủ **11 `FINDING_CODES`** (`title_missing|title_length|meta_description_missing|meta_description_length|content_missing|heading_primary_count|heading_structure_missing|canonical_missing|image_alt_missing|internal_links_missing|product_fields_missing`), **7 `FINDING_FIELDS`** (`title|meta_description|content|canonical|images|internal_links|product`) và **3 `SEVERITIES`** (`info|warning|error`).
3. `title_meta_suggestion`: khóa exact keys `target_field`, `value`, `language`, `provenance`, plain text 1 dòng ≤ 300 ký tự, không HTML/fence.
4. `product_description_draft`: khóa exact keys `target`, `content`, `language`, `provenance`, `uses_commercial_facts` (bắt buộc `false`), không bịa dữ liệu thương mại, không HTML/fence.

---

## 3. Ma trận kiểm thử bắt buộc & Báo cáo chuẩn (bổ sung cho `tests/Unit/Increment3CTest.php` và các test liên quan)

Tạo mới **`tests/Unit/Increment3CTest.php`** (và cập nhật `Increment3ATest.php`, `IncrementGTest.php`, `RuntimeCompositionTest.php` nếu cần) bao phủ đủ:

1. **`3C-O1` FrontendSeoEmitter & SeoModule:**
   - Ownership gate matrix: active Rank Math, probe null/unknown, competing SEO plugins (Yoast/AIOSEO/SEOPress), `rank_math_mode !== 'disabled'`, `duplicate_risk !== 'none'`, `is_admin()`, REST/AJAX/cron/feed, non-publish, non-singular, product khi Woo tắt → đều **0 emission**, không sửa `$title`, không gỡ `rel_canonical`.
   - Clean ownership (`myseo_for_all`, `owned`, `duplicate_risk = none`) trên `post`/`page`/`product` publish → xuất đúng title (giữ nguyên chữ hoa `A-Z`, chữ số `0-9`, tiếng Việt UTF-8, strip HTML/control chars), `<meta name="description">` escape XSS, `<link rel="canonical">` hợp lệ (và gỡ `rel_canonical` đúng 1 lần).
   - Từ chối canonical `javascript:alert(1)`, `https://admin@example.com`, `https://user:pass@example.com`, `http://localhost/x`, `https://site.local/x` và giữ `rel_canonical` mặc định.
   - Kiểm chứng `SeoModule::register()` nối `FrontendSeoEmitter` hoạt động thật qua container (không bị `null` seams).
2. **`3C-O2` WooCommerce HPOS & ProductReader:**
   - `WooCommerceModule` gọi seam `declare_compatibility('custom_order_tables', $pluginFile, true)` trên `before_woocommerce_init` và không crash khi thiếu `FeaturesUtil`.
   - `WooCommerceProductReader`: sản phẩm `simple`, `variable`, `variation` hợp lệ, variation mồ côi (`parent_product_missing`); `description` & `short_description` HTML dài > 2.000 ký tự được project an toàn; email/API key xuất hiện sau ký tự 2.000 bị reject `privacy_policy`; local URL bị lọc bỏ.
3. **`3C-O3` Metabox Saved SEO & UI Polish:**
   - Test `renderMetabox()` khi truyền đầy đủ `SeoModule` resolver (chứng minh **không còn `ArgumentCountError`**!).
   - Assert `.myseo-for-all-metabox__saved-seo` nằm **bên trong** `<div class="myseo-for-all-metabox">`, hiển thị đúng giá trị từ `CanonicalSeoMetaRepository` (escape XSS) và `Chưa thiết lập` + `--empty` khi rỗng, có đủ `data-seo-field="title|meta_description|canonical"`.
   - Assert JS tạo đủ class `.myseo-for-all-metabox__compare`, `.myseo-for-all-metabox__compare-card`, `.myseo-for-all-metabox__compare-card--proposed`, `.myseo-for-all-metabox__preview-actions`, `.myseo-for-all-metabox__confirm`, `.myseo-for-all-metabox__apply-feedback`, `.myseo-for-all-metabox__alert--success`, `.myseo-for-all-metabox__loading`, giữ `closest('.myseo-for-all-metabox')`, không có `document.currentScript.parentElement`, không có `.innerHTML`, không có `<table>`.
4. **`3C2-4` Prompt Hardening:**
   - Assert `system` prompt gửi tới transport cho cả 3 task cấm Markdown fence và liệt kê đủ 11 `FINDING_CODES`, 7 `FINDING_FIELDS`, 3 `SEVERITIES`; `GatewayAiProvider` vẫn reject fence/enum lạ với `INVALID_RESPONSE`.

## 4. Verification & Báo cáo bắt buộc (`docs/giai-doan-3/increment-3c-r2-rp.md`)

1. `php -l` PASS cho toàn bộ file PHP mới và sửa.
2. Chạy với `--verbose` (và `run_check` receipts):
   - `php vendor/bin/phpunit --verbose tests/Unit/Increment3CTest.php`
   - `php vendor/bin/phpunit --verbose tests/Unit/Increment3ATest.php`
   - `php vendor/bin/phpunit --verbose tests/Unit/IncrementGTest.php`
   - `php vendor/bin/phpunit --verbose tests/Unit/RuntimeCompositionTest.php`
   - `php vendor/bin/phpunit --verbose --configuration phpunit.xml tests/Unit`
3. Báo cáo **`docs/giai-doan-3/increment-3c-r2-rp.md`** bắt buộc ghi rõ:
   - Exact verification commands + `VERIFY-RUN` IDs + exit code;
   - Exact **tests count, assertions count, skipped/failures/errors** cho từng focused run và full suite;
   - Exact SHA-256 checksum mọi file thay đổi;
   - Xác nhận không sửa `myseoforall.php`, không commit `.phpunit.result.cache`, và nêu giới hạn offline vs kiểm thử runtime thật.
