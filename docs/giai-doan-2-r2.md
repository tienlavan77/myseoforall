# Giai đoạn 2 — R2: Nhận xét và hướng dẫn triển khai tiếp

> Tài liệu này là nhận xét kiến trúc và hướng dẫn delivery tiếp theo dựa trên `docs/report-giai-doan-2-r1.md`, `docs/giai-doan-2-r1.md` và bằng chứng source hiện có. Đây không phải Sprint Plan, không khóa ticket/file manifest và không phải bằng chứng nghiệm thu production.

## 1. Kết luận hiện tại

Mức đã đạt phù hợp để ghi nhận:

> **SEO local foundation + offline AI provider envelope/fake implementation — partial**

Đã có:

- `SeoContext` với top-level allowlist và normalization sơ bộ.
- `LocalSeoAnalyzer` chạy offline với các rule nền tảng.
- `RankMathDetector` ở mức provisional presence/active detector.
- `AiProvider` và `FakeAiProvider` với offline envelope/fake response cơ bản.
- Unit tests cho foundation, SEO core và fake provider; kết quả được báo cáo là `10 tests, 54 assertions`.

Chưa được coi là hoàn tất:

- Privacy boundary dùng chung và nested typed context.
- Finding/output schema đầy đủ theo từng task.
- Rank Math adapter, ownership registry và runtime integration.
- AI Settings, secret storage/rotation và retention policy.
- Gateway/network client, Preview/Apply và compatibility/security hardening.

Không bật gateway production hoặc gửi dữ liệu ra ngoài ở trạng thái này.

## 2. Nhận xét bắt buộc để tránh overclaim

### Context

`SeoContext` hiện chủ yếu lọc top-level key và đệ quy sao chép giá trị bên trong. Không nên mô tả là đã có privacy boundary hoàn chỉnh hoặc nested schema dùng chung.

Cách ghi đúng:

> Context có top-level allowlist và normalization sơ bộ; nested privacy enforcement hiện chỉ tồn tại riêng trong fake provider và chưa phải boundary dùng chung cho provider payload.

Cần tránh:

- Khẳng định mọi nested secret đã bị loại bỏ.
- Khẳng định đã có payload size/depth limit.
- Dùng trực tiếp `SeoContext` làm payload gateway.

### Local analyzer

Finding hiện chưa đủ contract trong tài liệu mục tiêu vì còn thiếu `suggested_fix`. Docblock mô tả thứ tự severity nhưng implementation chưa chứng minh đã sort. Các rule heading, canonical, internal link, offer/schema và variation còn hẹp hơn mục tiêu.

Cần xử lý cả dependency `mb_strlen()` vì `composer.json` chưa yêu cầu hoặc kiểm tra `ext-mbstring`.

### Rank Math

Detector chưa phải adapter. Không được diễn giải `Rank Math inactive` là `My SEO For All owns`. Cần phân biệt `unknown`, `conflict`, `rank_math`, `wordpress` và `myseo_for_all` theo từng output; compatibility phải dựa trên matrix/version rule thật.

### AI provider

Increment offline đã có envelope và fake implementation cơ bản. Đây chưa phải provider production-ready:

- Interface contract không tự thay thế runtime response validator.
- Task-specific output schema chưa đủ sâu về field, ngôn ngữ, độ dài và field đích.
- Nested privacy test hiện chưa chứng minh mọi nested list item đều an toàn.
- Chưa có payload depth/size limit.
- Fake provider không phát hiện commercial facts đã nằm sẵn trong text hợp lệ.
- Chưa có network, authentication, secret storage hoặc gateway registry — đây là đúng guardrail hiện tại.

## 3. Hướng dẫn code tiếp theo theo dependency

### Increment A — Hoàn thiện typed context và provider payload boundary

Mục tiêu: tạo một boundary dùng chung trước khi provider thật hoặc fake provider nhận dữ liệu.

Yêu cầu:

1. Định nghĩa schema riêng cho `article/page` và `product/variation`, gồm kiểu dữ liệu, field bắt buộc/tùy chọn và provenance.
2. Dùng allowlist lồng nhau theo từng field; không copy arbitrary nested arrays.
3. Reject hoặc bỏ qua kiểu dữ liệu sai theo policy rõ ràng, không cast tùy ý thành string.
4. Giới hạn depth, tổng kích thước payload, độ dài text và số phần tử list.
5. Redact/reject `api_key`, `token`, `secret`, customer/order/PII ở mọi cấp.
6. Tách `ContextBuilder`/`ProviderPayloadBuilder` khỏi `SeoContext`; provider không nhận `WP_Post`, `WC_Product` hay object nền tảng.
7. Ghi source provenance và canonical precedence khi nhiều nguồn SEO cùng cung cấp field.

Acceptance:

- Nested forbidden fields trong `images`, `seo`, `variation` và list item bị reject/redact theo contract.
- Payload vượt depth/size/length limit bị từ chối an toàn.
- Input không bị mutate.
- Test chứng minh payload provider chỉ chứa field allowlist và không có secret/PII.

### Increment B — Hoàn thiện AI task/output schema offline

Mục tiêu: fake provider và provider contract dùng cùng schema version, không để UI diễn giải text tự do.

Yêu cầu:

- `seo_analysis`: danh sách finding có `code`, `severity`, `field`, `evidence`, `suggested_fix`.
- `title_meta_suggestion`: chỉ field được phép, giới hạn độ dài và ngôn ngữ.
- `product_description_draft`: field đích, ngôn ngữ, context provenance và cờ không suy diễn commercial facts.
- Validator kiểm tra kiểu, field ngoài allowlist, schema version, độ dài và status.
- Tách lỗi malformed request, unsupported task và invalid response.
- Fake response deterministic cho từng task; test response shape thành công cho từng task.

Acceptance:

- Mỗi task có fixture success và malformed fixture.
- Response sai schema không được chuyển thành output có thể Apply.
- Không có network call.

### Increment C — Sửa và tích hợp Local SEO Analyzer

Yêu cầu:

- Bổ sung `suggested_fix` cho mọi finding.
- Chuẩn hóa rule code và severity.
- Sort severity thật hoặc sửa docblock để phản ánh đúng contract.
- Viết boundary tests cho title/meta.
- Kiểm tra heading hierarchy, canonical hợp lệ, internal-link semantics.
- Bổ sung offer/schema consistency và parent/variation rules.
- Quyết định rõ `mbstring`: dependency bắt buộc + health check, hoặc fallback abstraction có test.

Acceptance:

- Finding luôn có đủ sáu field: `code`, `severity`, `message`, `field`, `evidence`, `suggested_fix`.
- Kết quả có thứ tự ổn định được test.
- Không fatal khi môi trường PHP thiếu extension được hỗ trợ theo policy.

### Increment D — Rank Math adapter và ownership registry

Yêu cầu:

- Tách detector khỏi adapter đọc dữ liệu.
- Phân biệt installed/active/version/compatible.
- Dùng compatibility matrix có version rule cụ thể.
- Ownership theo từng output: title, meta, JSON-LD, sitemap, AI draft.
- Có `unknown` và `conflict`; không tự claim ownership khi chưa xác nhận.
- Phát hiện nhiều SEO plugin và ngăn duplicate output.
- Chỉ đọc ở increment này; chưa tự chuyển ownership hoặc ghi Rank Math meta.
- Đăng ký status trong runtime/Health Check mà không lộ secret.

Acceptance:

- Rank Math unavailable/inactive/unsupported/multiple-plugin có status riêng.
- Không có output trùng trong coexistence tests.
- Không ghi dữ liệu Rank Math khi chưa có explicit approved adapter contract.

### Increment E — Runtime composition

Yêu cầu:

- Đăng ký các service/module qua composition root.
- Hook bài viết/trang và WooCommerce product/variation qua adapter an toàn.
- Không fatal khi WooCommerce/Rank Math không khả dụng.
- Giữ bootstrap idempotent và không gọi network trong boot.
- Health output chỉ hiển thị trạng thái không nhạy cảm.

Acceptance:

- Plugin boot thực sự có thể gọi local analyzer và detector qua runtime boundary.
- Hook không đăng ký lặp.
- Tests có cả dependency available/unavailable.

### Increment F — AI Settings và secret boundary

Chưa triển khai trước khi các policy Owner/decision gate tương ứng được chốt.

Yêu cầu kiến trúc tối thiểu:

- Settings riêng, không đưa secret vào core configuration.
- Gateway registry cố định cho MVP; không custom URL tùy ý.
- Secret server-side, masked display, rotation/replace policy.
- Capability/nonce/sanitization/validation riêng.
- Ô key trống khi update phải giữ key cũ.
- Không đưa secret vào HTML, JavaScript, browser storage, log, health, export, exception hoặc telemetry.

Acceptance cần có test redaction, permission, key retention và secret non-disclosure.

### Increment G — Gateway/network client

Chỉ bắt đầu sau decision gate có bằng chứng về gateway test environment, API contract/version, model, authentication, SDK/base URL compatibility, secret storage/rotation, timeout/retry/redirect, SSRF controls, retention và compatibility matrix.

MVP nên chỉ cho gateway từ registry cố định. Nếu sau này custom URL, phải kiểm tra HTTPS/TLS, DNS sau resolve/redirect, loopback/private/link-local/reserved IPv4/IPv6, DNS rebinding, port/method, redirect và timeout.

Acceptance:

- Không network call khi gate chưa đạt.
- Request chỉ được tạo từ provider payload boundary.
- Không ghi request/response body vào database, log, queue, cache, debug, exception hoặc APM/telemetry.
- Lỗi gateway không lộ credential hoặc nội bộ.

### Increment H — Preview/Apply

Chỉ triển khai sau output schema, ownership và secret/network boundaries.

Yêu cầu:

- Preview không ghi dữ liệu.
- Snapshot/hash context và field được preview.
- Đọc lại dữ liệu hiện tại khi Apply.
- Từ chối stale preview và yêu cầu tạo lại.
- Chỉ ghi field user đã xác nhận qua adapter sở hữu.
- Không sửa giá, tồn kho, review hoặc dữ liệu thương mại.
- Có error boundary/partial-write recovery; không làm mất dữ liệu gốc.

## 4. Test và bằng chứng bắt buộc

Mỗi increment phải có unit/contract/integration test phù hợp, không chỉ test file tồn tại. Cần ghi lệnh và output thực tế, hoặc CI artifact gắn với revision/commit.

Tối thiểu:

- Context nested privacy, typed fields, provenance, depth/size limits.
- Mỗi AI task success/malformed response.
- Analyzer rule boundaries và severity ordering.
- Rank Math status/compatibility/ownership/conflict.
- Runtime composition và idempotent hooks.
- Settings permission, key retention và redaction.
- Gateway policy/SSRF/timeout/retry/error mapping.
- Preview non-persistence và Apply stale rejection.
- Không lưu request/response.

Kết quả `10 tests, 54 assertions` chỉ nên ghi là **reported execution evidence** nếu chưa có log/CI artifact độc lập.

## 5. Trạng thái nghiệm thu R2

| Hạng mục | Trạng thái |
|---|---|
| Context contract | Top-level foundation; chưa đủ nested typed shared boundary |
| Local analyzer | Offline partial; thiếu finding/rule coverage |
| AI provider/fake | Offline envelope/fake cơ bản; task-specific validation còn thiếu |
| Rank Math | Provisional detector; chưa adapter/ownership registry |
| Runtime composition | Chưa đạt |
| AI Settings/secret storage | Chưa có |
| Gateway/network | Chưa có; production bị khóa |
| Preview/Apply | Chưa có |
| Compatibility/security hardening | Chưa đạt |
| Test evidence | Reported 10 tests/54 assertions; cần artifact tái lập |

## 6. Quyết định và handoff

Không nghiệm thu Giai đoạn 2 đầy đủ. Cho phép tiếp tục delivery offline theo Increment A–E, không network call. Increment F–H cần giữ các decision gate và guardrail nêu trên.

SL có thể phân rã các increment thành ticket, chọn file thực tế và sắp xếp dependency trong boundary; không được tự mở scope sang gateway production, ownership transfer, dữ liệu bị cấm hoặc auto-publish.

Mọi thay đổi public contract, privacy semantics, ownership, retention hoặc security guardrail phải quay lại Architecture/Owner review trước khi triển khai.
