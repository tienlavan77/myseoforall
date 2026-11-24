# Giai đoạn 2 — Increment C r4: Hướng dẫn nghiệm thu và bàn giao

> Handoff Architecture sau `docs/giai-doan-2-increment-c-r3-report.md`. Đây là hướng dẫn review/approval; không phải kế hoạch code mới, Sprint Plan hay mutable-file manifest.

## 1. Kết luận hiện tại

Increment C đã đạt **điều kiện kỹ thuật của snapshot handoff** ở phạm vi Local SEO analyzer offline:

- Snapshot implementation/test: `9c0e2ed40833f784ee9350fce96f122728d3c9a7`.
- Focused analyzer receipt: `VERIFY-RUN-a1541943-9863-4099-92d3-dca69f3c2f72`, exit code 0.
- Toàn bộ unit receipt: `VERIFY-RUN-d71cf77a-0007-4e1c-bac5-ba416c1cccf4`, exit code 0.
- Checksums source/test trong report khớp snapshot đã chạy.

Tuy nhiên **chưa đóng exit gate nghiệm thu** vì report vẫn ghi Reviewer review độc lập và Owner confirmation chưa có. Không cần thêm code C theo bằng chứng hiện tại; việc còn lại là review, approval và kiểm soát snapshot.

## 2. Việc bắt buộc trước khi Owner chốt

### C4-R1 — Reviewer review độc lập

Reviewer cần đối chiếu snapshot `9c0e2ed...` và kiểm tra tối thiểu:

- diff chỉ nằm trong boundary Local SEO analyzer/context/test/report đã nêu;
- không có network, platform API, persistence, writer, Apply hoặc commercial mutation;
- finding đủ sáu trường và ordering deterministic;
- canonical/link không fetch hoặc DNS;
- heading là bounded heuristic, không claim HTML parser correctness;
- decimal policy không dùng float;
- variation không suy diễn parent/commercial data;
- Unicode policy và fallback đúng như report;
- receipt command, run ID, commit/base SHA, scope và checksum khớp.

Reviewer phải ghi kết luận, snapshot/revision đã review và các finding còn lại. Không dùng working-tree state hiện tại để thay thế snapshot đã kiểm thử.

### C4-R2 — Owner decision

Owner quyết định một trong hai trạng thái:

1. **Approve Increment C trong phạm vi offline**: cho phép chuyển sang Increment D/E theo dependency đã duyệt.
2. **Request changes**: giữ C mở, nêu chính xác contract/risk cần sửa; không mở increment tiếp theo.

Receipt `passed` và checksum không tự tạo Owner approval.

### C4-R3 — Snapshot hygiene

- Giữ snapshot `9c0e2ed...` làm revision được nghiệm thu; không chuyển approval sang revision mới chỉ vì HEAD hiện tại khác.
- Báo cáo r3 được hoàn thiện tại commit `ae5a9d4dce2d1ba705bdc6af2b03a58e843e2a3a`; khi review report, phân biệt tài liệu cập nhật sau snapshot với source/test đã được verification.
- Các file kế hoạch untracked hiện tại không phải source/test evidence của C; không đưa `.phpunit.result.cache` vào acceptance.
- Nếu source/test C thay đổi sau snapshot, phải chạy lại focused và unit scope, cập nhật checksum và xin approval revision mới.

## 3. Phạm vi nghiệm thu

Nếu Owner approve, quyết định chỉ áp dụng cho:

- Local SEO analyzer chạy offline, deterministic, read-only;
- finding contract/order, canonical/link syntax, heading heuristic, offer/decimal checks, variation structural checks và Unicode behavior đã nêu trong report;
- test evidence trên snapshot `9c0e2ed...`.

Không suy ra đã đạt:

- runtime WordPress/WooCommerce hoặc Health Check integration;
- HTML/XSS safety, semantic PII completeness hoặc commercial truthfulness;
- Rank Math adapter/ownership transfer;
- AI gateway, secret storage, network, Preview/Apply hoặc commercial writer.

## 4. Hướng tiếp theo sau approval

- **Increment D:** Rank Math read adapter, compatibility/coexistence và ownership registry theo decision gate riêng.
- **Increment E:** runtime composition/hooks/health integration, boot idempotency và dependency-unavailable behavior.

D và E không tự động được mở đồng thời nếu dependency hoặc acceptance của từng increment chưa được chốt. F–H vẫn bị khóa.

## 5. Điều kiện đóng C

Architecture ghi nhận C đã đóng chỉ khi có cả:

1. Reviewer review độc lập trên snapshot đúng revision.
2. Owner approval rõ ràng cho phạm vi offline.
3. Không có blocker Major/Blocker chưa xử lý.
4. Approval neo vào đúng commit/checksum; không neo vào HEAD khác hoặc working tree.

Nếu thiếu một trong bốn điều kiện, trạng thái vẫn là **technically verified, awaiting acceptance**.

## 6. Evidence

- Báo cáo C r3: `docs/giai-doan-2-increment-c-r3-report.md` — `sha256:5f6ceed3f82675357f9aa4f4e6001a3ea75d92c994816c2642c4897eee9d66a5`.
- Snapshot implementation/test: `9c0e2ed40833f784ee9350fce96f122728d3c9a7`.
- Focused receipt: `VERIFY-RUN-a1541943-9863-4099-92d3-dca69f3c2f72`.
- Unit receipt: `VERIFY-RUN-d71cf77a-0007-4e1c-bac5-ba416c1cccf4`.
- R3 handoff guide: `docs/giai-doan-2-increment-c-r3.md` — `sha256:46186029ae8acb89c29b41f0042007c8d533504f3072fd6848c27e4c8377678f`.

R4 không yêu cầu thêm implementation trong C trừ khi Reviewer/Owner phát hiện blocker có evidence cụ thể.