# Giai đoạn 3 — Increment 3B (Revision 6): Vá các lỗ hổng còn lại từ R4/R5 trước khi nghiệm thu

## 1. Mục tiêu R6

Báo cáo R5 (`docs/giai-doan-3/increment-3b-r5-rp.md`) đã xác nhận được output PHPUnit đầy đủ (`110 tests, 1616 assertions, 1 skipped, exit_code=0`) và lint PASS. Tuy nhiên đối chiếu trực tiếp với source hiện hành cho thấy vẫn còn **5 lỗi/thiếu sót cụ thể** so với contract R4 (`3B4-1`..`3B4-5`) và ma trận kiểm thử R5 (`3B5-3`).

R6 xử lý đúng 5 điểm có evidence dưới đây trong phạm vi Increment 3B; không mở rộng sang 3C frontend emission, không sửa `myseoforall.php`, không đổi schema persistence `_myseo_*` và không thay đổi Rank Math ownership.

## 2. Các điểm bắt buộc sửa trong R6

### `3B6-1` — Vá lỗ hổng cắt chuỗi né Privacy Gate (`3B4-2`) và giới hạn đầy đủ field text dài (`3B4-1`)

**Hiện trạng trong `src/SEO/Runtime/WordPressContentReader.php`:**
- `previewProjection()` cắt `content` xuống `2000` code points **trước khi** `ProviderPayloadBuilder::build()` kiểm tra `rejectPrivateText()`. Nếu email/phone/API key/token xuất hiện sau ký tự 2.000 của bài viết, cắt chuỗi sẽ vô tình xóa dữ liệu nhạy cảm và để payload đi qua (vi phạm `3B4-2`: không được cắt chuỗi để né kiểm tra privacy).
- `previewProjection()` chỉ cắt `content`, chưa giới hạn `excerpt` / `meta_description` nếu vượt `2000` code points (có thể vẫn bị `ProviderPayloadRules::inspect()` từ chối `payload_contract`).
- `normalizePreviewText()` gọi `html_entity_decode()` **sau** `strip_tags()`, làm entity mã hóa tag như `&lt;script&gt;` có thể bật lại thành chuỗi giống HTML sau khi đã strip.

**Yêu cầu sửa:**
1. Chuẩn hóa pipeline trong `normalizePreviewText()`: loại Gutenberg comments `<!-- ... -->` → loại shortcode không an toàn → `strip_tags()` → `html_entity_decode(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')` → chạy thêm một lượt `strip_tags()` an toàn để không để lọt tag sinh ra từ entity → chuẩn hóa `\r\n`/`\r` thành `\n` và gom whitespace thừa.
2. Chạy kiểm tra privacy/secret **trên toàn bộ văn bản đã normalize trước khi cắt** (hoặc kiểm tra cả phần đã normalize lẫn phần projection gửi đi), bảo đảm nếu bài viết chứa email/phone/API key/token/private URL ở bất kỳ vị trí nào (kể cả sau ký tự 2.000) đều bị reject fail-closed với `payload_status = 'rejected'` và `payload_reason = 'privacy_policy'`.
3. Áp dụng giới hạn an toàn `2000` code points cho `content` và các field text phụ có thể dài (`excerpt`, `meta_description`) sau bước privacy check, ghi nhận `content_truncated_for_preview` khi có rút gọn mà không sửa dữ liệu bài viết gốc hay `SeoContext` của Local SEO.
4. Giữ nguyên `readPost()` không bị ghi đè `title` bằng `get_the_title()` khi bài viết đã có key `title` nhưng rỗng có chủ ý (hiện tại `normalize()` luôn gán `'title' => ''` cho object nên cần kiểm tra/test rõ cả trường hợp mảng không có title lẫn object có `post_title = ''`).

### `3B6-2` — Hiển thị thông báo `content_truncated_for_preview` ở nhánh `accepted` trong Metabox (`3B4-4`)

**Hiện trạng trong `src/SEO/Runtime/EditorPreviewController.php:240-260`:**
- Khi bài viết dài được rút gọn an toàn, `WordPressContentReader` trả `payload_status = 'accepted'` kèm `payload_reason = 'content_truncated_for_preview'`.
- Nhưng `EditorPreviewController::renderMetabox()` lại đặt map thông báo `'content_truncated_for_preview'` bên trong nhánh `elseif ($payload_status !== 'accepted')`, nên thông báo rút gọn **không bao giờ được hiển thị** khi payload accepted.

**Yêu cầu sửa:**
- Ở nhánh `payload_status === 'accepted'`, nếu `payload_reason === 'content_truncated_for_preview'`, hiển thị thông báo tĩnh đã escape:
  `Nội dung dài đã được rút gọn an toàn cho bản xem trước AI.`
  cùng với phần khai báo trường công khai và đầy đủ các nút Preview (`Phân tích SEO bằng AI`, `Gợi ý Title/Meta`, `Soạn nháp mô tả sản phẩm` cho product).
- Ở nhánh `payload_status !== 'accepted'`, chỉ ánh xạ các reason từ chối đóng (`missing_title`, `missing_content`, `privacy_policy`, `payload_contract`) và fallback tĩnh mặc định; tuyệt đối không phản chiếu `payload_reason` tùy ý, raw content hay exception.

### `3B6-3` — Hoàn tất `ApplyToken` fallback `AUTH_KEY` → `wp_salt('auth')` (`3B4-5`)

**Hiện trạng trong `src/SEO/Runtime/SeoModule.php:121-123`:**
- `SeoModule::register()` vẫn chỉ kiểm tra `defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== ''`; chưa có fallback sang `wp_salt('auth')` như yêu cầu `3B4-5`.

**Yêu cầu sửa:**
- Tách resolver/helper nội bộ trong `SeoModule` để chọn secret deployment cho `ApplyToken` theo thứ tự:
  1. `AUTH_KEY` (nếu đã định nghĩa và chuỗi khác rỗng sau kiểm tra an toàn);
  2. `wp_salt('auth')` (nếu hàm tồn tại và trả chuỗi khác rỗng, có thể inject seam cho unit test hoặc đọc qua hàm platform mà không dùng secret hard-coded);
  3. Không có secret an toàn → không đăng ký `ApplyToken` và `ApplyController`, giữ nguyên Local SEO và Preview ở chế độ read-only (ẩn Apply).
- Không dùng bất kỳ chuỗi fallback hard-coded/dễ đoán nào.

### `3B6-4` — Khôi phục độ phủ test `422 payload_rejected` trong `Increment3ATest`

**Hiện trạng trong `tests/Unit/Increment3ATest.php:187-192`:**
- Do `id = 5` trong fixture chỉ dùng `str_repeat('x', 50000)`, sau R4 `id = 5` trở thành payload hợp lệ sau projection nên R5 đã đổi assertion thành `200 ok`, làm mất ca kiểm thử `422 payload_rejected` (0 calls tới AI provider) trong `testPreviewGateMatrix()`.

**Yêu cầu sửa:**
- Giữ ca kiểm thử content dài được projection thành công (`200 ok`), đồng thời khôi phục một fixture riêng (ví dụ bài chứa dữ liệu bị chặn bởi privacy gate như email/secret hoặc thiếu nội dung) trong `testPreviewGateMatrix()` để tiếp tục chứng minh nhánh `422 payload_rejected` thực thi đủ gate `['edit', 'nonce', 'type']`, đọc inspector nhưng gọi AI provider **0 lần**.

### `3B6-5` — Hoàn thiện đủ ma trận kiểm thử R4/R5 (`3B5-3`)

Bổ sung các test hành vi còn thiếu (trong `RuntimeCompositionTest.php` / `Increment3ATest.php` / `Increment3BTest.php` tùy phân bổ hợp lý):

1. `missing_title` trả `payload_status = 'rejected'`, `payload_reason = 'missing_title'` (cả object có `post_title = ''` lẫn mảng rỗng).
2. `privacy_policy`: email, phone, API key/token, private URL bị reject **kể cả khi nằm sau ký tự 2.000** của nội dung dài.
3. Projection không làm thay đổi `SeoContext` / findings của `LocalSeoAnalyzer` (chứng minh Local SEO vẫn thấy dữ liệu gốc).
4. `auto-draft` và `draft` sau Save đi qua cùng pipeline và cho kết quả nhất quán.
5. Permalink local/draft (`http://localhost/draft`, `https://site.local/...`) bị loại khỏi payload mà không làm hỏng title/content hợp lệ; đồng thời `excerpt`/`meta_description` dài được xử lý an toàn.
6. `EditorPreviewController::renderMetabox()` render đúng thông báo tĩnh cho `missing_title`, `missing_content`, `privacy_policy`, `payload_contract`, `unknown_reason` (fallback an toàn, không lộ chuỗi thô) và render thông báo `content_truncated_for_preview` kèm đầy đủ nút Preview khi payload accepted.
7. `SeoModule` / `ApplyToken`: kiểm thử đủ 3 trạng thái — có `AUTH_KEY`, thiếu `AUTH_KEY` nhưng có `wp_salt('auth')` (tạo được `ApplyToken` + `ApplyController`), và thiếu cả hai (không đăng ký `ApplyToken`, Preview vẫn hoạt động read-only).

## 3. Verification và báo cáo bắt buộc

1. Chạy focused tests (`RuntimeCompositionTest.php`, `Increment3ATest.php`, `Increment3BTest.php` nếu có) với `--verbose` và ghi exact command, exit code, tests, assertions, failures/errors/skipped.
2. Chạy full suite `php vendor/bin/phpunit --verbose --configuration phpunit.xml tests/Unit` đạt `exit_code=0` (0 failures, 0 errors).
3. Chạy `php -l` PASS cho toàn bộ file PHP đã sửa.
4. Xác nhận không sửa `myseoforall.php`, không đưa `.phpunit.result.cache` vào bàn giao.
5. Ghi exact SHA-256 checksum của mọi file thay đổi vào báo cáo **`docs/giai-doan-3/increment-3b-r6-rp.md`**, nêu rõ giới hạn chưa kiểm thử WordPress/WooCommerce runtime thật.
