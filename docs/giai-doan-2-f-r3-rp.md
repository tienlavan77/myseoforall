# Báo cáo triển khai Increment F r3

## Phạm vi đã thực hiện

Đã code theo `docs/giai-doan-2-f-r3.md` với boundary single-option và không mở network client:

- `OptionStore` nhận reader/writer/deleter/CAS injectable; runtime dùng WordPress API, option AI được tạo/cập nhật non-autoload.
- `Configuration` chỉ giữ sáu khóa core (`schema_version`, `locale`, `module_enablement`, `migration_status`, `rank_math_mode`, `sitemap_owner`) và lọc module/enum không hợp lệ.
- `GatewayEndpointValidator` chuyển thành service instance; chặn credentials, query/fragment, host provider và subdomain, IP private/reserved/metadata/documentation, IDN thiếu hỗ trợ, encoded traversal, URL quá dài; resolver chạy sau static validation và trả danh sách IP deterministic.
- `AiSettingsRepository` giữ `myseo_ai_settings`, revision/CAS seam, envelope Sodium secretbox, HKDF từ secret môi trường/WordPress salts, precedence constant → env → DB, clear explicit, readiness fail-closed, `purgeLegacyCoreKeys()` và health credential state đã chuẩn hóa.
- `AiSettingsController`, `AdminModule` và `Plugin` đã nối action admin, capability/nonce gate, hidden revision, password field rỗng, clear checkbox và health redaction.
- `IncrementFTest` dùng OptionStore test double, không còn định nghĩa global `get_option()`/`update_option()`.

## Evidence source

- `src/Infrastructure/WordPress/OptionStore.php` — `sha256:be6ebf29456d5d86f07e144b170ab922588bcc2f6c303e8975aec0d0b4d36f7d`
- `src/Core/Configuration/Configuration.php` — `sha256:80bd025ba0adde04e06290f8fcb3748157e602afdb22be31c4fda6d675c41569`
- `src/AI/GatewayEndpointValidator.php` — `sha256:7c8209a8ad34315078a85d95893f4102d838c0a8b6d2ea811132e71340de9be7`
- `src/AI/AiSettingsRepository.php` — `sha256:7b53a4da8ae5458d4a2c13a9cc6a09414fb3fab0cff27ccab4f521dc119c38d9`
- `src/AI/AiSettingsController.php` — `sha256:6a97fb95c81571708be28dc672f92af3bebca6a9bc0328b851a765bd434df6fb`
- `src/Admin/AdminModule.php` — `sha256:7d3369c5fd81add7dbd0270dd78a9cf38a39d9e8757ff667c14fe72969221bf8`
- `src/Core/Plugin.php` — `sha256:85838fa693e600713c89d7f3828bf1993465b513f47e95a48073fe8413cce124`
- `tests/Unit/IncrementFTest.php` — `sha256:71f8dc8922d258dec35a706d5de6deb8a54b12646d8cf2bda0f5ed99f302dd2d`

## Verification

- Focused: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php` — pass, run `VERIFY-RUN-62188fbf-441c-4b5a-b2de-29b3b324eb89`, exit code 0.
- Unit scope: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — pass, run `VERIFY-RUN-c1a814fc-e8f1-4efb-a0f0-a628b9fafdc4`, exit code 0.
- PHP lint cho các file source/test đã sửa — pass; riêng Plugin có run `VERIFY-RUN-fc751098-4ec3-4331-a5a4-f758052e6aaf`.
- `.phpunit.result.cache` được restore sau khi chạy test.

## Giới hạn còn lại

- Chưa có AES-256-GCM fallback khi Sodium không khả dụng; production readiness vẫn cần kiểm tra trên môi trường WordPress thật.
- Chưa có receipt production migration/concurrency; test seam chỉ chứng minh hành vi offline của test double.
- Chưa commit vì yêu cầu hiện tại chỉ yêu cầu code và báo cáo, chưa yêu cầu commit/push.
