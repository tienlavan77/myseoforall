# Báo cáo Increment G r4

Ngày: 24/11/2026.

## Kết quả triển khai

Đã xử lý các khoảng trống G4-1 và G4-2 trong src/AI/AiSettingsRepository.php:

- Ghép URL hoàn chỉnh rồi kiểm tra static policy trước DNS.
- Mỗi attempt chỉ resolve một lần và kiểm tra ngân sách còn lại trước HTTP.
- Truyền timeout còn lại xuống transport bằng floor(deadline - clock()).
- Chỉ retry status 429/502/503/504 khi bật replay-safe POST.
- Giữ nguyên exception nội bộ khi hết retry hoặc gặp lỗi không retry.
- Chỉ tạo Idempotency-Key trong replay-safe profile.
- Bổ sung Content-Type: application/json; charset=utf-8, Accept và anthropic-version: 2023-06-01.
- Body Messages hiện chứa cả task và context.

Đã mở rộng tests/Unit/IncrementGTest.php với fixture offline kiểm tra URL Messages, headers, task/context envelope, idempotency key và timeout truyền xuống transport.

## Kiểm tra đã chạy

- php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php — PASS, exit code 0, VERIFY-RUN-d69a4e0a-9ae2-4b3f-95eb-70667a8a073f.

Test này hiện có 4 test và kiểm tra transport cơ bản, overflow executor và request Messages replay-safe. Chưa chạy lại toàn bộ tests/Unit sau thay đổi r4 trong lần này.

## Snapshot và giới hạn

Increment G vẫn CHƯA ĐẠT (NOT ACCEPTED) để đóng snapshot:

- HEAD hiện tại là b306bd06daa45c981681b18f76bb7a0ce604ced2, working tree dirty và chứa nhiều thay đổi lịch sử ngoài phạm vi.
- Không tạo commit mới hoặc push.
- .phpunit.result.cache đã được khôi phục.
- Ma trận G4-4 đầy đủ AC1–AC6 vẫn chưa hoàn tất: chưa có coverage toàn diện cho auth precedence, mọi status/retry exception, deadline cạn trong resolver/backoff, response/schema rejection, zero-retention trace và hai lần generate với idempotency key khác nhau.
- Test offline chỉ chứng minh hành vi mã nguồn cục bộ; không chứng minh retention hoặc behavior của gateway production.

## SHA-256 hiện tại

- src/AI/AiSettingsRepository.php: abbbbc39576d7ae5ad61c0d4352df8b03cfe27466ca84bd9525b90f04b4e6709
- tests/Unit/IncrementGTest.php: c787aa6cc76cd4bf6d70236fb1b1e7a33b572345798c26b751183b7a0e0427c6
- src/AI/CurlGatewayTransport.php: a8ddceb464710b718e76be24ddddbcc877a2a3e07ce2ebfc629f5169061b2182
- src/AI/Contracts/GatewayTransport.php: 64b7fbd0773d9a6715599ecc6531f0b1e56f43cbb4b06da453bc1fae9df80673
- src/AI/GatewayAiProvider.php: ea632c5178b36c4ee14346cb80906a89eb57157c6202421213753cf517b7fb68
- src/Core/Plugin.php: c56ecc17334dd0ebdf5bd3463ea2203ebf037d55ef13f5c604054cee82a32376
