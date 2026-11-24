# Increment F r3 — Hướng dẫn code sau khi rà soát báo cáo r2

> Đối chiếu `docs/giai-doan-2-f-r2-rp.md` (`sha256:f036a1e3dddd72c322c15a2f61ee575547903e3eb9638388cc276b5e9053977e`), `docs/giai-doan-2-f-r2.md` (`sha256:0a341d622eb9e46c6ceb67fc4ea5cc3697319ea54af3fa33e39f68375019ee49`) và source/test hiện tại. Đây là hướng dẫn thực thi F-r3; giữ quyết định Architecture **single option, không lock/two-slot/fencing**.

## 1. Kết luận hiện trạng

Báo cáo r2 đúng khi gọi F là **chưa accepted**. Source hiện tại đã có foundation single-option nhưng còn gap đáng kể:

- `IncrementFTest.php` đang định nghĩa global WordPress functions, có nguy cơ làm hỏng full suite.
- `GatewayEndpointValidator` hiện vẫn là static API, còn bypass userinfo/query/fragment, provider subdomain và IP edge cases.
- `Configuration` chưa lọc core keys.
- `AiSettingsRepository` chưa hoàn thiện key provider, clear/read-only behavior, readiness fail-closed và health integration.
- `AiSettingsController` chưa được nối hoàn chỉnh vào AdminModule/UI.
- `Plugin` chưa đưa AI state vào health.

Không được tuyên bố F accepted chỉ dựa trên ba focused run trong `docs/giai-doan-2-f-rp.md`. Working tree dirty và chưa có full `tests/Unit` receipt.

## 2. Chính kiến Architecture — các giới hạn không được mở rộng

SE phải sửa đúng contract dưới đây, không tự quay lại thiết kế r7:

- Chỉ một option `myseo_ai_settings`, non-autoload.
- Không `myseo_ai_credentials`, `credential_binding_id`, two-slot, fencing, lease, recovery job hoặc compensating rollback.
- Không `AiSettingsWriteLock`; dùng `revision`/CAS tối giản nếu platform seam hỗ trợ.
- Giữ `save(string $apiKey)` strict type; controller reject non-string trước domain call.
- Không `readPlaintext()` hoặc `withAuthorizedHeader()` trong F.
- Không global stream-wrapper unregister, AST network scanner hay test-only global `get_option()`/`update_option()`.

## 3. Hướng dẫn code bắt buộc

### F3-1 — OptionStore seam và test isolation

1. Xóa hoàn toàn `function get_option()` và `function update_option()` khỏi `tests/Unit/IncrementFTest.php`.
2. Mở seam nhỏ trong `OptionStore` bằng optional injected reader/writer/deleter/CAS callable; default runtime tiếp tục dùng WordPress API.
3. Khi tạo mới `myseo_ai_settings`, dùng `add_option($key, $value, '', false)` nếu có API; cập nhật dùng `update_option($key, $value, false)`.
4. Không thay đổi behavior của các consumer A–E. Test InMemory OptionStore là test double, không phải claim về database concurrency production.
5. Bổ sung `delete()` nếu controller cần clear key; không dùng delete-then-add trong đường save nghiệp vụ.

### F3-2 — Core six-key allowlist và purge riêng

1. Sửa `Configuration::__construct()` để chỉ giữ sáu khóa core chính thức:
   `schema_version`, `locale`, `module_enablement`, `migration_status`, `rank_math_mode`, `sitemap_owner`.
2. Lọc module enablement theo allowlist; enum sai và unknown keys bị loại.
3. Giữ `AdminModule::sanitizeSettings()` pure, không gọi OptionStore.
4. Thêm `AiSettingsRepository::purgeLegacyCoreKeys()` như operation riêng:
   - `clean_noop` nếu core đã sạch;
   - `purged` nếu ghi bản sạch thành công;
   - `purge_conflict` nếu CAS expected value không còn khớp;
   - `purge_failed` nếu storage lỗi.
5. Chỉ gọi purge tại activation hoặc sau save AI thành công; form invalid, capability/nonce fail, revision mismatch không được purge.

### F3-3 — GatewayEndpointValidator: sửa bảo mật trước khi mở rộng

Chuyển thành service instance với policy allowlist được snapshot khi construction; public methods không nhận allowlist từ request:

```text
validateStaticUrl(string $url): array
validateResolvedIps(string $url, callable $resolver): array
```

Bắt buộc:

- Đổi điều kiện nhiều `isset()` thành OR: user/pass/query/fragment chỉ cần một thành phần tồn tại là reject.
- Chặn `?` và `#` cả khi tồn tại dạng rỗng ở URL raw.
- Chặn toàn bộ `anthropic.com` và subdomain, không chỉ `api.anthropic.com`.
- URL tối đa 512 bytes; HTTPS; port mặc định/443; không credentials.
- Bóc `[]` của IPv6 URL host trước khi IP validation.
- Dùng `inet_pton()`/`inet_ntop()` cho IP canonical; chặn loopback, RFC1918, link-local/metadata, CGNAT, reserved, mapped IPv6, NAT64 và literal decimal/octal/hex/shorthand.
- IDN Unicode hoặc `xn--` thiếu `ext-intl`/UTS-46 phải fail-closed.
- `validateResolvedIps()` phải static-validate trước, không gọi resolver nếu static fail.
- Resolver malformed/exception/timeout/empty/>32 IP fail-closed; list IP phải deduplicate và sort deterministic.
- Path phải tách theo segment trước decode; cho phép slash phân tách gốc, cấm slash/backslash/query/fragment/control/traversal sinh ra từ encoding; residual `%` sau tối đa 3 pass bị reject.

### F3-4 — Repository: crypto, clear và readiness

1. Giữ domain API `save(string $apiKey)`; controller kiểm tra `is_string()` trước khi gọi.
2. Khi constructor không nhận key fixture, production phải lấy key material từ `MYSEO_AI_SECRET_KEY` hoặc bộ WordPress salts hợp lệ; dẫn xuất 32 bytes bằng HKDF. Thiếu key material trả `encryption_unavailable`, không plaintext fallback.
3. Hỗ trợ Sodium secretbox; AES-256-GCM chỉ là fallback khi Sodium unavailable. Envelope canonical phải kiểm tra nonce/tag/base64/fingerprint/AAD và tamper fail-closed.
4. Thứ tự credential là Constant → Env → DB envelope. Constant/env hợp lệ là read-only; API key mới hoặc clear khi read-only trả `read_only_environment` và zero writes.
5. `clear_api_key=true` là cờ tường minh. Nếu gửi cùng API key mới thì reject; nếu clear hợp lệ thì envelope thành `null` trong cùng một update option.
6. Nếu `ai_enabled=true` nhưng thiếu canonical base URL, default model hoặc credential usable, phải hạ xuống `false` trước persist.
7. Input form rỗng giữ credential cũ; whitespace/control/độ dài ngoài `16..512` bị reject. Không trim key.
8. Không expose plaintext trong return DTO, health, log, exception hay HTML.

### F3-5 — Admin wiring và health redaction

1. Truyền `AiSettingsController` vào `AdminModule` bằng một dependency tùy chọn cuối constructor để giữ compatibility.
2. `AdminModule::boot()` đăng ký admin action/controller khi dispatcher khả dụng; render page dưới capability `manage_options`.
3. Form có nonce, hidden `expected_revision`, password input luôn `value=""`, clear checkbox và escaping toàn bộ output.
4. Handler thứ tự bắt buộc: capability → nonce → request/type validation → repository save. Gate fail phải có zero persistence.
5. `Plugin::health()` lấy state từ repository AI, không đọc `ai_enabled` từ core. Chỉ xuất:
   - `ai_enabled`;
   - `ai_configured`;
   - `ai_settings_state: absent|ok|corrupted`;
   - `ai_credential_state: absent|configured|environment|invalid_environment|corrupted|decryption_failed`.
6. Không xuất URL, host, model, allowlist, envelope, fingerprint, nonce, tag hoặc key.

### F3-6 — Test và snapshot gate

Bổ sung focused tests cho:

- test isolation không global WordPress functions;
- six-key core filtering và pure sanitizer;
- purge noop/purged/conflict/failure;
- one-option non-autoload create/update;
- revision mismatch và readiness fail-closed;
- Sodium/AES-GCM round-trip, tamper, clear, read-only, precedence;
- URL credential/query/fragment/provider-subdomain/IPv6-mapped/obfuscated-IP/path cases;
- resolver malformed/timeout/duplicate/sort;
- admin capability/nonce/escaping/empty password field;
- health redaction và bốn module health không đổi.

Sau code:

1. Commit sạch source/test của F vào snapshot SHA thực tế; không stage `.phpunit.result.cache`.
2. Chạy focused:
   `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php`
3. Chạy full:
   `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
4. Chỉ báo cáo accepted/ready-for-review nếu cả hai run pass trên cùng snapshot, có run ID, exit code, base SHA và checksum file.
5. Reviewer độc lập vẫn phải review diff và boundary; PHPUnit pass không chứng minh production WordPress/WooCommerce compatibility.

## 4. Handoff

F-r3 được bàn giao cho SL/SE để phân rã trong boundary trên. Không mở Increment G, không thêm network client, không chuyển ownership, không Preview/Apply và không sửa giá/tồn kho. Báo cáo hiện tại chỉ là evidence foundation; chưa có approval hoặc clean-snapshot acceptance.
