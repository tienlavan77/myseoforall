# Báo cáo sửa chữa Increment G r3

Ngày: 24/11/2026.

## Kết quả

Đã khôi phục `src/AI/AiSettingsRepository.php` thành PHP hợp lệ và bổ sung executor gateway có:

- readiness và credential được kiểm tra trước transport;
- credential từ environment hoặc encrypted envelope;
- typed `Closure` cho clock/sleeper;
- deadline, re-resolve IP mỗi attempt, backoff và retry cho lỗi kết nối;
- idempotency key mới cho mỗi lần gọi;
- system prompt ghi đúng schema khóa của từng task.

Đã hoàn thiện các điểm G3-2/G3-3:

- thêm `#[\\SensitiveParameter]` cho headers của contract/transport và API key;
- kiểm tra overflow cả trên executor seam;
- giữ cURL ở HTTPS, không proxy/redirect;
- resolver guard host rỗng và dùng error handler cục bộ thay cho `@`;
- composition trong `Plugin` tái sử dụng `GatewayTransport`, `GatewayResolver`, repository và provider đã có trong container.

Đã mở rộng `tests/Unit/IncrementGTest.php` với kiểm tra overflow executor 65.537 bytes.

## Kiểm tra

- `php -l src/AI/AiSettingsRepository.php` — PASS, `VERIFY-RUN-56dab8dd-bd22-448c-a66c-8c937b66a9e6`.
- `php -l src/AI/CurlGatewayTransport.php` — PASS, `VERIFY-RUN-9d406a49-c434-4470-800d-7de552fb3450`.
- `php -l src/AI/Contracts/GatewayTransport.php` — PASS, `VERIFY-RUN-8a40ff90-e088-4e64-b653-f594c40ca44a`.
- `php -l src/Core/Plugin.php` — PASS, `VERIFY-RUN-d832b660-695d-41ff-9c4b-c67963b75be6`.
- `php -l src/AI/NativeGatewayResolver.php` — PASS, `VERIFY-RUN-774ce23a-9baa-4ba9-ac2f-12432ba4e47a`.
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — PASS, exit 0, `VERIFY-RUN-99c8bcc6-efa6-4983-ade3-e1778ab9990e`.
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — PASS, exit 0, `VERIFY-RUN-19e3d05a-5c02-4c10-8a2f-ee901c1ad93d`.

## Evidence và giới hạn

Snapshot kiểm tra dùng `b306bd06daa45c981681b18f76bb7a0ce604ced2`. Working tree vẫn dirty vì có thay đổi lịch sử ngoài phạm vi; `.phpunit.result.cache` đã được khôi phục. Không tạo commit mới hoặc push.

SHA-256 hiện tại:

- `src/AI/AiSettingsRepository.php`: `af6cb858ea66b478d7a9cf3718a50d13c13e834966a6bb784ecfd52962de4800`
- `src/AI/CurlGatewayTransport.php`: `a8ddceb464710b718e76be24ddddbcc877a2a3e07ce2ebfc629f5169061b2182`
- `src/AI/Contracts/GatewayTransport.php`: `64b7fbd0773d9a6715599ecc6531f0b1e56f43cbb4b06da453bc1fae9df80673`
- `src/AI/NativeGatewayResolver.php`: `b651703d47cdbd61dc3d24c28b1c942a9551d32ab823b313c8ee38e559aa675e`
- `src/Core/Plugin.php`: `c56ecc17334dd0ebdf5bd3463ea2203ebf037d55ef13f5c604054cee82a32376`
- `tests/Unit/IncrementGTest.php`: `04159756b53289a28a6d4826fcfa4a5453b89514c8584f8ec7eb1955af76aabb`
