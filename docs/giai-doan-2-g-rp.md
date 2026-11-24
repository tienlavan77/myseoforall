# Báo cáo triển khai Increment G — Gateway AI có kiểm soát

Ngày: 23/11/2026.

## Phạm vi đã triển khai

Đã triển khai các điểm nối chính theo `docs/giai-doan-2-g.md`:

- Mở rộng `AiProviderException` với các category `provider_disabled`, `authentication_failed`, `gateway_policy_rejected`, `rate_limited` và `transport_failed`, đồng thời giữ nguyên ba category cũ.
- Thêm `GatewayTransport` và `GatewayResolver` làm các seam nội bộ cho transport và DNS.
- Thêm `NativeGatewayResolver` để lấy bản ghi A/AAAA và `CurlGatewayTransport` để gửi HTTPS POST trực tiếp, tắt proxy/redirect, bật TLS verification, ghim IP hostname và giới hạn body nhận 65.536 byte.
- Thêm `GatewayAiProvider`: kiểm tra request envelope và payload, chỉ nhận ba task được phép, yêu cầu HTTP 200, assistant message, `stop_reason=end_turn`, đúng một text block, parse toàn bộ JSON rồi gọi `AiOutputValidator` với provider `gateway`.
- Bổ sung đường thực thi gateway trong `AiSettingsRepository`: kiểm tra settings đã bật, precedence constant → environment → encrypted DB, từ chối override sai định dạng, xác minh IP trước request, tự dựng endpoint/header/body và chỉ trả về dữ liệu transport đã được chuẩn hóa.
- Đăng ký resolver, transport, gateway provider và alias `AiProvider` trong composition root; giữ nguyên bốn module và health schema hiện hữu.

## Kiểm tra đã chạy

- `php -l src/AI/GatewayAiProvider.php` — passed; run `VERIFY-RUN-6bb908e4-3237-4570-ac47-91823ba7ae27`.
- `php -l src/AI/AiSettingsRepository.php` — passed; run `VERIFY-RUN-7746a63e-ce8d-4b32-9e6a-19845354c224`.
- `php -l src/Core/Plugin.php` — passed; run `VERIFY-RUN-f7bb0205-430a-44c5-a350-0173e92af95f`.
- `php -l src/AI/CurlGatewayTransport.php` — passed; run `VERIFY-RUN-c3794300-b306-48af-a991-e156647ab2ba`.
- `php -l src/AI/NativeGatewayResolver.php` — passed; run `VERIFY-RUN-4a098f7e-08ad-442e-bfb9-2ab7db42d0c5`.
- `php -l src/AI/Contracts/AiProviderException.php` — passed; run `VERIFY-RUN-eb221c0f-fe33-409a-95ba-1a43b657fe96`.
- `php vendor/bin/phpunit tests/Unit/AiProviderTest.php` — passed; run `VERIFY-RUN-a635f6ea-ba9a-41a1-9f87-f69e32580d7f`.
- `php vendor/bin/phpunit tests/Unit/RuntimeCompositionTest.php` — passed; run `VERIFY-RUN-26eccfae-d194-443d-b31f-65e402490366`.
- `php vendor/bin/phpunit tests/Unit/IncrementFTest.php` — passed; run `VERIFY-RUN-882deaf8-90cf-4d28-958b-68036217ca26`.
- `php vendor/bin/phpunit tests/Unit/RuntimeCompositionSmokeTest.php` — passed; run `VERIFY-RUN-4bc416f5-6fba-40a6-9279-da350df2c346`.

## Giới hạn còn lại

Chưa có `IncrementGTest.php` bao phủ đầy đủ retry/backoff, clock budget, hai auth scheme, replay safety, response body boundary bằng write callback, và spy side-effect theo từng nhánh AC1–AC6. Chưa chạy full `tests/Unit` và chưa có kiểm chứng gateway thật; các focused checks trên chỉ xác nhận cú pháp, regression của provider fake, settings F và composition hiện hữu. Chưa commit vì yêu cầu hiện tại chỉ yêu cầu triển khai và báo cáo; working tree còn nhiều thay đổi có sẵn ngoài phạm vi Increment G.

## Bằng chứng snapshot

Các check Forge hiện ghi nhận commit context `b306bd06daa45c981681b18f76bb7a0ce604ced2`, base `4bf28d17c117002522989562e3a94f00fbdde39e`. Working tree vẫn dirty với nhiều file ngoài phạm vi; báo cáo này không coi đó là clean committed baseline và không tuyên bố đã đóng Giai đoạn 2.
