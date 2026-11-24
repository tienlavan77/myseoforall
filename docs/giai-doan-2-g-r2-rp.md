# Báo cáo sửa chữa Increment G r2

Ngày: 23/11/2026.

## Đã xử lý

- Mở rộng allowlist của `AiProviderException` để giữ đúng cả tám category.
- Tách `unsupported_task` khỏi `malformed_request`, đồng thời chấp nhận thứ tự key bất kỳ trong request envelope.
- Sửa ánh xạ `transport_unavailable` thành `transport_failed`, ánh xạ lỗi body quá lớn thành `invalid_response`, và phân loại HTTP 4xx/5xx ngoài các trường hợp đặc biệt theo O4.
- Đưa kiểm tra settings, model và allowlist lên trước capability transport/resolver.
- Sửa pin IPv6 theo cú pháp ngoặc vuông, fail-closed khi hostname không có IP, kiểm tra port/IP, và bật các cờ cURL cho HTTPS, không redirect, kết nối mới, không reuse và không proxy.
- Thêm seam executor nội bộ cho `CurlGatewayTransport`; write callback giới hạn response ở 65.536 bytes.
- Phân loại lỗi cURL thành TLS, connect unsent, timeout trước/sau gửi, overflow và lỗi transport chung.
- Bọc lỗi DNS native để trả về rỗng fail-closed.
- Thêm clock, sleeper, deadline tổng, kiểm định IP lại trước mỗi attempt, backoff 100/200 ms, task-specific system prompt và idempotency key ổn định khi replay-safe được bật.

## Kiểm tra đã chạy

- `php -l src/AI/CurlGatewayTransport.php` — passed; `VERIFY-RUN-0e6f4e10-09da-4026-aad3-d495979eb692`.
- `php -l src/AI/AiSettingsRepository.php` — passed; `VERIFY-RUN-5a51b1d1-bfee-4636-a07f-7ca2974da4db`.
- `php -l src/AI/GatewayAiProvider.php` — passed; `VERIFY-RUN-e3e31044-e4bc-40f8-b870-650c8d5191ef`.
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — passed; `VERIFY-RUN-fb107f41-051c-4b57-a6de-e68387c9a212`.
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — failed, exit code 2; `VERIFY-RUN-e02dc8d7-675f-490b-a046-8e39c6badc5c`. Forge returned only the PHPUnit banner, so no narrower failure identity was available.
- `php vendor/bin/phpunit tests/Unit/AiProviderTest.php` — passed; `VERIFY-RUN-d429ac3b-9336-48e3-906b-10aa9a6a2f57`.
- `php -l tests/Unit/IncrementGTest.php` — passed; `VERIFY-RUN-66d88c32-0f93-4650-a86a-7ac350f2d75f`.

## Giới hạn và chưa đạt

Chưa thể tuyên bố AC1–AC6 đạt đầy đủ: IncrementGTest hiện bao phủ seam transport cốt lõi nhưng chưa bao phủ toàn bộ ma trận credential, replay, deadline, output schema và zero-retention. Chưa có clean snapshot hoặc commit r2 riêng; run IDs trên gắn với context commit `b306bd06daa45c981681b18f76bb7a0ce604ced2`, không phải một commit sạch. Working tree vẫn chứa nhiều thay đổi ngoài phạm vi G và không được tự ý khôi phục.

## Evidence

Đặc tả nguồn: `docs/giai-doan-2-g-r2.md`, SHA-256 `76ea52c2fa09727faa75ed2dfba90301d1e5048b95108f76114f040b8390a7cd`.

SHA-256 sau chỉnh sửa:
- `src/AI/AiSettingsRepository.php`: `01aa40815af14da0bec11335fc7bfb9993c4add3b0d81ebd1d8e4c57e0877c7d`
- `src/AI/CurlGatewayTransport.php`: `e547c25fe69199a7d31eb57fa20992f5d44ea417fe8bcc07509529969652947a`
- `src/AI/GatewayAiProvider.php`: `ea632c5178b36c4ee14346cb80906a89eb57157c6202421213753cf517b7fb68`
- `tests/Unit/IncrementGTest.php`: `aede9f0dec0e7a8faa704ab6b7e5f130c387fcbb5f420f2c4bb00b172bd3213d`
- `docs/giai-doan-2-g-r2-rp.md`: `8082f3fb42387bdb461387ca3033b2649fd59be7f5c6cf8b1dbee59e9cf2ecc0`

Các file runtime chính đã sửa gồm `src/AI/AiSettingsRepository.php`, `src/AI/Contracts/AiProviderException.php`, `src/AI/GatewayAiProvider.php`, `src/AI/CurlGatewayTransport.php` và `src/AI/NativeGatewayResolver.php`; test mới là `tests/Unit/IncrementGTest.php`.
