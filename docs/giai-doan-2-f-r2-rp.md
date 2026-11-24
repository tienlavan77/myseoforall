# Increment F r2 — Báo cáo đọc và bàn giao

## Snapshot

- Tài liệu đã đọc: `docs/giai-doan-2-f-r2.md`
- SHA-256 tài liệu: `0a341d622eb9e46c6ceb67fc4ea5cc3697319ea54af3fa33e39f68375019ee49`
- Báo cáo được đối chiếu: `docs/giai-doan-2-f-rp.md`
- SHA-256 báo cáo được tài liệu r2 tham chiếu: `4fcfcdb7f20f420286c0f4cfc130ca35e0fdb5ca22605ded3d5c38dd340180b4`
- Quyết định kiến trúc: `docs/giai-doan-2-f.md`
- SHA-256 quyết định kiến trúc: `0c9b1e761eb9e46c6ceb67fc4ea5cc3697319ea54af3fa33e39f68375019ee49`

## Kết luận của r2

R2 giữ nguyên hướng single-option tối giản với `myseo_ai_settings`, nhưng xác định implementation hiện tại **chưa thể nghiệm thu**. Tài liệu nêu ba blocker và năm nhóm hoàn thiện bắt buộc; đây là hướng dẫn sửa, chưa phải evidence rằng các mục đã hoàn thành.

## Ba blocker

1. **Global test functions làm ô nhiễm full suite**

   `tests/Unit/IncrementFTest.php` khai báo `get_option()` và `update_option()` ở root namespace. Các hàm này làm thay đổi hành vi những test WordPress khác trong cùng tiến trình. R2 yêu cầu bỏ chúng và mở seam callable cho `OptionStore`.

2. **Gateway URL validator có bypass bảo mật**

   Điều kiện `isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])` dùng AND nên có thể lọt credential, query hoặc fragment đơn lẻ. R2 cũng yêu cầu đóng các trường hợp `*.anthropic.com`, IPv6/mapped IPv6, IP dạng số/hex/octal, IDN và path percent-decoding theo từng segment.

3. **Core, crypto, admin và health chưa đóng contract**

   Core `Configuration` chưa lọc đúng allowlist sáu khóa; repository chưa có clear key, read-only environment handling, key provider, AES-GCM fallback hoặc fail-closed readiness; controller chưa nối admin hook/UI; health chưa xuất các enum AI kín.

## Kế hoạch F2

- **F2-1:** mở seam reader/writer/deleter/CAS cho `OptionStore`, loại bỏ global test functions.
- **F2-2:** khóa core six-key allowlist và thêm `purgeLegacyCoreKeys()` với conflict-safe behavior.
- **F2-3:** chuyển gateway validator thành service có policy đóng băng, sửa host/IP/path/IDN validation.
- **F2-4:** hoàn thiện key material, Sodium/AES-GCM, clear key, precedence và readiness fail-closed.
- **F2-5:** nối controller vào `AdminModule`, render password field rỗng và redaction health.
- **F2-6:** mở rộng test matrix, khóa clean snapshot, chạy focused test và full `tests/Unit`.

## Verification bắt buộc theo r2

R2 yêu cầu chứng minh:

- Không còn global WordPress fixture functions.
- URL matrix chặn credential, query, fragment, provider subdomain, private/mapped IPv6 và traversal encoding.
- Core purge, revision, clear key, read-only environment, crypto tamper và readiness đều có test.
- Admin capability/nonce gate có zero persistence khi bị từ chối.
- Health không lộ URL, model, envelope, fingerprint hoặc API key.
- Focused F tests và toàn bộ `tests/Unit` chạy trên cùng clean snapshot, có SHA, run ID và exit code.

## Trạng thái bàn giao

**Increment F r2: chưa accepted.** Tài liệu r2 là danh sách blocker và hướng hoàn thiện; không có run ID mới hoặc clean snapshot nào được tạo trong lượt đọc này. Không sửa source và không chạy test trong lượt này.
