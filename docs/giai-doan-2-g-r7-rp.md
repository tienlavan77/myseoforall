# Báo cáo Increment G r7

Ngày: 24/11/2026.

## Phạm vi thực hiện

R7 tiếp tục hoàn tất bằng chứng kiểm chứng offline theo [hướng dẫn r7](giai-doan-2-g-r7.md). Không thay đổi logic trong `src/`; dùng resolver, transport, clock và option store giả để tránh DNS/gateway thật.

Các thay đổi test đang có trên working tree trong `tests/Unit/IncrementGTest.php` được giữ và kiểm tra lại. Test hiện bao phủ:

- readiness trước DNS/HTTP khi thiếu envelope;
- precedence credential với environment rỗng không fallback DB;
- cURL pin IPv6, proxy/redirect/TLS policy và giới hạn body 65.536 byte;
- endpoint Messages, ba task, ba dạng base path và response schema;
- status/error mapping, retry/backoff, replay-safe idempotency và re-resolve mỗi attempt;
- policy rejection cho tập IP có địa chỉ private.

## Verification snapshot

- HEAD dùng để chạy check: `35462d8d5ce8d57dd58af2c866802c994f80bdde`.
- Focused check: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — **PASS**, exit 0, run `VERIFY-RUN-6c4010bd-bbe6-4fa5-950e-178cf462ba53`.
- Check trước đó sau thử nghiệm fixture nhiều chunk thất bại, exit 2; fixture đã được khôi phục về boundary hiện có và focused check chạy lại thành công. Không dùng lần thất bại làm bằng chứng pass.
- Working tree sau check: `.phpunit.result.cache` và `tests/Unit/IncrementGTest.php` modified; báo cáo r7 untracked. Cache phát sinh chưa được đưa vào báo cáo hay snapshot.

## AC1–AC6 đối chiếu

| AC | Kết quả r7 | Bằng chứng và giới hạn |
|---|---|---|
| AC1 | Chưa đủ | Các ca thiếu credential, invalid resolved IP và request validation có 0 DNS/HTTP. Chưa có ma trận đầy đủ cho constructor/boot/health, settings hỏng cùng key sai, model ngoài allowlist và local analyzer trong một snapshot r7 hoàn chỉnh. |
| AC2 | Chưa đủ | Có credential encryption/readiness từ F và environment precedence trong G. Chưa có bằng chứng runtime trace/args end-to-end cho cả hai auth scheme và hai cipher; không được suy ra từ annotation. |
| AC3 | Chưa đủ | Có IPv6, proxy/redirect/TLS options, mixed IP rejection và re-resolve retry fixtures. Native cURL error classification và deadline thực của `dns_get_record()` chưa được chứng minh; resolver production có thể block. |
| AC4 | Chưa đủ | Có ba task, base path, malformed output và 65.536-byte single-chunk boundary. Chưa có file `tests/Unit/IncrementGMatrixTest.php` để cung cấp ma trận bổ sung; cumulative multi-chunk assertion chưa được snapshot hoàn chỉnh. |
| AC5 | Có bằng chứng offline một phần | Status matrix, backoff, retry counts, replay key và budget-exhausted 429 đã pass. Fake clock không chứng minh resolver mặc định bị hủy đúng deadline. |
| AC6 | Chưa đủ | Chưa có spy coverage trên mọi cổng side effect cho thành công, transport error và validator error. Health key redaction được kế thừa từ F; không chứng minh retention của upstream gateway. |

## Blockers en beperkingen

- `tests/Unit/IncrementGMatrixTest.php` được r6/r7 hướng dẫn tham chiếu nhưng không tồn tại trong working tree hoặc `HEAD`; vì vậy không thể báo cáo các test đó đã chạy.
- `NativeGatewayResolver::resolve()` truyền timeout vào `dns_get_record()` nhưng PHP API không cung cấp cancellation thực sự. Đây là blocker O2/AC3 được giữ mở.
- Full `tests/Unit` trên snapshot r7 sạch chưa chạy trong vòng này; không gán kết quả full suite từ commit khác.
- `.phpunit.result.cache` đang modified bởi các lần chạy và không được tự ý ghi đè trạng thái uncommitted. Cần chủ sở hữu snapshot quyết định việc vệ sinh cache.

## SHA-256 đã kiểm tra

- `tests/Unit/IncrementGTest.php`: `6b46f9570026b58e455f708ccc8c9ab0073c78878b154872697d772cb155073c`
- `src/AI/AiSettingsRepository.php`: `5ae10f389611094307bd30f307bd804c9c31487d57f22368cc01ad9b04735b47`
- `src/AI/GatewayAiProvider.php`: `438b5573ae8bacde2fe10634da05bd27ddb87add3b219124b1533e5025180e10`
- `src/AI/CurlGatewayTransport.php`: `a696c2b977f6d765e22c8fb7ff78fa317c57b0989c5d31e369f23b792136d278`
- `src/AI/NativeGatewayResolver.php`: `8e76bf62bd84d361ecd55b9cb38b7c2f554ca44da68cf731f1a244b20d87af09`

## Kết luận

Increment G r7 vẫn **CHƯA ĐẠT (NOT ACCEPTED)**. Focused G test pass trên working tree hiện tại, nhưng AC1–AC4 và AC6 còn thiếu bằng chứng bắt buộc; DNS deadline và snapshot hygiene vẫn là blocker. Báo cáo này không chứng minh gateway production, retention upstream hoặc quyền bật production.
