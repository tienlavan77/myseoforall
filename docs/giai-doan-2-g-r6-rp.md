# Báo cáo Increment G r6

Ngày: 24/11/2026.

## Kết quả G6-1

Đã harden gateway execution:

- `NativeGatewayResolver` luôn restore error handler và nhận timeout tùy chọn tương thích ngược.
- Executor tính timeout DNS còn lại trước mỗi resolve và truyền timeout bounded.
- Request Messages có `temperature: 0.2` cố định.
- Bổ sung `#[SensitiveParameter]` cho request/context/body/API key/envelope ở các boundary chính.
- `CurlGatewayTransport` từ chối scheme khác HTTPS hoặc URL thiếu host trước khi tạo request.

Giới hạn: `dns_get_record()` không hỗ trợ cancellation thực sự; timeout mới là contract/seam để resolver production thay thế bằng implementation có deadline.

## Kết quả G6-2

Giữ các test r5 và bổ sung assertion fixed temperature; các anonymous resolver doubles nhận timeout tùy chọn. Focused coverage xác nhận các thay đổi mới cùng regression cũ. Ma trận AC1–AC6 vẫn chưa bao phủ trọn mọi auth/cipher, toàn bộ status/retry, schema-response matrix, trace redaction end-to-end, zero-retention spies và health invariants; do đó chưa tuyên bố đóng Increment G.

## Verification snapshot

- Implementation commit: `6d1ef99cf87776aad63e8ed194105f44a7696683`
- Focused: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementGTest.php` — PASS, exit 0, `VERIFY-RUN-e3514abe-6ff9-4cbe-aeb7-49c512e6ea26`.
- Full Unit: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` — PASS, exit 0, `VERIFY-RUN-99753b9e-30a0-434b-84ad-dc5de63c198b`.
- PHPUnit cache đã được khôi phục sau verification.

## Kết luận

Increment G vẫn **CHƯA ĐẠT (NOT ACCEPTED)**. Các lỗi source G6-1 đã xử lý và hai suite pass trên cùng snapshot; Reviewer độc lập vẫn cần kiểm tra boundary và phần AC6 còn thiếu. Offline tests không chứng minh gateway production hoặc retention upstream.

## SHA-256

- `src/AI/AiSettingsRepository.php`: `5ae10f389611094307bd30f307bd804c9c31487d57f22368cc01ad9b04735b47`
- `src/AI/Contracts/GatewayResolver.php`: `44a5e666d168f62babc0be747b498ff9fa7189b198497637a9f1fb7acf0ac1f8`
- `src/AI/Contracts/GatewayTransport.php`: `60416efcb6fcd85d3d30e73f9f36c221aa997794d0ad47d51d61c4309ea7fa27`
- `src/AI/CurlGatewayTransport.php`: `a696c2b977f6d765e22c8fb7ff78fa317c57b0989c5d31e369f23b792136d278`
- `src/AI/GatewayAiProvider.php`: `438b5573ae8bacde2fe10634da05bd27ddb87add3b219124b1533e5025180e10`
- `src/AI/NativeGatewayResolver.php`: `8e76bf62bd84d361ecd55b9cb38b7c2f554ca44da68cf731f1a244b20d87af09`
- `tests/Unit/IncrementGTest.php`: `416f6fa3c7e1b58268f671a43f0c73a709e952ce94fc6cc62181da62521fc3e9`
