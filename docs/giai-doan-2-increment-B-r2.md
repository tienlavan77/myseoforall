# Giai đoạn 2 — Increment B r2: Hướng dẫn xử lý sau review

> Handoff Architecture cho System Engineer sau review báo cáo Increment B r1. Tài liệu này là hướng dẫn xử lý và acceptance contract; không phải Sprint Plan, ticket list hay mutable-file manifest.

## 1. Kết luận và trạng thái

- Increment B đã có implementation offline và các receipt kỹ thuật được báo cáo là pass.
- Chưa đóng exit gate Increment B.
- Lý do chính: receipt hiện được báo cáo gắn với working-tree snapshot trước commit triển khai `7ff338c10659cf0161ac33a4c848dac4774660df`, chưa có receipt chứng minh trực tiếp đúng commit/source snapshot bàn giao đã được kiểm thử.
- Working tree hiện còn `.phpunit.result.cache` modified và `docs/giai-doan-2-increment-B-r1.md` untracked; phải tách rõ artifact kiểm thử khỏi source/test bàn giao.
- B vẫn thuộc **Giai đoạn 2 — SEO Core**. Không mở Increment C hoặc F–H trước khi exit gate này được xác nhận.

Các receipt là bằng chứng kiểm thử, không tự thay thế Reviewer/Owner approval. Không tuyên bố production readiness, semantic PII completeness, HTML/XSS safety hoặc commercial truthfulness.

## 2. Mục tiêu xử lý

Tái xác nhận Increment B trên một revision cố định và chứng minh response boundary dùng chung không thể bị bypass. Sau xử lý phải có:

1. source/test snapshot bất biến, có checksum và revision;
2. focused contract test và unit scope chạy trên đúng snapshot đó;
3. test integration chứng minh `FakeAiProvider` dùng validator được inject;
4. test expected-task mismatch, tampered response và error precedence;
5. report/receipt không gán kết quả của working tree khác cho commit bàn giao.

## 3. Việc bắt buộc

### B2-R1 — Commit-bound verification

SE phải:

- Xác định revision chứa đầy đủ Increment B source và tests.
- Đối chiếu `git show`/checksum của tối thiểu:
  - `src/AI/Contracts/AiOutputValidator.php`;
  - `src/AI/Contracts/AiProviderException.php`;
  - `src/AI/Fake/FakeAiProvider.php`;
  - `tests/Unit/AiOutputContractTest.php`;
  - `tests/Unit/AiProviderTest.php`;
  - `composer.json`, `composer.lock`, `phpunit.xml` nếu được test snapshot sử dụng.
- Chạy focused contract test và toàn bộ `tests/Unit` qua `run_check` trên đúng revision/snapshot đã đối chiếu.
- Ghi nguyên command, `run_id`, status, exit code, evaluated time, commit/base SHA và phạm vi test.
- Không dùng kết quả `run_command`, PHPUnit cache hoặc receipt có commit/base SHA khác để đóng gate.

Nếu Forge chỉ chạy được trên working tree overlay, report phải ghi rõ overlay và checksum từng file; không gọi đó là commit-bound verification.

### B2-R2 — Shared validator integration

Giữ `AiOutputValidator` là service/contract độc lập. Bổ sung test chứng minh:

- `FakeAiProvider` nhận một validator được inject.
- Validator inject có thể ghi nhận invocation hoặc cố ý reject response; Fake không thể trả response thành công khi validator reject.
- Provider không tạo một private output validator khác làm boundary duy nhất.
- Response literal được validate trực tiếp không cần gọi Fake.
- Input request và response không bị mutate.

Không cần khóa tên mock/helper; acceptance là hành vi boundary.

### B2-R3 — Expected task và tamper

Bổ sung contract tests cho:

- response task khác `expectedTask` bị reject với `invalid_response`;
- thay đổi `schema_version`, `provider`, `status`, `task` hoặc output sau khi build bị reject;
- thêm key arbitrary, nested object/list ngoài schema, `publish`, `price`, `stock`, `sku`, `status` hoặc mutation field bị reject;
- malformed envelope được phân loại đúng trước khi validate output.

### B2-R4 — Error precedence và safe error

Chốt và test thứ tự:

1. envelope/key/type malformed → `malformed_request` nếu lỗi thuộc request caller;
2. task không nằm trong task allowlist → `unsupported_task`;
3. response envelope/output sai contract → `invalid_response`;
4. không đưa secret, credential, rejected value hoặc toàn bộ payload vào exception/log.

Nếu current implementation không phân biệt được request error và response error theo boundary, SE phải báo Architecture trước khi đổi public contract; không âm thầm đổi nghĩa response v1.

## 4. Contract không được nới lỏng

Giữ nguyên các guardrail của r1:

- response envelope `myseo.ai.response.v1` exact keys;
- ba task: `seo_analysis`, `title_meta_suggestion`, `product_description_draft`;
- closed task schema, exact types/enums và không arbitrary fields;
- Unicode code-point limits, tối đa 50 findings, depth tối đa 4 và JSON tối đa 12.000 bytes;
- không tự cast, cắt hoặc redact âm thầm;
- không network, gateway, credential, secret storage, persistence, Apply, publish hoặc WordPress/WooCommerce write;
- output chỉ là suggestion/preview bounded, không phải nguồn sự thật thương mại.

R2 không mở thêm tính năng AI, không triển khai Increment C (local analyzer), D (Rank Math) hoặc E (runtime wiring), ngoại trừ test wiring cần thiết và không có side effect.

## 5. Acceptance criteria và exit gate

Increment B chỉ được đề nghị đóng khi tất cả điều kiện sau có bằng chứng:

- Shared validator độc lập tồn tại và được gọi bởi Fake qua dependency injection.
- Literal success fixtures cho cả ba task pass.
- Literal malformed/tampered fixtures cho từng task pass, gồm exact key/type/enum/limit, expected-task mismatch và commercial mutation.
- Error categories và precedence được test; message không chứa payload nhạy cảm.
- Input/response không bị mutate.
- Focused contract suite và toàn bộ `tests/Unit` pass trên đúng revision/snapshot đã bàn giao.
- Report chứa checksum source/test, command, run ID và commit/base SHA khớp nhau.
- Reviewer review độc lập và Owner xác nhận exit gate.

Receipt `passed` chỉ thỏa điều kiện verification; không tự tạo approval.

## 6. Quy trình báo cáo cho SE

1. Cập nhật source/test trong phạm vi B2-R2 đến B2-R4.
2. Chạy verification đúng kênh được phê duyệt.
3. Đối chiếu revision/checksum sau verification; nếu source/test đổi sau run thì receipt cũ mất hiệu lực cho snapshot mới.
4. Tạo báo cáo kết quả gồm: thay đổi, test/receipt, checksum, giới hạn và phần chưa đạt.
5. Không tự đánh dấu `pass` hoặc mở Increment C/F–H; gửi Reviewer/Owner xác nhận.

## 7. Evidence hiện có

- Kế hoạch r1: `docs/giai-doan-2-increment-B-r1.md` — `sha256:ebddc6618352cab112ffd45023c7991755335e8623b65e73b9283400251f5358`.
- Review: `docs/giai-doan-2-increment-B-r1-review.md` — `sha256:f82ebe30203ba703c5cc297f93ad84f1ef9bf56dbfede135e59f190db213ad3b`.
- Báo cáo r1: `docs/giai-doan-2-increment-B-r1-report.md` — `sha256:0e10cd770954e1e650b4388c53eac62c68991fc6033dc0e44bd905888e246d63`.
- Implementation commit được report nêu: `7ff338c10659cf0161ac33a4c848dac4774660df`.
- Reported receipts: `VERIFY-RUN-aea19692-7ffc-403b-8732-ff98df3f5da4`, `VERIFY-RUN-27220cec-1bb2-426c-9233-56ef2997290b`, `VERIFY-RUN-a67862bf-786c-4ed6-851d-467d36a570dc`.

Các evidence trên là căn cứ để xử lý, không phải thay thế receipt mới nếu snapshot/revision thay đổi.
