# Báo cáo Increment G r5

Ngày: 24/11/2026.

## Kết quả G5-1

Đã sửa các lỗi phân loại và ngân sách trong `src/AI/AiSettingsRepository.php`:

- Credential envelope vắng trả `provider_disabled`; environment hiện diện nhưng rỗng không fallback xuống DB.
- IP/DNS không đạt policy trả `gateway_policy_rejected`, tách khỏi `deadline_exceeded`.
- Chuẩn hóa `base_url` trước khi nhận diện hậu tố `/v1` hoặc `/messages`.
- Clock mặc định dùng `hrtime()` monotonic.
- Khi đã nhận response retryable nhưng không còn đủ ngân sách backoff, giữ nguyên response để provider trả đúng category, đặc biệt `429 -> rate_limited`.

## Kết quả G5-2

Đã mở rộng `tests/Unit/IncrementGTest.php` từ 4 lên 8 test cases, bổ sung coverage offline qua `GatewayAiProvider` cho:

- thiếu credential và thứ tự readiness trước DNS/HTTP;
- environment key rỗng không fallback DB;
- IP private bị từ chối trước transport;
- giữ nguyên `RATE_LIMITED` khi ngân sách backoff cạn.

Các test cũ về IPv6 pinning, DNS rỗng, response boundary, Messages headers/task-context và idempotency key vẫn được giữ nguyên. Phạm vi AC1–AC6 chưa bao phủ đầy đủ toàn bộ auth schemes/ciphers, mọi bảng status/retry, schema response và zero-retention trace; vì vậy chưa tuyên bố đóng Increment G.

## Verification trên snapshot

- Implementation snapshot: `c277d44a5c04d765301824dccc2a8b58f32b980e`
- Focused: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — PASS, exit code 0, `VERIFY-RUN-06cb9030-3705-44ca-8db8-f0972bb1cad5`.
- Full Unit: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — PASS, exit code 0, `VERIFY-RUN-5ea3378e-b620-42a3-9dc9-2a4a510b46d0`.
- `.phpunit.result.cache` đã được khôi phục sau verification.

## Kết luận

G5-1 đã được sửa và các regression test mới đều pass trên snapshot. Increment G vẫn **CHƯA ĐẠT (NOT ACCEPTED)** để nghiệm thu cuối vì ma trận AC1–AC6 chưa đầy đủ và test offline không chứng minh gateway production hoặc retention upstream. Cần Reviewer độc lập kiểm tra diff/boundary trước quyết định đóng.

## SHA-256

- `src/AI/AiSettingsRepository.php`: `ee43b4436f043446de34864ef115cc25a66d4e7fdbee3a2d223c2ead5199c8da`
- `tests/Unit/IncrementGTest.php`: `b598a2a579dd8d41cb8612d7a48bd69833a600d2dd749e53f52a037d29f7078c`
- `src/AI/CurlGatewayTransport.php`: `362bdac34663f6ed2d171371f82c847fa6979bd1191fe68b434c67974bb4a0ed`
- `src/AI/Contracts/GatewayTransport.php`: `64b7fbd0773d9a6715599ecc6531f0b1e56f43cbb4b06da453bc1fae9df80673`
- `src/AI/GatewayAiProvider.php`: `ea632c5178b36c4ee14346cb80906a89eb57157c6202421213753cf517b7fb68`
- `src/AI/NativeGatewayResolver.php`: `b651703d47cdbd61dc3d24c28b1c942a9551d32ab823b313c8ee38e559aa675e`
- `src/Core/Plugin.php`: `c56ecc17334dd0ebdf5bd3463ea2203ebf037d55ef13f5c604054cee82a32376`
