# Increment G r2 — Hướng dẫn sửa chữa sau khi rà soát báo cáo G-r1

> Đối chiếu trực tiếp `docs/giai-doan-2-g-rp.md` (`sha256:135d1313d4ab369722280d6642430f3876f8c86c384dd818d643cfa3ba917caf`), `docs/giai-doan-2-g.md` (`sha256:05eb45a348a3ca2423afa0e38ab0d43ebf5c21ff59e8e0d345e7e3a993c50f36`) và toàn bộ 8 file mã nguồn hiện tại trên working tree.

## 1. Kết luận đánh giá G-r1: **CHƯA ĐẠT (`NOT ACCEPTED`)**

Cấu trúc lớp của G-r1 rất gọn và đúng hướng kiến trúc (`GatewayAiProvider`, `AiSettingsRepository::executeGatewayRequest`, `CurlGatewayTransport`, `NativeGatewayResolver`, không mở callback lộ header, không lưu payload). Tuy nhiên kiểm tra trực tiếp mã nguồn phát hiện **6 nhóm lỗi Blocker/Major thực tế** — trong đó có lỗi làm toàn bộ 5 category mới của `AiProviderException` bị biến hết thành `'malformed_request'`!

SE sửa dứt điểm đúng **6 mục (`G2-1 .. G2-6`)** dưới đây, không vẽ thêm abstraction ngoài phạm vi.

---

## 2. Sáu mục sửa chữa bắt buộc (`G2-1 .. G2-6`)

### G2-1 [Blocker Bug] — `AiProviderException::__construct()` đang nuốt sạch 5 category mới thành `'malformed_request'` & Sai `UNSUPPORTED_TASK`

1. **`src/AI/Contracts/AiProviderException.php` (`sha256:8fbf0f21...`, dòng 19–38):**
   - Đã khai báo 5 hằng số mới (`PROVIDER_DISABLED`, `AUTHENTICATION_FAILED`, `GATEWAY_POLICY_REJECTED`, `RATE_LIMITED`, `TRANSPORT_FAILED`), **nhưng mảng allowlist trong `__construct()` dòng 32–36 vẫn chỉ có 3 hằng số cũ**!
   - Hậu quả: Mọi `new AiProviderException(AiProviderException::PROVIDER_DISABLED, ...)` hay `AUTHENTICATION_FAILED` / `GATEWAY_POLICY_REJECTED` / `RATE_LIMITED` / `TRANSPORT_FAILED` đều rơi vào nhánh fallback dòng 37: `$category = self::MALFORMED_REQUEST;`!
   - **Cách sửa bắt buộc:** Đưa đủ cả 8 hằng số vào allowlist của `AiProviderException::__construct()`.

2. **`src/AI/GatewayAiProvider.php` (`sha256:6803e832...`, dòng 45–55):**
   - **Lỗi `UNSUPPORTED_TASK`:** Dòng 46 gộp chung `!in_array($request['task'], self::TASKS, true)` vào câu `if` ném `MALFORMED_REQUEST`, trái trực tiếp với bảng O4 của `docs/giai-doan-2-g.md` và chuẩn `FakeAiProvider`! Phải kiểm tra envelope + `is_string($request['task'])` trước (`MALFORMED_REQUEST`), sau đó nếu `!in_array($request['task'], self::TASKS, true)` thì ném **`AiProviderException::UNSUPPORTED_TASK`**. (Đồng thời kiểm tra mảng `$request` theo tập khóa `array_diff`, không phụ thuộc thứ tự khóa truyền vào như `array_keys($request) !== [...]`).
   - **Lỗi gán sai `transport_unavailable`:** Dòng 54 đang map `'transport_unavailable' => AiProviderException::PROVIDER_DISABLED`. Theo bảng O4 của `docs/giai-doan-2-g.md` (*"TLS lỗi, không bảo đảm transport... -> `transport_failed`"*), `'transport_unavailable'` phải map sang **`AiProviderException::TRANSPORT_FAILED`**, không phải `PROVIDER_DISABLED`!

---

### G2-2 [Blocker Thứ tự O1 & Readiness] — Kiểm tra Settings & Model TRƯỚC Transport/Credential/Network

Trong `src/AI/AiSettingsRepository.php` (`sha256:9ead2135...`, dòng 106–124):

1. **Sai thứ tự kiểm tra `transport_unavailable` vs `provider_disabled`:**
   - Dòng 108 kiểm tra `if ($this->transport === null || $this->resolver === null)` **trước** dòng 109 `$this->load()`. Khi tạo `new AiSettingsRepository($store)` mặc định mà `ai_enabled = false` hoặc option `absent`, gọi qua provider sẽ báo lỗi transport thay vì `provider_disabled`!
   - **Thứ tự bắt buộc theo O1:**
     1. Đọc `$loaded = $this->load()`; kiểm tra `$loaded['status'] === 'ok'`, `$settings['ai_enabled'] === true`, `$settings['base_url'] !== ''`, `$settings['default_model'] !== ''` (và nếu `$settings['allowed_models'] !== []` thì `in_array($settings['default_model'], $settings['allowed_models'], true)`). Nếu trượt bất kỳ điều kiện nào -> ném `'provider_disabled'` ngay lập tức!
     2. Giải quyết và xác minh credential (`Constant -> Env -> DB`): nếu vắng cả 3 nguồn -> `'provider_disabled'`; nếu Constant/Env có mặt nhưng sai định dạng hoặc giải mã DB lỗi -> `'authentication_failed'`.
     3. Kiểm tra capability runtime (`$this->transport !== null && $this->resolver !== null`, và nếu yêu cầu `zend.exception_ignore_args` trên production runtime hoặc gắn `#[\SensitiveParameter]` cho các tham số nhận `$headers`/`$credential` trên PHP 8.2+ để trace không bao giờ lưu secret!). Nếu thiếu `$transport`/`$resolver` -> ném `'transport_unavailable'` (map ra `TRANSPORT_FAILED`).

2. **Kiểm tra chuẩn hóa Endpoint hoàn chỉnh & Prompt theo Task:**
   - Dòng 122–123 ghép `/messages` hoặc `/v1/messages` từ `$settings['base_url']`. Phải kiểm tra lại endpoint sau ghép không vượt 512 bytes (để `validateResolvedIps($endpoint, ...)` xử lý nhất quán).
   - Ở dòng 130, `system` prompt cần nêu rõ cấu trúc JSON output bắt buộc của đúng `$task` đang gọi (`seo_analysis` / `title_meta_suggestion` / `product_description_draft`) và cấm bọc markdown code fence hay bịa dữ liệu thương mại, để gateway thật trả đúng schema mà `AiOutputValidator` yêu cầu.

---

### G2-3 [Blocker Transport O2] — Sửa lỗi Pin IPv6, Seam kiểm thử `CurlGatewayTransport` & Trần 64 KB

Trong `src/AI/CurlGatewayTransport.php` (`sha256:bcfe5643...`, dòng 13–34):

1. **Bug cú pháp `CURLOPT_RESOLVE` với IPv6 (dòng 18):**
   - Hiện viết: `$pin = ... ? $host . ':443:' . $ips[0] : null;`.
   - Khi `$ips[0]` là địa chỉ IPv6 (chứa `:`, ví dụ `2606:4700:4700::1111`), chuỗi thành `gw.example.test:443:2606:4700:4700::1111` — cURL parse theo dấu `:` nên hỏng hoàn toàn!
   - **Cách sửa bắt buộc (đã ghi rõ tại O2 `docs/giai-doan-2-g.md`):** Nếu `str_contains($ip, ':')`, phải bọc ngoặc vuông: **`$host . ':443:[' . $ip . ']'`**; nếu là IPv4 thì `$host . ':443:' . $ip`. Nếu `$ips === []` khi `$host` không phải IP literal -> fail-closed ngay trước khi mở cURL!

2. **Thiếu cờ bảo mật cURL để ép kết nối mới & chỉ HTTPS:**
   - Bổ sung vào `curl_setopt_array`:
     - `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS` và `CURLOPT_REDIR_PROTOCOLS => 0` (nếu hằng số tồn tại);
     - `CURLOPT_MAXREDIRS => 0`;
     - `CURLOPT_FRESH_CONNECT => true` và `CURLOPT_FORBID_REUSE => true` (đảm bảo mỗi attempt mở kết nối mới gắn đúng IP vừa kiểm định, không dùng lại connection pool cũ như O2 yêu cầu);
     - Nếu `defined('CURLOPT_NOPROXY')`, đặt thêm `CURLOPT_NOPROXY => '*'` bên cạnh `CURLOPT_PROXY => ''`.

3. **Phân biệt lỗi trước khi gửi vs sau khi gửi, và lỗi vượt 64 KB:**
   - Dòng 28–32: Khi write callback từ chối chunk vượt `65536` bytes (`return 0`), cURL trả `CURLE_WRITE_ERROR` (`23`) và dòng 32 hiện ném chung `'transport_failed'`! Theo bảng O4 của `docs/giai-doan-2-g.md`, **body vượt 65.536 bytes phải phân loại là `'response_too_large'` -> map ra `AiProviderException::INVALID_RESPONSE` (không retry)**.
   - Phân biệt rõ mã lỗi cURL (`curl_errno($handle)`):
     - Lỗi TLS/chứng chỉ (`CURLE_SSL_*`: `35, 51, 58, 60...`) -> `'tls_failed'` (`TRANSPORT_FAILED`, **cấm retry**).
     - Lỗi kết nối trước khi gửi dữ liệu (`CURLE_COULDNT_CONNECT = 7`) -> `'connect_failed'` (`sent = false`, có thể retry nếu còn ngân sách).
     - Timeout (`CURLE_OPERATION_TIMEDOUT = 28`): kiểm tra `curl_getinfo($handle, CURLINFO_REQUEST_SIZE) === 0` để biết chắc chưa gửi byte HTTP request nào (`connect_timeout_unsent`) hay đã gửi rồi (`timeout_after_send`, **cấm retry** theo O4!).

4. **Mở Seam nhỏ trong `CurlGatewayTransport` để test offline cho AC3 & AC4:**
   - Cho phép truyền optional `$curlExecutor` (hoặc tách hàm build options + write callback có thể gọi từ unit test với fake executor) để `IncrementGTest` kiểm chứng trực tiếp logic của chính `CurlGatewayTransport` mà không mở socket thật: kiểm tra `CURLOPT_RESOLVE` cho cả IPv4 & `[IPv6]`, `CURLOPT_PROXY === ''`, `CURLOPT_FOLLOWLOCATION === false`, `FRESH_CONNECT === true`, và gọi trực tiếp write callback ở đúng `65536` bytes (pass) vs `65537` bytes (hủy ngay và báo `response_too_large` -> `INVALID_RESPONSE`).
   - Đánh dấu `#[\SensitiveParameter]` lên tham số `$headers` của `GatewayTransport::send()` và `CurlGatewayTransport::send()` để exception stack trace không bao giờ chụp được API key!

---

### G2-4 [Blocker Retry, Budget & Replay Safety] — Triển khai đầy đủ O2/O4 cho `max_retries` và Ngân sách Monotonic

Hiện tại `executeGatewayRequest()` và `GatewayAiProvider::generate()` chỉ gọi `transport->send()` **đúng 1 lần duy nhất**, bỏ qua hoàn toàn `settings['max_retries']`, backoff `100ms / 200ms`, đồng hồ monotonic và quy tắc replay safety trong O2/O4!

**Cách triển khai gọn, đúng hợp đồng O2 & O4:**
1. Thêm 2 optional dependencies cuối constructor (có default chuẩn production để không phá vỡ caller hiện tại):
   - Monotonic clock `callable(): float` (mặc định `static fn (): float => hrtime(true) / 1_000_000_000`);
   - Sleeper `callable(int $milliseconds): void` (mặc định `static fn (int $ms): void => usleep($ms * 1000)`).
   - Và cờ cấu hình replay an toàn của profile gateway (mặc định `bool $replaySafePost = false` trừ khi được cấu hình/xác nhận rõ ràng, kèm gửi header `Idempotency-Key` cố định trong suốt 1 lần gọi `generate()` khi bật replay cho HTTP status).
2. Tính `$deadline = $clock() + (float) $settings['timeout_seconds']` ngay đầu lần thực thi:
   - Tổng số attempt tối đa = `1 + (int) $settings['max_retries']` (từ `1` đến `3`).
   - Trước **mỗi** attempt:
     - Kiểm tra `$remaining = $deadline - $clock()`; nếu `$remaining <= 0` -> dừng ngay với `'deadline_exceeded'` (`TRANSPORT_FAILED`).
     - Gọi lại `$this->gateway->validateResolvedIps(...)` (đảm bảo mỗi retry đều kiểm định/ghim lại IP mới nhất như O2 & AC3 yêu cầu!). Sau khi resolve xong, kiểm tra lại `$remaining = $deadline - $clock()`; nếu `<= 0` -> dừng trước khi mở HTTP!
     - Truyền timeout nguyên dương còn lại `max(1, (int) floor($remaining))` vào `$this->transport->send(...)`.
   - Quyết định Retry sau mỗi attempt (theo đúng bảng O4):
     - **Được phép retry** (khi `$attempt < $maxRetries`):
       - Lỗi kết nối trước khi gửi request (`sent === false`, ví dụ `'connect_failed'` / `'connect_timeout_unsent'`); HOẶC
       - HTTP `429`, `502`, `503`, `504` **chỉ khi** profile được bật `$replaySafePost === true` (với cùng `Idempotency-Key` cho cả phiên `generate()`). Nếu `$replaySafePost === false` mà request đã gửi (`sent === true`), không tự ý replay POST!
     - Trước khi sang attempt kế: tính `$backoffMs = $attempt === 0 ? 100 : 200` (tức `0.1s` rồi `0.2s`); nếu `($deadline - $clock()) * 1000 <= $backoffMs` thì không đủ ngân sách -> dừng ngay không chờ và không gửi thêm! Ngược lại gọi `($this->sleeper)($backoffMs)`.
     - **Cấm tuyệt đối retry** cho mọi trường hợp còn lại: `400, 401, 403, 404, 422`, `1xx`, `2xx != 200`, `3xx`, `500`, lỗi TLS, `transport_unavailable`, timeout sau khi đã gửi (`timeout_after_send`), `response_too_large` (> 64 KB), JSON lỗi hoặc `AiOutputValidator` từ chối!
   - Ánh xạ HTTP status cuối cùng khi không thành công (khớp 100% bảng O4):
     - `401`, `403` -> `AUTHENTICATION_FAILED`
     - `429` -> `RATE_LIMITED`
     - `1xx`, `2xx` khác `200`, `3xx`, hoặc status `<= 0` / không hợp lệ -> `INVALID_RESPONSE`
     - Mọi `4xx` khác (`400, 404, 408, 422...`) và mọi `5xx` (`500, 502, 503, 504...`) -> **`TRANSPORT_FAILED`** *(Lưu ý sửa dòng 33 `GatewayAiProvider.php`: hiện dòng 33 đang ném `400/500` thành `INVALID_RESPONSE`, phải sửa đúng bảng O4 là `4xx/5xx` còn lại -> `TRANSPORT_FAILED`, còn `1xx/2xx!=200/3xx` -> `INVALID_RESPONSE`!)*.

---

### G2-5 [Major DNS Timeout Guard] — Giới hạn thời gian ở `NativeGatewayResolver`

- `src/AI/NativeGatewayResolver.php` (`sha256:f594c6b8...`) hiện gọi `dns_get_record($host, DNS_A | DNS_AAAA)` trần (có thể quăng PHP Warning nếu DNS lỗi và không kiểm tra thời gian).
- **Cách sửa gọn:** Chặn PHP warning từ `dns_get_record` (hoặc dùng custom error handler cục bộ trong `try/finally` để không rò hostname nội bộ ra PHP error log!), và cho phép truyền/kiểm tra deadline budget hoặc resolver callable có giới hạn thời gian; nếu trả `false` hoặc quá hạn budget thì trả mảng rỗng `[]` (để `validateResolvedIps` fail-closed thành `gateway_policy_rejected`).

---

### G2-6 [Blocker Test Matrix & Git Hygiene] — Viết đầy đủ `tests/Unit/IncrementGTest.php` & Dọn sạch 95 file bẩn File Mode

1. **Dọn sạch Working Tree trước khi commit (Rất quan trọng!):**
   - `git status` hiện có ~90 file cũ bị đổi quyền thực thi `100644 -> 100755` và `.phpunit.result.cache` bị sửa đổi!
   - Khôi phục toàn bộ file mode ngoài phạm vi và `.phpunit.result.cache` để git diff chỉ chứa đúng các file thực sự thuộc Increment G!

2. **Tạo `tests/Unit/IncrementGTest.php` (`namespace MySeoForAll\Tests\Unit;`, không dùng `$GLOBALS`, không định nghĩa global WP functions) bao phủ trọn vẹn `AC1..AC6`:**
   - **AC1 (Zero-Network & Readiness Precedence):** `new Plugin()`, `boot()`, `health()`, `ai_enabled=false`, settings `absent`/`corrupted`, thiếu `base_url`/`default_model`, `default_model` ngoài `allowed_models`, credential `absent` (`provider_disabled`), Constant/Env sai định dạng (`authentication_failed`, không fallback xuống DB!), envelope sai khóa giải mã (`authentication_failed`), cùng lỗi kép (settings hỏng + env sai -> ưu tiên `provider_disabled`) -> tất cả đều có **`resolverCalls === 0` và `transportCalls === 0`**, đồng thời `LocalSeoAnalyzer` vẫn chạy bình thường.
   - **AC2 (Auth Schemes, Ciphers & Trace/Exception Redaction):** Kiểm chứng cả `x_api_key` và `bearer`, precedence Constant -> Env -> DB (cả Sodium lẫn AES-256-GCM). Khi transport/validator ném lỗi, kiểm tra `$e->getMessage()`, `$e->getPrevious() === null`, `json_encode($e->getTrace())` và state của repository/provider **không chứa** chuỗi API key đánh dấu hay payload!
   - **AC3 (Production `CurlGatewayTransport` Offline Seam & Pinning):** Test trực tiếp `CurlGatewayTransport` qua injected executor giả: xác nhận `CURLOPT_PROXY === ''`, `CURLOPT_FOLLOWLOCATION === false`, `CURLOPT_SSL_VERIFYPEER === true`, `CURLOPT_SSL_VERIFYHOST === 2`, `CURLOPT_FRESH_CONNECT === true`, pin đúng `host:443:1.1.1.1` cho IPv4 và **`host:443:[2606:4700:4700::1111]` cho IPv6**, không tạo pin giả cho URL IP literal, từ chối tập IP hỗn hợp có private IP với `transportCalls === 0`, và mỗi retry đều gọi lại resolver nhận IP mới.
   - **AC4 (Messages Protocol, 3 Tasks, Base Paths & Write Callback 64 KB):**
     - Thành công cho đủ 3 tasks (`seo_analysis`, `title_meta_suggestion`, `product_description_draft`) và 3 dạng `base_url` (`.../v1/messages`, `.../v1`, `https://gw.example.test`).
     - Từ chối `malformed_request` vs **`unsupported_task`** tách biệt; từ chối upstream có 2 content blocks, `type = 'tool_use'`, `stop_reason = 'max_tokens'` (truncated), text bọc markdown code fence, direct internal envelope (không qua Messages), và output vi phạm `AiOutputValidator` (`uses_commercial_facts = true` / chứa email / thừa trường) — tất cả dừng ở `1` attempt không retry.
     - Gọi trực tiếp `CURLOPT_WRITEFUNCTION` nhiều chunk: tổng đúng `65536` bytes nhận đủ; thêm `1` byte (`65537`) hủy ngay và ném `INVALID_RESPONSE`.
   - **AC5 (Retry, Monotonic Budget & Replay Safety):** Dùng fake clock + fake sleeper: kiểm tra từng dòng bảng lỗi O4 (`401/403` -> `authentication_failed`; `429` -> `rate_limited`; `400/500` -> `transport_failed`; `302` -> `invalid_response`; TLS/transport_unavailable -> `transport_failed` không retry); kiểm tra `max_retries = 0/1/2` với lỗi kết nối chưa gửi (`sent = false`) và với `429/503` khi bật vs tắt `$replaySafePost` (xác nhận không replay POST mù khi `$replaySafePost = false`, và gửi cùng `Idempotency-Key` khi bật); kiểm tra cạn ngân sách trong lúc resolve/trước backoff và timeout sau khi gửi (`sent = true`) không retry.
   - **AC6 (Zero-Retention & Clean Snapshot Verification):**
     - Xác nhận `OptionStore` spy có `writes === 0`, `deletes === 0` trên cả thành công, lỗi transport và lỗi validator; giữ nguyên 4 module canonical và 4 khóa `$health['ai']`.
     - Commit **clean snapshot** cho Increment G, chạy cả hai lệnh trên đúng commit SHA đó và ghi đầy đủ `commit_sha`, `VERIFY-RUN-...`, exit code, số tests/assertions và `sha256` từng file vào **`docs/giai-doan-2-g-r2-rp.md`**:
       1. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
       2. `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
