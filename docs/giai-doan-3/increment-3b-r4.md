# Giai Đoạn 3 — Increment 3B (Revision 4): Hướng dẫn sửa lỗi Preview/Apply trên bài viết WordPress thực tế

## 1. Mục tiêu R4

R4 xử lý lỗi runtime được Owner xác nhận:

- Bài mới vừa mở có thể hiện khu vực AI, nhưng sau khi **Save draft → reload** lại hiện `Dữ liệu nguồn hiện tại không vượt qua bộ lọc an toàn.`.
- Bài cũ thường bị chặn vì toàn bộ `post_content` HTML vượt giới hạn payload 2.000 code points.
- Khi payload bị từ chối, UI không nói rõ lý do và không thể đi tới Preview/Apply.

R4 là sửa compatibility/UX trong boundary của Increment 3B; không mở rộng sang frontend emission của 3C, không thay đổi schema persistence, không sửa `myseoforall.php`, không gửi dữ liệu vượt policy ra AI và không nới lỏng privacy gate.

## 2. Chẩn đoán được khóa

### 2.1 Không dùng toàn bộ HTML bài viết làm payload AI

`WordPressContentReader::readPost()` hiện đưa `post_content` nguyên bản vào `ProviderPayloadBuilder`. `ProviderPayloadRules` áp giới hạn `MAX_TEXT_LENGTH = 2000` cho từng chuỗi, đồng thời kiểm tra privacy trên toàn chuỗi. Bài WordPress thực tế có block comments, HTML, shortcode, URL và nội dung dài nên bị reject sau khi reload.

Không được sửa trực tiếp nội dung bài viết trong database. Cần tách:

- **Nguồn cho Local SEO Analyzer:** giữ dữ liệu hiện tại theo contract 3A để findings không bị thay đổi ngoài chủ ý.
- **Nguồn cho AI Preview:** tạo projection text-only, đã loại comment/HTML không cần thiết, giới hạn 2.000 code points trước khi gọi `ProviderPayloadBuilder`.

### 2.2 Không coi nội dung chưa đủ là lỗi mơ hồ

Một bài mới có thể có title nhưng content rỗng sau khi WordPress lưu. Đây là trạng thái hợp lệ để trả reason đóng, không phải exception chung. Reader phải phân biệt:

- `missing_title`: chưa có tiêu đề;
- `missing_content`: chưa có nội dung có ý nghĩa sau normalize;
- `content_truncated_for_preview`: đã tạo projection an toàn bị cắt theo giới hạn;
- `privacy_policy`: payload chứa dữ liệu nhạy cảm;
- `payload_contract`: payload không khớp schema khác.

Không phản chiếu nội dung bài viết hoặc raw exception vào HTML/JSON.

## 3. Thay đổi source bắt buộc

### `3B4-1` — Thêm AI preview projection riêng trong `WordPressContentReader`

- Giữ nguyên `SeoContext`/findings local từ dữ liệu nguồn cần thiết.
- Trước `ProviderPayloadBuilder::build()`, tạo helper nội bộ cho các field text gửi AI:
  - chuẩn hóa UTF-8;
  - chuyển `\r\n`/`\r` thành `\n`;
  - bỏ Gutenberg comments `<!-- ... -->`, shortcode không an toàn và HTML tags bằng pipeline deterministic (không thực thi shortcode, không gọi URL);
  - decode entity ở mức phù hợp nhưng không biến nội dung thành HTML executable;
  - trim whitespace lặp lại;
  - giới hạn **2.000 code points** bằng thao tác UTF-8 an toàn.
- Áp dụng projection cho `content` và các trường text dài có thể vượt giới hạn trong provider payload; không cắt `title`/URL theo cách làm mất tính hợp lệ của schema.
- Ghi `payloadReason = content_truncated_for_preview` chỉ như metadata nội bộ/closed reason; không gửi raw content ra UI.
- Nếu title/content sau normalize rỗng, trả `payload_status = rejected` với reason đóng tương ứng trước khi gọi provider.

### `3B4-2` — Không dùng cắt chuỗi để vượt privacy policy

- Projection phải chạy privacy inspection trên phần dữ liệu thực sự gửi AI.
- Nếu phát hiện email, phone, credential, token, private URL hoặc dữ liệu thương mại bị policy cấm, vẫn reject fail-closed; không cắt để né kiểm tra.
- Không log plaintext, không giữ bản projection ngoài request scope, không thêm retention/cache.
- Giữ nguyên zero network/DNS khi mở metabox và khi xây Preview request cho tới bước người dùng bấm nút AI theo contract 3A.

### `3B4-3` — Bảo đảm bài mới sau Save không bị khác contract với bài chưa Save

- Dùng cùng một `WordPressContentReader` và cùng projection pipeline cho mọi lần đọc; không có nhánh đặc biệt cho `auto-draft`.
- Sau Save, lấy post object/permalink mới nhưng chỉ đưa URL nếu qua URL validation; URL draft/local không hợp lệ phải được bỏ khỏi AI projection hoặc chuyển thành reason đóng, không làm hỏng toàn bộ title/content payload nếu field URL không bắt buộc.
- Không dùng `get_the_title()` hoặc permalink fallback để ghi đè title/content đã đọc nếu giá trị gốc đã tồn tại nhưng rỗng có chủ ý.
- Thêm test fixture cho các trạng thái: auto-draft, draft sau Save, published, content có Gutenberg comments/HTML, content dài, content rỗng và permalink local không hợp lệ.

### `3B4-4` — Hiển thị reason an toàn trong metabox

`EditorPreviewController::renderMetabox()` không được chỉ hiển thị một câu chung khi `payload_status = rejected`. Ánh xạ reason đóng phía server sang thông báo tĩnh:

- `missing_title` → `Bài viết chưa có tiêu đề.`
- `missing_content` → `Bài viết chưa có nội dung để xem trước.`
- `content_truncated_for_preview` → `Nội dung dài đã được rút gọn an toàn cho bản xem trước AI.`
- `privacy_policy` → `Dữ liệu nguồn chứa thông tin không được phép gửi tới AI.`
- `payload_contract` → `Dữ liệu nguồn chưa đáp ứng định dạng xem trước an toàn.`

Chỉ escape thông báo tĩnh. Không phản chiếu `payload_reason` tùy ý, HTML, query string hay exception.

Khi payload accepted, UI phải tiếp tục giữ nguyên nút `Phân tích SEO bằng AI`, `Gợi ý Title/Meta`, `Soạn nháp mô tả sản phẩm`; Apply vẫn chỉ xuất hiện sau Preview thành công có token/fingerprint.

### `3B4-5` — ApplyToken fallback và guardrail

Nếu R3 runtime vẫn chỉ tạo `ApplyToken` khi `AUTH_KEY` tồn tại:

- Ưu tiên secret deployment từ `AUTH_KEY` hoặc WordPress `wp_salt('auth')` theo policy hiện hành;
- không dùng secret hard-coded hoặc fallback dễ đoán;
- nếu không có secret an toàn, giữ Preview read-only và ẩn Apply, nhưng không được làm mất Local SEO/Preview;
- test cả `AUTH_KEY` có, không có và `wp_salt` không khả dụng.

## 4. Test bắt buộc cho R4

Bổ sung focused tests, không giảm các test hiện có:

1. `post_content` HTML/Gutenberg dài được project xuống giới hạn và Preview không bị reject chỉ vì độ dài.
2. Nội dung rỗng sau Save trả `missing_content` an toàn.
3. Title rỗng trả `missing_title` an toàn.
4. Email/phone/API key/private URL trong projection vẫn bị reject `privacy_policy`, không được cắt để né gate.
5. Auto-draft → draft sau Save dùng cùng projection và kết quả reason ổn định.
6. Permalink local/draft không hợp lệ không làm hỏng payload text hợp lệ.
7. Metabox ánh xạ reason đóng đúng thông báo tĩnh, không lộ raw content/exception.
8. Payload accepted vẫn render các nút Preview; Apply chỉ xuất hiện sau response Preview có token/fingerprint.
9. Full suite `tests/Unit` tiếp tục PASS; không sửa `myseoforall.php`; không commit `.phpunit.result.cache`.

Báo cáo phải ghi exact test/assertion count, checksum mọi file thay đổi và giới hạn chưa kiểm thử WordPress/WooCommerce runtime thật.

## 5. Không được làm trong R4

- Không tăng giới hạn payload bằng cách bỏ `ProviderPayloadRules`.
- Không gửi toàn bộ HTML/raw post content ra AI.
- Không xóa privacy/secret gate.
- Không tự động Apply, Publish hoặc sửa post content.
- Không sửa `_myseo_*` schema, Rank Math keys hoặc `myseoforall.php`.
- Không tuyên bố production acceptance chỉ từ PHPUnit offline.

## 6. Handoff

SE triển khai đúng boundary trên, cập nhật báo cáo vào `docs/giai-doan-3/increment-3b-r4-rp.md`, chạy focused tests và full `tests/Unit` bằng `--configuration phpunit.xml`, sau đó gửi evidence để Reviewer/Architecture nghiệm thu. SL tự phân rã ticket và verification trong boundary này.
