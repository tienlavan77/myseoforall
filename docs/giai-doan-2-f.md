# Giai đoạn 2 — Increment F (r8): AI Settings, Custom Base URL Policy & Secret Boundary

> Quyết định kiến trúc của Architecture Manager sau các vòng phản biện. Bản này **bác bỏ over-engineering** của thiết kế two-option/two-slot/fencing và là boundary cho SE/SL; không phải Sprint Plan, ticket list hay mutable-file manifest.

## 1. Quyết định chính

Increment F dùng **một option WordPress duy nhất** cho toàn bộ AI Settings và credential envelope:

- Option: `myseo_ai_settings`.
- `autoload = false` bắt buộc.
- Một lần persist cập nhật cả settings và encrypted credential envelope trong cùng một giá trị option; không có partial write giữa hai option.
- `myseo_core_settings` vẫn tách biệt hoàn toàn và không chứa AI fields.
- Increment F không có network, không Preview/Apply, không đọc plaintext ra ngoài boundary nội bộ và không có transport signer; các phần đó thuộc Increment G.

Đây là quyết định thay thế trực tiếp cho r7. **Không triển khai** `myseo_ai_credentials`, `credential_binding_id`, two-slot map, `AiSettingsWriteLock`, fencing token, lease/reclaim, compensating rollback, recovery job, `withAuthorizedHeader()`, AST network scanner hoặc global stream-wrapper unregister.

### Lý do bác bỏ thiết kế phức tạp

Đây là WordPress single-site, Settings API và một form quản trị site-scope. Tách settings/secret thành hai option mới tạo ra vấn đề atomicity nhân tạo rồi kéo theo lock, CAS, fencing và garbage collection. Một option non-autoload duy nhất cho phép MySQL/WordPress persist một row atomically; optimistic `revision` là đủ để phát hiện form cũ. Không đánh đổi maintainability và khả năng kiểm chứng để giải quyết concurrency giả định vượt quá boundary của F.

## 2. Outcomes và contract bắt buộc

### O1 — Core isolation và pure sanitization

1. `Configuration` chỉ giữ đúng sáu khóa core:
   - `schema_version = 1`;
   - `locale`;
   - `module_enablement` với các module bool allowlist;
   - `migration_status`;
   - `rank_math_mode` thuộc `observe | disabled | unknown` nếu có;
   - `sitemap_owner` thuộc `myseo_for_all | rank_math | unknown` nếu có.
2. `Configuration` loại bỏ `ai_enabled`, `base_url`, `api_key`, `secret`, `token` và mọi khóa lạ.
3. `AdminModule::sanitizeSettings(mixed $value): array` là pure function: không đọc/ghi option và không purge dữ liệu.
4. `purgeLegacyCoreKeys()` là operation riêng, chỉ chạy trong `Plugin::activate()` hoặc sau khi một lần lưu AI hợp lệ thành công. Operation dùng allowlist/CAS nếu repository đã cung cấp CAS; nếu phát hiện core option thay đổi trong lúc purge thì trả `purge_conflict`, không overwrite dữ liệu mới hơn. Purge thất bại không làm thay đổi kết quả AI và sẽ retry ở lần operation kế tiếp.
5. Không migrate AI fields từ core option. Dữ liệu AI cũ trong core chỉ bị loại bỏ, không được diễn giải thành credential hay base URL.

### O2 — Một closed AI option

`myseo_ai_settings` có đúng các khóa canonical sau; mọi khóa khác bị từ chối khi đọc và không được serialize khi ghi:

- `schema_version`: `1`;
- `revision`: số nguyên `>= 0`, tăng một lần sau mỗi persist thành công;
- `ai_enabled`: bool, mặc định `false`;
- `gateway_mode`: chỉ `custom_base_url`;
- `base_url`: chuỗi rỗng hoặc URL đã canonical hóa;
- `auth_scheme`: `x_api_key | bearer`, mặc định `x_api_key`;
- `allowed_models`: list tối đa 20 model ID theo regex an toàn;
- `default_model`: chuỗi rỗng hoặc model ID hợp lệ và thuộc allowlist nếu allowlist không rỗng;
- `timeout_seconds`: integer `5..60`, mặc định `20`;
- `max_retries`: integer `0..2`, mặc định `1`;
- `credential_envelope`: null hoặc envelope AEAD canonical.

`credential_envelope` là một object nội bộ trong cùng option, gồm đúng:
`schema_version`, `cipher`, `key_fingerprint`, `nonce`, `tag`, `ciphertext`, `updated_at`.
Plaintext trước mã hóa có duy nhất dạng JSON `{"v":1,"k":"..."}`. Envelope không xuất hiện trong health/UI.

### O3 — Load, save và concurrency tối giản

`AiSettingsRepository::load()` không ghi DB và trả một kết quả đóng:

- `absent`: option `null`, `false` hoặc `[]`; trả defaults, `revision = 0`;
- `ok`: option có đúng canonical keys, revision hợp lệ, URL đã canonical và envelope hợp lệ hoặc null;
- `corrupted`: mọi dạng sai schema/kiểu/biên/envelope; trả fallback fail-closed với `ai_enabled = false`, URL/model rỗng và stable `corruption_reason`.

Controller gửi `expected_revision` trong form. Khi save:

1. capability;
2. nonce;
3. validate toàn bộ input;
4. đọc một lần bản ghi hiện tại;
5. từ chối nếu `expected_revision` không khớp;
6. mã hóa envelope trong memory;
7. persist **một option duy nhất** với `revision + 1` và `autoload = false`;
8. nếu WordPress hỗ trợ CAS cho option, dùng CAS; nếu CAS không khả dụng thì fail-closed và không claim concurrency guarantee.

Không cần lock/fencing/lease vì commit point là một option row. Không dùng `delete → add` để sửa autoload trong đường lưu nghiệp vụ. Option mới phải được tạo bằng `add_option(..., false)`; repair một option cũ đang autoload=yes là operation migration riêng, có snapshot và retry rõ ràng, không trộn vào save AI.

### O4 — Secret source và crypto

Thứ tự credential: Constant `MYSEO_AI_GATEWAY_API_KEY` → environment `MYSEO_AI_GATEWAY_API_KEY` → encrypted envelope trong `myseo_ai_settings`.

- Constant/env hợp lệ là read-only và thắng DB.
- Constant/env sai format là `invalid_environment`, không fallback âm thầm.
- API key mới dùng `save(string $apiKey)` với `declare(strict_types=1)`; controller chịu trách nhiệm reject non-string trước khi gọi domain service.
- Không trim key. Chấp nhận đúng ASCII printable `[\x21-\x7E]`, dài `16..512` bytes; empty string trong form nghĩa là giữ key cũ, whitespace là invalid.
- AEAD dùng Sodium secretbox hoặc AES-256-GCM với tag 16 bytes, nonce đúng độ dài, AAD cố định gồm option/version/cipher/fingerprint; mọi lỗi crypto trả `encryption_unavailable` và không ghi option.
- Clear key là thao tác xác nhận riêng. Khi credential đến từ constant/env, clear trả `read_only_environment` và không ghi/xóa DB.
- F chỉ trả `CredentialStatus` an toàn (`absent`, `configured`, `environment`, `invalid_environment`, `corrupted`, `decryption_failed`). Không có `readPlaintext()` hay `withAuthorizedHeader()` trong F. Transport signer được thiết kế ở G.

### O5 — Custom Base URL và SSRF boundary

`GatewayEndpointValidator` là pure, deterministic và production policy được snapshot lúc construction; không nhận allowlist từ form. Production đọc allowlist system immutable; test được inject fixture tĩnh.

Static validation bắt buộc:

- HTTPS; port mặc định hoặc 443;
- không userinfo, query, fragment, control byte, trailing dot;
- chặn `api.anthropic.com` và subdomain;
- chặn localhost, single-label và host nội bộ trừ system allowlist;
- chặn IP literal private, loopback, link-local, metadata, CGNAT, reserved, mapped IPv6 và các dạng decimal/octal/hex/shorthand;
- IDN/`xn--` phải canonicalize bằng UTS-46; thiếu capability thì fail-closed;
- path tách theo segment, chặn traversal, encoded slash/backslash/query/fragment/control và mọi residual `%` sau tối đa ba decode; output không chứa `%` trần.

`validateResolvedIps()` luôn gọi static validation trước resolver. Resolver trả `list<string>` canonical hoặc DTO `{status, ips}`; scalar, exception, timeout, danh sách rỗng, IP malformed, quá 32 IP hoặc một IP non-public đều fail-closed. IP được canonicalize, deduplicate và sort deterministic.

Runtime DNS pinning, redirect prohibition, TLS verification và transport compatibility thuộc Increment G. F không gọi DNS thật.

## 3. Admin flow, health và compatibility

- Giữ bốn module health canonical của Increment E.
- Composition chỉ thêm các service cần thiết: validator, secret repository/store và AI settings controller; **không thêm write-lock service**.
- Handler riêng `admin_post_myseo_save_ai_settings` kiểm tra `capability → nonce → validation → single-option persist`.
- Sai capability/nonce/validation: zero persistence calls.
- `Plugin::health()` chỉ xuất bool/enum đóng: `ai_enabled`, `ai_configured`, `ai_settings_state`, `ai_credential_state`. Không xuất base URL, host, model, envelope, fingerprint, nonce, tag, ciphertext hoặc key.
- Form luôn render password input với `value=""`.

## 4. Acceptance criteria

1. Core giữ đúng sáu khóa; sanitizer pure; purge riêng, có conflict-safe behavior.
2. Một option AI duy nhất được tạo non-autoload và persist settings + envelope cùng lần ghi; test option mới và option hiện hữu.
3. Load có đúng `absent | ok | corrupted`, không write trong read path; corruption luôn fail-closed.
4. Revision mismatch từ chối trước persistence; revision tăng đúng một lần sau save thành công.
5. AEAD round-trip, AAD/fingerprint/tag/nonce tamper, key retention, clear, constant/env precedence và encryption failure đều có test; plaintext không xuất hiện trong DTO/UI/log.
6. URL canonicalization, IDN, path traversal, residual percent, direct provider, private IP và resolver malformed/timeout/duplicate cases có test; không DNS/network thật.
7. Admin gates có spy chứng minh zero persistence khi bị từ chối; giữ nguyên bốn module health và các test A–E.
8. Verification gồm focused F tests và full `tests/Unit` trên snapshot source/test sạch, có SHA, run IDs, exit code và checksums. Test doubles/spy chứng minh không có network; không dùng global stream-wrapper unregister hoặc AST scanner như acceptance bắt buộc.

## 5. Quyết định bác bỏ các phản biện gần nhất

- **Clock:** Không persist lease timestamp vì F không dùng lease. Nếu G cần timeout, wall-clock dùng cho dữ liệu persisted; monotonic chỉ đo budget trong một request.
- **Corrupted revision:** Repository là nguồn revision duy nhất; controller không đọc raw option riêng.
- **Core purge race:** dùng CAS/allowlist merge, trả `purge_conflict`, không overwrite.
- **Autoload repair:** không delete-then-add trong save nghiệp vụ; repair là migration riêng, fail-closed nếu không bảo đảm atomicity.
- **Two-slot/fencing/CAS lock:** bác bỏ vì tạo complexity không tương xứng với một option atomic trên site-scope Admin. Chỉ giữ optimistic revision và một CAS trên option nếu platform hỗ trợ.
- **`save(mixed)` và AST/global tripwire:** bác bỏ; giữ strict `string` domain contract, controller validate input, và dùng injected spies/static diff review để chứng minh F offline.

## 6. Evidence

- Phản biện đã xem: `docs/giai-doan-2-f-fb.md`, `sha256:af5f79fe3d9f8d932b069aecd61818e556e4cf9000c69c646074976c65dd1451`.
- Snapshot nền tảng: `ea62bf8026d6153e4769a79bdb0b275223bdf2e5`.
- Source đã đối chiếu: `src/Core/Configuration/Configuration.php`, `src/Infrastructure/WordPress/OptionStore.php`, `src/Core/Plugin.php`, `src/Admin/AdminModule.php`; các checksum lịch sử được giữ trong báo cáo E-r7 và các bản r1–r7 của tài liệu này.
