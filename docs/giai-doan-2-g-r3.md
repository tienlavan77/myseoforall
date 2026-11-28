# Increment G r3 — Hướng dẫn sửa lỗi hỏng file `AiSettingsRepository.php` & Hoàn tất AC1–AC6

> Đối chiếu trực tiếp `docs/giai-doan-2-g-r2-rp.md` (`sha256:f78c784a022683d0e37ac4021634aa0433c0624c1c89cd6209c1013d3d71c334`), `docs/giai-doan-2-g-r2.md` (`sha256:76ea52c2fa09727faa75ed2dfba90301d1e5048b95108f76114f040b8390a7cd`), `docs/giai-doan-2-g.md` (`sha256:05eb45a348a3ca2423afa0e38ab0d43ebf5c21ff59e8e0d345e7e3a993c50f36`) và toàn bộ mã nguồn/git diff hiện tại.

## 1. Kết luận đánh giá G-r2: **CHƯA ĐẠT (`NOT ACCEPTED`)**

Em đã tìm ra chính xác nguyên nhân vì sao `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` bị crash với **exit code 2** trong G-r2:
- File **`src/AI/AiSettingsRepository.php` (`sha256:01aa4081...`) đã bị ghi hỏng hoàn toàn!** File hiện **mất sạch `<?php`, `declare(strict_types=1);`, `namespace MySeoForAll\AI;` và các câu lệnh `use`**, đồng thời mỗi dòng đều dính tiền tố số thứ tự của công cụ đọc (`    14→final class AiSettingsRepository`, `    15→{`...). Vì thiếu thẻ `<?php`, `php -l` coi cả file là plain-text HTML nên báo "No syntax errors", nhưng khi PHPUnit chạy `tests/Unit` nạp tới `Plugin` hoặc `IncrementFTest` thì in nguyên ruột file ra màn hình và nổ lỗi `Error: Class "MySeoForAll\AI\AiSettingsRepository" not found` (exit code 2)!

Các phần `AiProviderException.php` và `GatewayAiProvider.php` đã sửa tốt `G2-1`. Ở vòng **G-r3** này, SE phải sửa dứt điểm **5 mục (`G3-1 .. G3-5`)** dưới đây để đưa toàn bộ test suite về xanh 100% trên snapshot sạch.

---

## 2. Năm mục sửa chữa bắt buộc (`G3-1 .. G3-5`)

### G3-1 [Blocker Crash] — Khôi phục file PHP hợp lệ & Sửa lỗi Fatal/Logic trong `src/AI/AiSettingsRepository.php`

Khi viết lại sạch `src/AI/AiSettingsRepository.php` (bắt đầu bằng `<?php`, `declare(strict_types=1);`, `namespace MySeoForAll\AI;`, không dính bất kỳ ký tự `14→` nào!), đồng thời phải sửa luôn **6 lỗi code bên trong `AiSettingsRepository`** vừa phát hiện qua git diff:

1. **Lỗi PHP Fatal `readonly` không có type-hint ở Constructor:**
   - Trong bản r2 bị hỏng, SE viết `private readonly $clock = null, private readonly $sleeper = null`. Trong PHP 8, thuộc tính `readonly` bắt buộc phải có type-hint (nếu để trống type sẽ nổ **Fatal Error: Readonly property ... must have type** ngay khi có `<?php`!).
   - **Cách sửa:** Khai báo `private readonly ?\Closure $clock = null, private readonly ?\Closure $sleeper = null` (hoặc `private readonly mixed $clock = null, private readonly mixed $sleeper = null`).

2. **Sai thứ tự kiểm tra Credential vs Transport (vi phạm O1 & G2-2):**
   - Bản r2 vẫn đặt `if ($this->transport === null || $this->resolver === null)` **trước** bước kiểm tra credential (`MYSEO_AI_GATEWAY_API_KEY` / `credential_envelope`)! Hệ quả: khi credential vắng (`provider_disabled`) hoặc sai (`authentication_failed`) mà repository không truyền transport, nó ném sai sang `'transport_unavailable'` (`transport_failed`).
   - **Thứ tự bắt buộc:** (1) `load()` + `ai_enabled` + `base_url` + `default_model`/`allowed_models` (`provider_disabled`) -> (2) resolve & giải mã credential (`provider_disabled` nếu vắng, `authentication_failed` nếu sai/lỗi giải mã) -> (3) mới kiểm tra `$this->transport === null || $this->resolver === null` (`transport_unavailable`).

3. **Gọi lặp thừa `validateResolvedIps()` ngoài vòng lặp & thiếu tính ngân sách DNS:**
   - Bản r2 gọi `validateResolvedIps()` một lần ở ngoài vòng `for` (trước khi tính `$deadline = $clock() + ...`!), rồi vào ngay `$attempt = 0` lại gọi `validateResolvedIps()` thêm lần thứ 2 (làm 1 request thành công bị gọi DNS **2 lần** thay vì **1 lần**!).
   - **Cách sửa:** Xóa lần gọi `validateResolvedIps()` thừa bên ngoài vòng `for`! Tính `$deadline = $clock() + (float) $settings['timeout_seconds']` trước vòng `for`, và chỉ gọi `validateResolvedIps()` **đúng 1 lần đầu mỗi attempt bên trong vòng `for`** (nằm trọn trong ngân sách `$deadline`).

4. **Lỗi Retry khi Transport ném Exception tạm thời (`connect_failed` / `connect_timeout_unsent`):**
   - Trong `CurlGatewayTransport::send()`, khi lỗi kết nối chưa gửi được byte nào (`errorCode === 7` hoặc `28` với `requestSize === 0`), hàm **ném `\RuntimeException('connect_failed')` / `\RuntimeException('connect_timeout_unsent')`** thay vì trả mảng `['sent' => false]`!
   - Nhưng vòng `for` trong `executeGatewayRequest()` hiện **không có `try/catch` quanh `$this->transport->send(...)`**, nên exception văng thẳng ra ngoài ngay ở `$attempt = 0` và **không bao giờ retry được lỗi kết nối tạm thời**!
   - **Cách sửa bắt buộc:** Bọc `$this->transport->send(...)` trong `try { ... } catch (RuntimeException $e)`:
     - Nếu `$e->getMessage()` thuộc `['connect_failed', 'connect_timeout_unsent']`: coi là lỗi chưa gửi (`retryable = true`); nếu còn lượt retry (`$attempt < $maxRetries`) và còn đủ ngân sách cho `$backoff` (`100ms` / `200ms`) thì gọi `$sleeper($backoff)` rồi `continue;` sang attempt kế; nếu hết lượt hoặc hết ngân sách thì ném lại `$e`!
     - Nếu `$e->getMessage()` thuộc `['tls_failed', 'timeout_after_send', 'response_too_large', 'transport_unavailable', 'gateway_policy_rejected']` (hoặc bất kỳ lỗi không được phép retry nào): `throw $e;` dừng ngay lập tức không retry!

5. **Lỗi `Idempotency-Key` bị dính chung giữa 2 lần gọi `generate()` khác nhau:**
   - Bản r2 tính `hash('sha256', $task . ':' . json_encode($context))` không có thành phần ngẫu nhiên theo phiên gọi. Nếu người dùng chủ động bấm tạo lại lần thứ 2 cho cùng bài viết sau 1 phút, gateway sẽ trả lại kết quả cũ của lần trước!
   - **Cách sửa:** Sinh 1 mã ngẫu nhiên cho **mỗi lần gọi `executeGatewayRequest()`** (ví dụ `bin2hex(random_bytes(16))`) trước vòng `for` và giữ nguyên cùng `Idempotency-Key` đó xuyên suốt các attempt `0..2` của chính lần gọi `generate()` đó.

6. **System Prompt theo Task phải khớp chính xác Schema khóa của `AiOutputValidator`:**
   - Trong `AiOutputValidator.php` (`sha256:305cebc4...`), các khóa bắt buộc cực kỳ nghiêm ngặt:
     - `seo_analysis`: `{"findings":[{"code":"...","severity":"info|warning|error","message":"...","field":"...","evidence":"...","suggested_fix":"..."}]}`
     - `title_meta_suggestion`: `{"target_field":"title|meta_description","value":"...","language":"vi|en","provenance":"wordpress|woocommerce|rank_math|editor"}`
     - `product_description_draft`: `{"target":"product","content":"...","language":"vi|en","provenance":"wordpress|woocommerce|rank_math|editor","uses_commercial_facts":false}`
   - Hãy ghi rõ đúng tên các khóa bắt buộc này vào `$prompts[$task]` (thay vì viết chung chung *"with title and meta suggestions"* sẽ khiến LLM trả sai khóa `{"title":...,"meta":...}` và bị `AiOutputValidator` từ chối!).

---

### G3-2 [Blocker Transport & Trace Redaction] — Hoàn thiện `#[\SensitiveParameter]` & Kiểm tra Overflow trên Executor Seam

Trong `src/AI/Contracts/GatewayTransport.php` và `src/AI/CurlGatewayTransport.php` (`sha256:e547c25f...`):

1. **Bổ sung `#[\SensitiveParameter]` chống lộ API key trong Exception Trace (O1 / FB-1 / G2-3):**
   - Gắn thuộc tính `#[\SensitiveParameter]` trực tiếp lên tham số `array $headers` ở cả `GatewayTransport::send(string $url, #[\SensitiveParameter] array $headers, ...)` và `CurlGatewayTransport::send(string $url, #[\SensitiveParameter] array $headers, ...)` (cũng như `encryptCredential(#[\SensitiveParameter] string $apiKey)`). Trên PHP 8.2+, PHP sẽ tự động che thành `Object(SensitiveParameterValue)` trong mọi stack trace dù `zend.exception_ignore_args` bật hay tắt!
2. **Kiểm tra `$overflow` cả trên đường `$this->curlExecutor` của `CurlGatewayTransport`:**
   - Ở dòng 91–102 của `CurlGatewayTransport.php`, khi chạy qua `$this->curlExecutor` trong unit test mà callback `$write` đã bật `$overflow = true` (nhận quá `65536` bytes), dòng 97 hiện vẫn return `status => 200` thay vì kiểm tra `if ($overflow) { throw new \RuntimeException('response_too_large'); }` giống dòng 116!
   - **Cách sửa:** Đặt `if ($overflow) { throw new \RuntimeException('response_too_large'); }` ngay sau khối `$this->curlExecutor` trước khi `return`, để test 64 KB + 1 byte kiểm chứng trọn vẹn đường ném `'response_too_large'` -> `AiProviderException::INVALID_RESPONSE`!
3. **Nối Composition ở `src/Core/Plugin.php` không ghi đè Service có sẵn trong Container:**
   - Trong `Plugin::__construct()`, nếu caller/test đã đăng ký sẵn `GatewayTransport::class` hoặc `GatewayResolver::class` vào `$container` truyền vào constructor thì ưu tiên `$this->container->has(...) ? $this->container->get(...) : new ...` (tránh lỗi duplicate registration trên `Container` khi test inject custom container).

---

### G3-3 [Major Resolver] — Thay `@` bằng Error Handler cục bộ & Guard Host rỗng ở `NativeGatewayResolver`

- Trong `src/AI/NativeGatewayResolver.php` (`sha256:d8b6f055...`): kiểm tra `if ($host === '' || !function_exists('dns_get_record')) { return []; }`. Dùng `set_error_handler(static fn (): bool => true)` bọc quanh `dns_get_record(...)` với `restore_error_handler()` trong `finally` (an toàn hơn toán tử `@` khi môi trường cài custom error handler chuyển warning thành exception chứa tên host).

---

### G3-4 [Blocker Test Matrix] — Mở rộng `tests/Unit/IncrementGTest.php` bao phủ đủ `AC1..AC6`

`tests/Unit/IncrementGTest.php` (`sha256:aede9f0d...`) hiện mới chỉ có **2 hàm test ngắn (62 dòng)** cho `CurlGatewayTransport`, chưa test `GatewayAiProvider` hay `AiSettingsRepository::executeGatewayRequest()`! Bắt buộc mở rộng `IncrementGTest.php` kiểm chứng đầy đủ 6 nhóm:

1. **AC1 — Zero-Network & Thứ tự ưu tiên lỗi Readiness:**
   - `new Plugin()`, `boot()`, `health()` có `resolverCalls === 0` và `transportCalls === 0`; `LocalSeoAnalyzer` vẫn hoạt động khi AI tắt.
   - `ai_enabled = false`, option `absent`/`corrupted`, thiếu `base_url`/`default_model`, `default_model` ngoài `allowed_models`, thiếu credential -> đều ném `AiProviderException::PROVIDER_DISABLED` với `0` DNS/HTTP call (kể cả khi repository chưa gắn transport/resolver!).
   - Env/Constant sai định dạng hoặc envelope sai khóa giải mã -> ném `AUTHENTICATION_FAILED` với `0` DNS/HTTP call (không fallback xuống DB); khi đồng thời settings hỏng + credential lỗi thì ưu tiên ném `PROVIDER_DISABLED`.
2. **AC2 — Hai Auth Schemes, Hai Ciphers & Không lộ Secret qua Exception/Trace/State:**
   - Kiểm chứng cả `auth_scheme = 'x_api_key'` (`x-api-key`) và `'bearer'` (`authorization: Bearer ...`), cùng cả Sodium lẫn OpenSSL AES-256-GCM.
   - Khi transport ném exception trong lúc gửi (có truyền `$headers` chứa secret marker `'sk-secret-marker-123456'`), bắt `AiProviderException` và assert chuỗi `'sk-secret-marker-123456'` **không xuất hiện** trong `$e->getMessage()`, `$e->getPrevious()` (`null`), `var_export($e->getTrace(), true)` và `var_export($provider, true)`.
3. **AC3 — IP Pinning (IPv4, `[IPv6]`, IP Literal), Proxy Off & Re-resolve mỗi Retry:**
   - Kiểm chứng `CURLOPT_RESOLVE` cho IPv4 (`gw.example.test:443:1.1.1.1`), IPv6 (`gw.example.test:443:[2606:4700:4700::1111]`), URL IP literal (`https://[2606:4700:4700::1111]/v1/messages` -> không gắn `CURLOPT_RESOLVE`), `CURLOPT_PROXY === ''`, `CURLOPT_FOLLOWLOCATION === false`, `CURLOPT_SSL_VERIFYPEER === true`, `CURLOPT_SSL_VERIFYHOST === 2`.
   - Tập IP trả về có lẫn `10.0.0.1` -> chặn `GATEWAY_POLICY_REJECTED` với `transportCalls === 0`.
   - Khi retry 2 lần, resolver được gọi lại đủ 2 lần (không gọi thừa 2 lần cho 1 attempt đơn!).
4. **AC4 — Ba Tasks, Ba Base URL Paths, Chặn Protocol Sai & Trần 65.536 Bytes:**
   - Chạy thành công `GatewayAiProvider::generate()` cho cả **3 tasks** (`seo_analysis`, `title_meta_suggestion`, `product_description_draft`) và **3 dạng `base_url`** (`https://gw.example.test`, `https://gw.example.test/v1`, `https://gw.example.test/v1/messages`).
   - Kiểm chứng tách biệt `MALFORMED_REQUEST` vs `UNSUPPORTED_TASK`.
   - Kiểm chứng từ chối (`INVALID_RESPONSE`, dừng ở đúng `1` HTTP call không retry) với: 2 content blocks, `type = 'tool_use'`, `stop_reason = 'max_tokens'`, markdown fence quanh JSON, direct envelope nội bộ không bọc Messages, output vi phạm `AiOutputValidator` (`uses_commercial_facts = true` / chứa email), và write callback nhận `65536` bytes (ok) vs `65537` bytes (`INVALID_RESPONSE`).
5. **AC5 — Bảng Lỗi O4, Retry/Backoff `100ms/200ms`, Monotonic Budget & Replay Safety:**
   - Kiểm chứng HTTP `401/403` -> `AUTHENTICATION_FAILED` (1 call); `429` -> `RATE_LIMITED`; `400/500` -> `TRANSPORT_FAILED` (1 call); `302` -> `INVALID_RESPONSE` (1 call); `tls_failed` / `timeout_after_send` -> `TRANSPORT_FAILED` (1 call, cấm retry).
   - Với lỗi `connect_failed` (`sent = false`) và `max_retries = 2`: thử đủ `3` attempts, sleeper nhận đúng `[100, 200]`; nếu attempt 2 trả `200` hợp lệ thì thành công ở `2` attempts.
   - Với HTTP `429`/`503`: khi `$replaySafePost = false` dừng ở `1` attempt (không replay POST đã gửi); khi `$replaySafePost = true` thử lại tối đa `1 + max_retries` với cùng header `idempotency-key` trong 1 lần `generate()`, nhưng hai lần `generate()` riêng biệt sinh hai `idempotency-key` khác nhau!
   - Fake clock nhảy vượt `$deadline` trong lúc resolve hoặc không còn đủ `100ms` trước backoff -> dừng ngay không mở thêm kết nối.
6. **AC6 — Zero-Retention & Health Invariants:**
   - Spy trên `OptionStore` xác nhận `writes === 0` và `deletes === 0` xuyên suốt cả ca `generate()` thành công, lỗi transport và lỗi validator; `Plugin::health()` giữ nguyên 4 modules canonical và 4 khóa đóng của `$health['ai']`.

---

### G3-5 [Blocker Clean Snapshot & Full Unit Suite Gate]

1. **Làm sạch trạng thái Git liên quan trước khi chốt snapshot:**
   - Khôi phục `.phpunit.result.cache` và không để các thay đổi file mode `100755` vô tình tạo nhiễu trên các file của Increment G.
   - Tạo commit sạch chứa đầy đủ code & test của Increment G để có **`commit_sha` thực tế** (không chạy trên dirty tree rồi dẫn SHA cũ như ở r2!).
2. **Bắt buộc cả 2 lệnh kiểm tra đều PASS (`exit code 0`) trên đúng commit SHA đó:**
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` *(Phải pass 100%, không còn lỗi exit code 2!)*
   - Ghi đầy đủ `commit_sha`, mã `VERIFY-RUN-...`, exit code, số lượng tests/assertions và bảng `sha256` của toàn bộ file Increment G vào **`docs/giai-doan-2-g-r3-rp.md`**.
