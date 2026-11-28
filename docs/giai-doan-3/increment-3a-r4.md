# Giai Đoạn 3 — Increment 3A (Vòng r4): Hoàn Thiện Đầy Đủ Ma Trận Kiểm Thử `AC1–AC5` & Chốt Nghiệm Thu

> Tài liệu phản hồi nghiệm thu của Architecture Manager sau khi đọc `docs/giai-doan-3/increment-3a-r3-rp.md` (`sha256:e4a7355625cef550889aa06285c7dbd1366310b8bc0ce99fd3603a6917c209e8`) và kiểm tra trực tiếp từng dòng của `src/SEO/Runtime/EditorPreviewController.php` (`sha256:0cc0e5304b57a27d0f4f8fbc87ec7c34a3672ccc43df628b06d17574ef6abe62`), `src/SEO/Runtime/SeoModule.php` (`sha256:cb1388ae5b58689c2ab2da52133dd21f19b3d555cd9d27c5d8f553d0842af29e`), `tests/Unit/Increment3ATest.php` (`sha256:3e1bd35b71334158c2fd6eb53420ba913f704e8de197def8c1d11e43e8a9b826`) và `myseoforall.php` (`sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`).

---

## 1. Kết luận Đánh giá Vòng r3: **CHƯA ĐẠT (`NOT ACCEPTED` — Phần `src/` đã đạt 100%, chỉ còn thiếu ma trận kiểm thử `AC1–AC5`)**

### 1.1. Phần đã ĐẠT hoàn toàn (Không sửa thêm nếu không cần thiết)
Kiểm tra trực tiếp mã nguồn xác nhận SE đã giải quyết **chính xác và sạch sẽ toàn bộ 5 lỗi runtime trong `src/`** của vòng r3:
- **`EditorPreviewController.php` (`sha256:0cc0e5304b57a27d0f4f8fbc87ec7c34a3672ccc43df628b06d17574ef6abe62`):**
  - Gate 1–8 đóng chặt theo đúng thứ tự bất biến (`POST` + array $\rightarrow$ `post_id > 0` $\rightarrow$ `edit_post` $\rightarrow$ `nonce` $\rightarrow$ `task` syntax/allowlist $\rightarrow$ `post_type` & khớp `task` trước khi gọi Inspector $\rightarrow$ `payload_status === 'accepted'` trước khi gọi `AiProvider` $\rightarrow$ hậu kiểm `AiOutputValidator`).
  - Allowlist Disclosure tại dòng 158–162 đã đọc đúng `array_column($provenance, 'field')`.
  - Điều kiện `$ready` tại dòng 151 đã đồng bộ kiểm tra `allowed_models`.
  - Hàm `currentValues()` tại dòng 283–296 chuẩn hóa chuỗi trống về `null` và cắt UTF-8 tối đa 2.000 ký tự cho `product.description` thay vì biến thành `null`.
  - Bảng `providerError()` tại dòng 299–312 ánh xạ độc lập đủ 8 category của `AiProviderException` ra đúng body 3 khóa `['status', 'code', 'message']` kèm `http_status` chuẩn.
  - Client JS inline dùng `fetch()` + `createElement` / `textContent` an toàn, không dùng `.innerHTML`.
- **`SeoModule.php` (`sha256:cb1388ae5b58689c2ab2da52133dd21f19b3d555cd9d27c5d8f553d0842af29e`):**
  - Đã nối lazy closure `$ownershipResolver` từ `RankMathDetector` + `OwnershipRegistry` vào `EditorPreviewController`.
  - Đã thêm seam `$metaBoxRegistrar` ở cuối constructor và tách sạch Cổng 1 (Metabox UI cho `post`, `page`, `product` khi Woo bật) khỏi nhánh fallback chẩn đoán offline (`product_variation` của Increment E).
- **`myseoforall.php` (`sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`):** Nguyên vẹn 100%, không bị đụng chạm.
- **Kết quả chạy PHPUnit hiện tại:** `Increment3ATest.php` (3 tests, 15 assertions) và toàn bộ `tests/Unit` (99 tests, 1.257 assertions) đã **PASS (`exit code 0`)**.

### 1.2. Lý do duy nhất chưa chốt nghiệm thu ở r3
Trong mục *"Giới hạn còn lại"* của `increment-3a-r3-rp.md`, chính SE tự ghi nhận: `tests/Unit/Increment3ATest.php` hiện mới chỉ dừng ở **3 test hàm sơ bộ (110 dòng)** và **chưa bổ sung ma trận kiểm thử bắt buộc `AC1–AC5`** đã khóa trong `docs/giai-doan-3/increment-3a.md` (Mục 3) và `docs/giai-doan-3/increment-3a-r3.md` (Mục 4):
- **`0 / 8`** category của `AiProviderException` được kiểm thử trong `Increment3ATest.php` (`AC4` đang trống hoàn toàn).
- **`0`** bài test cho Cổng đăng ký `SeoModule::registerDiagnosticsSurface()` qua `$metaBoxRegistrar` và Cổng render fail-closed (`ID = 0`, sai `post_type`, trượt `edit_post`, trạng thái AI tắt hiện link Cài đặt, hiển thị Ownership khi Rank Math active vs inactive, tên trường provenance thực tế, `0` lần gọi `AiProvider` khi render).
- **`0`** bài test cho `page` và WooCommerce `product` (`title_meta_suggestion`, `product_description_draft`, cắt mô tả dài 2.100 ký tự về 2.000 ký tự, trường thiếu trả `null`).
- Thiếu kiểm chứng đầy đủ các nhánh lỗi ở Gate 2, 3, 4, 5 (`unsupported_task`), 6 (`not_found`, `unsupported_resource`, `product` khi Woo tắt) và Gate 7 (`payload_rejected` trả `422` với `0` lần gọi `AiProvider`).
- Chưa gắn spy `OptionStore` xác nhận **`writes === 0`, `deletes === 0`, `cas === 0`** trong suốt quá trình render Metabox và gọi Preview (`AC5`).

*(Lưu ý về `.phpunit.result.cache`: Ghi nhận giải thích hợp lệ của SE rằng Forge chặn `git_restore` trên protected branch, nên không coi `.phpunit.result.cache` có sẵn là lỗi chặn nghiệm thu; SE chỉ cần tiếp tục luôn chạy PHPUnit với cờ `--do-not-cache-result` để không ghi bẩn thêm).*

---

## 2. Yêu cầu Duy Nhất trong Vòng r4 (`3A4-1`)

**Giữ nguyên `src/SEO/Runtime/EditorPreviewController.php` và `src/SEO/Runtime/SeoModule.php`** (trừ khi phát hiện lỗi nhỏ khi chạy test mới), chỉ tập trung **mở rộng hoàn chỉnh `tests/Unit/Increment3ATest.php`** phủ kín 5 nhóm `AC1–AC5` dưới đây:

1. **`AC1` — Test Cổng Đăng ký (`SeoModule`) & Cổng Render (`EditorPreviewController`):**
   - Dùng seam `$metaBoxRegistrar` trên `SeoModule`: xác nhận đăng ký `'myseo-for-all-editor-metabox'` cho `'post'`, `'page'`, và `'product'` khi Woo bật (kể cả khi `$post = null` hoặc `$post->ID = 0`, với **`0` lần gọi Inspector** lúc đăng ký); xác nhận **không** đăng ký cho `'product'` khi Woo tắt và **không** đăng ký metabox cho `'product_variation'`. Đồng thời xác nhận khi không có `$metaBoxRegistrar`, nhánh fallback offline cho `'product_variation'` vẫn gọi `ProductSeoInspector` bình thường.
   - Trên `renderMetabox()`:
     - `$post` thiếu ID / `ID = 0` / `'12abc'` $\rightarrow$ in thông báo lưu bản nháp, **`0` lần gọi Inspector**.
     - Sai `post_type`, `'product'` khi Woo tắt, hoặc trượt `edit_post($postId)` $\rightarrow$ output rỗng `''`, **`0` lần gọi Inspector**.
     - Hợp lệ khi AI tắt (`$ready === false`) $\rightarrow$ hiện link `options-general.php?page=myseo-for-all`, không in `<script>`, vẫn hiện Local SEO findings (`blocker`/`warning`/`info`).
     - Hợp lệ khi AI bật (`$ready === true`) $\rightarrow$ hiện đúng tên trường công khai (ví dụ `title, content` chứ không phải `0, 1`), hiện đúng thông điệp Ownership cho cả 2 trạng thái (`rank_math_active = true` vs `false`), nút `product_description_draft` chỉ xuất hiện trên `product` (không xuất hiện trên `post`/`page`), và **`0` lần gọi `AiProvider::generate()`**.

2. **`AC2` — Test Thứ tự Gate 1..7 Fail-Closed trên `handlePreviewRequest()`:**
   - Kiểm chứng từng mã lỗi trả đúng `http_status` và đúng 3 khóa `['status', 'code', 'message']`:
     - Gate 1..5 (`0` lần gọi Inspector & `0` lần gọi Provider): không phải mảng / `_method !== 'POST'` (`400 malformed_request`), `post_id` sai (`0`, `-5`, `'12abc'` $\rightarrow$ `400 invalid_post_id`), trượt `edit_post` (`403 forbidden`), sai/thiếu nonce (`403 invalid_nonce`), thiếu/rỗng `task` (`400 malformed_request`), `task` lạ (`400 unsupported_task`).
     - Gate 6 (`0` lần gọi Inspector & `0` lần gọi Provider): `resolvePostType` trả `null` (`404 not_found`), post type `'attachment'` hoặc `'product'` khi Woo tắt (`400 unsupported_resource`), gọi `product_description_draft` trên `'post'` hoặc `'page'` (`400 unsupported_task_for_resource`).
     - Gate 7 (`0` lần gọi Provider): Inspector trả `payload_status = 'rejected'` $\rightarrow$ `422 payload_rejected`.

3. **`AC3` — Test Thành công đủ 3 Tasks trên `article`, `page`, `product` & Chuẩn hóa `current`:**
   - Kiểm chứng `seo_analysis`, `title_meta_suggestion` (trên `post` -> `article`, `page` -> `page`, `product` -> `product`) và `product_description_draft` (trên `product`): HTTP `200`, đúng 7 khóa `['status', 'post_id', 'resource_type', 'task', 'current', 'proposal', 'read_only']`, `read_only === true`.
   - Kiểm chứng `current`: trường thiếu/rỗng quy về `null` (`article`/`page` có đúng 2 khóa `title`, `meta_description`; `product` có đúng 3 khóa `title`, `meta_description`, `description` và `meta_description` thiếu trả `null` chứ không lấy `short_description`); mô tả `product` dài `2100` ký tự UTF-8 được cắt đúng `2000` ký tự thay vì thành `null`.

4. **`AC4` — Vòng lặp Kiểm chứng Độc lập Đủ 8 Category của `AiProviderException`:**
   - Lặp qua đủ **8 hằng số category** của `AiProviderException` (`malformed_request` -> `400`, `unsupported_task` -> `400`, `invalid_response` -> `502`, `provider_disabled` -> `409`, `authentication_failed` -> `502`, `gateway_policy_rejected` -> `502`, `rate_limited` -> `429`, `transport_failed` -> `502`), ném kèm message chứa chuỗi nhạy cảm giả định `'SECRET_LEAK_MARKER'`:
     - Xác nhận đúng `http_status`, đúng `body['code']`, tất cả 8 `body['message']` tiếng Việt khác nhau đôi một (tách biệt rõ `malformed_request` và `unsupported_task`), `array_keys($result['body']) === ['status', 'code', 'message']`, và JSON không chứa `'SECRET_LEAK_MARKER'`.

5. **`AC5` — Zero-Write Spy, Chạy Full Suite & Báo cáo Nghiệm thu Cuối (`increment-3a-r4-rp.md`):**
   - Sau bước setup ban đầu của `AiSettingsRepository`, đặt lại bộ đếm spy trên `OptionStore` và xác nhận `writes === 0`, `deletes === 0`, `cas === 0` xuyên suốt mọi thao tác `renderMetabox()` và `handlePreviewRequest()`.
   - Chạy pass thực tế cả 2 lệnh với `--do-not-cache-result`:
     - `php vendor/bin/phpunit --do-not-cache-result tests/Unit/Increment3ATest.php` (`exit code 0`)
     - `php vendor/bin/phpunit --do-not-cache-result tests/Unit` (`exit code 0`)
   - Tuyệt đối không chạm `myseoforall.php`. Ghi báo cáo kết quả kèm SHA-256 của các file và câu ghi nhận giới hạn kiểm thử offline vào `docs/giai-doan-3/increment-3a-r4-rp.md` để chốt nghiệm thu Increment 3A!
