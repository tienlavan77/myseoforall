# Giai đoạn 2 — SEO Core, tích hợp Rank Math và AI Assistant có kiểm soát

> Tài liệu tổng hợp các ý tưởng và quyết định đã đánh dấu cho Giai đoạn 2. Đây là định hướng kiến trúc và phạm vi outcome; không phải Sprint Plan, danh sách ticket hay manifest file triển khai.

## 1. Mục tiêu

Xây dựng theo các increment có dependency rõ ràng: SEO Core cho bài viết/trang WordPress và sản phẩm/variation WooCommerce, một context contract chuẩn hóa, tích hợp an toàn với Rank Math, rồi AI Assistant qua gateway bên thứ ba **chỉ sau khi vượt decision gate về gateway và bảo mật**.

AI trong giai đoạn này chỉ tạo phân tích, đề xuất hoặc bản nháp. Người dùng phải xem trước và xác nhận trước khi áp dụng; không có auto-save hoặc auto-publish.

## 2. Kết quả cần đạt

- Chuẩn hóa dữ liệu bài viết, trang, sản phẩm và variation theo một context contract dùng chung.
- Phân tích SEO cục bộ bằng tiếng Việt, không cần gửi dữ liệu ra ngoài.
- Phát hiện Rank Math, đọc dữ liệu qua adapter riêng và hiển thị ownership/output status.
- Không xuất trùng title, meta, schema hoặc sitemap khi Rank Math đang sở hữu output tương ứng.
- Xây dựng AI provider contract; chỉ gọi gateway thật sau decision gate bắt buộc về gateway, API contract, model, authentication và secret storage.
- Có trang Settings để cấu hình gateway, model, API key/credential và đường dẫn tham chiếu/base URL theo policy an toàn.
- Chia delivery thành các increment: context/ownership matrix; local analyzer; Rank Math adapter; gateway contract/fake client; secret storage/network client; preview/Apply; compatibility và security hardening.
- Có luồng preview và Apply có xác nhận cho đề xuất AI.
- Không lưu request/response gửi gateway; không lưu lịch sử AI production nếu chưa có chính sách retention được chốt.

## 3. Phạm vi đối tượng

### In scope

- Bài viết WordPress.
- Trang WordPress.
- Sản phẩm WooCommerce.
- Variation WooCommerce ở mức cần thiết để giữ đúng quan hệ sản phẩm cha/con, giá, tồn kho và offer.
- Rank Math là integration SEO đầu tiên.
- Giao diện quản trị và hướng dẫn ưu tiên tiếng Việt.

### Out of scope

- Tự động xuất bản nội dung.
- Bulk generation.
- Tự động thay đổi giá, tồn kho, review hoặc dữ liệu thương mại gốc.
- Tự động tắt Rank Math hoặc tự chuyển ownership.
- Tự giành sitemap/schema output khi Rank Math đang sở hữu.
- Hỗ trợ nhiều plugin SEO cùng lúc.
- Đọc hoặc gửi dữ liệu khách hàng, đơn hàng, thông tin cá nhân, API key, token hay secret cho AI.

## 4. Context contract và allowlist dữ liệu

Context contract phải trung lập với WordPress, WooCommerce và Rank Math; adapter chịu trách nhiệm chuyển dữ liệu nền tảng vào context chuẩn hóa.

Context có thể bao gồm:

- ID và loại đối tượng.
- Tiêu đề, nội dung, excerpt hoặc mô tả.
- URL/canonical hiện tại khi có.
- Ảnh đại diện và dữ liệu media được phép.
- Danh mục, tag, thuộc tính và sản phẩm liên quan khi cần.
- SKU, giá, tồn kho, offer và variation khi là sản phẩm.
- Dữ liệu SEO hiện có qua adapter.
- Các trường còn thiếu hoặc không chắc chắn.

Payload phải được dựng từ allowlist trước khi gửi gateway. Không lấy toàn bộ object hoặc database rồi mới cố loại trừ dữ liệu nhạy cảm.

AI tuyệt đối không được nhận:

- Dữ liệu khách hàng.
- Dữ liệu đơn hàng.
- Thông tin cá nhân.
- API key, token hoặc secret.

Đối với sản phẩm, AI không được bịa giá, tồn kho, SKU, thuộc tính, chứng nhận, đánh giá hoặc cam kết thương mại.

## 5. Rank Math và ownership

Giai đoạn 2 chỉ triển khai detection, adapter đọc dữ liệu, ownership status và cảnh báo xung đột. Không tự tắt Rank Math, chuyển ownership, ghi đè dữ liệu hoặc giành sitemap/schema.

### Ownership matrix tối thiểu

| Output | Rank Math active | My SEO For All | Apply trong Giai đoạn 2 |
|---|---|---|---|
| SEO title/meta | Rank Math sở hữu; My SEO For All chỉ đọc/phân tích | Không xuất trùng | Chỉ qua adapter nếu contract cho phép và người dùng xác nhận |
| JSON-LD | Rank Math sở hữu | Không xuất | Không |
| Sitemap | Rank Math hoặc WordPress sở hữu | Không xuất | Không |
| AI suggestion/draft | Không phải public output | My SEO For All sở hữu phiên preview | Có thể Apply theo policy, không auto-publish |

Khi nhiều plugin SEO cùng hoạt động hoặc ownership không xác định, hệ thống phải báo trạng thái cần xử lý và không tự chọn nguồn sự thật.

## 6. Local SEO Analyzer

Analyzer chạy tại chỗ và vẫn hoạt động khi AI bị tắt. Kết quả nên phân loại:

- **Blocker:** có nguy cơ output sai hoặc vi phạm guardrail.
- **Warning:** vấn đề cần người dùng xem xét.
- **Info:** gợi ý cải thiện.

Phạm vi kiểm tra ban đầu:

- Thiếu hoặc độ dài không phù hợp của title/meta description.
- Heading không hợp lý.
- Canonical khi cần.
- Alt text ở mức có thể kiểm tra.
- Liên kết nội bộ.
- Trường sản phẩm còn thiếu.
- Tính nhất quán của giá, tồn kho, SKU, offer và variation.
- Schema dự kiến không khớp với dữ liệu hiển thị.

Analyzer không được hứa hẹn thứ hạng hoặc khả năng được hệ thống AI đề xuất.

Mỗi finding phải có contract ổn định, tối thiểu gồm:

```text
code, severity, message, field, evidence, suggested_fix
```

Các rule như khoảng title/meta, heading hierarchy, canonical, internal link và điều kiện variation phải được định lượng trong acceptance tests; không dùng text UI làm định danh rule.

## 7. AI provider và gateway thật

AI provider phải đi qua contract riêng để có thể thay gateway hoặc nhà cung cấp. Plugin không gọi trực tiếp endpoint Anthropic.

Trước network call phải chốt và kiểm thử:

- Gateway cụ thể.
- API contract và version.
- Model ID/version được phép.
- Authentication scheme.
- Base URL hoặc đường dẫn tham chiếu.
- Timeout, retry và xử lý lỗi.
- Allowlist dữ liệu gửi.
- Chính sách retention.

Gateway phải được quản lý qua registry cố định cho MVP; chưa cho phép base URL tùy chỉnh tùy ý. Nếu sau này mở custom URL, phải có HTTPS và TLS verification, không chứa credential, không redirect tùy ý, giới hạn method/port, kiểm tra DNS và địa chỉ sau resolve/redirect, chặn IPv4/IPv6 loopback, link-local, private/reserved ranges và chống DNS rebinding. Timeout kết nối/toàn request phải hữu hạn. Đây là decision gate, không phải chi tiết có thể bỏ qua khi triển khai.

## 8. AI Settings và bảo vệ secret

Tạo trang Settings riêng cho AI, không gộp secret tùy tiện vào cấu hình core. Các trường dự kiến:

- Provider/gateway.
- Base URL hoặc đường dẫn tham chiếu.
- Model mặc định.
- Danh sách model được phép chọn.
- API key/credential.
- Timeout và retry nếu được hỗ trợ.
- Trạng thái cấu hình.
- Nút kiểm tra kết nối nếu contract gateway cho phép.

Nguyên tắc:

- Chỉ xử lý key ở server-side.
- Lưu trong option/secret storage riêng theo policy được chốt.
- Không gửi key xuống JavaScript hoặc HTML.
- Sau khi lưu chỉ hiển thị dạng che một phần.
- Không đưa key vào log, Health Check, export cấu hình hoặc lỗi trả về người dùng.
- Dùng capability, nonce, sanitization và validation riêng.
- Khi trường key để trống lúc cập nhật, giữ key cũ thay vì ghi đè bằng chuỗi rỗng.
- Không lưu key trong browser storage.

WordPress không mặc định cung cấp cơ chế quản lý secret hoàn chỉnh ở cấp ứng dụng. Cần chọn cơ chế lưu trữ server-side phù hợp môi trường triển khai trước khi bật gateway production; ưu tiên secret manager/hosting secret nếu có, nếu không phải có giải pháp mã hóa và quản lý khóa rõ ràng.

## 9. Preview, Apply và dữ liệu đầu ra

Luồng bắt buộc:

```text
Người dùng chọn tác vụ
  -> tạo context theo allowlist
  -> hiển thị/giải thích dữ liệu dự kiến gửi
  -> gọi gateway
  -> validate output theo schema
  -> hiển thị preview
  -> người dùng Áp dụng / Sửa / Bỏ qua
```

AI output luôn là đề xuất hoặc draft. Không tự động xuất bản, không tự động ghi dữ liệu thật và không tự thay đổi giá, tồn kho, review hay dữ liệu thương mại.

Nếu có Apply, thao tác phải:

- Có quyền và nonce phù hợp.
- Xác nhận rõ trường nào sẽ thay đổi.
- Kiểm tra lại dữ liệu hiện tại trước khi ghi để tránh ghi đè ngoài ý muốn.
- Ghi qua adapter sở hữu dữ liệu, không ghi trực tiếp meta key Rank Math từ core.
- Có khả năng báo lỗi mà không làm mất dữ liệu gốc.
- Lưu snapshot/hash phiên bản context và các field đã preview.
- Đọc lại dữ liệu hiện tại khi Apply; từ chối và yêu cầu preview lại nếu snapshot/version đã thay đổi.
- Chỉ ghi các field người dùng đã xác nhận; lỗi một phần phải có error boundary và không làm mất dữ liệu gốc.

Không lưu request/response gửi gateway ở database, log, debug output, exception, queue, cache hoặc telemetry/APM; HTTP client và retry cũng không được tự ghi body. Payload preview phải redaction secret, giới hạn độ dài và dữ liệu tạm chỉ tồn tại trong request/phiên được kiểm soát. Việc lưu draft/snapshot để hoàn tác chỉ được thêm sau khi Owner chốt retention, quyền truy cập và phương án xóa dữ liệu.

## 10. AI output contract

Mỗi tác vụ AI phải có schema version riêng và được validate trước khi hiển thị hoặc Apply. Tối thiểu cần định nghĩa:

- SEO analysis: danh sách finding có `code`, `severity`, `field`, `evidence` và `suggested_fix`.
- Title/meta suggestion: chỉ các field được phép, giới hạn độ dài, ngôn ngữ và nguồn context.
- Product description draft: nội dung draft, ngôn ngữ, field đích và cờ xác nhận không suy diễn dữ liệu thương mại.

Gateway trả dữ liệu sai schema, field ngoài allowlist, sai kiểu hoặc vượt giới hạn phải bị từ chối an toàn; không để UI tự diễn giải text tự do thành dữ liệu có thể ghi.

## 11. Bảo mật, quyền riêng tư và vận hành

- Tính năng AI phải được bật chủ động.
- Hiển thị thông báo dữ liệu nào sẽ được gửi trước khi gọi gateway ở mức phù hợp.
- Có thể tắt AI mà vẫn dùng được SEO local.
- Phân quyền riêng cho cấu hình AI, gọi AI và Apply kết quả nếu cần.
- Không ghi secret hoặc payload nhạy cảm vào log.
- Timeout, retry, lỗi gateway và lỗi validation phải có thông báo an toàn, không lộ nội bộ.
- Không coi gateway response là dữ liệu sự thật; phải validate và hiển thị dưới dạng đề xuất.
- Theo dõi trạng thái dependency và integration trong Health Check mà không tiết lộ credential.

## 12. Increment và decision gate

Không triển khai toàn bộ Giai đoạn 2 như một hạng mục duy nhất. Thứ tự phụ thuộc đề xuất:

1. Context contract, canonical source và ownership matrix.
2. Local SEO analyzer và finding contract.
3. Rank Math detection, adapter và coexistence.
4. AI provider contract, schema version và fake client.
5. Secret storage, gateway registry và network client.
6. Preview/Apply với optimistic concurrency.
7. Compatibility, security và release hardening.

Các increment 1–4 có thể phát triển và kiểm thử mà chưa có network call production. Increment 5 chỉ được bật gateway thật sau decision gate có bằng chứng cho gateway test environment, API contract/version, model ID, authentication, SDK/base-URL compatibility, secret storage/rotation, timeout/retry/redirect policy, SSRF controls, retention và compatibility matrix. Khi gate chưa đạt, hệ thống chỉ được dùng fake client hoặc local analyzer.

## 13. Kiểm thử và nghiệm thu cấp outcome

Cần có test cho:

- Context contract và allowlist không chứa dữ liệu cấm.
- Bài viết/trang và sản phẩm/variation được chuẩn hóa đúng.
- WooCommerce không khả dụng không gây fatal error.
- Rank Math detection, adapter và coexistence không tạo duplicate output.
- Analyzer phân loại đúng blocker/warning/info theo rule code; finding có đủ `code`, `severity`, `field`, `evidence`, `suggested_fix`.
- Các ngưỡng title/meta, heading hierarchy, canonical, internal link và điều kiện variation được kiểm thử định lượng.
- Settings kiểm tra quyền, nonce, sanitization, giữ key cũ khi trường key để trống và không rò rỉ secret.
- Gateway authentication, timeout, retry, lỗi, SSRF controls và response schema; không network call khi decision gate chưa đạt.
- Preview không ghi dữ liệu; preview có redaction/giới hạn độ dài.
- Apply chỉ ghi sau xác nhận, kiểm tra snapshot/version, từ chối stale preview và không làm thay đổi dữ liệu thương mại.
- Chỉ ghi field đã xác nhận qua adapter sở hữu; lỗi một phần không làm mất dữ liệu gốc.
- AI bị tắt thì local SEO vẫn hoạt động.
- Request/response gateway không bị lưu trong database, log, debug output, exception, queue, cache hoặc telemetry/APM.

Trước khi release cần compatibility matrix cụ thể cho PHP, WordPress, WooCommerce và Rank Math; không dùng “latest” làm bằng chứng tương thích.

## 14. Rủi ro và quyết định còn cần hoàn tất

### Rủi ro chính

- Gateway tùy chỉnh có thể tạo SSRF hoặc gửi dữ liệu sai đích.
- Lưu API key không an toàn có thể gây lộ credential.
- Adapter Rank Math sai có thể tạo duplicate output hoặc ghi đè dữ liệu.
- Context quá rộng có thể làm lộ dữ liệu bị cấm.
- Apply dựa trên dữ liệu cũ có thể ghi đè thay đổi mới của người dùng.
- Không có retention policy rõ ràng khiến việc lưu draft/snapshot vi phạm yêu cầu riêng tư.

### Cần hoàn tất trước network call production

- Gateway, base URL và API contract/version.
- Model ID/version.
- Authentication scheme và cách lưu secret.
- Timeout/retry/redirect policy.
- Compatibility matrix và test environment.
- Retention policy cho dữ liệu tạm thời, draft hoặc snapshot.

## 15. Nguyên tắc triển khai

- Tách discovery/contract khỏi delivery implementation.
- Ưu tiên thay đổi incremental, backward-compatible.
- Không khóa danh sách ticket hay mutable-file manifest trong tài liệu này.
- SL có thể phân rã thành ticket, chọn file thực tế và sắp xếp dependency trong các boundary trên.
- Mọi thay đổi public contract, ownership, privacy semantics hoặc guardrail cần được xem xét lại trước khi triển khai.
