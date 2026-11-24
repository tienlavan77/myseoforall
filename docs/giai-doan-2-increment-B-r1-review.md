# Phản biện — Giai đoạn 2 Increment B r1

Cập nhật: 19/11/2026 (UTC). Phạm vi review: đối chiếu kế hoạch
`docs/giai-doan-2-increment-B-r1.md` với source/test hiện tại và bằng chứng R3 đã lưu.

## Kết luận

Increment B là hướng triển khai hợp lý và giữ đúng các guardrail offline, không network,
không secret storage, không Apply. Tuy nhiên tài liệu chưa đủ chặt để làm acceptance
contract; nếu triển khai theo văn bản hiện tại, khả năng cao sẽ chỉ tách code của fake
provider mà vẫn thiếu một response boundary dùng chung. Exit gate chưa đạt trên bằng
chứng hiện tại.

Mức đề nghị: **chưa chấp thuận để đóng Increment B; cần bổ sung contract trước khi
implementation**.

## Blocker

### B1. Tiền đề “Increment A đã được nghiệm thu” chưa khớp bằng chứng

Mục 1 ghi Owner đã chốt nghiệm thu Increment A, nhưng
`docs/giai-doan-2-r3-baocao.md` vẫn ghi Increment A cần review độc lập và nghiệm thu
của chủ dự án chưa được chứng minh bởi receipt. Receipt `VERIFY-RUN-115e21b5-19ce-4aa7-b24b-ec13f7e53b14`
chỉ chứng minh `run_check` pass và `ready_for_review`; nó không phải owner approval.

Cần sửa wording thành “đã có code và verification receipt, đang chờ review/approval”
hoặc đính kèm evidence approval cụ thể. Không nên mở B dựa trên một trạng thái nghiệm
thu chưa có bằng chứng.

### B2. Chưa định nghĩa error contract có thể kiểm thử

Tài liệu yêu cầu phân biệt `malformed_request`, `unsupported_task` và
`invalid_response`, nhưng chưa chốt:

- hình dạng lỗi (exception hay response envelope);
- mã/category và message-safe policy;
- status hợp lệ, field bắt buộc khi lỗi, và có được phép có `output` hay không;
- quy tắc ưu tiên khi một request có nhiều lỗi.

“SE tự chọn exception” là đủ cho tên class, nhưng không đủ cho observable contract.
Cần chốt contract version, error category, exact keys/types và test cho từng cặp lỗi
để không có implementation khác nhau cùng được coi là pass.

## Major findings

### M1. Validator hiện vẫn là private logic của FakeAiProvider

`src/AI/Fake/FakeAiProvider.php` hiện gọi private `validateOutput()` ngay sau
`buildOutput()`. Không có service/contract dùng chung, không có public boundary để
provider tương lai hoặc caller đưa response vào kiểm tra. Vì vậy mục tiêu 3.1 và
R3 mục 4.109 chưa được phản ánh trong source hiện tại.

Cách kiểm chứng cần ghi rõ: validator được gọi độc lập từ test bằng literal response;
FakeAiProvider được cấu hình dùng validator đó; một response bị tamper hoặc provider
trả malformed response phải bị chặn trước khi caller nhận output.

### M2. Validation hiện tại không phải exact closed schema

Các thiếu sót cụ thể trong `validateOutput()`:

- `seo_analysis` chỉ kiểm tra finding là array và có sáu key; không từ chối key
  thừa, không kiểm tra exact type của từng value, list tuần tự, code/severity/field
  thuộc enum, độ dài, evidence hoặc suggested_fix.
- `title_meta_suggestion` và `product_description_draft` không từ chối key thừa;
  language/provenance chỉ cần là string; không có allowlist language/provenance.
- Product output chỉ kiểm tra `uses_commercial_facts === false`; các field thương mại
  hoặc lệnh publish thêm vào output vẫn có thể tồn tại vì không có exact-key check.
- Envelope do `generate()` tạo ra nhưng không có shared validator kiểm tra exact
  keys/types cho `provider`, `task`, `status`, `output`.

Do đó yêu cầu “không arbitrary field/object/list” và “không commercial mutation field”
chưa được chứng minh.

### M3. Limits và đơn vị đo còn không nhất quán

Mục 4.86 nói “giới hạn hợp lý” nên không phải acceptance có thể tái lập. Tài liệu cần
chốt giá trị, phạm vi áp dụng và đơn vị: mỗi scalar, mỗi finding, số finding, list
depth, tổng envelope size, Unicode code points hay bytes. Source hiện dùng
`strlen()` cho title/meta/description, trong khi Increment A report ghi Unicode code
points. Nếu tái sử dụng constants của payload boundary, phải ghi tên/version và test
biên inclusive/overflow.

### M4. Fixture hiện tại chưa độc lập và chưa đủ negative coverage

`tests/Unit/AiProviderTest.php` hiện kiểm tra response được Fake tạo ra; không có
validator contract test độc lập với implementation. Có success coverage cho ba task
ở mức provider, nhưng chưa có malformed fixture riêng cho từng task cho các trường hợp
thiếu/thừa key, sai scalar/list/object, enum, language, target, provenance, limit và
commercial mutation.

Một test chỉ gọi Fake rồi assert output không thể phát hiện validator bị bỏ qua hoặc
validator quá rộng. Cần literal fixtures cho validator, sau đó test integration riêng
chứng minh Fake dùng boundary chung và không mutate input/response.

### M5. Acceptance của task còn để nhiều điểm tùy ý

Các trường sau chưa được chốt dù chúng quyết định compatibility:

- finding code, severity và field enum;
- `seo_analysis` có language/message ngoài danh sách sáu field hay không;
- title/meta có bắt buộc cả hai field, cho phép rỗng, và giới hạn inclusive ra sao;
- language policy (ví dụ tập mã nào, normalization nào);
- provenance là label, object hay explanation; source labels nào được phép;
- product target chỉ là `product` hay cho phép field cụ thể;
- status success/error và response version khi mở rộng schema.

Nên đưa một bảng schema exact cho envelope và từng task vào tài liệu trước khi code.

### M6. “Safe để hiển thị/preview” chưa có định nghĩa đủ dùng

Schema validation không tự bảo đảm output an toàn khi render. Cần nêu rõ policy cho
HTML/markup, control characters, URL nếu response tương lai chứa URL, và việc output
chỉ là suggestion/preview, không phải sự thật thương mại và không có quyền Apply.
Nếu chưa muốn giải quyết output sanitization ở B, phải đổi wording thành
“schema-safe và bounded để caller tiếp tục xử lý”, tránh diễn giải thành XSS hoặc
truthfulness guarantee.

## Điểm đã đúng

- Giữ request/context boundary của Increment A độc lập với response boundary.
- Giữ offline, không network, không secret storage, không persistence và không write.
- Chọn ba task cụ thể và yêu cầu không mutate.
- Yêu cầu receipt gắn revision/checksum và giữ gate F–H.
- Nhắc không dùng `SeoContext` thay cho provider boundary.

## Điều kiện cần sửa trước khi chấp thuận

1. Sửa trạng thái Increment A hoặc bổ sung approval evidence.
2. Chốt response envelope exact schema, version, status và error categories.
3. Chốt schema đóng cho từng task: key/type/enum/optional/length/language/provenance.
4. Chốt limits, đơn vị đo, depth semantics và byte budget bằng constant/version cụ thể.
5. Đặt shared validator ở contract/service độc lập; Fake chỉ điều phối và gọi validator.
6. Thêm literal success + malformed fixtures độc lập cho cả ba task, gồm tampered
   response và mọi commercial mutation field.
7. Tách test validator khỏi test Fake; chứng minh input/response không bị mutate và lỗi
   không chứa secret/payload đầy đủ.
8. Ghi exact verification command, revision/checksum và receipt cho focused contract
   test cùng unit scope; không coi fixture offline là bằng chứng production readiness.

## Bằng chứng đã đối chiếu

| Path | SHA-256 |
| --- | --- |
| `docs/giai-doan-2-increment-B-r1.md` | `e1b7c7f5a63224cf460af75bae8baf999097f1c032049ec9a4b627a0528ee114` |
| `docs/giai-doan-2-r3.md` | `ddf972458b4b7db24120df919088f04718bf7d4def8c6e421a721acca4311c77` |
| `src/AI/Fake/FakeAiProvider.php` | `4b7398147069315e48e649ebff14fe39a0461c3b4a7c6b80d482c45673de67ee` |
| `src/AI/Contracts/AiProvider.php` | `b5a87d137ecf0cf1400eb0e1077cd8c59ce3974069cdd4e99ade129c8aef5bad` |
| `tests/Unit/AiProviderTest.php` | `ac923147c78ef9869d9b980b3ccb29f72199979340fe7d70f845a6b835b11946` |
| `docs/giai-doan-2-r3-baocao.md` | `5b7690ea7e369671bd2b9899ec37c5d6a51daae8910c38eca108a6407744aaab` |

Review này là đánh giá tài liệu và source/test hiện tại; chưa thực hiện thay đổi runtime,
chưa chạy lại PHPUnit và không coi verification receipt hiện có là bằng chứng production
compatibility.
