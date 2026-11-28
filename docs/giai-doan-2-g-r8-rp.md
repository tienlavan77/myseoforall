# Báo cáo Increment G r8

Ngày: 24/11/2026.

## Thay đổi thực hiện

- `src/AI/NativeGatewayResolver.php`: thay thế `dns_get_record()` bằng resolver UDP wire query nội bộ, có nameserver provider/query executor seam, kiểm tra hostname, literal IP, deadline monotonic, timeout stream, A/AAAA parsing, TXID/QR/rcode validation và fail-closed khi không có nameserver/socket.
- `src/AI/AiSettingsRepository.php`: kiểm tra `zend.exception_ignore_args` trước khi tạo transport request; thử bật cấu hình và dừng `transport_unavailable` nếu runtime không cho phép.
- `tests/Unit/IncrementGTest.php`: thêm test offline cho literal IP, hostname lỗi và deadline không hợp lệ; các test G hiện hữu tiếp tục được giữ.

Không thêm dependency Composer, không gọi DNS/gateway thật.

## Verification

- Focused command: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
- Kết quả: **PASS**, exit code 0.
- Run ID: `VERIFY-RUN-eb7740ef-edd1-47eb-805b-094af0c9c5cc` (sau khi bổ sung kiểm tra TXID).
- Check chạy trên execution commit được Forge báo cáo là `35462d8d5ce8d57dd58af2c866802c994f80bdde`; các thay đổi r8 hiện vẫn còn trong working tree và chưa commit.
- Full `tests/Unit` chưa chạy trong vòng r8.

## Đối chiếu AC và giới hạn

| AC | Kết quả | Ghi nhận |
|---|---|---|
| AC1 | Chưa đủ | Gate runtime và các ca readiness hiện có pass; chưa có toàn bộ lifecycle/health/local analyzer matrix r8 trong một snapshot sạch. |
| AC2 | Chưa đủ | Runtime gate đã được thêm. Chưa có test đủ 4 tổ hợp auth/cipher và kiểm trace end-to-end theo yêu cầu r8. |
| AC3 | Chưa đủ | Resolver UDP bounded đã thay thế DNS blocking; focused test mới chỉ kiểm literal/invalid input. Chưa có fixture packet A/AAAA, TXID/QR/rcode và compression pointer để chứng minh toàn bộ parser. |
| AC4 | Có bằng chứng một phần | Ma trận Messages và response boundary hiện hữu pass; chưa có full envelope matrix r8. |
| AC5 | Có bằng chứng một phần | Retry/deadline fixtures hiện hữu pass; chưa chạy full suite trên commit sạch. |
| AC6 | Chưa đủ | Chưa có zero-retention spy cho cả success, transport error và validator error theo r8. |

## Snapshot và rủi ro còn mở

- `tests/Unit/IncrementGMatrixTest.php` vẫn không tồn tại; r8 đã định hướng gom test vào `IncrementGTest.php`, nhưng toàn bộ test bổ sung bắt buộc chưa được triển khai.
- `.phpunit.result.cache` đang modified bởi PHPUnit. Không tự ý khôi phục vì trạng thái cache là thay đổi chưa commit và thao tác overwrite đã bị review từ chối.
- Chưa commit/push vì yêu cầu hiện tại chỉ yêu cầu code và báo cáo, không yêu cầu commit.
- Resolver production hiện có deadline stream, nhưng cần bổ sung test packet response thực tế và kiểm tra TXID để biến bằng chứng parser thành đầy đủ.

## SHA-256 hiện tại

- `src/AI/NativeGatewayResolver.php`: `ffd6d4109f5f81f4237a3b5c6e2da1549d0d634e9569d8eafafd9410e9b1fffa`
- `src/AI/AiSettingsRepository.php`: `3125e1f4cd66c61ba0a60d07e161a82baba1bd94d7862c2f813ba4876032fb07`
- `tests/Unit/IncrementGTest.php`: `31b132c999dceccb3c3c967c7bb2b46bada21441dede644a205edfee93d5d93d`
- `docs/giai-doan-2-g-r8.md`: `0efe6e490a0fad7e918292f04f160afa432f794e7eeee8d04a556efc04066ab1`

## Kết luận

R8 đã xử lý phần production quan trọng của hai blocker: DNS không còn gọi API blocking và đường gateway có runtime trace gate. Focused G pass, nhưng Increment G vẫn **CHƯA ĐẠT (NOT ACCEPTED)** cho đến khi bổ sung packet/parser, auth/cipher/redaction, zero-retention và full snapshot verification; báo cáo này không chứng minh gateway production hoặc retention upstream.
