# Increment F r5 — báo cáo nghiệm thu sửa lỗi và verification

## Kết quả

Increment F r5 đã xử lý đủ ba nhóm bắt buộc:

- **F5-1 — GatewayEndpointValidator:** IDN/punycode được xử lý trước policy ASCII và fail-closed khi `idn_to_ascii()` thiếu hoặc trả lỗi; single-label host chỉ hợp lệ khi nằm trong allowlist; `anthropic.com` và mọi subdomain bị loại khỏi allowlist.
- **F5-2 — Admin/activation:** form Core Settings dùng `admin_url('options.php')` với fallback an toàn; bỏ lần gọi purge trùng trong `Plugin::activate()`.
- **F5-3 — test và snapshot:** `IncrementFTest` có namespace chuẩn, state/counters cô lập trong test object, không dùng `$GLOBALS`, và bao phủ URL/IP/path, resolver, purge, Sodium, AES-256-GCM, tamper, clear/readiness, admin UI revision, capability/nonce và health redaction.

Snapshot source/test được commit tại:

- `commit_sha: e8aed50edf60bb67ed75c8d4c8e673347b7d73c1`

## Verification trên đúng commit

1. `php 'vendor/bin/phpunit' --configuration phpunit.xml tests/Unit/IncrementFTest.php`
   - `VERIFY-RUN-97775a64-fbfa-4e97-8fee-5dbe60272f0b`
   - exit code `0`
   - **5 tests, 96 assertions**, passed

2. `php 'vendor/bin/phpunit' --configuration phpunit.xml tests/Unit`
   - `VERIFY-RUN-411a4ffd-6a4a-4285-864f-2614ef41999f`
   - exit code `0`
   - **81 tests**, passed
   - Forge verification receipt không trả tổng assertion của toàn bộ Unit scope; không suy đoán con số này.

`.phpunit.result.cache` đã được restore sau mỗi lần chạy. Working tree của `src/` và `tests/` sạch sau snapshot.

## Evidence checksums

- `src/AI/AiSettingsController.php` — `sha256:c4fb32bb3df24a3d4789502e46e734e33d2a35d00612f511b3c7adaece5009ac`
- `src/AI/AiSettingsRepository.php` — `sha256:e3429010064c445936780ec5fa803b2566ef82d2b80ce8bb48a1d940b8b81c4f`
- `src/AI/GatewayEndpointValidator.php` — `sha256:727660e36a5612dd8cffc7d20dc5134636230cd4e5cb19a6fc3edc0b15a3948a`
- `src/Admin/AdminModule.php` — `sha256:2254a1467fc828a6e0bf906cfcf4ab450f530f1bc41a856691c5200504ec3296`
- `src/Core/Configuration/Configuration.php` — `sha256:f1c6904a89d956b2516114b55eb28853a82d7bddc07392f62c8e6086687a8820`
- `src/Core/Plugin.php` — `sha256:3af2ae3e959c6f8cde33d64de7e5e6375d48efb5a78711bde664dcd59deb94df`
- `src/Infrastructure/WordPress/OptionStore.php` — `sha256:df9ce3f35205dd09b75861eb3622dccb2cc4eb31a10e98a69505e77a2736d499`
- `tests/Unit/IncrementFTest.php` — `sha256:761a136791c76b570938ed815435cbd98d663227c2d31884cdf8a323c77ce894`

## Giới hạn

Các kiểm thử là offline và dùng seams/test doubles; chúng không chứng minh migration hoặc runtime WordPress production. Không có network call, secret plaintext hay Apply operation trong phạm vi này.
