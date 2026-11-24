<!-- Reviews Increment G's core contracts and acceptance boundaries for the Architecture Manager. -->
# Phản biện kiến trúc — Increment G

Ngày: 23/11/2026. Người nhận: Architecture Manager (AM).

## Kết luận và phạm vi

Đồng ý với hướng O1–O4: nối provider qua gateway, giữ nguyên schema đã có, bảo vệ kết nối, không phát sinh mạng khi khởi động và không lưu payload. Một interface transport cùng một implementation production là phù hợp với phạm vi G.

Tuy nhiên, bản hiện tại cần chốt các điểm dưới đây trước implementation. Các đề nghị chỉ làm rõ auth, transport, response và bằng chứng nghiệm thu đã được AM đặt ra; không mở Preview/Apply, lịch sử, option mới, streaming, queue hay router.

Nguồn phản biện: `docs/giai-doan-2-g.md`, SHA-256 `0c83c5d3eba0ec08f55f298234a873c4ec3697666f60482d32c94cd273415102`. Đọc đầy đủ 172 dòng và đối chiếu các symbol liên quan trong working tree. Đây là phản biện kế hoạch, không phải kết quả nghiệm thu triển khai G.

## Các điểm cần AM chốt

### G-FB-1 — Blocker: Callback nhận header không tự bảo đảm bí mật không thoát ra ngoài

**Tham chiếu:** O1, dòng 33–46; O4, dòng 119–121; AC2.

`withAuthorizedHeaders(callable $operation): mixed` truyền toàn bộ auth headers vào một callable rồi trả kết quả tùy ý. Callable có thể trả chính headers, giữ bản sao hoặc ném exception mang dữ liệu đó. Vì vậy yêu cầu “không bao giờ trả header/secret” không được bảo đảm chỉ bằng chữ ký này và `finally`. Xóa biến cục bộ cũng không xóa các bản sao đã truyền sang callback.

Cần xác định callback là mã nội bộ được tin cậy của provider/transport; không expose nó như cổng callback tùy ý cho UI/hook. Khóa kết quả được phép đi qua seam, giới hạn vòng đời header và quy tắc xử lý exception từ callback. Không mô tả `sodium_memzero` như bằng chứng đã xóa mọi bản sao trong PHP.

Zero-retention còn bao gồm exception trace: dù message tĩnh, trace có thể giữ argument là headers hoặc body, tùy cấu hình runtime. Cần chỉ rõ cách bảo vệ argument nhạy cảm và giới hạn runtime được hỗ trợ; không chỉ kiểm tra `getMessage()`. Không nối raw exception làm `previous` khi nó có thể giữ dữ liệu này.

**Acceptance cần bổ sung trong AC2/AC6:** lỗi phát sinh bên trong đường callback/transport không làm lộ header, prompt hoặc response qua dữ liệu exception được phép đưa ra ngoài; service không giữ chúng sau khi kết thúc lời gọi.

### G-FB-2 — Major: Readiness đang yêu cầu xác minh credential trước chính bước giải mã

**Tham chiếu:** O1, dòng 38–41; AC1/AC2.

O1 yêu cầu credential hợp lệ trước khi giải mã. Với credential DB, `load()` chỉ chuẩn hóa option/envelope; `credentialStatus()` phải gọi `decryptCredential()` để biết key dùng được. Điều kiện hiện tại cần diễn đạt thành hai bước: kiểm tra settings trước; sau đó resolve/giải mã và xác minh credential; chỉ khi cả hai đạt mới gọi operation. Mọi nhánh thất bại vẫn phải có 0 DNS/HTTP call.

Thứ tự Constant → Env → DB cần nói rõ chỉ chuyển nguồn khi nguồn ưu tiên **vắng mặt**. `credentialStatus()` hiện trả `invalid_environment` khi constant/env hiện diện nhưng sai; AC1 cũng yêu cầu chặn trạng thái đó. G không nên vô tình fallback sang DB và gửi request bằng một key khác.

AM nên khóa bảng ánh xạ lỗi cho settings absent/corrupted, key absent, invalid_environment và decryption_failed, kể cả thứ tự ưu tiên khi đồng thời có nhiều lỗi. Giữ phạm vi này ở authorization của G; không mở lại persistence/migration của F.

### G-FB-3 — Blocker: IP pinning cần khóa cả đường proxy và kết nối thực tế

**Tham chiếu:** O2, dòng 64–76; AC3.

Hướng `CURLOPT_RESOLVE` + URL gốc + TLS verify là đúng. Tuy nhiên, cấu hình pin cho hostname đích chưa đủ nếu cURL sử dụng proxy từ môi trường: proxy có thể là nơi resolve và kết nối tới đích. Khi đó test chỉ nhìn vào mảng cURL options chưa chứng minh đường gửi đã dùng IP được duyệt.

Với transport tối giản của G, cần khóa kết nối trực tiếp và vô hiệu hóa proxy môi trường; fail-closed nếu không bảo đảm được. Mỗi attempt dùng kết nối gắn với tập IP vừa duyệt, không tái sử dụng trạng thái DNS/kết nối ngoài phạm vi request trái với invariant này.

Cần ghi rõ cú pháp pin cho IPv4/IPv6 và cách xử lý URL là IP literal; không áp dụng máy móc chuỗi `host:443:ip` của ví dụ cho mọi dạng IPv6. Đây là hoàn thiện invariant O2, không yêu cầu thêm loại transport.

**AC3:** ngoài options, kiểm tra đường thực thi đã nhận đúng IP được duyệt, proxy bị tắt và mỗi attempt đều đi qua kiểm định. Fake provider transport chỉ chứng minh provider orchestration; cần seam trong production adapter để kiểm tra chính adapter đó mà vẫn chạy offline.

### G-FB-4 — Major: Category lỗi và contract retry chưa nhất quán

**Tham chiếu:** O2, dòng 70, 74; O3, dòng 98–102; O4, dòng 112–118; AC5.

O2 dùng `TRANSPORT_UNAVAILABLE` và `UPSTREAM_ERROR`, nhưng O4 không đưa chúng vào tập đóng. Đồng thời `TRANSPORT_FAILED` đang gom cả timeout tạm thời, TLS fail, thiếu capability và 5xx; O3 chỉ cho retry một phần các lỗi đó. Chỉ dựa vào category tổng hợp sẽ dễ retry cả lỗi TLS.

Cần dùng một bảng thống nhất: loại lỗi thấp tầng → category trả cho caller → có/không retry. Có thể giữ nguyên tập category O4 và phân biệt nguyên nhân nội bộ bằng mã an toàn; không cần thêm framework lỗi. Chốt mapping cho mọi status ngoài 200, gồm 3xx, 4xx khác và 5xx không thuộc tập retry.

Chốt backoff hữu hạn và timeout áp dụng theo attempt hay toàn bộ generate; kiểm định DNS cũng cần giới hạn thời gian để không nằm ngoài cam kết timeout. Sleeper no-op là seam kiểm thử, chưa phải chính sách chờ production.

**AC5:** bao phủ 0/1/2 retries, timeout/network tạm thời, 429/502/503/504, TLS và thiếu capability không retry; trước mỗi attempt đều xác minh/pin lại. Giữ giới hạn tối đa 3 HTTP attempts như AM đã nêu.

### G-FB-5 — Major: Response parsing có thể bỏ qua phần dữ liệu chưa được hậu kiểm

**Tham chiếu:** O3, dòng 103–106; AC4.

Chỉ đọc `content[0]` chưa quy định xử lý content block tiếp theo, tool-use hoặc response kết thúc vì cắt ngắn. Một block đầu có JSON hợp lệ không đủ chứng minh toàn bộ response được chấp nhận theo giao thức G.

Cần chốt tập response Messages được hỗ trợ: cấu trúc text block, số block/cách kết hợp được phép, trạng thái kết thúc chấp nhận và cách từ chối block không hỗ trợ. Không cần mở streaming hay tool-use; chỉ cần fail-closed với dạng ngoài phạm vi.

Câu “hoặc fallback nếu gateway trả thẳng object envelope” cũng cần một nhánh rõ ràng. Nếu giữ fallback, phải validate **nguyên envelope nhận được**, gồm task/provider/status/schema và extra keys, trước khi trả; không chỉ lấy output rồi dựng envelope mới để che mất sai lệch từ upstream. `AiOutputValidator::validate()` hiện đã kiểm đúng các trường này.

**AC4:** kiểm thử response nhiều block/không hỗ trợ, thiếu text, cắt ngắn, JSON lỗi và direct envelope sai task/provider/extra key; tất cả dừng không retry. Nếu AM bỏ fallback thì chỉ cần test từ chối nó.

### G-FB-6 — Major: AC2/AC6 chưa đủ bằng chứng cho tuyên bố zero-retention

**Tham chiếu:** O4, dòng 120–121; AC2/AC6.

`OptionStore writes === 0` chứng minh không ghi qua OptionStore, chưa chứng minh không ghi qua transient/cache/file/log hoặc service property. Tương tự, kiểm tra message không bao phủ trace ở G-FB-1.

Cần gắn bằng chứng vào đúng cổng side effect mà implementation thực sự có thể gọi: kiểm tra code path và spy tại cổng được sử dụng, cùng cả nhánh thành công, lỗi transport và lỗi validator. Không cần dựng hệ thống audit/log mới hoặc spy toàn bộ WordPress.

Phân biệt zero-retention của plugin với retention tại gateway bên thứ ba: test offline của G không chứng minh được chính sách lưu trữ của upstream.

Test 64 KB cần thực sự gọi write callback với dữ liệu tới ngưỡng/vượt ngưỡng và chứng minh ngắt nhận; chỉ kiểm tra hằng số hoặc fake transport trả body nhỏ chưa chứng minh giới hạn của production adapter.

### G-FB-7 — Major: Phải tách baseline, health hiện hữu và điều kiện đóng giai đoạn

**Tham chiếu:** phần mở đầu; O4, dòng 123–125; AC6; mục 4.

G dẫn checksum `Plugin.php = 3af2…94df` của snapshot F. Working tree hiện là `12cd…688c`, đúng với addendum F-r5-RP về bootstrap. Cần chọn baseline thực thi rõ ràng hoặc ghi rõ thay đổi bổ sung được kế thừa; không dùng receipt snapshot cũ để chứng minh working tree mới. Đây là yêu cầu truy vết, không tự kết luận snapshot F sai hay mở lại toàn bộ F.

`Plugin::safeAiHealth()` hiện trả đúng bốn khóa đã nêu; `ai_configured` chỉ biểu thị credential configured/environment, không chứng minh base URL/model/DNS/transport sẵn sàng. Giữ nguyên semantics đó; readiness đầy đủ phải được thực thi tại generate, không suy từ health và không làm DNS probe trong health.

“G là mảnh cuối khép Giai đoạn 2” nên là mục tiêu delivery. Pass unit offline không tự đóng gate nghiệm thu, không chứng minh kết nối gateway thật hoặc tương thích production WordPress. AC6 cần giữ phân biệt verification kỹ thuật, review độc lập và quyết định nghiệm thu của Owner trong đúng phạm vi G.

## Bằng chứng và giới hạn

Parent trực tiếp đọc kế hoạch và các symbol `validateStaticUrl`, `AiProviderException`, `FakeAiProvider::validateRequest`, `AiOutputValidator::validate`, `AiSettingsRepository::load/credentialStatus/environmentCredential`, `Plugin::safeAiHealth`.

Hai tác vụ read-only `g_auth` và `g_runtime` bổ sung đối chiếu credential và composition; đã thu kết quả cùng observed_files rồi tổng hợp độc lập. Evidence từ source_worker `parent:4c01af79-98c0-4d10-b6ed-c815d4911fe6` được tái sử dụng khi còn fresh.

| Source | SHA-256 |
| --- | --- |
| `src/AI/AiSettingsRepository.php` | `e3429010064c445936780ec5fa803b2566ef82d2b80ce8bb48a1d940b8b81c4f` |
| `src/AI/GatewayEndpointValidator.php` | `727660e36a5612dd8cffc7d20dc5134636230cd4e5cb19a6fc3edc0b15a3948a` |
| `src/AI/Contracts/AiProviderException.php` | `93d257acf0e31ccc073df215a87d27b657716a6e5adf9cd166d70b2dbdc78e3c` |
| `src/AI/Contracts/AiOutputValidator.php` | `305cebc44755da9cb92323c50a67d99958e8723583de1cb7472c1933c474e2c4` |
| `src/AI/Fake/FakeAiProvider.php` | `b0d4dc6a76b5d22d6166de275ebd7821975dafbadf251fffb1127cd238a74378` |
| `src/Core/Plugin.php` | `12cdaddbeaf3a635d2926a9c06e2622af2b7aadc100ed078f7a11c927f84688c` |
| `docs/giai-doan-2-f-r5-rp.md` | `6a9727d5bfc9e7d6cab0c15ace1dd79d4e497c58980722b6bd5f5e149e2e6d2f` |

Một yêu cầu đọc symbol `validateResolvedIps` không chạy được vì automatic approval review lỗi “completed without an assessment payload”; đây không phải kết luận hành động không an toàn. Không vòng qua cổng kiểm soát và không coi symbol đó đã được đọc; nhận xét transport ở trên dựa vào contract AM và source đã đọc được.

Không sửa source/test, không chạy PHPUnit hoặc kết nối gateway thật trong lượt phản biện tài liệu này. Các vấn đề nêu trên là điểm cần khóa trong kế hoạch, không phải tuyên bố đã tái hiện lỗi production.

AM cần giải quyết G-FB-1 đến G-FB-7 trong O1–O4 và AC1–AC6; các lựa chọn triển khai còn lại có thể để SE quyết định trong boundary đã khóa.

[CRITIQUE: DISCUSS]
