# Increment G r5 — Hướng dẫn chốt nghiệm thu cuối cùng sau r4

> Đối chiếu trực tiếp `docs/giai-doan-2-g-r4-rp.md` (`sha256:ca46716a1cd6781360e80b408a19792a84a91501a76b77f953eea8f92afa16a9`), `docs/giai-doan-2-g-r4.md` (`sha256:e2434227ec62ca4cf269f9e750e5434a65e6214d344355beef9b4df0069cc9ea`), `docs/giai-doan-2-g.md` (`sha256:05eb45a348a3ca2423afa0e38ab0d43ebf5c21ff59e8e0d345e7e3a993c50f36`) và mã nguồn tại commit `63b6527c3760d3d439d2304cfcf811b3dfcbbf8b`.

## 1. Kết luận đánh giá G-r4: **CHƯA ĐẠT (`NOT ACCEPTED`)**

G-r4 đã có tiến bộ lớn: đã commit sạch working tree, đã bổ sung Messages headers (`Content-Type`, `Accept`, `anthropic-version`), đã đưa cả `task` + `context` vào body và cả hai lệnh PHPUnit đã pass.

Tuy nhiên giống hệt SE tự nhận trong báo cáo r4, `tests/Unit/IncrementGTest.php` mới có **4 test nhỏ (113 dòng)**, chưa viết ma trận AC1–AC6 — và **chính vì chưa viết test nên 4 lỗi logic mới phát sinh ngay trong `executeGatewayRequest()` (dòng 120–147 của `src/AI/AiSettingsRepository.php`) đã bị lọt!**

Ở vòng **G-r5 (vòng chốt hạ)** này, SE xử lý dứt điểm đúng **3 mục (`G5-1 .. G5-3`)** dưới đây để đóng hoàn toàn Increment G và khép lại Giai đoạn 2.

---

## 2. Ba mục sửa chữa bắt buộc (`G5-1 .. G5-3`)

### G5-1 [Blocker Bug] — Sửa 4 lỗi phân loại trong `AiSettingsRepository::executeGatewayRequest()`

Trong `src/AI/AiSettingsRepository.php` (`sha256:abbbbc39576d7ae5ad61c0d4352df8b03cfe27466ca84bd9525b90f04b4e6709`, dòng 119–147), sửa ngay 4 lỗi rút gọn câu lệnh quá tay ở r4:

1. **Lỗi 1 (Dòng 141) — Đang nuốt lỗi SSRF/DNS thành `'deadline_exceeded'` (`TRANSPORT_FAILED`):**
   - Hiện viết gộp:
     `if (($resolved['status'] ?? 'invalid') !== 'ok' || ($remaining = $deadline - $clock()) <= 0) throw new RuntimeException('deadline_exceeded');`
   - Hậu quả: Khi DNS trả về IP nội bộ (`10.0.0.1`, `127.0.0.1`, `169.254.169.254`) hoặc DNS fail (`$resolved['status'] === 'invalid'`), nó ném sai thành `'deadline_exceeded'` -> `GatewayAiProvider` map thành `TRANSPORT_FAILED` thay vì **`GATEWAY_POLICY_REJECTED`**!
   - **Cách sửa bắt buộc — tách làm 2 câu `if` riêng biệt:**
     ```php
     if (($resolved['status'] ?? 'invalid') !== 'ok') {
         throw new RuntimeException('gateway_policy_rejected');
     }
     $remaining = $deadline - $clock();
     if ($remaining <= 0) {
         throw new RuntimeException('deadline_exceeded');
     }
     ```

2. **Lỗi 2 (Dòng 123) — Biến trạng thái chưa có API key (`credential_envelope === null`) thành `'authentication_failed'`:**
   - Hiện viết: `try { $credential = $this->decryptCredential($settings['credential_envelope'] ?? []); } catch (RuntimeException) { throw new RuntimeException('authentication_failed'); }`
   - Khi chưa cấu hình bất kỳ nguồn key nào (`Constant` vắng, `Env` vắng, `credential_envelope === null`), truyền `[]` vào `decryptCredential()` làm ném `'authentication_failed'`, trái với bảng O1 & O4 của `docs/giai-doan-2-g.md` (*"key vắng -> `provider_disabled`"*).
   - **Cách sửa bắt buộc:** Kiểm tra `if ($settings['credential_envelope'] === null) { throw new RuntimeException('provider_disabled'); }` **trước** khi gọi `decryptCredential($settings['credential_envelope'])`!

3. **Lỗi 3 (Dòng 122) — Bỏ lọt biến môi trường có mặt nhưng rỗng (`MYSEO_AI_GATEWAY_API_KEY=""`):**
   - Dòng 122 viết: `if (defined('MYSEO_AI_GATEWAY_API_KEY') || ($env !== false && $env !== '')) throw new RuntimeException('authentication_failed');`
   - Theo O1 của `docs/giai-doan-2-g.md`: *"Constant/env có mặt nhưng **rỗng** hoặc sai định dạng: dừng `authentication_failed`, không dùng DB"* (cũng khớp với `credentialStatus()` dòng 160–163 đang coi `$rawEnvironment !== false` mà sai `validApiKey` là `'invalid_environment'`). Việc thêm `&& $env !== ''` làm khi `MYSEO_AI_GATEWAY_API_KEY=""` nó lén fallback xuống DB!
   - **Cách sửa bắt buộc:** Đổi thành `if (defined('MYSEO_AI_GATEWAY_API_KEY') || $env !== false) { throw new RuntimeException('authentication_failed'); }` (vì nếu hợp lệ thì `$this->environmentCredential()` ở dòng 119 đã khác `null` rồi).

4. **Lỗi 4 (Dòng 126, 136 & 146) — Trailing slash `base_url`, Monotonic Clock & Giữ mã `429` khi cạn ngân sách Backoff:**
   - **Dòng 126:** Đang check `str_ends_with((string) $settings['base_url'], '/messages')` trên chuỗi chưa `rtrim`, thay vì check trên biến `$base = rtrim((string) $settings['base_url'], '/')`. Phải gán `$base = rtrim((string) $settings['base_url'], '/');` trước rồi mới kiểm tra `str_ends_with($base, '/messages')` và `str_ends_with($base, '/v1')`.
   - **Dòng 136:** Default clock đang dùng `microtime(true)` (wall-clock bị nhảy khi NTP chỉnh giờ) thay vì đồng hồ monotonic `hrtime(true) / 1_000_000_000` như O2 quy định. Đổi về `static fn (): float => hrtime(true) / 1_000_000_000`.
   - **Dòng 146:** Sau khi đã nhận được HTTP response (ví dụ `status = 429` -> `RATE_LIMITED`), nếu không còn đủ ngân sách để chờ backoff retry tiếp (`$clock() + $backoff / 1000 >= $deadline`), phải **`return $result;`** (để `GatewayAiProvider` ném đúng `RATE_LIMITED` cho `429` hoặc `TRANSPORT_FAILED` cho `502/503/504`), không ném đè `'deadline_exceeded'` làm mất mã `RATE_LIMITED` của response vừa nhận! (Còn ở dòng 143 nhánh `catch` chưa nhận được HTTP response thì ném `'deadline_exceeded'` hoặc `$error` đều map ra `TRANSPORT_FAILED` là chuẩn).

---

### G5-2 [Blocker Verification] — Hoàn tất trọn vẹn ma trận `AC1..AC6` trong `tests/Unit/IncrementGTest.php`

**Không được để lại mục test thiếu sang vòng sau nữa!** Mở rộng `tests/Unit/IncrementGTest.php` (tương tự chuẩn 96 assertions của `IncrementFTest.php`) để phủ kín cả 6 điều kiện nghiệm thu thông qua `GatewayAiProvider` + `AiSettingsRepository` + `CurlGatewayTransport`:

1. **AC1 — Zero-Network & Thứ tự ưu tiên lỗi Readiness:**
   - `new Plugin()`, `boot()`, `health()` có `resolverCalls === 0` và `transportCalls === 0`; `LocalSeoAnalyzer` vẫn chạy bình thường khi AI tắt.
   - Kiểm chứng qua `GatewayAiProvider::generate()`:
     - `ai_enabled = false`, option `absent`, option `corrupted`, thiếu `base_url`/`default_model`, `default_model` không thuộc `allowed_models`, và `credential_envelope === null` -> tất cả ném **`AiProviderException::PROVIDER_DISABLED`** với `0` DNS/HTTP call (kể cả khi chưa gắn transport/resolver).
     - Env `MYSEO_AI_GATEWAY_API_KEY=""` (hoặc quá ngắn) trong khi DB có key hợp lệ -> ném **`AUTHENTICATION_FAILED`** với `0` DNS/HTTP call (chứng minh không fallback xuống DB; dùng `putenv('MYSEO_AI_GATEWAY_API_KEY=...')` và dọn sạch `putenv('MYSEO_AI_GATEWAY_API_KEY')` trong `finally`!).
     - Envelope DB sai khóa giải mã -> ném **`AUTHENTICATION_FAILED`** (`0` DNS/HTTP call); khi đồng thời settings `corrupted` + env sai -> ưu tiên ném **`PROVIDER_DISABLED`**.

2. **AC2 — Hai Auth Schemes, Hai Ciphers & Không lộ Secret qua Exception/Trace/State:**
   - Kiểm chứng cả `auth_scheme = 'x_api_key'` (`x-api-key`) và `'bearer'` (`Authorization: Bearer ...`), cùng cả nhánh Sodium (`$preferOpenSsl = false`) lẫn AES-256-GCM (`$preferOpenSsl = true`).
   - Khi transport ném exception trong lúc gửi với secret marker `'sk-secret-marker-987654'`, bắt `AiProviderException` và assert marker đó **không xuất hiện** trong `$e->getMessage()`, `$e->getPrevious()` (`null`), `var_export($e->getTrace(), true)` và `var_export($provider, true)`.

3. **AC3 — IP Pinning, SSRF Policy Rejected & Re-resolve mỗi Retry:**
   - Giữ các test `CurlGatewayTransport` hiện có (IPv6 `[2606:4700:4700::1111]`, empty DNS, 65.536 vs 65.537 bytes) và bổ sung: IPv4 pin (`gw.example.test:443:1.1.1.1`), URL IP literal (`https://[2606:4700:4700::1111]/v1/messages` -> không có `CURLOPT_RESOLVE`).
   - Qua `GatewayAiProvider::generate()`: khi resolver trả `['1.1.1.1', '10.0.0.1']` (hoặc `['169.254.169.254']`) -> bắt buộc ném **`AiProviderException::GATEWAY_POLICY_REJECTED`** (chứng minh Lỗi 1 ở `G5-1` đã được sửa!) với `transportCalls === 0`.
   - Khi retry 2 lần, resolver trả IP thay đổi theo từng lần và transport nhận đúng IP mới ở mỗi attempt (`resolverCalls === attemptCount`).

4. **AC4 — Đủ 3 Tasks, 3 dạng Base URL & Chặn toàn bộ Response/Schema sai:**
   - Chạy `GatewayAiProvider::generate()` thành công (`status = 'ok'`, `provider = 'gateway'`) cho đủ **3 tasks** (`seo_analysis`, `title_meta_suggestion`, `product_description_draft`) và **3 dạng `base_url`** (`https://gw.example.test`, `https://gw.example.test/v1`, `https://gw.example.test/v1/messages`).
   - Kiểm chứng tách biệt `MALFORMED_REQUEST` (thiếu khóa / context chưa qua `ProviderPayloadBuilder`) vs `UNSUPPORTED_TASK`.
   - Kiểm chứng từ chối **`INVALID_RESPONSE`** với đúng `1` HTTP call (không retry) cho: 2 content blocks, `type = 'tool_use'`, `stop_reason = 'max_tokens'`, text bọc markdown ```json, direct envelope nội bộ không bọc Messages, JSON hỏng, và output vi phạm `AiOutputValidator` (`uses_commercial_facts = true` hoặc chứa email).

5. **AC5 — Đầy đủ Bảng lỗi O4, Retry/Backoff, Monotonic Deadline & Replay Safety:**
   - Kiểm chứng HTTP `401/403` -> `AUTHENTICATION_FAILED` (1 call); `429` -> `RATE_LIMITED`; `400/500` -> `TRANSPORT_FAILED` (1 call); `302` -> `INVALID_RESPONSE` (1 call); `tls_failed` và `timeout_after_send` -> `TRANSPORT_FAILED` (1 call, không retry).
   - Lỗi `connect_failed` với `max_retries = 2`: thử đủ 3 lần, sleeper nhận `[100, 200]`; nếu attempt 2 trả `200` hợp lệ thì thành công ở 2 lần.
   - HTTP `429` và `503`: khi `$replaySafePost = false` dừng ngay ở `1` call (không có header `Idempotency-Key`); khi `$replaySafePost = true` thử lại tối đa `1 + max_retries` với cùng `Idempotency-Key` trong 1 lần `generate()`, còn 2 lần `generate()` riêng biệt sinh 2 `Idempotency-Key` khác nhau!
   - Fake clock cạn ngân sách ngay sau bước resolve -> `TRANSPORT_FAILED` với `transportCalls === 0`; fake clock nhận `429` ở attempt 1 nhưng không đủ `100ms` cho backoff -> dừng ngay ở `1` call và vẫn ném đúng **`RATE_LIMITED`**.

6. **AC6 — Zero-Retention & Health Invariants:**
   - Spy trên `OptionStore` xác nhận `writes === 0` và `deletes === 0` xuyên suốt mọi ca `generate()` (thành công, lỗi transport, lỗi schema); `Plugin::health()` giữ nguyên 4 modules canonical (`admin`, `woocommerce`, `seo`, `rank_math`) và 4 khóa đóng của `$health['ai']`.

---

### G5-3 [Blocker Clean Snapshot] — Commit Snapshot Sạch & Báo cáo Nghiệm thu cuối vào `docs/giai-doan-2-g-r5-rp.md`

1. Giữ sạch `.phpunit.result.cache`, commit toàn bộ sửa đổi `G5-1` và `G5-2` thành một **clean snapshot commit** mới.
2. Chạy trên đúng commit SHA sạch đó:
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
3. Xác nhận cả hai đều PASS (`exit code 0`), khôi phục `.phpunit.result.cache` nếu bị chạm, và ghi đầy đủ `commit_sha`, mã `VERIFY-RUN-...`, exit code, số lượng tests/assertions cùng bảng `sha256` của toàn bộ file Increment G vào **`docs/giai-doan-2-g-r5-rp.md`**.
