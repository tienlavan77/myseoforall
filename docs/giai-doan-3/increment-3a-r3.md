# Giai Đoạn 3 — Increment 3A (Vòng r3): Sửa Lỗi Gãy PHPUnit Exit Code 2 & Chốt Nghiệm Thu

> Tài liệu phản hồi nghiệm thu của Architecture Manager sau khi đọc `docs/giai-doan-3/increment-3a-r2-rp.md` (`sha256:ca8cb67edaf3fada5a0b67bc1dfb30efac9a6c7a0808743410e973d9e4b6933c`) và kiểm tra trực tiếp từng dòng của `tests/Unit/Increment3ATest.php`, `src/SEO/Runtime/EditorPreviewController.php`, `src/SEO/Runtime/SeoModule.php`.

---

## 1. Kết luận Đánh giá Vòng r2: **CHƯA ĐẠT (`NOT ACCEPTED` — Chính SE cũng tự đánh dấu NOT ACCEPTED)**

Phần cấu trúc `EditorPreviewController` ở r2 đã tiến bộ rất nhiều (tách Gate 1–7, `fetch()` + DOM API không dùng `.innerHTML`, tách `http_status` khỏi `body`). Tuy nhiên kiểm tra trực tiếp mã nguồn đã tìm ra **nguyên nhân chính xác 100% khiến `Increment3ATest.php` nổ `exit code 2`**, kèm **5 lỗi thực tế trong `src/`** cần sửa gọn trong vòng r3 để chốt nghiệm thu.

---

## 2. Thủ phạm làm `Increment3ATest.php` nổ `exit code 2` & Cách sửa

### `3A3-1` [Blocker Crash — Nguyên nhân `exit code 2`] — Sửa 3 lỗi trong `tests/Unit/Increment3ATest.php`

1. **Nguyên nhân #1 (Gây crash trực tiếp ở `testMetaboxEscapesOutputAndUsesTextContent` dòng 73–92):**
   - Tại dòng 78, test khởi tạo `AiSettingsRepository` với `OptionStore` trả `null` $\rightarrow$ `$ready === false`!
   - Khi `$ready === false`, `renderMetabox()` vào nhánh dòng 153 (`Trợ lý AI chưa được cấu hình đầy đủ. Mở Cài đặt`) và **KHÔNG render thẻ `<script>`** (nhánh dòng 162)!
   - Dẫn tới dòng 90 `self::assertStringContainsString('textContent', $html)` **FAIL 100%**!
2. **Nguyên nhân #2 (Gây `INVALID_RESPONSE` ngầm làm fail `testSuccessfulPreviewUsesPayloadAndClosedResponse` dòng 49–70):**
   - Tại dòng 57, provider spy chỉ trả `return ['output' => ['findings' => []]];` (thiếu `schema_version`, `provider`, `task`, `status`).
   - Đến dòng 78 của `EditorPreviewController`, `AiOutputValidator::validate()` kiểm tra exact keys `['schema_version', 'provider', 'task', 'status', 'output']` nên **ném `AiProviderException(INVALID_RESPONSE)`**!
   - Dòng 89 bắt exception và trả về `['http_status' => 502, 'body' => ['status' => 'error', ...]]`!
   - Thêm vào đó, dòng 66 của test lại đọc `$result['status']` ở cấp ngoài cùng thay vì `$result['body']['status']` (vì r2 đã bọc `['http_status' => ..., 'body' => ...]`) $\rightarrow$ nổ lỗi `Undefined array key "status"` / fail toàn tập cả ở dòng 41, 42, 43 (`['code']` thay vì `['body']['code']`) và dòng 66, 67, 69!
3. **Cách sửa:**
   - Mọi assertion trên kết quả `handlePreviewRequest()` phải kiểm tra cả `$result['http_status']` và `$result['body']` (ví dụ `$result['body']['code']`, `array_keys($result['body'])`).
   - Provider spy trong test thành công (hoặc dùng trực tiếp `new FakeAiProvider()`) phải trả đủ envelope chuẩn `['schema_version' => 'myseo.ai.response.v1', 'provider' => 'test', 'task' => $task, 'status' => 'ok', 'output' => ...]`.
   - Test render script Metabox phải cấu hình `AiSettingsRepository` ở trạng thái `$ready === true` (lưu `ai_enabled = true`, `base_url = 'https://gw.example.com'`, `default_model = 'model-1'` và API key hợp lệ với encryption key 32 bytes như trong `IncrementFTest`).

---

## 3. Năm Lỗi Logic Thực Tế trong `src/SEO/Runtime/` bắt buộc sửa cùng lúc

### `3A3-2` [Blocker Bug] — Lỗi hỏng Allowlist Disclosure & Nối Ownership thật trong `EditorPreviewController` + `SeoModule`

1. **Bug in ra `0, 1, 2` thay vì tên trường ở dòng 157 `EditorPreviewController.php`:**
   - Trong `ProviderPayloadBuilder.php` (dòng 42–45), `$payload['provenance']` có cấu trúc danh sách tuần tự:
     `[['field' => 'title', 'source' => 'wordpress'], ['field' => 'content', 'source' => 'wordpress'], ...]`.
   - Nhưng dòng 157 của `EditorPreviewController.php` lại viết `array_keys($inspection['provider_payload']['provenance'])`! Kết quả trên màn hình WordPress của người dùng sẽ in ra: **`"Chỉ gửi các trường công khai: 0, 1, 2, 3"`** thay vì `"canonical, content, id, title"`!
   - **Cách sửa:** Trích xuất `array_column($inspection['provider_payload']['provenance'], 'field')` (lọc `is_string` và `!== ''`).
2. **Quên nối `$ownershipResolver` trong `SeoModule::register()` (`SeoModule.php` dòng 98–109):**
   - `SeoModule` khởi tạo `new EditorPreviewController(...)` mà **không truyền tham số thứ 9 (`$ownershipResolver`)**! Hệ quả là trên WordPress thật, Metabox **không bao giờ biết Rank Math đang bật hay tắt**, lúc nào cũng chỉ in `unknown / unknown`!
   - **Cách sửa:** Trong `SeoModule::register()`, truyền closure `$ownershipResolver` đọc lazy từ `$this->container` (`RankMathDetector::class` + `OwnershipRegistry::class`): xác định `rank_math_active` khi `$detected['activation'] === 'active'`, và đọc `$registry->resolve()['outputs']['title_meta']` để lấy `owner` + `state` hợp lệ.
3. **Bug cắt cụt `current.description` thành `null` khi mô tả sản phẩm dài > 2000 ký tự (`EditorPreviewController.php` dòng 283):**
   - Dòng 283 viết `preg_match('/\A.{0,2000}\z/us', $payload['description']) === 1 ? $value($payload['description']) : null`. Một bài mô tả sản phẩm WooCommerce thực tế dài 2.100 ký tự sẽ bị biến thành **`null` ("Chưa có dữ liệu")** trên bảng so sánh!
   - **Cách sửa:** Nếu chuỗi hợp lệ UTF-8 dài hơn 2000 code points, **cắt lấy 2000 ký tự UTF-8 đầu tiên** (`preg_match('/\A.{0,2000}/us', $desc, $m)` $\rightarrow$ `$m[0]`), không được biến dữ liệu có thật thành `null`.
4. **Sửa lỗi gãy nhánh Fallback `product_variation` trong `SeoModule::registerDiagnosticsSurface()` (`SeoModule.php` dòng 160–221):**
   - Dòng 161–165 đặt kiểm tra `$supported` (chỉ có `post`, `page`, `product`) ngay đầu hàm và `return;` luôn — nên dòng 219 `in_array($postType, ['product', 'product_variation'], true)` bên dưới trở thành **dead code không bao giờ chạy tới được** khi truyền `'product_variation'`! Đồng thời dòng 204–214 bị lặp lại khối `if (function_exists('add_meta_box'))` thừa.
   - **Cách sửa sạch sẽ:**
     - Nếu có `add_meta_box` (hoặc seam `$metaBoxRegistrar` tùy chọn thêm ở cuối constructor `SeoModule` để test Cổng 1 dễ dàng): kiểm tra `$postType` thuộc `post | page` hoặc (`product` khi WooCommerce bật), kiểm tra khớp `$post->post_type` nếu `$post` là object, rồi đăng ký metabox và `return;`.
     - Nếu chạy nhánh fallback offline (không có `add_meta_box` và không có `$metaBoxRegistrar`): kiểm tra `$post->ID > 0`, `edit_post($postId)`, rồi cho phép `post | page` vào `ContentSeoInspector` và `product | product_variation` (khi WooCommerce bật) vào `ProductSeoInspector` đúng y hệt Increment E.
5. **Kiểm tra mô hình `allowed_models` trong `$ready` của `renderMetabox()` (`EditorPreviewController.php` dòng 151):**
   - Bổ sung điều kiện `(($settings['allowed_models'] ?? []) === [] || in_array($settings['default_model'], $settings['allowed_models'], true))` để khớp 100% với điều kiện `$ready` trong `AiSettingsRepository::executeGatewayRequest()` (dòng 117).

---

## 4. Hoàn thiện Đầy đủ Ma trận `tests/Unit/Increment3ATest.php` (`AC1` -> `AC5`) & Chạy Pass 100%

Mở rộng `tests/Unit/Increment3ATest.php` từ 3 hàm sơ bộ hiện tại lên đủ ma trận `AC1..AC5` và **bắt buộc chạy pass thực tế**:
1. **`AC1`:** Test Cổng đăng ký của `SeoModule` (qua seam `$metaBoxRegistrar`: đăng ký `post`, `page`, `product` khi Woo bật; từ chối `product` khi Woo tắt và từ chối `product_variation` trên metabox UI với `0` inspector call lúc đăng ký; đồng thời giữ pass nhánh fallback `product_variation` khi không có registrar). Test Cổng render của `EditorPreviewController::renderMetabox` (bài chưa có ID / `ID = 0` báo lưu nháp với `0` inspector call; sai `post_type` hoặc trượt `edit_post` trả rỗng `''` với `0` inspector call; hợp lệ khi AI tắt hiện link `options-general.php?page=myseo-for-all`; hợp lệ khi AI bật hiện đúng tên trường `title, content...` chứ không phải `0, 1`, hiện Ownership khi Rank Math active vs inactive, bộ đếm `blocker/warning/info`, nút `product_description_draft` chỉ hiện cho `product`, `0` provider/DNS/HTTP call).
2. **`AC2`:** Test đủ các cổng chặn `malformed_request` (GET, mảng sai, thiếu/rỗng `task`), `invalid_post_id`, `forbidden`, `invalid_nonce`, `unsupported_task`, `not_found`, `unsupported_resource`, `unsupported_task_for_resource` (`0` inspector call & `0` provider call), và `payload_rejected` (`422`, `0` provider call).
3. **`AC3`:** Test thành công đủ 3 tasks (`seo_analysis`, `title_meta_suggestion`, `product_description_draft`) trên `article`, `page`, `product` (kể cả `current` trả `null` khi vắng mặt, cắt `description` dài 2100 ký tự về 2000 ký tự thay vì `null`, và escape `<script>` + không có `.innerHTML`).
4. **`AC4`:** Vòng lặp kiểm chứng độc lập **đủ 8 category** của `AiProviderException`: đúng `http_status`, đúng `code`, đúng `message` tiếng Việt riêng biệt, `array_keys($result['body']) === ['status', 'code', 'message']`, không lọt chuỗi nhạy cảm từ message exception gốc.
5. **`AC5`:** Spy `OptionStore` xác nhận `writes === 0`, `deletes === 0`, `cas === 0`; chạy pass cả:
   - `php vendor/bin/phpunit --do-not-cache-result tests/Unit/Increment3ATest.php` (`exit code 0`)
   - `php vendor/bin/phpunit --do-not-cache-result tests/Unit` (`exit code 0` cho toàn bộ suite dự án)
   - Khôi phục sạch `.phpunit.result.cache`, không chạm `myseoforall.php`, và ghi báo cáo kèm SHA-256 + kết quả chạy test vào `docs/giai-doan-3/increment-3a-r3-rp.md`.
