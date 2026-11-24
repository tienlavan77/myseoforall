# Increment F r5 — Hướng dẫn chốt nghiệm thu cuối cùng sau r4

> Đối chiếu trực tiếp `docs/giai-doan-2-f-r4-rp.md` (`sha256:e38023c051d7590087b9f4e3c263edbed8f6b3d78207ff668612420ed4196f30`), `docs/giai-doan-2-f-r4.md` (`sha256:e04359933b1fe1f02411ef04e2b751114103601a5766a92d931474dad478ecaf`) và 8 file source/test hiện tại.

## 1. Kết luận nghiệm thu F-r4: **CHƯA ĐẠT (`NOT ACCEPTED`)**

Các phần `F4-1..F4-5` đã vào đúng kiến trúc **single-option `myseo_ai_settings`** của bản r8, nhưng kiểm tra trực tiếp mã nguồn phát hiện **2 bug code thực tế ở `GatewayEndpointValidator` / `AdminModule`**, **1 dòng gọi thừa ở `Plugin::activate()`**, và **`F4-6` bị bỏ dở** (test vẫn giữ 4 hàm cũ 102 dòng, working tree dirty chưa có clean snapshot).

**Không mở rộng thiết kế** — chỉ xử lý dứt điểm đúng **3 mục bắt buộc (`F5-1 .. F5-3`)** dưới đây để đóng hoàn toàn Increment F.

---

## 2. Ba mục sửa chữa bắt buộc để đóng Increment F

### F5-1 [Blocker] — Sửa thứ tự IDN fail-closed & Single-Label Allowlist trong `src/AI/GatewayEndpointValidator.php`

Code hiện tại (`sha256:b31a0e5554d96e1c31ded9820a89d1503215d49a5485371de091510db8f776ae`) có 3 lỗi logic cần sửa gọn:

1. **Sửa thứ tự kiểm tra IDN / Punycode fail-closed (dòng 48–56):**
   - Dòng 48 hiện chặn `preg_match('/[^\x00-\x7F]/', $host) === 1` **trước** dòng 51 `idn_to_ascii()`, làm chết hoàn toàn tên miền Unicode IDN; đồng thời nếu `$host` chứa `'xn--'` mà thiếu `idn_to_ascii()` hoặc `idn_to_ascii()` trả `false` thì code hiện lờ đi cho chạy tiếp thay vì fail-closed!
   - **Cách sửa bắt buộc:**
     - Ở dòng 48 chỉ chặn trailing dot và `%` (`str_ends_with($host, '.') || str_contains($host, '%')`).
     - Ngay tiếp theo: nếu `preg_match('/[^\x00-\x7F]/', $host) === 1 || str_contains($host, 'xn--')`:
       - Nếu `!function_exists('idn_to_ascii')` -> return `['status' => 'invalid', 'reason' => 'idn_unavailable']`;
       - Gọi `$converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)`; nếu `!is_string($converted) || $converted === ''` -> return `['status' => 'invalid', 'reason' => 'idn_invalid']`;
       - Gán `$host = strtolower($converted);` rồi kiểm tra lại `str_ends_with($host, '.') || preg_match('/[^\x00-\x7F]/', $host) === 1` -> nếu vi phạm thì reject `'host_policy'`.

2. **Cho phép Single-Label Host khi nằm trong `$this->allowedInternalHosts` (dòng 71–76 & 174–186):**
   - Hiện tại `validDnsHost($host)` bắt buộc `count($labels) >= 2` **trước** khi kiểm tra `$this->allowedInternalHosts`, khiến host nội bộ single-label được cấu hình hợp pháp trong `MYSEO_AI_ALLOWED_GATEWAY_HOSTS` (ví dụ `'ai-gateway'`) luôn bị từ chối.
   - **Cách sửa bắt buộc:**
     - Nếu `in_array($host, $this->allowedInternalHosts, true)`: cho phép `count($labels) >= 1`, nhưng mọi label vẫn phải khớp `/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/` và không được toàn chữ số (vẫn đi qua `isObfuscatedIpHost($host)` trước đó, và ở `validateResolvedIps()` vẫn bắt buộc resolve ra IP public!).
     - Nếu **không** nằm trong `$this->allowedInternalHosts`: giữ nguyên yêu cầu `count($labels) >= 2`, TLD không toàn số, và chặn `isInternalHost($host)`.

3. **Lọc chính `'anthropic.com'` trong Constructor (dòng 20):**
   - Dòng 20 hiện mới lọc `!str_ends_with($host, '.anthropic.com')` mà sót `$host !== 'anthropic.com'`. Đổi thành `!$this->isBlockedProvider($host)` (tức `$host !== 'anthropic.com' && !str_ends_with($host, '.anthropic.com')`).

---

### F5-2 [Blocker] — Sửa lỗi `function_exists('options.php')` trong `src/Admin/AdminModule.php` & Dọn dòng thừa ở `src/Core/Plugin.php`

1. **`src/Admin/AdminModule.php` dòng 247 (`sha256:f8584dc2...`):**
   - Đang viết sai:
     `echo '<form method="post" action="' . $this->escapeHtml(function_exists('options.php') ? 'options.php' : '') . '">';`
   - `'options.php'` không phải hàm PHP nên `function_exists('options.php')` luôn trả `false`, làm form Core Settings xuất `action=""` (hỏng submit lưu cấu hình nền tảng trên WordPress!).
   - **Sửa thành:**
     `echo '<form method="post" action="' . $this->escapeHtml(function_exists('admin_url') ? (string) admin_url('options.php') : 'options.php') . '">';`

2. **`src/Core/Plugin.php` dòng 293–299 (`sha256:d5d579ae...`):**
   - Trong `Plugin::activate()`, dòng 296 đã gọi `$repository->purgeLegacyCoreKeys(...)`, nhưng dòng 298 lại gọi thêm một lần `(new \MySeoForAll\AI\AiSettingsRepository($options))->purgeLegacyCoreKeys();` ngay bên dưới (kể cả vừa tạo default xong ở dòng 294 cũng đọc/kiểm tra lại lần nữa).
   - **Sửa gọn:** Xóa dòng 298 bị lặp.

---

### F5-3 [Blocker Verification & Clean Snapshot] — Hoàn thành đầy đủ `tests/Unit/IncrementFTest.php` & Commit Snapshot Sạch

**Không được bỏ qua mục này như ở r4!** Mọi nhánh bảo mật và nghiệp vụ đã code phải có unit test tự động chứng minh trước khi nghiệm thu:

1. **Chuẩn hóa `tests/Unit/IncrementFTest.php`:**
   - Thêm `namespace MySeoForAll\Tests\Unit;` (đồng bộ với các test khác trong `tests/Unit/`).
   - Bỏ hoàn toàn `$GLOBALS['myseo_increment_f_*']`; dùng biến/class helper cục bộ trong test (đếm chính xác `readCount`, `writeCount`, `deleteCount`, `casCount` và cờ `autoload`) để đảm bảo cô lập tuyệt đối giữa các test.

2. **Bổ sung đủ các test case bắt buộc trong `IncrementFTest.php`:**
   - **Core & Purge:** giữ đúng 6 khóa, bảo toàn `locale = 'en_US'`, lọc khóa lạ/AI keys; `AdminModule::sanitizeSettings()` là pure (`writeCount === 0`); `purgeLegacyCoreKeys()` kiểm chứng đủ `clean_noop`, `purged`, `purge_conflict` (với injected CAS) và `purge_failed`.
   - **GatewayEndpointValidator:**
     - Chặn raw `/v1/../admin`, `/v1/.`, `/v1\admin`, encoded `/v1/%2fadmin`, `/v1/%252e%252e`, `/v1/%3fq=1`, `/v1/%23frag`, `/v1/%25`, query/fragment rỗng (`?`, `#`), userinfo (`https://u@gw.example.test`), port `:8443`, trailing dot, `anthropic.com` & `sub.anthropic.com`.
     - Chặn obfuscated IP (`0177.0.0.1`, `0x7f.0.0.1`, `127.1`, `2130706433`), IPv4-mapped IPv6 (`[::ffff:127.0.0.1]`, `[::ffff:169.254.169.254]`), NAT64 (`[64:ff9b::1]`), CGNAT (`100.64.0.1`), metadata (`169.254.169.254`).
     - Khẳng định ca dương tính cho **IPv6 public** (`https://[2606:4700:4700::1111]/v1` giữ nguyên `[...]`), **single-label host trong `$allowedInternalHosts`** (`https://ai-gateway/v1`), và **IDN/punycode** (nếu có `idn_to_ascii` thì `xn--` hợp lệ pass, `xn---` sai bị chặn `'idn_invalid'`).
     - **`validateResolvedIps()`:** static fail -> `resolverCalls === 0`; ca dương tính `['8.8.8.8', '1.1.1.1', '8.8.8.8']` trả `status = 'ok'` và `ips = ['1.1.1.1', '8.8.8.8']`; ca âm tính với exception, mảng rỗng, >32 IP, và host trong `$allowedInternalHosts` resolve ra `10.0.0.1` vẫn bị từ chối.
   - **Crypto (Cả 2 nhánh Sodium & OpenSSL AES-256-GCM), Clear & Readiness:**
     - Test round-trip (`save` -> `credentialStatus() === 'configured'`) và tamper fail-closed (`key_fingerprint`, `nonce`, `tag`, `ciphertext`, đổi `cipher`/AAD -> `'decryption_failed'`) cho **cả `$preferOpenSsl = false` (Sodium) VÀ `$preferOpenSsl = true` (AES-256-GCM)**.
     - Thiếu key material (`new AiSettingsRepository($store, null)` khi không có env/salts) trả `invalid` / `encryption_unavailable` với `writeCount === 0`.
     - Biên key `15` (reject), `16` (ok), `512` (ok), `513` (reject), `'                '` 16 khoảng trắng (reject, không trim), `''` rỗng giữ nguyên envelope cũ; `clear_api_key = true` xóa envelope về `null` trong cùng option; gửi cả `clear_api_key` lẫn key mới bị reject; readiness hạ `ai_enabled = false` khi thiếu `base_url` / `default_model` / credential.
   - **Admin UI (2 lần lưu liên tiếp) & `Plugin::health()` Redaction:**
     - Bắt output `ob_start()` của `AdminModule::renderPage()`: xác nhận có 2 `<form>` riêng (`admin-post.php` và `options.php`, không phải `action=""`), password input có `value=""`, lần đầu render `expected_revision` bằng `"0"`, sau khi `handlePost` lưu thành công lần 1 (`revision = 1`) thì `renderPage()` xuất `expected_revision` bằng `"1"` và submit lần 2 với `expected_revision = "1"` thành công lên `revision = 2` (bao gồm cả tắt checkbox `ai_enabled` qua `ai_enabled_present = '1'`).
     - Capability fail, nonce fail, thiếu `expected_revision`, `api_key` sai kiểu mảng đều có `writeCount === 0`.
     - `Plugin::health()` giữ đúng 4 module canonical (`admin`, `woocommerce`, `seo`, `rank_math`), có `health()['ai']` với đúng 4 khóa đóng và toàn bộ JSON `health()` không chứa `base_url`, `default_model`, `ciphertext` hay raw API key.

3. **Khôi phục sạch cache, Commit Clean Snapshot & Chạy Verification trên đúng SHA:**
   - Khôi phục `.phpunit.result.cache` để không lọt vào commit.
   - Tạo **clean snapshot commit** cho Increment F (working tree `src/` và `tests/` sạch hoàn toàn).
   - Chạy trên đúng commit SHA đó và ghi đầy đủ `commit_sha`, `VERIFY-RUN-...`, exit code, số tests/assertions và `sha256` từng file vào **`docs/giai-doan-2-f-r5-rp.md`**:
     1. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php`
     2. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
