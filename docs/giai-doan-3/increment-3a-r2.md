# Giai Đoạn 3 — Increment 3A (Vòng r2): Hướng Dẫn Chỉnh Sửa & Chốt Nghiệm Thu

> Tài liệu phản hồi nghiệm thu của Architecture Manager sau khi đọc `docs/giai-doan-3/increment-3a-rp.md` (`sha256:9c24d7afd772b44927a9753b173e2ab4a05c64896e628cd0ee8c759c5f6aa473`) và kiểm tra trực tiếp `src/SEO/Runtime/EditorPreviewController.php` (`sha256:8b53595b4515d846e2f1c8ed8aee0c8f2b08d104f0e7c1701c2383e3e64048b3`), `src/SEO/Runtime/SeoModule.php` (`sha256:4245cccec6e61b96b55bb42483c7b22723b82cab71b450983a78595478b305f2`) đối chiếu với hợp đồng hiệu lực `docs/giai-doan-3/increment-3a.md` (`sha256:95495b3ad54fe6b779ea6796145a3b242372954f4e79df8318b2d870219e61f8`).

---

## 1. Kết luận Đánh giá Vòng r1: **CHƯA ĐẠT NGHIỆM THU (`NOT ACCEPTED` — Cần hoàn tất r2)**

Bản r1 được triển khai dựa trên hash cũ của `increment-3a.md` (`91c32512...`), chưa áp dụng các điều khoản đã khóa sau phản biện `docs/giai-doan-3-fb.md` (`95495b3a...`), chưa có file `tests/Unit/Increment3ATest.php`, và đặc biệt phần giao diện Metabox trong `EditorPreviewController::renderMetabox()` hiện **mới là khung tĩnh chưa gọi AJAX thật** (nút bấm chỉ gán chữ `"Đang chờ yêu cầu xem trước."` chứ chưa gửi `fetch()`, chưa tạo nonce, chưa hiển thị Ownership, bộ đếm Local SEO hay khung so sánh Preview).

Dưới đây là **6 hạng mục bắt buộc (`3A2-1` -> `3A2-6`)** cần sửa dứt điểm trong vòng r2.

---

## 2. Sáu Hạng mục Bắt buộc Chỉnh sửa ở Vòng r2

### `3A2-1` [Blocker UI / Functional] — Hoàn thiện Metabox & Luồng Client `fetch()` thật trong `EditorPreviewController::renderMetabox()`

Trong `src/SEO/Runtime/EditorPreviewController.php` (dòng 77–113), hiện tại chưa dùng được trên WordPress thật vì thiếu toàn bộ kết nối AJAX và thông tin bắt buộc của `3A-O1` / `3A-O2`:

1. **Xử lý Cổng Render (`3A-O1`):**
   - Nếu `!is_object($post)`: dừng ngay (`return;`, `0` lần gọi Inspector).
   - Nếu `!isset($post->ID)` hoặc `parsePositiveId($post->ID) === null` (ví dụ bài mới `post-new.php` / `auto-draft` có `ID = 0`): chỉ render thông báo tĩnh `<p>Hãy lưu bản nháp trước khi chạy phân tích SEO & AI.</p>` rồi `return;` (**gọi Inspector `0` lần**).
   - Kiểm tra `$post->post_type` thuộc tập hỗ trợ (`post`, `page`, hoặc `product` khi WooCommerce khả dụng) và kiểm tra `$this->canEdit($postId)` $\rightarrow$ nếu trượt bất kỳ điều kiện nào, `return;` ngay lập tức (**render rỗng `''` và gọi Inspector `0` lần**).
   - Khi gọi `inspect()` trả về `$inspection['status'] !== 'ok'`, cũng phải hiển thị thông báo lỗi đọc an toàn (hiện dòng 84 chỉ kiểm tra `$inspection === null` nên khi reader trả `['status' => 'not_found']` vẫn lọt xuống dưới báo nhầm *"Không phát hiện vấn đề SEO cục bộ nào"*!).
2. **Hiển thị đủ 3 khối chuẩn `3A-O2`:**
   - **Khối 1 — Ownership / Coexistence thực tế:** Truyền callback hoặc đọc an toàn từ `RankMathDetector` + `OwnershipRegistry` (bọc `try/catch (\Throwable)`). Khi Rank Math đang active thì hiện rõ cảnh báo đang hoạt động thụ động; khi không có plugin khác sở hữu thì hiện rõ chế độ xem trước Read-Only kèm `owner` / `state` của `title_meta`.
   - **Khối 2 — Bộ đếm + Danh sách Local SEO:** Đếm và hiển thị rõ 3 huy hiệu tổng hợp (`blocker`, `warning`, `info`) trước danh sách chi tiết từng finding.
   - **Khối 3 — Allowlist Disclosure, Nonce, Link Cài đặt & `fetch()` Preview thật:**
     - Khi `!$ready`: hiển thị thông báo kèm thẻ `<a href="...">` trỏ tới `admin_url('options-general.php?page=myseo-for-all')` (có fallback chuỗi tĩnh khi test offline).
     - Khi `$ready && $payload_status === 'accepted'`:
       - Liệt kê tên các trường thực tế sẽ gửi đi từ `$inspection['provider_payload']['provenance']` (chỉ in tên `field`, đã escape).
       - Tạo AJAX endpoint URL (`admin_url('admin-ajax.php')` hoặc fallback `'admin-ajax.php'`) và nonce (`wp_create_nonce('myseo_ai_preview')` hoặc seam/chuỗi rỗng khi offline), gắn vào `data-ajax-url`, `data-nonce`, `data-post-id` trên container của metabox.
       - Viết đầy đủ JS inline gửi `fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: new URLSearchParams({ action: 'myseo_ai_preview', post_id, _ajax_nonce, task }) })`.
       - Khi nhận JSON phản hồi: dựng giao diện bằng **`document.createElement` + `textContent`** (**tuyệt đối không xuất hiện `.innerHTML` trong script**):
         - Nếu `data.status !== 'ok'`: hiển thị hộp lỗi màu đỏ với `data.message`.
         - Nếu `data.status === 'ok'`:
           - Với `seo_analysis`: render danh sách `data.proposal.findings` (`severity` thuộc `error | warning | info`, `field`, `message`, `suggested_fix`).
           - Với `title_meta_suggestion`: render bảng/khung 2 cột **Hiện tại** (`data.current[target_field]` hoặc *"Chưa có dữ liệu"*) vs **Đề xuất AI** (`data.proposal.value`) + nút **Sao chép đề xuất** (`navigator.clipboard.writeText`).
           - Với `product_description_draft`: render khung so sánh **Mô tả hiện tại** (`data.current.description` hoặc *"Chưa có dữ liệu"*) vs **Bản nháp AI** (`data.proposal.content`) + nút **Sao chép bản nháp**.

---

### `3A2-2` [Blocker Gate Separation] — Sửa Tách Cổng Đăng ký Metabox trong `SeoModule.php` (`3A-O1`)

Trong `src/SEO/Runtime/SeoModule.php` (dòng 159–195):
- Dòng 161–179 hiện đang chặn sớm nếu `$post === null`, `$post->ID <= 0` hoặc `!current_user_can('edit_post', $postId)` **ngay trước khi gọi `add_meta_box()`**. Trong WordPress, hook `add_meta_boxes` đăng ký theo `$postType` (và trên màn hình tạo mới `post-new.php` hoặc một số luồng admin, `$post` chưa có ID đã lưu tại thời điểm đăng ký), khiến metabox biến mất hoàn toàn.
- **Cách sửa chuẩn `3A-O1` mà vẫn giữ 100% tương thích ngược với test Increment E:**
  1. Kiểm tra `$postType` là chuỗi hợp lệ thuộc `['post', 'page']` hoặc (`'product'` khi WooCommerce khả dụng).
  2. **Nhánh đăng ký Metabox UI** (khi có `function_exists('add_meta_box')` hoặc seam `$metaBoxRegistrar` được truyền cho test 3A, và `$this->container->has(EditorPreviewController::class)`):
     - Nếu có truyền kèm `$post` object và `isset($post->post_type)` mà `$post->post_type !== $postType` thì dừng.
     - Đăng ký ngay `add_meta_box('myseo-for-all-editor-metabox', ...)` theo `$postType` hợp lệ **mà không gọi Inspector tại thời điểm đăng ký**; toàn bộ kiểm tra ID nguyên dương và `edit_post($postId)` do Cổng 2 (`EditorPreviewController::renderMetabox`) đảm nhiệm khi WordPress thực sự render hộp đó.
  3. **Nhánh fallback tương thích ngược Increment E** (khi chạy offline không có `add_meta_box` và không truyền `$metaBoxRegistrar`): giữ nguyên kiểm tra `$post->ID > 0`, `edit_post($postId)` và gọi `ContentSeoInspector` / `ProductSeoInspector` (kể cả `'product_variation'` như code cũ của Increment E ở dòng 166 cũ — lưu ý ở r1 bạn đã vô tình xóa `'product_variation'` khỏi nhánh fallback dòng 181 làm lệch hành vi cũ của Increment E!).

---

### `3A2-3` [Blocker Gate Ordering] — Sửa Thứ tự Gate 5 & Gate 6 trong `EditorPreviewController::handlePreviewRequest()` (`3A-O3`)

Trong `EditorPreviewController.php` (dòng 42–53), hiện có **3 lỗi sai hợp đồng Gate `3A-O3`**:
1. **Gate 1 & Gate 5 phân loại sai `malformed_request`:**
   - Dòng 42 đang trả `'invalid_request'` thay vì `'malformed_request'` theo `3A-O3` Gate 1.
   - Dòng 48 gộp chung `!is_string($task) || $task === ''` vào `'unsupported_task'`. Theo Gate 5 của `increment-3a.md`: khi thiếu `task`, `!is_string($task)` hoặc `$task === ''` phải trả `400`, `code = 'malformed_request'`; chỉ khi `task` là chuỗi khác rỗng nhưng nằm ngoài 3 task cho phép mới trả `400`, `code = 'unsupported_task'`.
2. **Gate 6 đang gọi thẳng Inspector trước khi kiểm tra tương thích `task ↔ post_type`!**
   - Dòng 49 gọi `$this->inspect($postId)` (bên trong gọi luôn `contentInspector->inspect($postId)`) **trước** dòng 52 kiểm tra `$task === 'product_description_draft' && $resourceType !== 'product'`. Điều này vi phạm trực tiếp yêu cầu bắt buộc: *"Nếu `task === 'product_description_draft'` mà post type là `'post'` hoặc `'page'` $\rightarrow$ dừng ngay trước khi gọi Inspector/Reader/Provider (`0` inspector call)"*.
   - **Cách sửa:** Tách bước lấy `$postType` (`resolvePostType($postId)`) ra trước:
     1. `$postType === null || $postType === false || $postType === ''` $\rightarrow$ `404`, `'not_found'` (`0` inspector call).
     2. `$postType` ngoài `['post', 'page', 'product']` hoặc (`'product'` khi WooCommerce tắt) $\rightarrow$ `400`, `'unsupported_resource'` (`0` inspector call).
     3. `$task === 'product_description_draft' && $postType !== 'product'` $\rightarrow$ `400`, `'unsupported_task_for_resource'` (`0` inspector call).
     4. Vượt qua đủ 3 kiểm tra trên mới gọi đúng Inspector tương ứng và kiểm tra `$inspection['status']` (`'not_found'` $\rightarrow$ `404 not_found`; `!== 'ok'` $\rightarrow$ `400 unsupported_resource`) rồi mới kiểm tra `payload_status === 'accepted'`.

---

### `3A2-4` [Blocker Response Schema] — Sửa Nguồn chuẩn `current` & Schema Lỗi Đóng 3 Khóa (`3A-O2` & `3A-O3`)

1. **`currentValues()` đang sai nguồn và sai kiểu dữ liệu (`EditorPreviewController.php` dòng 56–58, 151–154):**
   - Hiện code đọc từ `$seo_context`, ép `''` thay vì `null` khi vắng mặt, bỏ qua `seo.title` / `seo.meta_description` từ Rank Math override trên `product`, và **thiếu hẳn khóa `description` cho `product`**.
   - **Sửa đúng theo `3A-O2` & `3A-O3`:** Dựng `current` từ `$payload = $inspection['provider_payload']` (chuỗi rỗng hoặc vắng mặt quy về **`null`**):
     - Với `article` / `page` (đúng 2 khóa):
       - `'title' => nonEmptyStringOrNull($payload['seo']['title'] ?? $payload['title'] ?? null)`
       - `'meta_description' => nonEmptyStringOrNull($payload['seo']['meta_description'] ?? $payload['meta_description'] ?? null)`
     - Với `product` (đúng 3 khóa):
       - `'title' => nonEmptyStringOrNull($payload['seo']['title'] ?? $payload['product_name'] ?? null)`
       - `'meta_description' => nonEmptyStringOrNull($payload['seo']['meta_description'] ?? null)` *(tuyệt đối không lấy `short_description` thế vào)*
       - `'description' => boundedUtf8OrNull($payload['description'] ?? null, 2000)`
2. **Response lỗi đang thừa khóa `http_status` và `read_only` trong JSON gửi đi (dòng 73 & 176):**
   - Dòng 176 trả `['status' => 'error', 'code' => $code, 'message' => $message, 'http_status' => $status, 'read_only' => true]`, và dòng 73 truyền nguyên `$result` vào `wp_send_json($result, ...)` làm lọt 5 khóa thay vì **đúng 3 khóa cố định `['status', 'code', 'message']`** đã khóa ở Mục 2 (`3A-O3.4`) và `AC4`!
   - **Cách sửa:** Hoặc `handlePreviewRequest()` trả `['http_status' => int, 'body' => array]` rõ ràng cho cả thành công/lỗi, hoặc nếu giữ `http_status` ở mức nội bộ thì trước khi đưa ra JSON body (và trong assertion schema body) chỉ xuất đúng `['status' => 'error', 'code' => $code, 'message' => $message]` (3 khóa duy nhất cho lỗi) và đúng 7 khóa `['status', 'post_id', 'resource_type', 'task', 'current', 'proposal', 'read_only']` cho thành công.
   - Kiểm tra lại thông điệp của `providerError()` khớp bảng 8 dòng riêng biệt trong `increment-3a.md` (`malformed_request` khác câu chữ với `unsupported_task`).

---

### `3A2-5` [Blocker Verification] — Viết Đầy đủ `tests/Unit/Increment3ATest.php` (`AC1` -> `AC5`)

Bắt buộc tạo `tests/Unit/Increment3ATest.php` bao phủ toàn bộ 5 nhóm tiêu chí trong `docs/giai-doan-3/increment-3a.md`:
- `AC1`: Tách Cổng đăng ký (`post`, `page`, `product` theo WooCommerce, `0` inspector call khi đăng ký) vs Cổng render (bài chưa có ID, sai post type, sai `edit_post` đều `0` inspector call; hợp lệ thì render đủ Ownership + bộ đếm `blocker/warning/info` + link cài đặt khi AI tắt / nút & disclosure khi AI bật, `0` AI/DNS/HTTP call).
- `AC2`: Từng lỗi Gate 1..6 (`malformed_request` khi method khác POST hoặc `task` rỗng/sai kiểu, `invalid_post_id`, `forbidden`, `invalid_nonce`, `unsupported_task`, `not_found`, `unsupported_resource`, `unsupported_task_for_resource` cho `product_description_draft` trên `post`/`page`) đảm bảo **`0` lần gọi Inspector và `0` lần gọi `AiProvider`**; Gate 7 `payload_rejected` (`422`) đảm bảo **`0` lần gọi `AiProvider`**.
- `AC3`: Chạy đủ 3 tasks trên `article`, `page`, `product`; kiểm tra `current` trả đúng `null` khi trường thiếu (và `product` có đúng 3 khóa `title`, `meta_description`, `description`, không gán nhầm `short_description`); kiểm tra escape `<script>` trên HTML metabox và khẳng định script không chứa `.innerHTML`.
- `AC4`: Loop kiểm tra độc lập đủ **8 category** của `AiProviderException`, đúng `http_status`, `code`, `message` riêng biệt, JSON lỗi chỉ có đúng 3 khóa `['status', 'code', 'message']`, không lọt secret/message nội bộ.
- `AC5`: Spy `OptionStore` (`writes === 0`, `deletes === 0`, `cas === 0`), bảo toàn `Plugin::health()` và khôi phục `'product_variation'` cho fallback Increment E.

---

### `3A2-6` [Blocker Hygiene & Report] — Chạy Full Suite & Báo cáo `increment-3a-r2-rp.md`

1. Chạy PHPUnit với `--do-not-cache-result` (hoặc khôi phục sạch `.phpunit.result.cache`) cho cả:
   - `tests/Unit/Increment3ATest.php`
   - Toàn bộ `tests/Unit` (`exit code 0`, bao gồm `IncrementFTest`, `IncrementGTest`, `RuntimeCompositionTest`, `IncrementER6VerificationTest`...).
2. Đảm bảo `myseoforall.php` không bị sửa đổi.
3. Ghi báo cáo kết quả kèm SHA-256 thực tế của từng file vào `docs/giai-doan-3/increment-3a-r2-rp.md` (nêu rõ giới hạn kiểm thử offline so với bước test WordPress/gateway thật của Owner).
