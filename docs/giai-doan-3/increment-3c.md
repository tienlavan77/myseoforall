# Giai Đoạn 3 — Increment 3C: Safe Frontend SEO Emission (Ownership-Gated), WooCommerce Variation/HPOS Hardening & AI Preview/Apply UI Polish

## 1. Mục tiêu và phạm vi

Increment 3C khép lại Giai đoạn 3 (Hướng 2) sau khi luồng Preview → Apply của 3A/3B đã vận hành end-to-end trên WordPress thật. Increment 3C tập trung vào 3 trụ cột độc lập nhưng bổ trợ nhau:

1. **`3C-O1` — Safe Frontend SEO Emission (`Duplicate-Zero`, Ownership-Gated):** Xuất `<title>`, `<meta name="description">` và `<link rel="canonical">` từ dữ liệu chuẩn của My SEO For All (`_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`) ra frontend `<head>` **chỉ khi không xung đột với Rank Math hoặc plugin SEO khác**.
2. **`3C-O2` — WooCommerce Variation & HPOS Hardening:** Khai báo tương thích HPOS (`custom_order_tables`) đúng chuẩn WooCommerce lifecycle, đảm bảo đọc/projection cho sản phẩm đơn (`simple`) và biến thể (`variable`/`variation`) nhất quán, an toàn với HTML dài, không truy vấn SQL bảng đơn hàng cũ và không chạm trường thương mại.
3. **`3C-O3` — CSS/UI Polish cho khu vực AI Preview & Apply trong Editor Metabox:** Thiết kế lại phần hiển thị kết quả AI trong `.myseo-ai-preview-panel` (bảng so sánh `Hiện tại` / `Đề xuất AI`, danh sách `seo_analysis`, checkbox xác nhận Rank Math ownership, nút `Sao chép đề xuất` / `Áp dụng vào My SEO` và banner kết quả Apply) để gọn, hiện đại, rõ phân cấp, responsive và giữ 100% scoped CSS + DOM-safe.

### In scope

- Service frontend emitter có cổng kiểm soát ownership (`FrontendSeoEmitter` hoặc tương đương trong `src/SEO/Runtime/`) và đăng ký hook frontend chỉ đọc trong `SeoModule::boot()`.
- Quy tắc fail-closed khi đánh giá ownership (`OwnershipRegistry`, `RankMathDetector`, `Configuration::get('rank_math_mode')`, và phát hiện plugin SEO phổ biến khác).
- Hardening WooCommerce: khai báo HPOS compatibility trên hook `before_woocommerce_init` (guarded, không fatal khi thiếu WooCommerce) và áp dụng preview projection text-only an toàn cho `WooCommerceProductReader` tương đồng với `WordPressContentReader`.
- Nâng cấp CSS + markup DOM-safe cho `.myseo-ai-preview-panel` trong `EditorPreviewController::renderMetabox()`.
- Bộ kiểm thử PHPUnit offline đầy đủ (focused + full `tests/Unit`) và báo cáo provenance rõ ràng.

### Out of scope

- Không sửa `myseoforall.php`.
- Không ghi hoặc xóa meta key nội bộ của Rank Math (`rank_math_*`); không tự tắt hoặc ghi đè hook của Rank Math.
- Không triển khai XML Sitemap, JSON-LD Schema, Open Graph / Twitter Card hoặc redirect manager trong 3C.
- Không thay đổi schema 11 khóa `myseo_ai_settings`, schema `_myseo_*` đã khóa ở 3B, hoặc transport/SSRF của Giai đoạn 2.
- Không thêm lời gọi AI/HTTP/DNS trên frontend hoặc khi mở Edit Post.

---

## 2. Guardrails bất biến

1. **Không sửa `myseoforall.php`.**
2. **Duplicate-Zero & Fail-Closed ngoài Frontend:** Nếu Rank Math đang active, hoặc trạng thái ownership cho `title_meta` là `conflict` / `unknown` / thuộc plugin khác, hoặc phát hiện plugin SEO khác quản lý `<head>` (Yoast, AIOSEO, SEOPress...) → **My SEO For All phải xuất 0 thẻ** (không đổi document title, không in `<meta name="description">`, không in `<link rel="canonical">`).
3. **Chỉ đọc (Read-Only) trên Frontend:** Tuyệt đối 0 thao tác ghi DB (`update_post_meta`, `wp_update_post`, `OptionStore::update/delete`) và 0 kết nối mạng/DNS trên mọi request frontend.
4. **Escape & Validation tuyệt đối khi xuất HTML:**
   - `<title>`: lọc qua filter chuẩn của WordPress (`pre_get_document_title` hoặc `document_title_parts`) với plain text đã loại bỏ HTML/control chars; không in thêm thẻ `<title>` thủ công trong `wp_head`.
   - `<meta name="description">`: chỉ in khi `_myseo_meta_description` khác rỗng sau normalize; escape bằng `esc_attr` / `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
   - `<link rel="canonical">`: chỉ in khi `_myseo_canonical` khác rỗng và qua kiểm tra URL công khai hợp lệ (`http`/`https`, không chứa credential `user:pass@`, không chứa ký tự điều khiển/newline); tránh trùng `rel_canonical` mặc định của WordPress chỉ khi My SEO thực sự sở hữu và thực sự có canonical hợp lệ để xuất.
5. **WooCommerce & HPOS Boundary:** Không truy vấn trực tiếp bảng `wp_posts`/`wp_postmeta` cho đơn hàng; không đọc/ghi `price`, `regular_price`, `sale_price`, `stock_quantity`, `stock_status`, `sku`, `reviews`, `orders`; giữ nguyên tính an toàn khi WooCommerce không cài đặt hoặc tắt.
6. **UI Security & CSS Scoping:** Mọi CSS phải nằm dưới `.myseo-for-all-metabox`; mọi node JS động trong `.myseo-ai-preview-panel` bắt buộc dùng `document.createElement` + `textContent` + `appendChild`, **cấm tuyệt đối `innerHTML`** cho dữ liệu động; không lộ API key, token, raw exception hay raw payload.

---

## 3. Đặc tả kỹ thuật từng Outcome

### 3.1 `3C-O1` — Frontend SEO Emitter có cổng kiểm soát Ownership

#### A. Bảng quyết định quyền xuất Frontend (`shouldEmit()`)

Trước khi thay đổi title hoặc in bất kỳ thẻ `<meta>`/`<link>` nào ngoài frontend, emitter phải kiểm tra theo thứ tự fail-closed:

| Điều kiện | Kết quả |
|---|---|
| Đang ở `is_admin()`, AJAX, REST, cron hoặc feed | **Không xuất (`false`)** |
| Không phải trang đơn hợp lệ (`post`, `page`, `product` khi Woo active) hoặc `$postId <= 0` hoặc `post_status !== 'publish'` | **Không xuất (`false`)** |
| `RankMathDetector::detect()` báo `activation.state === 'active'` hoặc `activation === 'active'` hoặc trạng thái `unknown`/`conflict` khi probe lỗi | **Không xuất (`false`)** |
| Plugin SEO cạnh tranh khác đang active (ví dụ Yoast `WPSEO_VERSION`, AIOSEO `AIOSEO_VERSION`, SEOPress `SEOPRESS_VERSION`) | **Không xuất (`false`)** |
| `Configuration::get('rank_math_mode')` không cho phép xuất độc lập khi vẫn còn rủi ro đồng sở hữu, hoặc `OwnershipRegistry::resolve()` trả về `owner !== 'myseo_for_all'` / `state !== 'owned'` / `duplicate_risk !== 'none'` | **Không xuất (`false`)** |
| **Chỉ khi:** frontend singular `publish` (`post`/`page`/`product`) **VÀ** không có Rank Math / plugin SEO khác active **VÀ** quyết định ownership xác nhận `My SEO For All` là chủ thể duy nhất an toàn (`duplicate_risk === 'none'`) | **Được phép xuất (`true`)** |

*(Lưu ý về `OwnershipRegistry`: hiện tại `RankMathModule::register()` đăng ký `OwnershipRegistry` mặc định ở trạng thái rỗng/fail-closed. Trong 3C, cần xây dựng helper/adapter thu thập snapshot quan sát tối thiểu cho frontend từ `RankMathDetector` + `Configuration` + danh sách plugin SEO đã kiểm tra, truyền vào `OwnershipRegistry::resolve()` đúng chuẩn inventory/observation để chứng minh `duplicate_risk === 'none'` trước khi xuất, không sửa lỏng quy tắc `write_allowed = false` của `OwnershipRegistry` vốn dùng cho coexistence diagnostics.)*

#### B. Hành vi xuất từng thẻ khi `shouldEmit() === true`

Đọc từ `CanonicalSeoMetaRepository::snapshot($postId)` (3 khóa `_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`):

1. **Title (`_myseo_title`):**
   - Gắn filter `pre_get_document_title` (priority `20`).
   - Nếu `_myseo_title` rỗng/null hoặc không được phép xuất → trả nguyên `$title` đầu vào (để WordPress/theme xử lý mặc định).
   - Nếu có giá trị hợp lệ → chuẩn hóa bỏ HTML tags, newline/control chars, trim, giới hạn an toàn, trả về chuỗi title sạch.
2. **Meta Description (`_myseo_meta_description`):**
   - Gắn action `wp_head` (priority `5`).
   - Chỉ in `<meta name="description" content="..." />` khi `_myseo_meta_description` khác rỗng sau normalize và escape.
   - Không in thẻ rỗng nếu bài viết chưa lưu `_myseo_meta_description`.
3. **Canonical (`_myseo_canonical`):**
   - Chỉ xử lý khi `_myseo_canonical` khác rỗng và hợp lệ (`https?://`, không có user/pass, không có control chars/whitespace).
   - Khi thực sự xuất `<link rel="canonical" href="..." />` trong `wp_head`: gỡ `rel_canonical` mặc định của WordPress (`remove_action('wp_head', 'rel_canonical')`) **chỉ trong request hiện tại sau khi đã xác nhận có canonical hợp lệ và `shouldEmit() === true`** để đảm bảo có đúng **1 thẻ canonical duy nhất**, không trùng lặp.
   - Nếu `_myseo_canonical` rỗng hoặc không hợp lệ → giữ nguyên `rel_canonical` mặc định của WordPress, không in gì thêm.
4. **Tính idempotent & không rò rỉ:**
   - Gắn tối đa 1 lần mỗi hook thông qua `HookDispatcher` (giữ deduplication).
   - Không làm thay đổi cấu trúc 4 module health trong `Plugin::health()`.

---

### 3.2 `3C-O2` — WooCommerce Variation & HPOS Hardening

1. **Khai báo tương thích WooCommerce HPOS (`src/WooCommerce/WooCommerceModule.php`):**
   - Trong `WooCommerceModule::boot()`, đăng ký named listener trên hook `before_woocommerce_init` (qua `HookDispatcher`).
   - Khi hook chạy và `class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)` cùng hằng/file plugin tồn tại (truyền plugin file path hoặc seam inject để test được offline), gọi:
     ```php
     \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $pluginFile, true);
     ```
   - Không crash/fatal khi WooCommerce chưa cài đặt, chưa bật hoặc phiên bản cũ không có `FeaturesUtil`.
   - Không sửa `myseoforall.php` (dùng `dirname(__DIR__, 2) . '/myseoforall.php'` hoặc seam constructor có default an toàn).
2. **Hardening `WooCommerceProductReader` (`src/SEO/Runtime/WooCommerceProductReader.php`):**
   - Áp dụng projection text-only an toàn cho `description` và `short_description` (cùng `seo.title`, `seo.meta_description` nếu dài) theo cùng nguyên tắc với `WordPressContentReader` ở R6:
     - Loại Gutenberg comments, shortcode, HTML tags (double-strip sau `html_entity_decode`), chuẩn hóa whitespace;
     - Kiểm tra privacy/secret **trước khi cắt** trên toàn bộ văn bản đã normalize (không cho cắt chuỗi để né privacy gate);
     - Giới hạn 2.000 code points cho `description` / `short_description` trong provider payload để sản phẩm WooCommerce có mô tả HTML dài không bị reject oan, đồng thời giữ nguyên dữ liệu gốc cho `LocalSeoAnalyzer`;
     - Loại URL local/private không hợp lệ khỏi provider payload tương tự bài viết.
   - Kiểm chứng rõ cả 3 dạng sản phẩm: `simple`, `variable` (sản phẩm cha) và `variation` (biến thể có `parent_id` hợp lệ vs `parent_id` mồ côi fail-closed `parent_product_missing`), đảm bảo giá (`decimal`), tồn kho (`stock`), `sku`, `availability` vẫn được đọc qua WooCommerce CRUD methods chuẩn và không bị thay đổi.

---

### 3.3 `3C-O3` — CSS/UI Polish & Hiển thị dữ liệu SEO đã lưu trong Editor Metabox

Hiện tại Metabox còn hai điểm gây khó quan sát cho người biên tập:
1. Chưa có khung hiển thị 3 giá trị SEO chuẩn đang lưu trong My SEO For All (`_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`), nên sau khi bấm **Áp dụng vào My SEO** người dùng không nhìn thấy trực tiếp giá trị đã vào database hay chưa.
2. Phần kết quả render động bên trong `.myseo-ai-preview-panel` (`src/SEO/Runtime/EditorPreviewController.php:297-327`) đang dùng `<table>`, `<tr>`, `<td>`, `<button>` và `<label>` thô không có class CSS, khiến bảng so sánh **Hiện tại / Đề xuất AI** và hai nút **Sao chép đề xuất** + **Áp dụng vào My SEO** dính sát nhau, thiếu khoảng cách và khó nhìn.

#### Yêu cầu thiết kế UI/CSS (chỉ scoped dưới `.myseo-for-all-metabox`):

0. **Khung "Dữ liệu SEO đang lưu (My SEO For All)" (`.myseo-for-all-metabox__saved-seo`):**
   - Đặt ngay trước khu vực **Trợ lý AI** (dưới Local SEO) cho `post`/`page` (và hiển thị mô tả/SEO tương ứng cho `product` mà không chạm trường thương mại).
   - Đọc trực tiếp từ `CanonicalSeoMetaRepository::snapshot($postId)` (hoặc snapshot seam read-only inject qua `SeoModule`, không fallback sang Rank Math để người dùng biết chính xác dữ liệu đã lưu trong namespace My SEO).
   - Hiển thị rõ 3 mục dạng danh sách/grid gọn gàng (`.myseo-for-all-metabox__saved-list`, `.myseo-for-all-metabox__saved-item`) có `data-seo-field="title|meta_description|canonical"`:
     - **SEO Title (`_myseo_title`)**: hiện giá trị đã escape hoặc trạng thái mờ `Chưa thiết lập`.
     - **Meta Description (`_myseo_meta_description`)**: hiện giá trị đã escape hoặc `Chưa thiết lập`.
     - **Canonical URL (`_myseo_canonical`)**: hiện giá trị đã escape hoặc `Chưa thiết lập`.
   - Khi người dùng bấm **Áp dụng vào My SEO** và server trả `res.ok && body.status === 'ok'`, JavaScript cập nhật ngay text của đúng dòng `[data-seo-field="..."]` bằng `textContent` (kèm highlight nhẹ/badge `Đã lưu`) để người dùng nhìn thấy tức thì mà không cần F5; khi F5 tải lại trang, giá trị từ database vẫn hiển thị khớp hoàn toàn.

1. **Khung Preview Panel (`.myseo-for-all-metabox__preview-panel`):**
   - Chỉ hiện viền/nền card rõ ràng khi có nội dung (`:not(:empty)`), nền `#f8f9fa`, viền `1px solid var(--myseo-border)`, bo góc `6px`, padding `14px`.
   - Trạng thái loading (`Đang tải...`) hiển thị gọn với chữ màu muted và khoảng cách hợp lý (`.myseo-for-all-metabox__loading`).
   - Trạng thái lỗi dùng class `.myseo-for-all-metabox__alert.myseo-for-all-metabox__alert--error` thay vì inline `style.color`.

2. **Bảng so sánh `Hiện tại` vs `Đề xuất AI` (`.myseo-for-all-metabox__compare`):**
   - Dùng layout 2 cột bằng CSS Grid (`grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px;`), tự động xếp 1 cột trên màn hình nhỏ (`@media (max-width: 782px)`).
   - Mỗi cột là một card rõ ràng (`.myseo-for-all-metabox__compare-card`):
     - Cột **Hiện tại**: nền trắng `#fff`, viền `#dcdcde`, nhãn nhỏ uppercase màu muted (`#646970`).
     - Cột **Đề xuất AI** (`.myseo-for-all-metabox__compare-card--proposed`): nền xanh rất nhạt `#f0f6fc`, viền `#72aee6` (hoặc viền trái `3px solid #2271b1`), nhãn màu `#135e96` nổi bật để người dùng phân biệt ngay giá trị cũ vs mới.
     - Nội dung đề xuất hiển thị rõ ràng, xuống dòng tự nhiên (`white-space: pre-wrap; word-break: break-word; font-size: 13.5px; line-height: 1.55;`).

3. **Danh sách phân tích `seo_analysis` (`.myseo-for-all-metabox__ai-findings`):**
   - Dùng chung hệ thống card finding như phần Local SEO (`.myseo-for-all-metabox__findings`, `.myseo-for-all-metabox__finding--blocker/warning/info` hoặc mapping `error -> blocker`), tách rõ badge mức độ ưu tiên, `<code>` tên trường, mô tả và gợi ý sửa — không còn dấu nối thô `severity: field — message — fix`.

4. **Hộp xác nhận Ownership Rank Math (`.myseo-for-all-metabox__confirm`):**
   - Khi `data.ownership_confirmation_required === true`, hiển thị hộp cảnh báo nhẹ nền `#fff8e5`, viền `#dba617`, bo góc `4px`, padding `10px 12px`, `display: flex; align-items: flex-start; gap: 8px; margin-top: 12px;` để checkbox và dòng chữ dễ đọc, không dính vào nút.

5. **Thanh nút hành động Preview/Apply (`.myseo-for-all-metabox__preview-actions`):**
   - Bọc nút **Sao chép đề xuất / Sao chép bản nháp** (`class="button myseo-for-all-metabox__copy"`) và nút **Áp dụng vào My SEO / Áp dụng mô tả sản phẩm** (`class="button button-primary myseo-ai-apply"`) vào cùng một flex container có `display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px;` — chấm dứt tình trạng hai nút dính liền nhau như `Sao chép đề xuấtÁp dụng vào My SEO`.

6. **Thông báo kết quả sau khi bấm Apply (`.myseo-for-all-metabox__apply-feedback`):**
   - Thay vì nối dồn nhiều `<p>` mỗi lần bấm, dùng một vùng feedback riêng (cập nhật lại nội dung mỗi lần bấm) với kiểu banner rõ ràng:
     - Thành công (`res.ok && body.status === 'ok'`): nền xanh `#edfaef`, viền trái `4px solid #00a32a`, chữ `#14532d` (`.myseo-for-all-metabox__alert--success`).
     - Thất bại (`!res.ok || body.status !== 'ok'`): nền đỏ nhạt `#fbeaea`, viền trái `4px solid #b32d2e`, chữ `#7a2020` (`.myseo-for-all-metabox__alert--error`).

7. **Giữ nguyên các ràng buộc DOM & JS đã kiểm chứng ở R7:**
   - Giữ nguyên `const box = document.currentScript ? document.currentScript.closest('.myseo-for-all-metabox') : null; if (!box) return;`
   - Giữ nguyên `createElement` + `textContent` + `appendChild`; tuyệt đối **không** dùng `innerHTML`.
   - Giữ nguyên payload, nonce và endpoint của `myseo_ai_preview` và `myseo_ai_apply`.

---

## 4. Acceptance Criteria (AC) của Increment 3C

### `3C-AC1` — Frontend Emission & Duplicate-Zero
- Khi Rank Math không active, không có plugin SEO khác active và ownership xác nhận `myseo_for_all` sở hữu `title_meta` (`duplicate_risk = none`):
  - `pre_get_document_title` trả đúng `_myseo_title` đã làm sạch cho `post`/`page`/`product` ở trạng thái `publish`.
  - `wp_head` xuất đúng 1 thẻ `<meta name="description" content="...">` khi có `_myseo_meta_description` và 1 thẻ `<link rel="canonical" href="...">` (đồng thời gỡ `rel_canonical` mặc định trong request đó) khi có `_myseo_canonical` hợp lệ.
- Khi Rank Math active, hoặc plugin SEO khác active, hoặc trạng thái ownership là `conflict` / `unknown`, hoặc không phải singular `publish`, hoặc đang ở `is_admin()`:
  - Xuất **0 thẻ**, không sửa `$title` và không gỡ `rel_canonical` mặc định của WordPress.
- Mọi giá trị động xuất ra HTML đều được escape an toàn chống XSS/header-injection; URL canonical chứa `javascript:`, credential hoặc ký tự điều khiển bị từ chối fail-closed.
- 0 DB writes, 0 network/DNS calls trong mọi nhánh frontend.

### `3C-AC2` — WooCommerce Variation & HPOS Hardening
- `WooCommerceModule` đăng ký khai báo `custom_order_tables` trên `before_woocommerce_init` khi `FeaturesUtil` khả dụng và không gây lỗi khi WooCommerce không tồn tại.
- `WooCommerceProductReader` xử lý an toàn mô tả HTML/Gutenberg dài (> 2.000 ký tự) cho sản phẩm, vẫn chạy privacy gate trên toàn bộ văn bản trước khi cắt, giữ nguyên Local SEO findings và giữ nguyên fail-closed với variation thiếu parent hoặc trường thương mại sai định dạng.

### `3C-AC3` — Metabox Saved SEO Display & Preview/Apply UI Polish
- Metabox hiển thị khu vực **Dữ liệu SEO đang lưu (My SEO For All)** (`.myseo-for-all-metabox__saved-seo`) với 3 dòng `data-seo-field="title|meta_description|canonical"` đọc từ `CanonicalSeoMetaRepository` (escape HTML đầy đủ, hiện `Chưa thiết lập` khi rỗng) và cập nhật live bằng `textContent` ngay sau khi Apply trả `200 ok`.
- `.myseo-ai-preview-panel` render bảng so sánh 2 cột (`.myseo-for-all-metabox__compare`, `.myseo-for-all-metabox__compare-card`, `.myseo-for-all-metabox__compare-card--proposed`), nhóm nút có khoảng cách (`.myseo-for-all-metabox__preview-actions`), hộp xác nhận ownership (`.myseo-for-all-metabox__confirm`) và phản hồi Apply theo trạng thái success/error.
- CSS hoàn toàn scoped dưới `.myseo-for-all-metabox`, không dùng `.innerHTML`, vẫn dùng `closest('.myseo-for-all-metabox')` và vẫn giữ 0 writes khi render metabox.

---

## 5. Ma trận kiểm thử bắt buộc (PHPUnit Offline)

Bổ sung bộ test focused (ví dụ `tests/Unit/Increment3CTest.php`) và cập nhật test liên quan (`Increment3ATest.php`, `RuntimeCompositionTest.php`), không xóa/giảm test cũ:

1. **Frontend ownership gate matrix:**
   - Active Rank Math → 0 title override, 0 meta, 0 canonical, không gỡ `rel_canonical`.
   - Active competing SEO plugin (Yoast/AIOSEO/SEOPress seam) → 0 emission.
   - Ownership `conflict` / `unknown` / `duplicate_risk !== 'none'` → 0 emission.
   - `is_admin() === true` hoặc `post_status !== 'publish'` (draft/private/auto-draft) hoặc không phải singular → 0 emission.
   - Clean ownership (`myseo_for_all` + `duplicate_risk = none`) trên `post`, `page`, `product` publish → xuất đủ title, description, canonical đúng escape; gỡ `rel_canonical` chỉ khi canonical hợp lệ được xuất.
2. **Frontend sanitization & security:**
   - `_myseo_title` chứa HTML/quotes/newlines → trả về plain text sạch.
   - `_myseo_meta_description` chứa `" <script>` → escape đúng trong attribute.
   - `_myseo_canonical` chứa `javascript:alert(1)` hoặc `https://user:pass@example.com` hoặc `http://localhost/x` → bị từ chối, không xuất thẻ, giữ `rel_canonical` mặc định.
   - `_myseo_*` rỗng/null → không xuất thẻ rỗng, giữ nguyên `$title` mặc định.
   - 0 DB writes và 0 network/DNS calls.
3. **WooCommerce HPOS & Variation projection:**
   - Hook `before_woocommerce_init` khai báo `custom_order_tables` với seam hợp lệ; an toàn khi `FeaturesUtil` không tồn tại.
   - Mô tả sản phẩm dài có HTML/Gutenberg được rút gọn an toàn 2.000 code points, payload accepted; nhưng nếu chứa email/API key sau ký tự 2.000 vẫn bị reject `privacy_policy`.
   - `simple`, `variable`, `variation` hợp lệ và `variation` thiếu parent (`parent_product_missing`) đều giữ đúng contract và không chạm giá/tồn kho/SKU/orders.
4. **UI Polish & Saved SEO Display regression (`Increment3ATest` / `Increment3CTest`):**
   - Assert khu vực `.myseo-for-all-metabox__saved-seo` hiển thị đúng giá trị đã lưu từ `CanonicalSeoMetaRepository` (escape XSS) và `Chưa thiết lập` khi rỗng, với `data-seo-field="title"`, `meta_description`, `canonical`.
   - Assert sự tồn tại của các class scoped mới (`.myseo-for-all-metabox__compare`, `.myseo-for-all-metabox__compare-card`, `.myseo-for-all-metabox__compare-card--proposed`, `.myseo-for-all-metabox__preview-actions`, `.myseo-for-all-metabox__confirm`, `.myseo-for-all-metabox__alert--success`).
   - Assert giữ `closest('.myseo-for-all-metabox')`, không có `document.currentScript.parentElement`, không có `.innerHTML`.
5. **Chạy verification:**
   - `php -l` cho mọi file PHP mới/sửa.
   - Focused tests (`Increment3CTest.php`, `Increment3ATest.php`, `RuntimeCompositionTest.php`) với `--verbose`.
   - Full suite `php vendor/bin/phpunit --verbose --configuration phpunit.xml tests/Unit` đạt `exit_code=0`, 0 failures, 0 errors.

---

## 6. Handoff cho SE / SL & Phân loại bằng chứng

- SL tự phân rã ticket, tên class nội bộ và thứ tự triển khai trong boundary trên.
- Không sửa `myseoforall.php`, không commit `.phpunit.result.cache`, không ghi meta key nội bộ của Rank Math.
- Sau khi triển khai, SE ghi báo cáo vào **`docs/giai-doan-3/increment-3c-rp.md`** gồm:
  1. Danh sách file thay đổi + exact SHA-256 checksum;
  2. Exact verification commands, `VERIFY-RUN` IDs, test count, assertion count, skipped/failures/errors;
  3. Phân tách rõ bằng chứng offline PHPUnit và các hạng mục Owner cần nghiệm thu trên WordPress/WooCommerce runtime thật (view-source `<head>` khi bật/tắt Rank Math, kiểm tra trang WooCommerce Features HPOS, và kiểm tra giao diện Metabox trên trình duyệt thật).
