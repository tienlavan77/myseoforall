# Increment G r6 — Hướng dẫn hoàn tất dứt điểm sau r5

> Đối chiếu trực tiếp `docs/giai-doan-2-g-r5-rp.md` (`sha256:c313fa93ffc2945f61f03d506d5b9480b870c44e6f15abff1bf7d8ed51d609a1`), `docs/giai-doan-2-g-r5.md` (`sha256:9165f905c0648ccea4652cc456fd02201b4b320d2855b62878e95be5649608b4`), `docs/giai-doan-2-g.md` (`sha256:05eb45a348a3ca2423afa0e38ab0d43ebf5c21ff59e8e0d345e7e3a993c50f36`) và mã nguồn trên `HEAD f38c1d6ba36f04c691729cd684186674cf3efb20` (implementation commit `c277d44a5c04d765301824dccc2a8b58f32b980e`).

## 1. Đánh giá thực chất G-r5: **CHƯA ĐẠT (`NOT ACCEPTED`)**

Trong G-r5, SE đã sửa đúng 4 lỗi phân loại cốt lõi của `G5-1` trong `AiSettingsRepository::executeGatewayRequest()` (`credential_envelope === null -> provider_disabled`, env rỗng `"" -> authentication_failed` không fallback DB, tách `gateway_policy_rejected` khỏi `deadline_exceeded`, dùng `hrtime()` monotonic và giữ nguyên `429 -> rate_limited` khi hết ngân sách backoff).

Tuy nhiên, đúng như SE đã tự kết luận trong `docs/giai-doan-2-g-r5-rp.md`, Increment G chưa thể nghiệm thu vì:
1. **Còn 4 lỗi thực tế trong source code (`G6-1`)** phát hiện khi kiểm tra từng dòng ở `NativeGatewayResolver`, `AiSettingsRepository`, `GatewayAiProvider` và `CurlGatewayTransport`.
2. **Ma trận `AC1..AC6` (`G5-2` -> `G6-2`) bị bỏ dở một nửa:** `IncrementGTest.php` mới có 8 test nhỏ, thiếu phần lớn `AC1, AC2, AC3, AC4, AC5, AC6`.
3. **Vệ sinh Git & Độ chính xác báo cáo (`G6-3`):**
   - Commit `63b6527` trước đó đã vô tình đổi file mode `100644 -> 100755` trên ~90 file `src/`, `tests/`, `docs/` không liên quan, và commit `c277d44` vẫn commit thay đổi vào `.phpunit.result.cache`.
   - Bảng SHA-256 cuối `docs/giai-doan-2-g-r5-rp.md` ghi sai hash của `src/AI/CurlGatewayTransport.php` (`362bdac3...`), trong khi file thật trên đĩa có `sha256:a8ddceb464710b718e76be24ddddbcc877a2a3e07ce2ebfc629f5169061b2182`.

**Giữ nguyên kiến trúc tối giản hiện tại** (không đẻ thêm class/queue/audit engine mới). SE thực hiện trọn vẹn **4 mục `G6-1 .. G6-4`** dưới đây trong **một vòng chốt duy nhất** — không cắt bớt test để đẩy sang vòng sau nữa!

---

## 2. Bốn mục bắt buộc thực hiện ở `G-r6`

### G6-1 [Blocker Code Fixes] — Sửa 4 lỗi còn sót trong `src/AI/`

1. **[Blocker Bug — Leak Global Error Handler] `src/AI/NativeGatewayResolver.php` (dòng 13–26):**
   - Hiện tại dòng 18 viết: `finally { if ($previous !== null) { restore_error_handler(); } }` (kèm `if (function_exists('dns_get_record'))` kiểm tra thừa 2 lần ở dòng 13 và 16).
   - **Lỗi nghiêm trọng trong PHP:** Khi trước đó chưa có custom error handler nào được đăng ký, `set_error_handler(...)` trả về `null` nhưng **vẫn đẩy handler nuốt toàn bộ lỗi (`fn (): bool => true`) vào stack của PHP**! Do điều kiện `if ($previous !== null)`, `restore_error_handler()` **không bao giờ được gọi**, làm rò rỉ handler câm toàn cục sang toàn bộ WordPress và PHPUnit sau lần gọi `resolve()` đầu tiên!
   - **Cách sửa bắt buộc:** Xóa `if` thừa dòng 16, và trong khối `finally` **luôn gọi thẳng `restore_error_handler();` vô điều kiện** (mỗi `set_error_handler()` bắt buộc đi kèm đúng 1 `restore_error_handler()`).
   - Đồng thời mở tham số tùy chọn giữ tương thích ngược trên `GatewayResolver::resolve(string $host, int $timeoutSeconds = 5): array` và `NativeGatewayResolver::resolve(string $host, int $timeoutSeconds = 5): array`; tại `AiSettingsRepository::executeGatewayRequest()` truyền `max(1, min(10, (int) ceil($deadline - $clock())))` vào `$this->resolver->resolve($host, $dnsTimeout)` (nếu hết thời gian trước khi gọi thì dừng `deadline_exceeded` ngay, không gọi resolver).

2. **[Major Contract Gap — Thiếu `temperature` cố định theo O3] `src/AI/AiSettingsRepository.php` (dòng 134):**
   - `docs/giai-doan-2-g.md` (O3) quy định rõ: *"Body dùng model của snapshot, `max_tokens` hữu hạn ≤ 2048, **temperature cố định**, system prompt theo task và một user message gồm task/context đã kiểm định."*
   - Hiện tại dòng 134 mới có `'model'`, `'max_tokens' => 2048`, `'system'`, `'messages'`, **quên trường `'temperature'`**.
   - **Cách sửa bắt buộc:** Thêm `'temperature' => 0.2` (hoặc `0.0` cố định, kiểu `float`, `0.0 <= temperature <= 1.0`) vào mảng `$body` ở dòng 134 và assert trực tiếp trong `IncrementGTest`.

3. **[Blocker Security — Che tham số nhạy cảm trên toàn bộ Call Stack theo O1 & AC2]:**
   - Hiện mới gắn `#[\SensitiveParameter]` cho `$headers` trên `GatewayTransport::send()` và `CurlGatewayTransport::send()`. Nếu exception nổ bên trong transport/repository/provider, stack trace PHP (khi môi trường có `zend.exception_ignore_args = 0`) vẫn chụp nguyên `$request` / `$context` / `$body` hoặc `$apiKey` / `$envelope` trên các frame phía trên!
   - **Cách sửa bắt buộc:** Gắn `#[\SensitiveParameter]` cho:
     - `$request` trong `GatewayAiProvider::generate(#[\SensitiveParameter] array $request): array` và `validateRequest(#[\SensitiveParameter] array $request): void`;
     - `$context` trong `AiSettingsRepository::executeGatewayRequest(string $task, #[\SensitiveParameter] array $context): array`;
     - `$apiKey` trong `AiSettingsRepository::save(..., #[\SensitiveParameter] ?string $apiKey = null)` và `encryptCredential(#[\SensitiveParameter] string $apiKey)`, cùng `$envelope` trong `decryptCredential(#[\SensitiveParameter] array $envelope)`;
     - `$body` trong `GatewayTransport::send(..., #[\SensitiveParameter] array $body, ...)` và `CurlGatewayTransport::send(..., #[\SensitiveParameter] array $body, ...)` (cùng tất cả anonymous test doubles implement `GatewayTransport` trong `IncrementGTest.php`).

4. **[Minor Hardening] `src/AI/CurlGatewayTransport.php` (dòng 31–36):**
   - Kiểm tra tường minh `($parts['scheme'] ?? '') === 'https'` ngay ở đầu `send()` (nếu khác `'https'` hoặc `$host === ''` -> `throw new \RuntimeException('gateway_policy_rejected')`) để transport tự bảo vệ độc lập ngay cả khi bị gọi trực tiếp trong test.

---

### G6-2 [Blocker Verification] — Hoàn tất ĐẦY ĐỦ ma trận `AC1..AC6` trong `tests/Unit/IncrementGTest.php`

Không được dừng ở 8 test sơ bộ nữa. Viết đầy đủ các test methods trong `tests/Unit/IncrementGTest.php` bao phủ trọn vẹn từng hàng của `AC1..AC6` trong `docs/giai-doan-2-g.md`:

1. **AC1 — Zero-Network Lifecycle & Thứ tự ưu tiên Readiness:**
   - Khởi tạo `new Plugin(...)` (inject spy `GatewayResolver` + spy `GatewayTransport` qua `Container`), gọi `boot()` và `health()`: assert `resolverCalls === 0`, `transportCalls === 0`; `LocalSeoAnalyzer::analyze()` vẫn chạy bình thường khi AI tắt.
   - Qua `GatewayAiProvider::generate()`: kiểm chứng `ai_enabled = false`, option `absent`, option `corrupted`, `default_model` ngoài `allowed_models`, và `credential_envelope === null` đều ném `PROVIDER_DISABLED` với `0` DNS/HTTP call.
   - Precedence: (a) settings `corrupted` + env key sai định dạng cùng lúc -> ném **`PROVIDER_DISABLED`** (settings kiểm trước credential); (b) settings `ok` + env key rỗng/quá ngắn trong khi DB có key hợp lệ -> ném **`AUTHENTICATION_FAILED`** (không fallback DB); (c) envelope DB bị sửa tag/sai khóa giải mã -> ném **`AUTHENTICATION_FAILED`** (`0` DNS/HTTP).

2. **AC2 — Hai Auth Schemes, Hai Ciphers & Redaction trên Exception/Trace/Service State:**
   - Chạy round-trip `save()` -> `GatewayAiProvider::generate()` với cả **4 tổ hợp**: (`auth_scheme = 'x_api_key'` vs `'bearer'`) × (Sodium `$preferOpenSsl = false` vs AES-256-GCM `$preferOpenSsl = true`), kiểm tra đúng tên & giá trị header nhận được ở transport (`x-api-key: ...` vs `Authorization: Bearer ...`, không gửi cả hai cùng lúc).
   - Dùng marker giả `'sk-marker-secret-999888777'` và payload chứa chuỗi `'marker-title-payload-123'`, cho transport ném `RuntimeException('connect_failed')`: bắt `AiProviderException` và assert cả hai chuỗi marker **không xuất hiện** trong `$e->getMessage()`, `$e->getPrevious()` (`=== null`), `var_export($e->getTrace(), true)` (kể cả khi tạm bật `ini_set('zend.exception_ignore_args', '0')` trong `try/finally`) và `var_export($provider, true)`.
   - Dùng `ReflectionClass(AiSettingsRepository::class)` xác nhận không tồn tại method `readPlaintext` hay `withAuthorizedHeaders`.

3. **AC3 — IP Pinning (IPv4, IPv6, Literal), Tắt Proxy/Redirect & Re-resolve mỗi Retry:**
   - Trên `CurlGatewayTransport`: kiểm chứng pin IPv4 (`gw.example.test:443:1.1.1.1`), pin IPv6 (`gw.example.test:443:[2606:4700:4700::1111]`), và URL literal IP public (`https://[2606:4700:4700::1111]/v1/messages` -> **không có** `CURLOPT_RESOLVE`, giữ nguyên URL); assert đầy đủ `CURLOPT_PROXY === ''`, `CURLOPT_FOLLOWLOCATION === false`, `CURLOPT_MAXREDIRS === 0`, `CURLOPT_SSL_VERIFYPEER === true`, `CURLOPT_SSL_VERIFYHOST === 2`, `CURLOPT_PROTOCOLS === CURLPROTO_HTTPS`.
   - Set tạm `putenv('HTTPS_PROXY=http://127.0.0.1:8888')` trong `try/finally` và xác nhận `$options[CURLOPT_PROXY]` vẫn bằng `''` (tắt proxy môi trường).
   - Khi retry 2 lần (`max_retries = 2`, attempt 1 lỗi `connect_failed`, attempt 2 trả `200`), resolver trả `['1.1.1.1']` ở lần 1 và `['1.0.0.1']` ở lần 2 -> assert `resolverCalls === 2` và attempt 2 nhận đúng IP mới `['1.0.0.1']`.
   - Tập IP hỗn hợp `['1.1.1.1', '10.0.0.1']` và lỗi `NativeGatewayResolver` khôi phục đúng error handler sau khi gọi `resolve('')` / host không tồn tại.

4. **AC4 — Đủ 3 Tasks, 3 dạng Base URL, Fixed Temperature & Chặn toàn bộ Response/Schema sai:**
   - Thành công end-to-end qua `GatewayAiProvider::generate()` (`status = 'ok'`, `provider = 'gateway'`) cho đủ **3 tasks**: `seo_analysis`, `title_meta_suggestion`, `product_description_draft`; và kiểm chứng **3 dạng `base_url`** (`https://gw.example.test`, `https://gw.example.test/v1/`, `https://gw.example.test/v1/messages/`) đều gọi đúng `https://gw.example.test/v1/messages` kèm `'temperature'` cố định trong `$body`.
   - Biên request: thiếu khóa / thừa khóa / sai `schema_version` / context chưa qua `ProviderPayloadBuilder` -> `MALFORMED_REQUEST` (`0` DNS/HTTP); task lạ -> `UNSUPPORTED_TASK` (`0` DNS/HTTP).
   - Biên upstream (mỗi ca đều ném `INVALID_RESPONSE` với đúng **`1` lần gọi transport, không retry**): (a) 2 content blocks, (b) block `type = 'tool_use'`, (c) `stop_reason = 'max_tokens'`, (d) text bọc Markdown ```` ```json ... ``` ````, (e) trả thẳng direct envelope nội bộ không bọc Messages, (f) JSON hỏng, (g) vi phạm `AiOutputValidator` (`uses_commercial_facts = true` ở `product_description_draft` hoặc chứa email trong text), và (h) multi-chunk write callback đúng `65.536` bytes (`32768 + 32768`) thành công vs thêm `1` byte (`+ 'x'`) bị hủy ngay với `INVALID_RESPONSE`.

5. **AC5 — Toàn bộ Bảng lỗi O4, Retry/Backoff, Monotonic Deadline & Replay Safety:**
   - Kiểm chứng từng hàng status/lỗi: `401`, `403` -> `AUTHENTICATION_FAILED` (1 call); `429` -> `RATE_LIMITED`; `400`, `500` -> `TRANSPORT_FAILED` (1 call); `302` -> `INVALID_RESPONSE` (1 call); `tls_failed`, `timeout_after_send`, `transport_unavailable` -> `TRANSPORT_FAILED` (1 call, không retry).
   - `connect_failed` / `connect_timeout_unsent` với `max_retries = 2`: chạy đủ 3 attempts, sleeper ghi nhận chính xác `[100, 200]`; khi attempt 2 trả `200` hợp lệ thì thành công ngay ở attempt 2.
   - Replay safety cho POST (`429` và `503` với `max_retries = 2`):
     - Khi `$replaySafePost = false`: dừng ngay sau **`1` attempt** (không retry POST đã gửi, không gửi header `Idempotency-Key`);
     - Khi `$replaySafePost = true`: thử tối đa `3` attempts với **cùng 1 `Idempotency-Key`** trong cùng lệnh `generate()`, và 2 lệnh `generate()` kế tiếp sinh **2 `Idempotency-Key` khác nhau**.
   - Ngân sách thời gian (fake clock): (a) hết deadline ngay sau `validateResolvedIps` -> `TRANSPORT_FAILED` với `transportCalls === 0`; (b) nhận `429` nhưng không đủ thời gian cho backoff -> dừng ngay và giữ nguyên `RATE_LIMITED`.

6. **AC6 — Zero-Retention Spies & Health Invariants:**
   - Bọc `OptionStore` bằng bộ đếm `$writes` / `$deletes`: chạy qua cả 3 nhánh (generate thành công, lỗi transport `503`, lỗi validator `INVALID_RESPONSE`) và xác nhận **`$writes === 0` và `$deletes === 0`** trong suốt quá trình `generate()`.
   - Kiểm tra `Plugin::health()` giữ nguyên đúng 4 modules (`admin`, `woocommerce`, `seo`, `rank_math`) và đúng 4 khóa của `$health['ai']` (`ai_enabled`, `ai_configured`, `ai_settings_state`, `ai_credential_state`), không lộ URL/model/key.

---

### G6-3 [Major Git Hygiene] — Khôi phục File Mode `100644` & `.phpunit.result.cache`

1. Khôi phục file mode về `100644` cho các file `.php` và `.md` bị đổi nhầm sang `100755` ở commit `63b6527` (`chmod 644` trên `src/`, `tests/`, `docs/`), và trả `.phpunit.result.cache` về đúng trạng thái baseline F-r5 (`578b559d0226c38cde60f659a56864df38c9d303`) để git diff của G chỉ còn đúng các thay đổi thực chất.
2. Không sửa bất kỳ nội dung logic nào của Increment A–F ngoài những file thuộc phạm vi Increment G.

---

### G6-4 [Blocker Clean Snapshot & Report] — Commit Snapshot Sạch & Ghi `docs/giai-doan-2-g-r6-rp.md`

1. Commit toàn bộ `G6-1`, `G6-2`, `G6-3` thành một **clean implementation snapshot commit** (working tree sạch hoàn toàn trước khi test).
2. Chạy cả hai lệnh PHPUnit trên đúng commit SHA đó:
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
3. Khôi phục `.phpunit.result.cache` nếu PHPUnit ghi đè trong lúc chạy (đảm bảo `git status` sạch 100%).
4. Ghi báo cáo `docs/giai-doan-2-g-r6-rp.md` kèm: `implementation_commit_sha`, mã `VERIFY-RUN-...`, exit code, tổng số tests & assertions của cả hai lệnh, và bảng `sha256` **đối chiếu chính xác từng file trên đĩa** (tuyệt đối không copy nhầm hash cũ như `CurlGatewayTransport.php` ở r5-rp).
