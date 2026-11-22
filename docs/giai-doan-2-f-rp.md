# Increment F r8 — Báo cáo implementation

## Kết quả

Đã triển khai phần nền tảng theo `docs/giai-doan-2-f.md` với một option AI duy nhất:

- Option: `myseo_ai_settings`.
- Settings và credential envelope được ghi trong cùng một giá trị option.
- Repository không ghi trong đường đọc và trả ba trạng thái `absent | ok | corrupted`.
- Revision tăng sau save và từ chối `expected_revision` cũ.
- Credential chỉ được báo bằng status đóng; plaintext không được trả ra ngoài boundary.
- Base URL được kiểm tra offline, không gọi DNS hoặc network.
- Admin controller kiểm tra capability → nonce → input type → một lần save.
- Composition root đăng ký repository và controller mà không thêm module health thứ năm.

Increment F hiện ở trạng thái **implemented foundation / chưa nghiệm thu đầy đủ**.

## Files đã thay đổi

### Source

- `src/AI/AiSettingsRepository.php`
  - Closed schema cho `schema_version`, `revision`, `ai_enabled`, gateway, model, timeout/retry và envelope.
  - Sodium secretbox envelope với fingerprint, nonce, ciphertext và timestamp.
  - Constant/environment credential precedence trả về status an toàn.
  - Corrupt input fail-closed, không tự sửa option khi load.
  - SHA-256: `dc9145f28c9cec4efd1a9358cf27ee2d0a1c3f8382391d4875b0f9e7a3729dc2`.

- `src/AI/GatewayEndpointValidator.php`
  - HTTPS-only, cấm provider host, private/reserved IP literal, internal host ngoài fixture allowlist.
  - Chặn credentials, query/fragment, traversal và residual percent encoding.
  - Resolver chỉ nhận qua callable injection; kết quả IP được validate, deduplicate và sort.
  - SHA-256: `1a2c2da6c0a55f86885b47936cc4f0e623866aba931e3654e257328c90bc355e`.

- `src/AI/AiSettingsController.php`
  - Admin gate độc lập, fail trước persistence khi capability hoặc nonce sai.
  - Non-string API key và revision sai kiểu bị từ chối.
  - SHA-256: `7600a39be80a219460a6ab36de883160a4927380a34f09fa4530832672e24bf8`.

- `src/Core/Plugin.php`
  - Đăng ký `AiSettingsRepository` và `AiSettingsController` trong container.
  - Không thay đổi bốn module health hiện có.
  - SHA-256 hiện tại: `5fedf431795f944c5ebf7f9b2230c6ee9f033ea2f12ee85cbe97e236f69a4c65`.

### Tests

- `tests/Unit/IncrementFTest.php`
  - Kiểm tra single-option/non-autoload, revision mismatch, envelope không lộ plaintext, corruption fail-closed, URL policy và admin gate.
  - SHA-256: `f79d35ebae7eacb2ca0e9eb8193f0c59b46a06ccb3c4385b59ffbf9cc767531a`.

## Verification

| Run ID | Command | Status | Exit |
|---|---|---:|---:|
| VERIFY-RUN-f083bf22-c0ea-4cb2-ace3-9bd8b3a5f430 | `php -l src/AI/AiSettingsRepository.php` | passed | 0 |
| VERIFY-RUN-644fa324-5244-47b8-b9e0-2544abc5ac8e | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php` | passed | 0 |
| VERIFY-RUN-824a9922-5879-4665-bd97-53149084e408 | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeCompositionTest.php` | passed | 0 |

Verification service báo cáo `commit_sha=e03536081003e58d77a6c078f3ea6f4b1980a7d1`; working tree hiện vẫn dirty và các file F chưa được commit. Vì vậy kết quả trên là focused evidence, chưa phải clean-snapshot gate.

## Giới hạn còn lại

- `OptionStore` hiện chưa có primitive CAS; repository chỉ có optimistic revision và chưa thể claim concurrency guarantee production.
- Controller đã được compose nhưng chưa được nối vào admin hook/UI flow hoàn chỉnh.
- Health output chưa thêm các enum `ai_settings_state` và `ai_credential_state`.
- Encryption key hiện được inject vào repository; chưa có production key-material provider riêng.
- Chưa có autoload-repair migration, purge legacy core keys, AEAD tamper matrix đầy đủ hoặc full `tests/Unit` verification.
- Runtime DNS pinning, redirect prohibition, TLS và transport signer vẫn đúng boundary của Increment G.

## Kết luận

Phần code hiện tại thực hiện boundary F tối thiểu, offline và fail-closed. Có thể tiếp tục với integration/admin wiring và verification matrix; chưa nên gọi Increment F là accepted.
