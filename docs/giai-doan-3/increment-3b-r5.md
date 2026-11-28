# Giai đoạn 3 — Increment 3B (Revision 5): Khắc phục verification và hoàn tất bằng chứng R4

## 1. Mục tiêu

R4 chưa được nghiệm thu. Báo cáo `increment-3b-r4-rp.md` ghi nhận các thay đổi projection/UX và PHP lint đã chạy, nhưng focused test và full suite đều kết thúc với `exit_code=1` chỉ kèm dòng nhận diện PHPUnit, không có failure detail, assertion count hoặc stack trace.

R5 là vòng sửa để làm cho kết quả verification có thể quan sát, sửa nguyên nhân test fail nếu có, và bổ sung evidence đủ để Reviewer/Architecture quyết định nghiệm thu. R5 không mở rộng tính năng Preview/Apply, không thay đổi persistence schema, privacy policy, ownership policy, 3C frontend emission hoặc bootstrap `myseoforall.php`.

## 2. Trạng thái phải xử lý

Các lệnh trong báo cáo R4 đã được ghi nhận là chưa xác định kết quả:

- `php vendor/bin/phpunit tests/Unit/RuntimeCompositionTest.php`
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeCompositionTest.php`
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`

Không được biến `exit_code=1` thành PASS, cũng không suy đoán nguyên nhân từ việc runner chỉ in banner. Trước hết phải làm failure observable; sau đó sửa đúng lỗi có evidence.

## 3. Hướng dẫn triển khai bắt buộc

### `3B5-1` — Làm rõ test runner và môi trường

- Xác nhận binary/configuration PHPUnit đang được sử dụng, PHP version và đường dẫn test; không thay đổi contract chỉ để che lỗi runner.
- Chạy lại từng lệnh với output chi tiết phù hợp của PHPUnit (verbose/debug hoặc equivalent được hỗ trợ bởi phiên bản đang cài), bảo đảm stdout/stderr và exit code được thu thập đầy đủ.
- Nếu test bị dừng trước discovery hoặc bootstrap, ghi lại lỗi parse/autoload/bootstrap và sửa nguyên nhân trong boundary R4; không nuốt exception, không biến lỗi thành skip.
- Kiểm tra `phpunit.xml` và bootstrap chỉ ở mức cần thiết để test chạy đúng; không đổi `myseoforall.php`.
- Dọn hoặc loại khỏi bàn giao `.phpunit.result.cache` nếu nó chỉ là artifact sinh ra khi chạy test. Không xóa thay đổi do Owner/nhánh khác nếu chưa xác định ownership.

### `3B5-2` — Sửa lỗi implementation theo failure evidence

Đối chiếu failure thực tế với R4, đặc biệt các điểm sau:

- `WordPressContentReader` tạo projection text-only nhưng vẫn giữ dữ liệu nguồn Local SEO nguyên vẹn.
- Projection loại Gutenberg comments, HTML/shortcode theo pipeline deterministic và giới hạn 2.000 code points UTF-8.
- `missing_title`, `missing_content`, `content_truncated_for_preview`, `privacy_policy` và `payload_contract` là reason đóng; không phản chiếu raw exception/content.
- Auto-draft và draft sau Save đi qua cùng pipeline; permalink local/draft không hợp lệ không làm hỏng title/content hợp lệ.
- Privacy/secret gate vẫn chạy trên chính dữ liệu được gửi AI; không cắt dữ liệu để né gate.
- Apply chỉ xuất hiện sau Preview thành công có token/fingerprint; thiếu secret an toàn thì Preview vẫn read-only.

Chỉ sửa implementation khi có test/failure hoặc code evidence tương ứng. Không nới giới hạn payload, bỏ privacy gate, tự động Apply/Publish, hoặc sửa dữ liệu bài viết trong database.

### `3B5-3` — Bổ sung test matrix có kết quả định lượng

Focused tests phải bao phủ tối thiểu:

1. HTML/Gutenberg content dài được project an toàn và không bị reject chỉ vì độ dài.
2. Content rỗng sau Save trả `missing_content`.
3. Title rỗng trả `missing_title`.
4. Email, phone, API key, token hoặc private URL vẫn trả `privacy_policy`.
5. Auto-draft và draft sau Save dùng cùng projection/reason.
6. Permalink local/draft không hợp lệ không làm hỏng payload text hợp lệ.
7. Metabox chỉ render thông báo tĩnh theo reason allowlist.
8. Preview accepted render đúng các nút; Apply cần token/fingerprint hợp lệ.
9. Không có network/DNS call trong các test projection/rendering.

Mỗi test phải có assertion hành vi, không chỉ assertion rằng không ném exception. Nếu có fixture thay đổi, giữ fixture nhỏ và không chứa secret thật.

## 4. Verification và báo cáo bắt buộc

Sau khi sửa:

1. Chạy focused test cho các file/runtime liên quan và ghi chính xác command, exit code, số tests, assertions, failures/errors/skipped.
2. Chạy full `tests/Unit` với `--configuration phpunit.xml`; ghi cùng các số liệu trên.
3. Chạy PHP lint cho mọi file PHP thực sự thay đổi.
4. Kiểm tra `git diff`/status để xác nhận không bàn giao `.phpunit.result.cache`, secret, log runtime hoặc file ngoài boundary.
5. Ghi checksum hiện tại của từng file thay đổi vào báo cáo R5; không ghi checksum đoán.
6. Phân loại rõ:
   - PASS: có output test đầy đủ và exit code 0;
   - FAIL: có failure detail/stack trace và nêu test còn lỗi;
   - BLOCKED: runner/provider không cung cấp output dù đã thử phương án được hỗ trợ, kèm evidence cụ thể.

Báo cáo phải cập nhật tại `docs/giai-doan-3/increment-3b-r5-rp.md`, gồm kết quả focused, full suite, lint, test count/assertion count, checksum, thay đổi thực tế và giới hạn WordPress/WooCommerce runtime chưa kiểm thử. Không tuyên bố production acceptance chỉ từ PHPUnit offline.

## 5. Guardrails và tiêu chí hoàn tất

- Không sửa `myseoforall.php`.
- Không sửa schema `_myseo_*`, persistence, Rank Math ownership hoặc frontend emission 3C.
- Không gửi toàn bộ HTML/raw post content ra gateway.
- Không log hoặc commit plaintext secret.
- Không commit `.phpunit.result.cache`.
- Không đánh dấu R4 nghiệm thu nếu full suite vẫn exit khác 0, hoặc nếu kết quả chỉ có banner PHPUnit mà thiếu failure detail.

R5 chỉ đủ điều kiện chuyển nghiệm thu khi output test có thể đối chiếu được, mọi failure đã được sửa hoặc được báo cáo minh bạch, và báo cáo R5 cung cấp provenance/checksum đầy đủ. Nếu test vẫn FAIL, giữ trạng thái chưa nghiệm thu và trả lại failure cụ thể cho vòng xử lý tiếp theo.
