# Giai đoạn 2 — Increment B r1: Shared AI Output Schema

> Hướng dẫn Architecture cho System Engineer sau khi Project Owner yêu cầu “chốt A”. Tài liệu này khóa boundary, contract và acceptance ở cấp increment; không phải Sprint Plan, ticket list hay mutable-file manifest.

## 1. Quyết định và trạng thái đầu vào

- Owner đã chỉ đạo chốt Increment A trong phạm vi **shared typed provider boundary, hoàn toàn offline**. Đây là approval của Owner trong hội thoại hiện tại; receipt kiểm thử không tự thay thế approval.
- Bằng chứng kỹ thuật đối chiếu: `docs/giai-doan-2-r3-baocao.md`, code A tại commit `358b745769ec99bf2a557de547a147546d14514e`, và receipt `VERIFY-RUN-115e21b5-19ce-4aa7-b24b-ec13f7e53b14` cho `tests/Unit`.
- Increment B tiếp tục thuộc **Giai đoạn 2 — SEO Core**.
- Chỉ triển khai response contract và validation offline. Không mở gateway, network, secret storage, Preview/Apply, ownership transfer hoặc auto-publish.

## 2. Mục tiêu và boundary

Tạo một **shared AI output boundary** dùng chung cho provider offline và provider tương lai, tách khỏi private logic của `FakeAiProvider`. Response chỉ được trả cho caller khi đúng schema, bounded và có thể tiếp tục xử lý/hiển thị theo policy của caller. “Schema-safe” **không** có nghĩa là đã sanitize HTML/XSS, xác minh sự thật thương mại hoặc cấp quyền Apply.

Hai boundary phải độc lập:

1. request/context boundary của Increment A trước khi provider xử lý;
2. response boundary của Increment B trước khi caller nhận output.

Không dùng `SeoContext`, `WP_Post`, `WC_Product` hoặc raw context để bypass một trong hai boundary.

## 3. Contract bắt buộc

### 3.1 Response envelope — `myseo.ai.response.v1`

Response thành công phải là object đóng, exact keys:

| Key | Kiểu | Quy tắc |
| --- | --- | --- |
| `schema_version` | non-empty string | chính xác `myseo.ai.response.v1` |
| `provider` | non-empty string | provider identifier; không chứa credential |
| `task` | string | một trong ba task được duyệt |
| `status` | string | thành công: `ok` |
| `output` | object | schema đúng task, không arbitrary keys |

Không có key thừa, không cast kiểu sai, không `output` tùy ý. Response lỗi dùng exception/service result theo convention hiện có nhưng observable error contract phải có:

- category: một trong `malformed_request`, `unsupported_task`, `invalid_response`;
- safe code/message không chứa secret, credential hoặc toàn bộ payload;
- không có output thành công; nếu dùng envelope lỗi thì phải chốt exact schema riêng trước khi implementation.

Quy tắc ưu tiên: kiểm tra envelope/request shape trước; schema version hoặc task sai trả category tương ứng; chỉ validate output sau khi envelope và task hợp lệ. `invalid_response` dành cho output/envelope response sai contract.

### 3.2 `seo_analysis`

`output` exact keys: `findings` (list tuần tự, tối đa 50 phần tử). Mỗi finding exact keys:

| Key | Kiểu/quy tắc |
| --- | --- |
| `code` | enum finding code mà contract hiện hành đã dùng; code lạ bị reject |
| `severity` | enum `info`, `warning`, `error` |
| `message` | non-empty string, tối đa 2.000 Unicode code points |
| `field` | enum field SEO được contract cho phép; không nhận field thương mại tùy ý |
| `evidence` | string, tối đa 2.000 Unicode code points |
| `suggested_fix` | string, tối đa 2.000 Unicode code points |

SE phải lấy enum `code`/`field` từ rule/finding contract thực tế đã khảo sát và ghi vào test fixture; không mở rộng enum ngầm. Không có key thừa, nested object/list hoặc command.

### 3.3 `title_meta_suggestion`

`output` exact keys: `target_field`, `value`, `language`, `provenance`.

- `target_field`: enum `title`, `meta_description`;
- `value`: non-empty string, tối đa 2.000 Unicode code points;
- `language`: mã ngôn ngữ lower-case hợp lệ theo policy hiện hành, tối thiểu `vi` được hỗ trợ; không tự gắn nhãn khi không hợp lệ;
- `provenance`: non-empty string thuộc allowlist source labels đã chốt; không phải credential và không phải arbitrary object.

Nếu implementation cần field tùy chọn, phải cập nhật version/compatibility trước; không tự thêm key.

### 3.4 `product_description_draft`

`output` exact keys: `target`, `content`, `language`, `provenance`, `uses_commercial_facts`.

- `target`: chính xác `product`;
- `content`: non-empty string, tối đa 4.000 Unicode code points;
- `language`: mã lower-case hợp lệ, tối thiểu `vi`;
- `provenance`: source label hợp lệ dạng string;
- `uses_commercial_facts`: boolean chính xác `false` trong Increment B.

Không cho key/field điều khiển `price`, `stock`, `sku`, `status`, `publish`, `review`, `schema` hoặc mutation thương mại. Không coi `false` là bằng chứng nội dung đã đúng sự thật.

## 4. Limits, safety và semantics

- Đo text bằng **Unicode code points**, không dùng `strlen()` để thay thế; giới hạn là inclusive, vượt giới hạn bị reject.
- `seo_analysis.findings` tối đa 50; mọi array phải tuần tự, không nested list/object ngoài schema.
- Tổng response JSON UTF-8 tối đa **12.000 bytes**, dùng cùng cách encode deterministic đã quy định ở Increment A; nếu task content 4.000 code points làm envelope vượt budget thì reject.
- Mỗi response không vượt depth 4; không tự cắt hoặc redact âm thầm.
- Reject sai kiểu, key lạ, language/provenance không hợp lệ, control characters không được policy cho phép và text rỗng ở trường bắt buộc.
- B chưa giải quyết HTML sanitization, mọi dạng semantic PII hoặc truthfulness; caller phải coi output là suggestion/preview bounded, không phải nguồn sự thật.
- Không lưu request/response lâu dài, không network, không side effect và không log payload đầy đủ.

## 5. Tương thích với Increment A và provider

- Request vẫn phải dùng `AiProvider::REQUEST_SCHEMA` và payload `provider-payload-2` do `ProviderPayloadBuilder` tạo.
- `FakeAiProvider` chỉ điều phối: nhận request hợp lệ, tạo response offline và gọi shared response validator trước khi trả response.
- Validator phải có API/service độc lập để test bằng literal success/malformed response; không chỉ được gọi gián tiếp qua Fake.
- Nếu đổi `myseo.ai.response.v1`, phải tăng version hoặc ghi compatibility/migration và báo Architecture; không âm thầm đổi nghĩa response.

## 6. Test và acceptance criteria

SE phải bổ sung contract tests độc lập với implementation của Fake:

1. Literal success fixture riêng cho cả ba task, kiểm tra exact keys/types/enums/limits.
2. Literal malformed fixture riêng từng task cho thiếu/thừa key, sai scalar/list/object, sai version/task, enum sai, language/provenance sai và text/response vượt limit.
3. Tampered response: sửa envelope, thêm commercial mutation field, thêm arbitrary nested data; tất cả bị reject trước khi caller nhận output.
4. Error contract kiểm tra category, safe message và quy tắc ưu tiên khi có nhiều lỗi.
5. Test input/response không bị mutate; không có secret hoặc toàn bộ payload trong exception/log.
6. Integration test chứng minh Fake dùng validator chung và không có private validator còn sót làm boundary duy nhất.
7. Test offline qua kênh verification được phê duyệt; ghi command, run ID, revision và checksum source/test. Không dùng fixture offline để tuyên bố production readiness.

**Exit gate:** shared validator độc lập, ba task có success/malformed fixtures, acceptance trên pass, receipt đối chiếu được và Reviewer/Owner xác nhận. Không mở F–H chỉ vì B pass.

## 7. Không làm trong B

- Không gọi Anthropic hoặc gateway.
- Không thêm API key, secret rotation, retention hay permission UI.
- Không tạo Preview/Apply, snapshot, stale-preview rejection hoặc optimistic concurrency.
- Không publish, đổi giá/tồn kho hoặc chuyển ownership SEO.
- Không mở rộng sang C/D/E, trừ wiring tối thiểu cần thiết để test contract mà không có side effect.

## 8. Hướng dẫn delivery cho SE

1. Khảo sát source/test hiện tại và chốt enum `code`/`field` bằng evidence; không đoán enum từ tên ví dụ.
2. Viết shared validator và literal contract fixtures trước/cùng implementation; giữ thay đổi incremental.
3. Nếu source hiện tại không thể giữ response v1 mà không breaking, dừng và báo Architecture thay vì âm thầm sửa contract.
4. Chạy focused contract test và toàn bộ unit scope qua verification được phê duyệt; đối chiếu snapshot/checksum thực tế.
5. Báo cáo rõ phần đạt, phần chưa đạt, giới hạn semantic PII/HTML/truthfulness và không tự đánh dấu exit gate.
6. Chờ Reviewer/Owner xác nhận B trước khi bắt đầu C hoặc F–H.

## 9. Bằng chứng tham chiếu và phản biện

- `docs/giai-doan-2-r3.md` — hướng dẫn Increment B tại mục 4.
- `docs/giai-doan-2-increment-B-r1-review.md` — phản biện B1–B6; các điểm blocker đã được phản ánh trong bản cập nhật này.
- `docs/giai-doan-2-r3-baocao.md` — code/receipt và giới hạn của Increment A.
- `src/AI/Contracts/AiProvider.php` — request/response contract hiện tại.
- `src/AI/Fake/FakeAiProvider.php` — provider offline; validator private hiện tại phải được thay bằng shared boundary.
- `tests/Unit/AiProviderTest.php` — test hiện tại; cần tách contract coverage độc lập.

Tài liệu này không cấp quyền triển khai network, secret, persistence hoặc Apply. Mọi thay đổi chạm các boundary đó phải quay lại Architecture/Owner decision gate.

### Trạng thái review

Bản r1 đã được cập nhật theo phản biện `docs/giai-doan-2-increment-B-r1-review.md`. Increment B **chưa được nghiệm thu** và chưa được phép mở các gate ngoài phạm vi nêu trên.