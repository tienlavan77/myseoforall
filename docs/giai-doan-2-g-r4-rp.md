# Báo cáo Increment G r4

Ngày: 24/11/2026.

## Kết quả triển khai

Đã xử lý các khoảng trống G4-1 và G4-2 trong `src/AI/AiSettingsRepository.php`:

- Ghép URL hoàn chỉnh rồi kiểm tra static policy trước DNS.
- Mỗi attempt chỉ resolve một lần và kiểm tra ngân sách còn lại trước HTTP.
- Truyền timeout còn lại xuống transport bằng floor(deadline - clock()).
- Chỉ retry status 429/502/503/504 khi bật replay-safe POST.
- Giữ nguyên exception nội bộ khi hết retry hoặc gặp lỗi không retry.
- Chỉ tạo Idempotency-Key trong replay-safe profile.
- Bổ sung Content-Type: application/json; charset=utf-8, Accept và anthropic-version: 2023-06-01.
- Body Messages hiện chứa cả task và context.

Đã mở rộng `tests/Unit/IncrementGTest.php` với fixture offline kiểm tra URL Messages, headers, task/context envelope, idempotency key và timeout truyền xuống transport. Fixture hiện có 4 test cases và 14 assertion tường minh, cùng các expectation cho exception.

## Kiểm tra trên snapshot sạch

- Commit: `63b6527c3760d3d439d2304cfcf811b3dfcbbf8b`
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — PASS, exit code 0, run `VERIFY-RUN-d3a20e11-797d-4a38-8a15-0f26edc079db`.
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — PASS, exit code 0, run `VERIFY-RUN-9efe1902-b2db-42c7-8f2f-4206954cbe96`.

## Kết luận và giới hạn

Increment G vẫn **CHƯA ĐẠT (NOT ACCEPTED)** để đóng snapshot. Full Unit pass chỉ chứng minh hành vi mã nguồn cục bộ; chưa chứng minh gateway production hoặc retention upstream. Ma trận G4-4 AC1–AC6 vẫn chưa hoàn tất, gồm auth precedence, toàn bộ status/retry exception, deadline cạn trong resolver/backoff, response/schema rejection, zero-retention trace và hai lần generate với idempotency key khác nhau. Reviewer độc lập vẫn cần kiểm tra boundary trước quyết định đóng giai đoạn.

## SHA-256 hiện tại

- `src/AI/AiSettingsRepository.php`: `abbbbc39576d7ae5ad61c0d4352df8b03cfe27466ca84bd9525b90f04b4e6709`
- `src/AI/CurlGatewayTransport.php`: `362bdac34663f6ed2d171371f82c847fa6979bd1191fe68b434c67974bb4a0ed`
- `src/AI/Contracts/GatewayTransport.php`: `64b7fbd0773d9a6715599ecc6531f0b1e56f43cbb4b06da453bc1fae9df80673`
- `src/AI/GatewayAiProvider.php`: `ea632c5178b36c4ee14346cb80906a89eb57157c6202421213753cf517b7fb68`
- `src/AI/NativeGatewayResolver.php`: `b651703d47cdbd61dc3d24c28b1c942a9551d32ab823b313c8ee38e559aa675e`
- `src/Core/Plugin.php`: `c56ecc17334dd0ebdf5bd3463ea2203ebf037d55ef13f5c604054cee82a32376`
- `tests/Unit/IncrementGTest.php`: `c787aa6cc76cd4bf6d70236fb1b1e7a33b572345798c26b751183b7a0e0427c6`
