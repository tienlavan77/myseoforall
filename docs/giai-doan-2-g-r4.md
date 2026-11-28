# Increment G r4 — Hướng dẫn chốt các khoảng trống còn lại sau báo cáo G-r3

> Đối chiếu `docs/giai-doan-2-g-r3-rp.md` (`sha256:d9145639c71abd08709323962f84a55451c93bdf0cb7cf1bf8c1e503c1a3928c`), đặc tả G (`docs/giai-doan-2-g.md`, `sha256:05eb45a348a3ca2423afa0e38ab0d43ebf5c21ff59e8e0d345e7e3a993c50f36`) và source/test hiện tại. Báo cáo r3 ghi focused/full Unit pass, nhưng cũng xác nhận working tree dirty, chưa commit; test G hiện vẫn chỉ có 3 test transport, chưa chứng minh toàn bộ AC1–AC6.

## 1. Kết luận G-r3: **CHƯA ĐẠT (`NOT ACCEPTED`)**

Các lỗi crash và lỗi overflow executor của r2 đã được xử lý; source hiện đã trở lại PHP hợp lệ và full Unit được báo cáo pass. Tuy nhiên chưa được đóng Increment G vì còn các khoảng trống contract thực chất:

- `executeGatewayRequest()` vẫn truyền **timeout cấu hình đầy đủ cho từng attempt**, không truyền phần ngân sách còn lại; deadline hiện mới được kiểm tra trước attempt/backoff, chưa thực sự giới hạn DNS + HTTP theo một ngân sách tổng.
- Request thực tế thiếu header `anthropic-version` bắt buộc trong profile Messages đã khóa ở O1/O3.
- Body `messages[].content` chỉ JSON hóa `context`, bỏ mất trường `task` mà contract O3 yêu cầu gửi cùng context.
- Retry/error behavior chưa được kiểm thử qua `GatewayAiProvider` cho toàn bộ bảng O4; focused test chỉ kiểm tra cURL seam, không kiểm tra credential, response schema, replay safety, resolver retry hay zero-retention.
- Chưa có clean snapshot; không thể gán các run ID trên working tree dirty cho một revision nghiệm thu bất biến.

Không mở thêm abstraction lớn. SE xử lý đúng `G4-1 .. G4-5` dưới đây.

---

## 2. Các mục sửa bắt buộc

### G4-1 [Blocker] — Hoàn thiện ngân sách thời gian tổng và retry contract trong `AiSettingsRepository`

`executeGatewayRequest()` hiện tại (`sha256:af6cb858ea66b478d7a9cf3718a50d13c13e834966a6bb784ecfd52962de4800`) có các điểm cần khóa:

1. **Timeout của transport phải là thời gian còn lại, không phải `settings['timeout_seconds']` lặp lại ở mọi attempt.**
   - Sau bước resolve và ngay trước `$this->transport->send()`, tính:
     `remaining = deadline - clock()`.
   - Nếu `remaining <= 0`, dừng với `deadline_exceeded` trước khi mở HTTP.
   - Truyền `max(1, (int) floor($remaining))` vào `send()`, không truyền lại toàn bộ timeout cấu hình.
   - Nếu resolver là blocking và không nhận deadline/cancellation, phải ghi rõ giới hạn còn lại: resolver production cần một seam có deadline thực thi; không tuyên bố monotonic budget đã được bảo đảm chỉ vì kiểm tra đồng hồ sau khi resolver trả về.

2. **Mỗi attempt chỉ resolve một lần và mỗi retry phải resolve lại.**
   - Giữ invariant: `validateResolvedIps()` đúng một lần trong mỗi attempt, sau đó kiểm tra lại ngân sách trước transport.
   - Không resolve trước vòng lặp hoặc resolve hai lần cho attempt đầu.

3. **Retry exception phải giữ nguyên phân loại nội bộ.**
   - `connect_failed` và `connect_timeout_unsent`: có thể retry nếu còn lượt và còn đủ backoff; khi hết lượt thì ném lại đúng lỗi để provider map thành `transport_failed`.
   - `tls_failed`, `timeout_after_send`, `transport_unavailable`, `gateway_policy_rejected`, `response_too_large`: không retry.
   - Không biến mọi `RuntimeException` thành một lỗi chung trước khi provider phân loại.

4. **Status retry phải đúng bảng O4:**
   - Chỉ `429`, `502`, `503`, `504` được xem xét retry, và chỉ khi `$replaySafePost === true` vì đây là POST.
   - Không retry `500`, `400`, `401`, `403`, `404`, `422`, redirect, status bất hợp lệ hoặc body/schema lỗi.
   - `max_retries` là số retry thêm, tổng attempt tối đa `1 + max_retries`.
   - Sleeper nhận đúng `100ms`, rồi `200ms`; không chờ nếu ngân sách còn lại không đủ.

5. **Idempotency key:**
   - Sinh một key ngẫu nhiên cho mỗi `executeGatewayRequest()` và giữ nguyên qua các attempt của lần gọi đó.
   - Chỉ gửi header `Idempotency-Key` khi profile replay-safe được bật; khi replay-safe tắt thì không được tạo ấn tượng rằng POST có thể replay an toàn.

### G4-2 [Blocker Protocol] — Khóa đúng HTTP Messages request

Trong `executeGatewayRequest()`:

1. Bổ sung header bắt buộc:
   - `Content-Type: application/json; charset=utf-8`;
   - `Accept: application/json`;
   - `anthropic-version: 2023-06-01`;
   - đúng một trong `x-api-key` hoặc `Authorization: Bearer ...`.
2. Body phải chứa đúng dữ liệu đã được kiểm định, gồm cả task:
   `messages[0]['content'] = json_encode(['task' => $task, 'context' => $context], ...)`.
   Không truyền context thô ngoài payload boundary và không nhận model/URL override từ request.
3. System prompt phải mô tả đúng schema khóa của từng task như G3 đã quy định; prompt không thay thế validation. `GatewayAiProvider` vẫn phải gọi `AiOutputValidator::validate()` sau khi parse.
4. Kiểm tra endpoint hoàn chỉnh sau ghép `/v1/messages`, giới hạn độ dài và static policy trước resolver; không gọi DNS nếu static policy fail.

### G4-3 [Major Security] — Khóa zero-retention và sensitive arguments end-to-end

1. Duy trì `#[\SensitiveParameter]` cho header/API-key parameters ở contract và implementation, đồng thời kiểm tra không truyền raw exception làm `previous` ra ngoài.
2. Trong mọi nhánh `finally`, xóa tham chiếu credential, headers và body; không giữ chúng trong property của repository/transport/provider.
3. Không ghi request/response vào `OptionStore`, log, cache, transient, file, telemetry hoặc exception message. `OptionStore writes === 0` chỉ là một phần bằng chứng; test phải kiểm cả state service và exception trace/message.
4. Không coi test offline là bằng chứng retention của gateway bên thứ ba; báo cáo phải tách rõ hai phạm vi.

### G4-4 [Blocker Test] — Mở rộng `IncrementGTest.php` từ 3 test transport thành ma trận AC1–AC6

`tests/Unit/IncrementGTest.php` hiện mới có các test transport cơ bản và checksum `sha256:04159756b53289a28a6d4826fcfa4a5453b89514c8584f8ec7eb1955af76aabb`. Bổ sung test offline với resolver/transport/clock/sleeper/OptionStore doubles:

- **AC1:** `Plugin` construct/boot/health có 0 DNS/HTTP; AI tắt, settings absent/corrupted, thiếu model/base URL, credential thiếu/sai đều dừng trước resolver/transport; local analyzer vẫn hoạt động.
- **AC2:** cả `x-api-key` và Bearer, Sodium và AES-GCM, precedence Constant → Env → DB; marker secret không xuất hiện trong message, previous, trace hoặc object state.
- **AC3:** cURL seam kiểm tra IPv4/IPv6 pin, literal IP không tạo pin sai, proxy/redirect/TLS/fresh connection, resolver được gọi lại từng retry; tập IP có private address bị chặn trước send.
- **AC4:** ba task và ba dạng base URL; header/body có `task`, `context`, `anthropic-version`; response hai block, tool-use, stop reason sai, markdown fence, direct envelope, JSON/schema sai và output chứa commercial facts đều bị từ chối không retry; overflow 65.537 bytes bị từ chối.
- **AC5:** HTTP 401/403/429/400/500/502/503/504/302; lỗi TLS, timeout sau gửi, connect unsent; retry `0/1/2`, sleeper `100/200`, deadline cạn trong resolve/backoff, replay-safe bật/tắt và idempotency key khác nhau giữa hai generate riêng.
- **AC6:** success/transport/schema failure đều không ghi persistence; giữ 4 module canonical và 4 khóa health AI; test không dùng global WP function hoặc `$GLOBALS` bừa bãi.

### G4-5 [Blocker Snapshot] — Clean snapshot và báo cáo có thể truy vết

1. Không tự ý khôi phục các thay đổi lịch sử ngoài phạm vi của người khác, nhưng phải tạo snapshot sạch có phạm vi rõ ràng cho Increment G; loại `.phpunit.result.cache` và thay đổi mode không chủ ý.
2. Chạy trên đúng commit SHA:
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php`
   - `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`
3. Cả hai phải exit `0`. Báo cáo `docs/giai-doan-2-g-r4-rp.md` phải ghi commit SHA, run IDs, exit codes, tests/assertions và checksum từng file G; không dùng SHA của dirty working tree làm snapshot nghiệm thu.
4. Reviewer độc lập vẫn phải kiểm tra diff và boundary trước khi Owner quyết định đóng Giai đoạn 2. Full Unit pass là bằng chứng kỹ thuật, không phải bằng chứng gateway production hoặc retention upstream.
