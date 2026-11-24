# Increment F r2 — Kiểm tra báo cáo `docs/giai-doan-2-f-rp.md` và Hướng dẫn hoàn tất

> Đối chiếu báo cáo [`docs/giai-doan-2-f-rp.md`](giai-doan-2-f-rp.md) (`sha256:4fcfcdb7f20f420286c0f4cfc130ca35e0fdb5ca22605ded3d5c38dd340180b4`) với quyết định kiến trúc [`docs/giai-doan-2-f.md`](giai-doan-2-f.md) (`sha256:0c9b1e761b6299294568fb30837cb285cff7da86207d8d55f41dd26c5cbf1b57`) và mã nguồn thực tế trên working tree. Đây là hướng dẫn kiến trúc cho vòng hoàn thiện F-r2; giữ nguyên triết lý **single-option tối giản, không over-engineer**.

---

## 1. Kết luận kiểm tra báo cáo & mã nguồn hiện tại

Báo cáo `docs/giai-doan-2-f-rp.md` trung thực khi ghi trạng thái **implemented foundation / chưa nghiệm thu đầy đủ**. Hướng đi 1-option (`myseo_ai_settings`) gọn, sạch và đúng tinh thần r8. Tuy nhiên, qua kiểm tra trực tiếp từng dòng code trong `src/` và `tests/Unit/IncrementFTest.php`, có **3 lỗi Blocker làm gãy full test suite / lủng logic bảo mật** và **5 hạng mục bắt buộc của r8 bị bỏ sót** cần đóng dứt điểm ở vòng F-r2:

### Các lỗi Blocker phát hiện trực tiếp trong code hiện tại

1. **[BLOCKER 1 — Làm gãy Full Suite `tests/Unit`] Ô nhiễm hàm global `get_option()` trong `tests/Unit/IncrementFTest.php` (`17–32`):**
   - `IncrementFTest.php` khai báo global `function get_option(...)` và `function update_option(...)` trong root namespace!
   - Hệ quả: Khi chạy full suite `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` (như yêu cầu bắt buộc ở AC8; báo cáo của SE **chưa dám chạy full suite**!), PHP nạp `IncrementFTest.php` theo thứ tự bảng chữ cái **trước** `IncrementER6VerificationTest.php` và `RuntimeCompositionTest.php`. Ngay lập tức `function_exists('get_option')` biến thành `true` trên toàn tiến trình PHP, làm **`IncrementER6VerificationTest::testRankMathProbeMatrix()` dòng `18–19` FAIL chắc chắn** (vì `new WordPressRankMathProbe()` gọi `get_option('active_plugins', [])` trả `observed` thay vì `'unobserved'`)!
   - **Cách sửa bắt buộc:** Xóa ngay global `function get_option` / `update_option` khỏi `IncrementFTest.php`! Cho phép `OptionStore` nhận optional callable reader/writer/deleter qua constructor (hoặc trích `OptionStoreContract` / non-final seam nhỏ gọn) giống hệt cách `EnvironmentCheck` và `WordPressRankMathProbe` đang làm.

2. **[BLOCKER 2 — Lỗ hổng câu điều kiện `isset(...)` nhiều biến trong `GatewayEndpointValidator.php` (`29`) + Bypass IPv6/Subdomain/Octal IP]:**
   - Dòng `29` viết: `isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])`! Trong PHP, `isset($a, $b, $c, $d)` là phép **AND** (chỉ trả `true` khi **cả 4 thành phần cùng tồn tại một lúc**!). Do đó URL chứa credential `https://user:pass@gw.example.test/v1` hoặc chứa query `https://gw.example.test/v1?leak=1` hoặc `#frag` đơn lẻ **ĐỀU LỌT QUA KIỂM TRA** (vì `parse_url` tách riêng `path` nên vòng decode `path` bên dưới cũng không thấy `?` hay `user:pass`)! Phải sửa thành kiểm tra **OR**: `isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])`.
   - Cùng trong `GatewayEndpointValidator.php`:
     - Dòng `131` chỉ chặn `.api.anthropic.com`, để lọt `claude.anthropic.com` / `*.anthropic.com` (O5 yêu cầu chặn `api.anthropic.com` và subdomain `*.anthropic.com`).
     - Dòng `36–60`: `parse_url('https://[::1]/v1')['host']` trả chuỗi **có ngoặc vuông** `'[::1]'`. `filter_var('[::1]', FILTER_VALIDATE_IP)` trả `false`, rồi `isInternalHost('[::1]')` thấy không có dấu `.` thì chặn tình cờ, nhưng với IPv4-mapped IPv6 `https://[::ffff:127.0.0.1]/v1` (có dấu `.`!) thì `isIpLiteral` trả `false` **VÀ `isInternalHost` CŨNG TRẢ `FALSE` -> LỌT HOÀN TOÀN LOOPBACK QUA IPv6!** Đồng thời IP ngụy trang dạng hex/octal/decimal (`0x7f.0.0.1`, `0177.0.0.1`, `2130706433`) và IPv4-mapped IPv6 trong `validateResolvedIps()` (`'::ffff:10.0.0.1'`) chưa được bóc ngoặc `[]` và chuẩn hóa nhị phân qua `inet_pton()`.
     - Dòng `67–76`: Chưa tách theo segment `/` trước khi `rawurldecode()`, làm URL hợp lệ có ký tự unreserved mã hóa kèm path như `https://gw.example.test/v1/%7etest` bị từ chối nhầm vì `$next` chứa dấu `/` gốc của `/v1/`! Ngoài ra tham số `$allowedInternalHosts` vẫn đang phơi trên static method thay vì đóng băng ở constructor instance.

3. **[BLOCKER 3 — Chưa tách Core `Configuration` (O1), chưa hỗ trợ Clear Key / Key Provider / AES-GCM Fallback (O4), chưa nối Admin & Health (Section 3)]:**
   - `src/Core/Configuration/Configuration.php` **chưa hề được sửa** (`sha256` vẫn y nguyên E-r7): vẫn giữ mọi khóa lạ qua `array_merge()`, chưa lọc đúng 6 khóa core, chưa có `purgeLegacyCoreKeys()`.
   - `AiSettingsRepository::save()` **chưa có nhánh Clear API Key** và **chưa chặn ghi/xóa key khi đang có Constant/Env override** (`read_only_environment`), cũng chưa ép `ai_enabled = false` khi bật AI mà chưa đủ `base_url` + `default_model` + credential khả dụng (trong `IncrementFTest` dòng `166`, gửi mỗi `['ai_enabled' => true]` không có URL/model/key vẫn lưu `ai_enabled = true`!).
   - `Plugin::__construct()` khởi tạo `new AiSettingsRepository($options)` với `$encryptionKey = null` cố định (chưa đọc key material dẫn xuất HKDF từ `MYSEO_AI_SECRET_KEY` / WordPress salts và chưa có fallback `aes-256-gcm` khi thiếu `sodium`), khiến trên WordPress thật mọi lần lưu API key đều trả `'encryption_unavailable'`!
   - `AiSettingsController` chưa nối vào `AdminModule` (chưa đăng ký hook khi `Plugin::boot()`, chưa render form `<input type="password" value="">`), và `Plugin::health()` vẫn đọc `ai_enabled` cũ từ core thay vì xuất 4 trường đóng của AI.

---

## 2. Hướng dẫn sửa gọn & đóng đủ Increment F trong vòng F-r2 (`F2-1` .. `F2-5`)

Giữ nguyên cấu trúc 1 option gọn nhẹ hiện tại (`AiSettingsRepository`, `GatewayEndpointValidator`, `AiSettingsController`), không đẻ thêm lock/two-slot, chỉ hoàn thiện đúng **5 hạng mục** sau:

### `F2-1` — Xóa Global Functions trong Test & Mở Seam Nhẹ cho `OptionStore`
1. Trong `src/Infrastructure/WordPress/OptionStore.php`:
   - Thêm các optional callables vào constructor `public function __construct(?callable $getter = null, ?callable $updater = null, ?callable $deleter = null)` (mặc định `null` dùng `get_option` / `add_option(..., '', false)` + `update_option(..., false)` / `delete_option`), đảm bảo khi tạo option mới dùng `add_option($key, $value, '', false)` nếu hàm tồn tại.
   - Bổ sung `compareAndSwap(string $key, mixed $expected, array $newValue): bool`: nếu có injected CAS/updater trong test thì so sánh trực tiếp; trên WordPress nếu có `$wpdb` hợp lệ thì chạy `UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s`, còn khi tạo mới (`$expected === null`) dùng `add_option($key, $newValue, '', false)`.
2. Trong `tests/Unit/IncrementFTest.php`: **Xóa bỏ hoàn toàn** `function get_option` và `function update_option` ở global scope; dùng `new OptionStore($inMemoryGet, $inMemoryUpdate, $inMemoryDelete)` để chạy chung hài hòa 100% với toàn bộ test của Increment A–E.

### `F2-2` — Khóa 6 Khóa Core `Configuration` & Operation `purgeLegacyCoreKeys()` (O1)
1. Trong `src/Core/Configuration/Configuration.php`: chỉ giữ đúng **6 khóa** (`schema_version`, `locale`, `module_enablement` lọc theo `['core', 'admin', 'woocommerce', 'seo', 'rank_math']`, `migration_status`, và `rank_math_mode` / `sitemap_owner` khi khớp đúng enum hợp lệ). Loại bỏ sạch `ai_enabled`, `base_url`, `api_key`, `secret`, `token` và mọi khóa lạ. `AdminModule::sanitizeSettings()` giữ nguyên là hàm pure.
2. Thêm `AiSettingsRepository::purgeLegacyCoreKeys(): string`: đọc `myseo_core_settings`, nếu đã sạch trả `'clean_noop'`; nếu có khóa thừa thì dùng `compareAndSwap` (hoặc `update` khi khởi tạo ở `activate`) ghi bản sạch 6 khóa, trả `'purged'` hoặc `'purge_conflict'` / `'purge_failed'` mà không overwrite mù. Gọi hàm này trong `Plugin::activate()` và sau khi `AiSettingsRepository::save()` thành công.

### `F2-3` — Sửa Các Lỗ Hổng Bảo Mật Của `GatewayEndpointValidator` (O5)
1. Chuyển `GatewayEndpointValidator` sang instance service `public function __construct(?array $testAllowedInternalHosts = null)` (production đọc một lần hằng số `MYSEO_AI_ALLOWED_GATEWAY_HOSTS`, cấm wildcard `*`, đóng băng vào `private readonly array $allowedInternalHosts`); bỏ tham số `$allowedInternalHosts` khỏi chữ ký `validateStaticUrl(string $url)` và `validateResolvedIps(string $url, callable $resolver)`. Đăng ký `GatewayEndpointValidator::class` trong `Plugin::__construct()` và truyền vào `AiSettingsRepository`.
2. Sửa ngay câu điều kiện dòng `29` thành phép **OR**: từ chối nếu `isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])` (kiểm tra thêm `str_contains($url, '?') || str_contains($url, '#') || str_contains($url, '@')` trên phần authority/query rỗng kiểu `https://gw.example.test/v1?` hoặc `#`). Giới hạn tổng độ dài URL `<= 512` bytes.
3. Chuẩn hóa Host & IP chặt chẽ:
   - Nếu `$host` bọc trong `[...]` (IPv6 literal trong URL): bóc `[]` trước khi kiểm tra IP; chặn mọi host dạng số/hex/octal ngụy trang (`0x7f.0.0.1`, `0177.0.0.1`, `2130706433`, `127.1`) không phải IPv4 4-octet chuẩn.
   - Kiểm tra cả IP literal lẫn từng IP từ `$resolver` (hỗ trợ cả `list<string>` và DTO `['status' => 'ok', 'ips' => [...]]`) bằng hàm chung `isPublicIp(string $ip)`: dùng `inet_pton()`, chặn cả IPv4-mapped IPv6 (`::ffff:a.b.c.d`, trích 4 byte cuối kiểm tra IPv4), NAT64 (`64:ff9b::/96`), CGNAT (`100.64.0.0/10`), loopback, RFC1918, link-local/metadata (`169.254.0.0/16`, `fe80::/10`), reserved; chuẩn hóa qua `inet_ntop()`, khử trùng và sắp xếp nhị phân.
   - Sửa `isBlockedProvider($host)`: chặn `anthropic.com` và `str_ends_with($host, '.anthropic.com')`.
   - Sửa IDN: nếu host có byte `>= 0x80` hoặc label `xn--` thì bắt buộc có `idn_to_ascii` UTS-46 hợp lệ; nếu thiếu `ext-intl` hoặc `idn_to_ascii` trả `false` -> fail-closed `'idn_unavailable'`.
4. Sửa Path Decoding theo **từng segment**: cấm `\` và `//` giữa path; tách `explode('/', trim($path, '/'))`; trên từng segment chạy tối đa 3 lượt `rawurldecode()`, từ chối nếu segment bằng `.` / `..`, chứa `/`, `\`, `?`, `#`, control char, hoặc sau 3 lượt còn bất kỳ dấu `%` nào (`%25`, `%2525`); chuẩn hóa bỏ `/` cuối.

### `F2-4` — Hoàn Thiện Crypto Key Provider, Clear Key, Readiness & AAD Trong `AiSettingsRepository` (O2–O4)
1. **Key Material Provider & AES-256-GCM Fallback:** Khi `$encryptionKey === null`, tự động dẫn xuất khóa 32-byte bằng `hash_hkdf('sha256', $material, 32, 'myseo-ai-settings-v1')` từ hằng số `MYSEO_AI_SECRET_KEY` (`>= 32` bytes) hoặc tổ hợp 4 WordPress salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_SALT` khác mặc định). Hỗ trợ cả `'sodium_secretbox'` (gắn AAD vào subkey HKDF theo `option|v1|cipher|fingerprint`, `tag = ''` hoặc `'none'` nhất quán) và fallback `'aes-256-gcm'` (`openssl_encrypt` với `$aad` và tag đúng 16 bytes base64) khi thiếu `sodium`.
2. **Precedence, Clear Key & Fail-Closed `ai_enabled`:**
   - Khi đang có Constant/Env (`credentialStatus()['status']` là `'environment'` hoặc `'invalid_environment'`): nếu request gửi `api_key !== null && $apiKey !== ''` **hoặc** `clear_api_key === true` -> từ chối ngay với `status = 'read_only_environment'` (`0` writes).
   - Bổ sung tham số `bool $clearApiKey = false` vào `save()` (và đọc từ `$request['clear_api_key'] ?? false` trong `AiSettingsController`): khi `true` (và không read-only), đặt `credential_envelope = null` (nếu gửi đồng thời cả `clear_api_key = true` và `api_key !== ''` thì báo `'invalid'`).
   - Trước khi ghi: tính trạng thái sẵn sàng thực tế — nếu `$candidate['ai_enabled'] === true` nhưng `$candidate['base_url'] === ''` hoặc `$candidate['default_model'] === ''` hoặc credential sau cập nhật không ở trạng thái `'configured'`/`'environment'` -> buộc hạ `$candidate['ai_enabled'] = false` (fail-closed, không bao giờ lưu `ai_enabled = true` rỗng như test cũ!).
   - Khử trùng mảng `allowed_models` giữ nguyên thứ tự và giới hạn model ID `<= 96` ký tự theo spec.

### `F2-5` — Nối `AdminModule` UI/Hooks & `Plugin::health()` Redaction (Section 3)
1. **Admin Wiring:** Truyền `$aiController` (tạo sẵn trong `Plugin::__construct()`) vào tham số tùy chọn cuối của `AdminModule::__construct(..., ?AiSettingsController $aiController = null)`. Trong `AdminModule::boot()`, gọi `$this->aiController?->register($this->dispatcher)` (đăng ký `admin_post_myseo_save_ai_settings` và submenu/section render form tiếng Việt có `<input type="password" name="api_key" value="" autocomplete="new-password">`, hidden `expected_revision`, tất cả xuất qua `escapeHtml()`, kiểm tra `manage_options` trước khi render).
2. **`Plugin::health()`:** Xóa dòng đọc `$values['ai_enabled']` từ core trong `safeConfiguration()`. Đọc trạng thái đóng từ `AiSettingsRepository::load()` + `credentialStatus()` và đặt vào `$health['configuration']`:
   - `'ai_enabled' => bool` (chỉ `true` khi `load()['status'] === 'ok'`, `settings['ai_enabled'] === true`, `base_url !== ''`, `default_model !== ''`, và credential là `'configured'` hoặc `'environment'`),
   - `'ai_configured' => bool` (`load()['status'] === 'ok'` && `base_url !== ''` && `default_model !== ''` && credential thuộc `['configured', 'environment']`),
   - `'ai_settings_state' => 'absent' | 'ok' | 'corrupted'`,
   - `'ai_credential_state' => 'absent' | 'configured' | 'environment' | 'invalid_environment' | 'corrupted' | 'decryption_failed'`.
   - Tuyệt đối không lộ `base_url`, `default_model`, `allowed_models`, `credential_envelope`, `key_fingerprint` hay API key.

---

## 3. Verification & Quy trình Khóa Snapshot Sạch (`F2-6`)

1. Mở rộng `tests/Unit/IncrementFTest.php` bao phủ đầy đủ AC1–AC7 của `docs/giai-doan-2-f.md`:
   - Core 6-key filtering, pure `sanitizeSettings()`, `purgeLegacyCoreKeys()` (gồm cả `purge_conflict`).
   - Single-option non-autoload cho cả tạo mới và cập nhật, revision mismatch, fail-closed `ai_enabled` khi thiếu URL/model/key.
   - AEAD round-trip (cả nhánh Sodium lẫn AES-256-GCM), tamper `ciphertext`/`nonce`/`tag`/`key_fingerprint`, biên key `15 / 16 / 512 / 513` bytes, whitespace key `'   '` bị từ chối, `''` giữ key cũ, `clear_api_key = true`, Constant/Env precedence và `read_only_environment`.
   - URL matrix: `user:pass@`, `?query`, `#frag` đơn lẻ đều bị chặn; `*.anthropic.com`, `[::1]`, `[::ffff:127.0.0.1]`, `0x7f.0.0.1`, `/v1/%7etest` hợp lệ vs `/v1/%25` / `/v1/%252e%252e` bị chặn; resolver malformed/timeout/mapped-IPv6/dedup-sort.
   - Admin render capability gate + `value=""` + XSS escaping + `Plugin::health()` redaction với sentinel secrets/URLs + giữ nguyên 4 modules health.
2. **Khóa Commit Snapshot Sạch & Chạy Đủ Full Suite:**
   - Commit toàn bộ `src/` và `tests/` của Increment F thành một commit sạch `<F_R2_SNAPSHOT_SHA>` (không stage `.phpunit.result.cache`).
   - Chạy qua `run_check` trực tiếp trên `<F_R2_SNAPSHOT_SHA>`:
     - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php`
     - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` (**bắt buộc chạy full suite** để chứng minh không làm gãy `IncrementER6VerificationTest`, `FoundationTest`, `IncrementER2SafetyTest`, `RuntimeCompositionTest`!).
   - Cập nhật báo cáo bàn giao vào `docs/giai-doan-2-f-rp.md` (commit riêng sau `run_check`) kèm `<F_R2_SNAPSHOT_SHA>`, `base_sha`, bảng `VERIFY-RUN-*` có `exit 0` và SHA-256 đầy đủ của mọi file `src/` + `tests/` thuộc F.
