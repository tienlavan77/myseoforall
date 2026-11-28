# Giai Đoạn 3 — Increment 3A: Hướng Dẫn Thiết Kế & Triển Khai Editor Metabox (Local SEO + Read-Only AI Preview)

> Tài liệu hướng dẫn kỹ thuật của Architecture Manager dành cho System Engineer / Sprint Leader theo `docs/giai-doan-3/plan.md` và cập nhật chính thức sau phản biện `docs/giai-doan-3-fb.md`. Tài liệu khóa ranh giới kiến trúc, hợp đồng dữ liệu và điều kiện nghiệm thu cấp outcome; SL chủ động phân rã ticket và chọn danh sách file triển khai trong boundary đã duyệt.

---

## 1. Bối cảnh, Phạm vi & Ranh giới bất biến

### 1.1. Mục tiêu của Increment 3A
Đưa năng lực chẩn đoán **Local SEO** (`ContentSeoInspector`, `ProductSeoInspector`, `LocalSeoAnalyzer`), trạng thái **Ownership / Coexistence** (`RankMathDetector` / `OwnershipRegistry`) và trợ lý **AI Preview theo yêu cầu** (`AiProvider` / `GatewayAiProvider`) lên ngay màn hình biên tập WordPress (`post`, `page`) và WooCommerce (`product`) ở chế độ **100% chỉ đọc (`0` Database Writes, `0` Retention)**.

### 1.2. Hiện trạng mã nguồn được tái sử dụng trực tiếp
- `src/Core/Plugin.php`: Đã đăng ký `AiSettingsRepository::class`, `GatewayAiProvider::class` và `AiProvider::class` vào `Container`, truyền cùng `Container` + `HookDispatcher` cho `SeoModule` và `RankMathModule`.
- `src/SEO/Runtime/SeoModule.php`: Đang đăng ký `ContentSeoInspector::class`, `ProductSeoInspector::class` và hook `add_meta_boxes -> registerDiagnosticsSurface`.
- `WordPressContentReader::readPost(int $postId)` & `WooCommerceProductReader::readProduct(int $productId)`: Trả về envelope đóng gồm `status`, `reason`, `resource_type` (`article` | `page` | `product` | `variation`), `seo_context` (`SeoContext`), `findings` và `provider_payload` (`schema_version = 'provider-payload-2'`, `payload_status = 'accepted' | 'rejected'`).
- `AiProvider::generate(array $request)`: Nhận `['schema_version' => 'myseo.ai.request.v2', 'task' => $task, 'context' => $providerPayload]` và trả về `['schema_version' => 'myseo.ai.response.v1', 'provider' => ..., 'task' => $task, 'status' => 'ok', 'output' => ...]`, đã hậu kiểm qua `AiOutputValidator`.

### 1.3. Guardrails cấp Owner bắt buộc giữ nguyên
1. **Tuyệt đối không chỉnh sửa `myseoforall.php`.**
2. **Zero-Write tuyệt đối trong 3A:** Không gọi `wp_update_post`, `update_post_meta`, `delete_post_meta`, WooCommerce CRUD `save()`, `set_transient`, hay `OptionStore::update/delete/compareAndSwap` ở bất kỳ nhánh nào của 3A.
3. **Zero-Network khi mở Editor:** Khi mở trang biên tập và render Metabox, số lần gọi DNS và HTTP outbound bắt buộc bằng **`0`**. Mạng chỉ được gọi khi người biên tập chủ động bấm nút yêu cầu AI qua AJAX.
4. **Zero-Retention & Redaction:** Không lưu request payload hay AI output vào DB/log/property đối tượng; không lộ API key, URL nội bộ của gateway, ciphertext, `$e->getMessage()`, `$e->getPrevious()` hay `$e->getTrace()` về trình duyệt.

---

## 2. Đặc tả Kỹ thuật theo từng Outcome (`3A-O1` -> `3A-O4`)

### `3A-O1` — Tách bạch Cổng Đăng ký Metabox & Cổng Render (`post`, `page`, `product`)

Khóa cứng **hai cổng độc lập** để phù hợp đúng cơ chế WordPress gọi `add_meta_boxes($postType, $post)` mà không biến quyền trên một đối tượng thành điều kiện đăng ký toàn cục:

1. **Cổng 1 — Đăng ký Metabox theo Post Type (`Registration Gate`):**
   - Chỉ chấp nhận danh sách post type hợp lệ của Metabox 3A:
     - `'post'`, `'page'`: luôn được phép đăng ký.
     - `'product'`: chỉ được phép đăng ký khi WooCommerce khả dụng (`$wooAvailability` callback trả `true` hoặc `function_exists('wc_get_product')` khi không truyền seam). Khi WooCommerce tắt, không đăng ký `'product'` và không báo lỗi.
     - `'product_variation'` và mọi post type khác: không đăng ký metabox UI trong 3A (riêng hành vi chẩn đoán tương thích ngược của `SeoModule::registerDiagnosticsSurface()` giữ nguyên chữ ký và các bài test Increment E hiện hữu).
   - Ở Cổng 1 (khi đăng ký `add_meta_box` qua WordPress API hoặc seam đăng ký metabox trong test):
     - ID: `'myseo-for-all-editor-metabox'`
     - Tiêu đề: `'My SEO For All — Trợ lý SEO & AI'`
     - Screen: đúng `$postType` hợp lệ; Context: `'normal'`; Priority: `'high'`.
     - **Chưa cần đọc Inspector nếu chưa có `$post` hợp lệ** (ví dụ khi WordPress truyền `$post = null` hoặc bài mới `auto-draft` chưa có ID nguyên dương ở thời điểm đăng ký hook).
2. **Cổng 2 — Kiểm tra tại thời điểm Render Callback (`Render Gate`, Fail-Closed):**
   - Callback render nhận đối tượng `$post` từ WordPress (hoặc fixture test) và bắt buộc kiểm tra tuần tự trước khi gọi bất kỳ Inspector/Reader nào:
     1. `$post` là object có thuộc tính `ID` nguyên dương chặt chẽ (`> 0`, từ chối `0`, số âm, chuỗi bẩn như `'12abc'`, hoặc bài chưa lưu ID) $\rightarrow$ nếu trượt, chỉ render thông báo tĩnh hướng dẫn lưu bản nháp trước khi phân tích và **gọi Inspector `0` lần**.
     2. `post_type` của `$post` khớp tập hỗ trợ (`'post'`, `'page'`, hoặc `'product'` khi WooCommerce khả dụng) $\rightarrow$ nếu sai, dừng ngay (`0` HTML nội dung nhạy cảm, `0` lần gọi Inspector).
     3. Kiểm tra quyền `edit_post` trên đúng `$postId` (`checkCapability('edit_post', $postId) === true`) $\rightarrow$ nếu không đủ quyền, dừng ngay fail-closed (**render rỗng `''` và gọi Inspector `0` lần**).

---

### `3A-O2` — Hợp đồng Hiển thị Metabox, Nguồn chuẩn `current` & Ownership

Mọi dữ liệu hiển thị trong Metabox và trả về ở bảng so sánh **chỉ được lấy từ inspection envelope (`seo_context` / `provider_payload`) và các service chẩn đoán đã đăng ký**, tuyệt đối không tự gọi `get_post_meta()`, `wc_get_product()` thô hay đọc nguồn ngoài allowlist:

1. **Nguồn chuẩn bất biến cho Giá trị Hiện tại (`current`) theo từng Resource Type:**
   - Trích xuất duy nhất từ `$payload = $inspection['provider_payload']` (hoặc `$context = $inspection['seo_context']->toArray()` khi chỉ render local), quy đổi mọi trường vắng mặt / `null` / không phải `string` về **`null`** (trên JSON) hoặc nhãn tĩnh *"Chưa có dữ liệu"* (trên UI), không bao giờ suy đoán từ nguồn ngoài:
     - Với `resource_type === 'article' | 'page'`:
       - `current.title`: `$payload['seo']['title'] ?? $payload['title'] ?? null` (nếu chuỗi rỗng `''` thì quy về `null`).
       - `current.meta_description`: `$payload['seo']['meta_description'] ?? $payload['meta_description'] ?? null` (chuỗi rỗng quy về `null`).
     - Với `resource_type === 'product'`:
       - `current.title`: `$payload['seo']['title'] ?? $payload['product_name'] ?? null` (chuỗi rỗng quy về `null`).
       - `current.meta_description`: `$payload['seo']['meta_description'] ?? null` (lưu ý `WooCommerceProductReader` chỉ đặt `meta_description` trong nhánh `seo`; khi vắng mặt trả `null`, không tự lấy `short_description` gán bừa làm `meta_description`).
       - `current.description` (chỉ có mặt cho `product_description_draft`): `$payload['description'] ?? null` (chuỗi rỗng quy về `null`), cắt hiển thị tối đa `2000` ký tự UTF-8 an toàn nếu cần, không bao giờ đính kèm giá, tồn kho hay SKU thô vào khung so sánh văn bản.
2. **Hợp đồng Hiển thị Ownership (Chỉ hiển thị, không cấp quyền ghi hay đổi Payload):**
   - Đọc an toàn qua `RankMathDetector::detect()` và `OwnershipRegistry::resolve()` bọc trong `try/catch (\Throwable)`:
     - Ownership chỉ là thông tin hiển thị trạng thái chung sống trên UI (`title_meta.owner`, `title_meta.state`, và trạng thái kích hoạt của Rank Math), **không bao giờ bật `write_allowed`** và **không thay đổi `provider_payload`** (vì `seoOverrideResolver` của Reader đã xử lý ở tầng đọc).
3. **Ba khối hiển thị của Metabox (Escape HTML 100%):**
   - **Khối 1 — Ownership Banner:** Báo rõ chế độ chỉ đọc an toàn và trạng thái Rank Math hiện tại.
   - **Khối 2 — Chẩn đoán Local SEO (`LocalSeoAnalyzer`):** Hiển thị bộ đếm và danh sách `findings` theo đúng 3 mức `'blocker'` (đỏ), `'warning'` (vàng), `'info'` (xanh) kèm `field`, `message`, `suggested_fix`; vẫn chạy đầy đủ khi AI tắt.
   - **Khối 3 — Trợ lý AI & Khung Preview:**
     - Chỉ mở nút gọi AI khi `AiSettingsRepository` sẵn sàng (`load()['status'] === 'ok'`, `ai_enabled === true`, `base_url !== ''`, `default_model !== ''`, `credential_status` thuộc `['configured', 'environment']`) **và** `payload_status === 'accepted'`.
     - Hiển thị rõ **Allowlist Disclosure** (danh sách tên trường từ `provider_payload['provenance']`).
     - Render các nút `type="button"` (`seo_analysis`, `title_meta_suggestion`, và `product_description_draft` chỉ cho `product`) gửi `fetch()` POST tới `admin-ajax.php`, cập nhật DOM bằng `createElement` + `textContent` (**cấm tuyệt đối `innerHTML` với dữ liệu động**).

---

### `3A-O3` — Thứ tự Gate Bất biến & Hợp đồng Đóng cho AJAX `wp_ajax_myseo_ai_preview`

1. **Đăng ký Hook:** Chỉ đăng ký `wp_ajax_myseo_ai_preview` (cấm `wp_ajax_nopriv_...`).
2. **Thứ tự Gate Bất biến (Trượt ở bước nào dừng ngay ở bước đó — bảo toàn `0` cuộc gọi xuống tầng sau):**
   - **Gate 1 — Request & HTTP Method:** WordPress handler yêu cầu `POST`; `handlePreviewRequest(mixed $request)` yêu cầu `is_array($request)`. Trượt $\rightarrow$ dừng ngay (`0` capability/nonce/reader/provider call), trả HTTP `400`, `code = 'malformed_request'`.
   - **Gate 2 — Parse `post_id` nguyên dương:** `post_id` phải là số nguyên dương chặt chẽ (`> 0`). Trượt $\rightarrow$ dừng ngay (`0` reader/provider call), trả HTTP `400`, `code = 'invalid_post_id'`.
   - **Gate 3 — Capability (`edit_post`, `$postId`):** Trượt $\rightarrow$ dừng ngay (`0` reader/provider call), trả HTTP `403`, `code = 'forbidden'`.
   - **Gate 4 — Nonce (`myseo_ai_preview`):** Trượt $\rightarrow$ dừng ngay (`0` reader/provider call), trả HTTP `403`, `code = 'invalid_nonce'`.
   - **Gate 5 — Task Syntax & Allowlist:**
     - Nếu thiếu `task` hoặc `!is_string($request['task'])` hoặc `$request['task'] === ''` $\rightarrow$ dừng ngay (`0` reader/provider call), trả HTTP `400`, `code = 'malformed_request'`.
     - Nếu `task` không thuộc `['seo_analysis', 'title_meta_suggestion', 'product_description_draft']` $\rightarrow$ dừng ngay (`0` reader/provider call), trả HTTP `400`, `code = 'unsupported_task'`.
   - **Gate 6 — Xác định Loại Đối tượng & Khớp Task trước khi đọc sai Reader:**
     - Xác định post type của `$postId` qua port đọc nhẹ (`postTypeResolver($postId)` dùng `get_post_type($postId)` trong WordPress hoặc seam trong test):
       - Không tồn tại (`false`/`null`/`''`) $\rightarrow$ dừng ngay (`0` inspector/provider call), trả HTTP `404`, `code = 'not_found'`.
       - Không thuộc `['post', 'page', 'product']`, hoặc là `'product'` nhưng WooCommerce không khả dụng $\rightarrow$ dừng ngay (`0` inspector/provider call), trả HTTP `400`, `code = 'unsupported_resource'`.
       - Nếu `task === 'product_description_draft'` mà post type là `'post'` hoặc `'page'` $\rightarrow$ **dừng ngay trước khi gọi Inspector/Reader/Provider**, trả HTTP `400`, `code = 'unsupported_task_for_resource'`.
   - **Gate 7 — Đọc Inspector đúng loại & Kiểm tra `payload_status`:**
     - Chỉ gọi đúng 1 Inspector tương ứng (`ContentSeoInspector` cho `post`/`page`, `ProductSeoInspector` cho `product`).
     - Nếu `status === 'not_found'` $\rightarrow$ HTTP `404`, `code = 'not_found'`; nếu `status !== 'ok'` $\rightarrow$ HTTP `400`, `code = 'unsupported_resource'`.
     - Nếu `payload_status !== 'accepted'` hoặc `!is_array($provider_payload)` $\rightarrow$ **dừng ngay trước khi gọi `AiProvider::generate()`**, trả HTTP `422`, `code = 'payload_rejected'`.
   - **Gate 8 — Thực thi `AiProvider::generate()`:** Chỉ thực thi khi vượt qua đủ Gate 1..7.

3. **Schema JSON Đóng cho Response Thành công (`HTTP 200`):**
   Chỉ gồm đúng **7 khóa cố định**:
   ```json
   {
     "status": "ok",
     "post_id": 123,
     "resource_type": "article",
     "task": "title_meta_suggestion",
     "current": { "title": "...", "meta_description": null },
     "proposal": { "target_field": "title", "value": "...", "language": "vi", "provenance": "wordpress" },
     "read_only": true
   }
   ```
   *(Với `product`, `current` có đúng 3 khóa `title`, `meta_description`, `description`; với `article`/`page`, `current` có đúng 2 khóa `title`, `meta_description`).*

4. **Schema JSON Đóng cho Mọi Response Lỗi (Tuyệt đối không kèm `post_id`, `resource_type`, `task`, `trace` hay raw message):**
   Mọi nhánh lỗi (cả lỗi Gate 1..7 lẫn lỗi từ `AiProviderException`) chỉ trả đúng **3 khóa cố định** ở body JSON (kèm `http_status` ở envelope nội bộ của `handlePreviewRequest` để WordPress wrapper truyền vào `wp_send_json(..., $httpStatus)`):
   ```json
   {
     "status": "error",
     "code": "rate_limited",
     "message": "AI Gateway đang giới hạn tần suất yêu cầu; vui lòng thử lại sau ít phút."
   }
   ```

5. **Bảng Ánh xạ Độc lập cho Đủ 8 Category của `AiProviderException`:**

| `AiProviderException::category()` | HTTP Status | `code` ổn định trong JSON | `message` tiếng Việt tĩnh (độc lập từng mã) |
|---|---:|---|---|
| `malformed_request` | `400` | `malformed_request` | Yêu cầu gửi tới trợ lý AI không đúng định dạng hợp lệ. |
| `unsupported_task` | `400` | `unsupported_task` | Tác vụ AI được yêu cầu không nằm trong danh mục hỗ trợ. |
| `invalid_response` | `502` | `invalid_response` | Phản hồi từ AI Gateway không đạt chuẩn kiểm định an toàn nên đã bị từ chối. |
| `provider_disabled` | `409` | `provider_disabled` | Trợ lý AI đang tắt hoặc chưa cấu hình đầy đủ trong trang Cài đặt. |
| `authentication_failed` | `502` | `authentication_failed` | Xác thực với AI Gateway thất bại; vui lòng kiểm tra lại API key. |
| `gateway_policy_rejected` | `502` | `gateway_policy_rejected` | Địa chỉ AI Gateway bị từ chối bởi chính sách bảo mật mạng. |
| `rate_limited` | `429` | `rate_limited` | AI Gateway đang giới hạn tần suất yêu cầu; vui lòng thử lại sau ít phút. |
| `transport_failed` | `502` | `transport_failed` | Không thể kết nối tới AI Gateway hoặc yêu cầu đã hết thời gian chờ. |

---

### `3A-O4` — Zero-Write, Zero-Retention & Phạm vi Bằng chứng Kiểm thử Offline

1. Không lưu đệm payload/output trên thuộc tính của bất kỳ service/controller nào sau khi kết thúc hàm; giữ nguyên 100% cấu trúc `Plugin::health()` (4 modules + 4 khóa AI đã redact).
2. **Phân định minh bạch giá trị của bằng chứng kiểm thử:**
   - Bộ kiểm thử PHPUnit offline chứng minh tính đúng đắn của orchestration, thứ tự gate bảo mật, zero-write trong seam, chống XSS và redaction của mã lỗi.
   - Kết quả PHPUnit offline **không thay thế** bằng chứng chạy hook thực tế trên WordPress/WooCommerce runtime, hành vi mạng/TLS/DNS thật tới gateway production hay chính sách lưu trữ (retention) phía upstream gateway — bước kiểm thử thực tế đó thuộc cổng nghiệm thu trên WordPress thật của Project Owner trước khi mở Increment 3B.

---

## 3. Ma trận Kiểm thử PHPUnit bắt buộc (`tests/Unit/Increment3ATest.php`)

1. **`AC1` — Tách Cổng Đăng ký vs Cổng Render & Zero-Network:**
   - Cổng đăng ký mở đúng cho `post`, `page`, và `product` (chỉ khi WooCommerce bật); không gọi Inspector khi đăng ký theo post type hoặc khi `$post` chưa có ID.
   - Cổng render chặn fail-closed với `0` lần gọi Inspector khi `$post` chưa lưu ID (`0`/thiếu ID), sai `post_type` hoặc trượt `edit_post($postId)`.
   - Khi hợp lệ: render đúng `blocker`/`warning`/`info` của `LocalSeoAnalyzer`, trạng thái Ownership chỉ đọc, trạng thái AI tắt/bật và đảm bảo `0` lần gọi `AiProvider::generate()` / `0` DNS / `0` HTTP.
2. **`AC2` — Thứ tự Gate Bất biến trên AJAX Preview:**
   - Kiểm chứng từng lỗi Gate 1..6 (`malformed_request`, `invalid_post_id`, `forbidden`, `invalid_nonce`, `unsupported_task`, `not_found`, `unsupported_resource`, `unsupported_task_for_resource` khi gọi `product_description_draft` trên `post`/`page`) dừng ngay với **`0` lần gọi Inspector/Reader phía sau và `0` lần gọi `AiProvider`**.
   - Khi Gate 7 gặp `payload_status = 'rejected'`, dừng ngay với HTTP `422` (`payload_rejected`) và **`0` lần gọi `AiProvider`**.
3. **`AC3` — Hợp đồng `current` / `proposal` cho 3 Tasks & Chống XSS:**
   - Kiểm chứng đủ 3 tasks trên `article`, `page`, `product`, bao gồm cả trường hợp trường SEO hiện tại vắng mặt/`null` trả đúng `null` mà không đọc lén nguồn ngoài allowlist.
   - Payload `<script>` trong dữ liệu bài viết/finding bị escape hoàn toàn trên HTML; JS inline không dùng `innerHTML` cho dữ liệu động.
4. **`AC4` — Kiểm chứng Độc lập Đủ 8 Category Lỗi & Schema Lỗi 3 Khóa:**
   - Test riêng từng dòng trong cả **8 category** của `AiProviderException` (tách biệt rõ `malformed_request` và `unsupported_task`), xác nhận đúng `http_status`, `code`, `message` tĩnh, chỉ có đúng 3 khóa `['status', 'code', 'message']` và không rò rỉ marker từ message/previous/trace nội bộ.
5. **`AC5` — Zero-Write, Giới hạn Bằng chứng Offline & Hồi quy:**
   - Spy `OptionStore` (`writes === 0`, `deletes === 0`, `cas === 0`) trên toàn bộ nhánh render và preview; pass cả `tests/Unit/Increment3ATest.php` lẫn full `tests/Unit` (`exit code 0`); không chạm `myseoforall.php`; báo cáo ghi rõ giới hạn kiểm thử offline so với nghiệm thu WordPress/gateway thật.
