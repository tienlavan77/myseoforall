# Increment F r4 — Hướng dẫn sửa chữa dứt điểm sau khi rà soát báo cáo r3

> Đối chiếu trực tiếp `docs/giai-doan-2-f-r3-rp.md` (`sha256:4064feaf578ce97046f722b17632ce51d56f57b13431bc2335b3629bac47c2ce`), `docs/giai-doan-2-f.md` bản r8 (`sha256:0c9b1e761b6299294568fb30837cb285cff7da86207d8d55f41dd26c5cbf1b57`), `docs/giai-doan-2-f-r3.md` (`sha256:995167d134b35da122f189500eb8779c73b7017cd3ca4819612d9862dd57a778`) và toàn bộ 8 file source/test trên working tree.

## 1. Kết luận đánh giá báo cáo r3

F-r3 đã đi đúng hướng kiến trúc **một option duy nhất `myseo_ai_settings`** và đã xóa global `get_option()`/`update_option()` khỏi test. Tuy nhiên **chưa thể chốt nghiệm thu F** vì kiểm tra trực tiếp mã nguồn phát hiện **6 nhóm lỗi Blocker/Major thực chất** (bao gồm lỗ hổng bypass SSRF/path traversal, form Admin luôn hỏng từ lần lưu thứ 2 trở đi, lệch kiểu dữ liệu HTML POST làm save thất bại, thiếu AES-256-GCM + AAD, lỗi `sanitize_key` làm hỏng locale và thiếu clean snapshot + test matrix).

**Giữ nguyên ranh giới kiến trúc tối giản của r8:**
- Chỉ **1 option `myseo_ai_settings` (`autoload = false`)**; không tạo `myseo_ai_credentials`, two-slot, lease/fencing, write-lock, AST scanner hay global stream-wrapper unregister.
- Giữ strict typing `AiSettingsRepository::save(..., ?string $apiKey = null)`; không mở `readPlaintext()` hay `withAuthorizedHeader()` ở F.

---

## 2. Danh mục sửa chữa bắt buộc (F4-1 .. F4-6)

### F4-1 [Blocker Bảo mật] — Sửa lỗ hổng URL, IP & Path trong `src/AI/GatewayEndpointValidator.php`

Code hiện tại (`sha256:7c8209a8...`) đang có 4 lỗi bypass thật sự cần sửa ngay:

1. **Bypass raw path traversal (`/..`, `/.`), raw backslash (`\`) và encoded slash (`%2f`):**
   - Dòng 73–82 hiện chạy `rawurldecode()` trên nguyên chuỗi `$path` và `if ($next === $decoded) break;` ngay ở `$pass = 0` TRƯỚC khi kiểm tra regex! Hệ quả: URL không mã hóa như `https://gw.example.test/v1/../admin` hoặc `https://gw.example.test/v1\admin` đi lọt hoàn toàn (`status = 'ok'`)! Ngoài ra `%2f` bị giải mã thành `/` phân tách path mới mà không bị chặn.
   - **Cách sửa bắt buộc:**
     - Trên chuỗi `$path` gốc: từ chối ngay nếu chứa `\` hoặc control chars, chứa `//` rỗng (khi path khác `''` và `'/'`), hoặc có dấu `%` không đi kèm đúng 2 ký tự hex (`preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1`).
     - Tách `$segments = explode('/', trim($path, '/'))` (nếu `trim($path, '/') === ''` thì `$segments = []`).
     - Với **từng `$segment`** độc lập: kiểm tra ngay giá trị gốc (chặn `'.'`, `'..'`), sau đó lặp `rawurldecode()` tối đa 3 lượt; ở **mỗi lượt** (kể cả khi không đổi), nếu `$segment === '.' || $segment === '..'` hoặc chứa `/`, `\`, `?`, `#`, hoặc byte `[\x00-\x20\x7F]` -> reject `'encoded_path_traversal'`. Sau 3 lượt nếu còn `%` -> reject `'residual_percent'`.
     - Ghép lại path chuẩn hóa từ các segment đã kiểm tra (encode lại các ký tự không thuộc RFC 3986 unreserved/sub-delims hợp lệ bằng chữ hoa hex, bỏ `/` cuối).

2. **Bypass Obfuscated IPv4 (Octal / Hex / Decimal / Shorthand):**
   - `isIpLiteral()` dùng `filter_var($host, FILTER_VALIDATE_IP)` nên trả `false` với `0177.0.0.1` (octal), `0x7f.0.0.1` (hex), `127.1` / `169.254.65278` (shorthand) và `2130706433` (decimal). Vì các dạng có dấu chấm không rơi vào `isInternalHost()`, chúng đi lọt `validateStaticUrl()` như tên miền hợp lệ!
   - **Cách sửa bắt buộc:** Trước khi coi `$host` (không phải IPv6) là domain hợp lệ, kiểm tra các label tách bởi `.`:
     - Nếu toàn bộ các label chỉ gồm chữ số `[0-9]+` hoặc dạng hex `0x[0-9a-f]+` (ví dụ `127.1`, `0177.0.0.1`, `0x7f.0.0.1`, `2130706433`), mà `$host` không phải IPv4 dotted-quad chuẩn 4 phần thập phân không có số `0` dẫn đầu (`0` đơn lẻ được phép, `01` bị cấm) -> **từ chối ngay (`'obfuscated_ip_host'`)**!
     - Với domain thường, TLD (label cuối cùng) không được toàn số và mỗi label phải khớp chuẩn DNS `[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?`.

3. **Lỗi ghép URL IPv6 mất dấu ngoặc vuông `[...]`:**
   - Dòng 44–46 bóc `[` và `]` khỏi IPv6 host, nhưng dòng 86 ghép lại `'https://' . $host` không bọc `[...]`, tạo ra URL sai cú pháp `https://2606:4700:4700::1111/v1` và làm `parse_url($static['url'])` ở `validateResolvedIps()` hỏng luôn!
   - **Cách sửa bắt buộc:** Nếu `$host` là IP literal hợp lệ, canonical hóa qua `strtolower((string) inet_ntop((string) inet_pton($host)))`; nếu là IPv6 (chứa `:`), khi ghép URL ở bước trả về phải bọc `'[' . $canonicalIp . ']'`, và ở `validateResolvedIps()` bóc `[]` trước khi truyền vào `$resolver` (hoặc truyền IP literal đã canonical).

4. **Nguồn System Host Allowlist lúc khởi tạo:**
   - Khi khởi tạo mặc định (`$allowedInternalHosts === null`), đọc một lần hằng số `MYSEO_AI_ALLOWED_GATEWAY_HOSTS` (nếu `defined` và là `array`), lọc bỏ wildcard `*`, IP literal và `*.anthropic.com`, đóng băng vào thuộc tính `readonly`. Test truyền mảng tĩnh tường minh vào constructor.

---

### F4-2 [Blocker Form & Luồng Lưu] — Sửa lỗi hỏng Form Admin từ lần lưu thứ 2 & Ép kiểu POST HTML

Hiện tại sự kết hợp giữa `AdminModule.php` (`sha256:7d3369c5...`), `AiSettingsController.php` (`sha256:6a97fb95...`) và `AiSettingsRepository.php` (`sha256:7b53a4da...`) khiến **form HTML thực tế trên WordPress không thể dùng được**:

1. **Bug 1 — Hard-code `expected_revision="0"` và trộn 2 form:**
   - `AdminModule::renderPage()` dòng 172 hard-code cứng `<input type="hidden" name="expected_revision" value="0">`. Ngay sau lần lưu đầu tiên (`revision` lên `1`), mọi lần bấm lưu tiếp theo trên UI đều gửi `expected_revision = 0` và bị từ chối vĩnh viễn với `'revision_mismatch'`!
   - Đồng thời dòng 234 gọi `settings_fields('myseo_for_all')` ngay bên trong `<form action="admin-post.php">` (vốn ghi đè hidden `<input name="action" value="update">` và `_wpnonce` của `options.php`, phá hỏng cả `myseo_save_ai_settings` lẫn `checkNonce`!).
   - **Cách sửa bắt buộc:**
     - Tách riêng `<form>` của Core Settings (`options.php` + `settings_fields('myseo_for_all')`) và `<form>` của AI Settings (`admin-post.php` + `action=myseo_save_ai_settings` + `wp_nonce_field('myseo_save_ai_settings')`), không nhét `settings_fields('myseo_for_all')` vào chung form AI!
     - Form AI lấy snapshot redacted/view-state từ `AiSettingsController` (hoặc repository qua controller): điền đúng `expected_revision` hiện tại (`$loaded['settings']['revision']`), render đủ các trường cấu hình AI cơ bản (`ai_enabled`, `base_url`, `auth_scheme`, `default_model`, `allowed_models`, `timeout_seconds`, `max_retries`), ô `api_key` luôn `value=""`, checkbox `clear_api_key`, và thông báo trạng thái `read_only` khi credential đến từ Constant/Env (tất cả qua `escapeHtml()`).

2. **Bug 2 — HTML `$_POST` gửi chuỗi làm `normalize()` ném lỗi kiểu:**
   - Browser gửi `$_POST` toàn bộ là `string` (`'ai_enabled' => '1'`, `'timeout_seconds' => '20'`, `'max_retries' => '1'`, `'allowed_models' => "m1\nm2"` hoặc mảng), và khi uncheck checkbox `ai_enabled` thì khóa không xuất hiện trong `$_POST` (dẫn tới `array_merge($currentSettings, $input)` giữ kẹt `ai_enabled = true` không bao giờ tắt được!).
   - **Cách sửa bắt buộc trong `AiSettingsController::handlePost()`:**
     - Chuẩn hóa rõ ràng payload từ HTTP form sang kiểu domain trước khi gọi `$this->repository->save()`:
       - `expected_revision` bắt buộc phải có mặt trong form submit (`null` hoặc thiếu trong `handlePost` phải trả `'invalid'` / `'expected_revision'` thay vì bỏ qua kiểm tra revision!).
       - Parse `ai_enabled` (bool: hỗ trợ cả `bool` lẫn `'1'`/`'0'`; nếu submit từ HTML form có cờ form marker mà vắng `ai_enabled` thì hiểu là `false`), `timeout_seconds` và `max_retries` (chỉ ép `int` khi là `int` hoặc chuỗi số nguyên thuần `/^-?\d+$/`, chuỗi rác như `'abc'` giữ nguyên hoặc trả `'invalid'` ngay), `allowed_models` (nhận `array` hoặc chuỗi phân tách bởi dấu phẩy/xuống dòng, trim từng phần tử rỗng).
       - Chỉ truyền đúng tập khóa hợp lệ sang `repository->save()`, không đẩy các khóa rác của `$_POST` (như `action`, `submit`) vào `save()`.
     - Trong `handleWordPressPost()`: dùng `wp_unslash($_POST)` nếu hàm tồn tại, và sau khi xử lý xong nếu `wp_safe_redirect` khả dụng thì redirect an toàn về trang settings kèm mã trạng thái đóng (không in raw output hay lộ chi tiết nhạy cảm).

---

### F4-3 [Blocker Crypto] — Hoàn thiện AES-256-GCM Fallback, AAD chuẩn & Constant Key Material

Trong `docs/giai-doan-2-f-r3-rp.md`, SE tự ghi nhận *"Chưa có AES-256-GCM fallback"*, và code `AiSettingsRepository.php` hiện tại cũng chưa gắn AAD cũng như chưa đọc hằng số PHP `MYSEO_AI_SECRET_KEY`:

1. **Bổ sung hằng số `MYSEO_AI_SECRET_KEY` vào `resolvedEncryptionKey()`:**
   - Kiểm tra theo thứ tự: `$this->encryptionKey` (test fixture 32 bytes) -> PHP Constant `MYSEO_AI_SECRET_KEY` (nếu `defined` và là chuỗi khác rỗng) -> `getenv('MYSEO_AI_SECRET_KEY')` -> nối 4 hằng số WordPress salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY` — yêu cầu **đủ cả 4 hằng số khác rỗng** để không dẫn xuất khóa yếu từ 1 hằng số ngắn), rồi chạy `hash_hkdf('sha256', $material, 32, 'myseo-ai-settings-v1', '')`.
2. **Chuẩn hóa AAD và hỗ trợ cả 2 nhánh AEAD (`sodium` ưu tiên + `aes-256-gcm` fallback):**
   - Chuỗi AAD cố định: `$aad = self::OPTION_KEY . '|v1|' . $cipher . '|' . $fingerprint`.
   - **Nhánh 1 (ưu tiên):** Nếu `function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')`, dùng AEAD có AAD thực sự; nếu dùng `sodium_crypto_secretbox` (không có tham số AAD native) thì bắt buộc bind `$aad` vào key dẫn xuất con (`hash_hkdf('sha256', $key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $aad, '')`) và tách/kiểm tra `MAC` 16 bytes vào trường `'tag'` base64 thật sự (không để `'tag' => 'none'` giả tạo!).
   - **Nhánh 2 (fallback bắt buộc):** Khi Sodium không khả dụng (hoặc qua constructor toggle `$preferOpenSsl = false` để test deterministic cả 2 nhánh trên cùng máy!), dùng `openssl_encrypt($plaintextJson, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce12Bytes, $tag, $aad, 16)` và `openssl_decrypt(..., $aad)` với kiểm tra `strlen($nonce) === 12` và `strlen($tag) === 16`.
   - `decryptCredential()` giải mã theo đúng `$envelope['cipher']` (`sodium_secretbox` / `aes-256-gcm`); sửa bất kỳ trường nào trong `cipher`, `key_fingerprint`, `nonce`, `tag`, `ciphertext` đều phải fail-closed trả `'decryption_failed'`.

---

### F4-4 [Major Concurrency & Storage] — Sửa `OptionStore::compareAndSwap()`, `update()` và `purgeLegacyCoreKeys()`

1. **`OptionStore::compareAndSwap()` (`src/Infrastructure/WordPress/OptionStore.php` dòng 65–75):**
   - Hiện tại khi `$this->cas === null`, hàm âm thầm chạy `$this->get()` rồi `$this->update()` (non-atomic read-then-write), trái trực tiếp với O3 mục 8 của `docs/giai-doan-2-f.md` (*"nếu CAS không khả dụng thì fail-closed và không claim concurrency guarantee"*).
   - **Quyết định rõ ràng:**
     - Trong `OptionStore::compareAndSwap()`: chỉ thực thi khi `is_callable($this->cas)`, hoặc trên runtime WordPress khi có `global $wpdb` hợp lệ hỗ trợ câu lệnh nguyên tử (hoặc nếu không dùng `$wpdb` thì trả `false` fail-closed khi `$this->cas === null`, tuyệt đối **không** giả lập CAS bằng `get() + update()` bên trong `compareAndSwap()`!).
     - Đối với `AiSettingsRepository::save()`: trên single-site WordPress, nếu `$options->hasCas()` khả dụng thì gọi `compareAndSwap(self::OPTION_KEY, $rawLoadedSnapshot, $candidate)`; nếu đang chạy trên WordPress Options API chuẩn không có injected CAS, dùng `expected_revision` đã kiểm tra + `OptionStore::update()` một dòng duy nhất (như lý do kiến trúc r8 đã chốt: 1 row update + `expected_revision` cho single-site form, nhưng không gọi mạo danh là atomic CAS).
2. **`OptionStore::update()` với giá trị không đổi và tạo mới:**
   - Ở dòng 50–55, nếu `get_option($key, $missing) === $missing`, gọi `add_option($key, $value, '', false)` và **trả về ngay kết quả đó** (không fall through xuống `update_option` khi `add_option` thất bại!).
   - Khi option đã tồn tại: nếu giá trị hiện tại `=== $value`, coi là no-op `true` để `Plugin::activate()` gọi lại không bị nhận nhầm `false` từ `update_option()` của WordPress.
3. **`AiSettingsRepository::purgeLegacyCoreKeys()`:**
   - Nếu `$options->hasCas()` là `true`, dùng `compareAndSwap(Configuration::OPTION_KEY, $raw, $clean)` và khi CAS trả `false` (trong khi option vẫn tồn tại nhưng đã đổi khác `$raw`) thì trả `['status' => 'purge_conflict']`.
   - Trong `Plugin::activate()`: không cần gọi `update()` đè rồi mới gọi `purgeLegacyCoreKeys()` thành 2 lần ghi trùng lặp — chỉ tạo mặc định nếu `myseo_core_settings` chưa tồn tại, còn nếu đã tồn tại thì dùng chính đường lọc allowlist/`purgeLegacyCoreKeys()` để tránh ghi đè mù.

---

### F4-5 [Major Core Bug] — Sửa lỗi `sanitize_key()` làm hỏng `locale` trong `src/Core/Configuration/Configuration.php`

- Dòng 34–36 hiện viết:
  `$locale = function_exists('sanitize_key') ? sanitize_key($values['locale']) : ...;`
  rồi khớp `/^[a-z]{2}(?:_[A-Z]{2})?$/`.
- Trong WordPress thật, `sanitize_key('en_US')` ép toàn bộ chuỗi về chữ thường `'en_us'`, khiến regex đòi `[A-Z]{2}` **luôn trượt 100%** trên runtime WordPress thật cho mọi locale có mã vùng (`vi_VN`, `en_US`, `ja_JP`...)!
- **Cách sửa bắt buộc:** Không dùng `sanitize_key()` cho `locale` có chữ hoa; chỉ kiểm tra trực tiếp `preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/', $values['locale']) === 1` trên chuỗi gốc! (Bổ sung test với `'en_US'` để khóa cứng).

---

### F4-6 [Blocker Verification & Clean Snapshot] — Mở rộng `tests/Unit/IncrementFTest.php` & Commit Snapshot Sạch

`tests/Unit/IncrementFTest.php` hiện mới có 4 test cơ bản (102 dòng), thiếu `namespace MySeoForAll\Tests\Unit;`, chưa dọn `$GLOBALS` trong `tearDown()`, chưa có ca dương tính cho `validateResolvedIps()` (vì dùng `203.0.113.x` thuộc TEST-NET bị chặn!) và thiếu phần lớn ma trận bắt buộc của r8/r3.

1. **Chuẩn hóa `IncrementFTest.php`:**
   - Khai báo đúng `namespace MySeoForAll\Tests\Unit;`, không dùng `$GLOBALS` (giữ mảng state cục bộ trong object test double `InMemoryOptionStore` có đếm `readCount`, `writeCount`, `deleteCount`, `casCount` và cờ `autoload`), không định nghĩa bất kỳ global function nào.
2. **Bổ sung đầy đủ các bài test còn thiếu:**
   - **Core & Purge:** giữ đúng 6 khóa, giữ nguyên `locale = 'en_US'`, loại bỏ `ai_enabled`/`api_key`/`base_url`/`sitemap_owner` sai; `AdminModule::sanitizeSettings()` chạy với `writeCount === 0`; `purgeLegacyCoreKeys()` đủ 4 trạng thái `clean_noop`, `purged`, `purge_conflict`, `purge_failed`.
   - **Validator URL/IP/Path/Resolver:**
     - Chặn raw `/v1/../admin`, `/v1/.`, `/v1\admin`, encoded `/v1/%2fadmin`, `/v1/%252e%252e`, `/v1/%3fq=1`, `/v1/%23frag`, `/v1/%25`, query/fragment rỗng (`https://gw.example.test/v1?`, `#`), userinfo (`https://u@gw.example.test`), port `:8443`, trailing dot, `anthropic.com` & `sub.anthropic.com`.
     - Chặn obfuscated IP (`https://0177.0.0.1`, `https://0x7f.0.0.1`, `https://127.1`, `https://2130706433`), IPv4-mapped IPv6 (`https://[::ffff:127.0.0.1]`, `https://[::ffff:169.254.169.254]`), NAT64 (`https://[64:ff9b::1]`), CGNAT (`https://100.64.0.1`), metadata (`https://169.254.169.254`); và kiểm tra **IPv6 public hợp lệ** (`https://[2606:4700:4700::1111]/v1`) giữ nguyên dấu ngoặc vuông `[...]`!
     - **Resolver:** static fail -> `resolverCalls === 0`; ca dương tính với IP public thật (ví dụ `['8.8.8.8', '1.1.1.1', '8.8.8.8']`) trả `status = 'ok'` và `ips = ['1.1.1.1', '8.8.8.8']` đã khử trùng + sắp xếp; ca âm tính với exception, mảng rỗng, >32 IP, và hostname thuộc `$allowedInternalHosts` nhưng resolve ra `10.0.0.1` vẫn bị chặn.
   - **Crypto, Precedence, Clear & Readiness:**
     - Round-trip và tamper fail-closed (`key_fingerprint`, `nonce`, `tag`, `ciphertext`, `cipher`/AAD) cho **cả 2 nhánh Sodium VÀ AES-256-GCM fallback**; HKDF từ WordPress salts / `MYSEO_AI_SECRET_KEY`; thiếu key material trả `encryption_unavailable` với `writeCount === 0`.
     - Biên độ dài key `15` (reject), `16` (ok), `512` (ok), `513` (reject), whitespace `'   '` (reject, không trim), `''` rỗng giữ nguyên key cũ; `clear_api_key = true` xóa envelope về `null` trong cùng option; gửi đồng thời `clear_api_key` + key mới bị reject; chế độ environment read-only chặn cả save key mới lẫn clear với `writeCount === 0`.
     - Readiness fail-closed: gửi `ai_enabled = true` nhưng thiếu `base_url`, thiếu `default_model` hoặc chưa có credential hợp lệ -> persist `ai_enabled === false`.
   - **Admin UI, Controller & Health Redaction:**
     - `AdminModule::renderPage()` render 2 form tách biệt, `expected_revision` phản ánh đúng revision hiện tại (`0` -> sau khi lưu lên `1` -> render ra `value="1"`!), lần lưu thứ 2 liên tiếp từ form thành công (`revision = 2`), password input luôn `value=""`, HTML output được escape và không chứa raw key/ciphertext.
     - Capability fail, nonce fail, thiếu/sai `expected_revision`, `api_key` sai kiểu (`['bad']`) đều có `writeCount === 0`.
     - `Plugin::health()` giữ nguyên đúng 4 module canonical (`admin`, `woocommerce`, `seo`, `rank_math`), xuất đúng 4 khóa đóng dưới `health()['ai']` và không lộ `base_url`, `default_model`, `allowed_models`, `credential_envelope` hay secret.

3. **Clean Snapshot & Verification Gate bắt buộc trước khi báo cáo F-r4:**
   - Commit sạch các thay đổi của Increment F thành snapshot commit thật sự (không stage `.phpunit.result.cache` hay file tạm ngoài phạm vi).
   - Chạy và ghi nhận đầy đủ mã `VERIFY-RUN-...`, exit code, commit SHA và `sha256` của từng file cho cả hai lệnh:
     1. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php`
     2. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
   - Ghi báo cáo kết quả vào `docs/giai-doan-2-f-r4-rp.md`.
