# Giai đoạn 2 — Increment D r4: Kết luận kỹ thuật và hướng dẫn đóng gate nghiệm thu

> Handoff Architecture sau khi đối chiếu `docs/giai-doan-2-d-r3-report.md` và implementation snapshot `5aa3332133f203f41a8701e83b213c246cf491da` với contract `docs/giai-doan-2-Increment-D.md`. Đây là hướng dẫn review/approval và vệ sinh hồ sơ; không phải Sprint Plan, danh sách ticket hay yêu cầu code mới.

## 1. Kết luận kiểm tra vòng r3

Đã đối chiếu trực tiếp diff, mã nguồn, unit tests và SHA-256 trên snapshot `5aa3332133f203f41a8701e83b213c246cf491da`:

- **D3-1 (O1 — `RankMathDetector::evaluate`):** Đã chuyển sang các kiểm tra độc lập khi `installation = absent`; khi mâu thuẫn đồng thời `absent + active + known version + positive capability`, từng sub-signal đều được gắn `evidence_state = 'conflict'` với reason riêng, đồng thời reason cấp cao nhất giữ đúng thứ tự ưu tiên.
- **D3-2 (O3 — `OwnershipObservationNormalizer::normalizeFields`):** Đã bật `'had_error' => true` khi `title_meta` thiếu key `'fields'`, ép đi qua `rawFingerprint()` nên hai bản ghi khác `evidence_state` gốc (`observed` vs `declared`) trên cùng `observation_id` trả đúng `identity_collision`.
- **D3-3 (Commit-bound Verification):** Code và test của D đã được khóa trước tại commit `5aa3332133f203f41a8701e83b213c246cf491da`; toàn bộ `tests/Unit` chạy trực tiếp trên đúng commit đó (`VERIFY-RUN-0dafd0d1-ca2a-4714-a828-ef75d6b2ded9`, exit code `0`), sau đó báo cáo r3 mới được commit riêng tại `6ed722a170b9f5a298450cb8db2fcaf60276a078` nên không làm lệch SHA của snapshot source/test.

**Kết luận:** **Không còn lỗi mã nguồn hoặc thiếu sót contract test trong phạm vi Increment D offline.** SE **không cần sửa thêm code PHP hay test** của Increment D trừ khi Reviewer độc lập phát hiện vấn đề mới.

---

## 2. Những điểm cần hoàn tất để đóng Exit Gate (D-R4)

Dù mặt kỹ thuật của code/test D đã đạt ở phạm vi offline, Increment D hiện ở trạng thái **technically verified, awaiting acceptance** vì còn 3 việc quản trị hồ sơ & gate:

### D4-1 — Bổ sung đủ bảng SHA-256 của cả 5 file test D trong hồ sơ handoff (Minor — Documentation)

Trong `docs/giai-doan-2-d-r3-report.md`, mục *Snapshot file hashes → Tests* mới liệt kê 2 file test vừa sửa ở r3 và ghi tắt *"Other Unit files were unchanged"*. Để Reviewer độc lập đối chiếu trọn vẹn một nơi duy nhất trên snapshot `5aa3332133f203f41a8701e83b213c246cf491da`, bảng đầy đủ 5 file test thuộc phạm vi kiểm tra của D là:

- `tests/Unit/RankMathDetectorContractTest.php` — `sha256:f39679e8fd0dc634587b3fee3b62be6ab0f8c7a8ca2747b4b6a47ed9b0906af1`
- `tests/Unit/RankMathReadAdapterContractTest.php` — `sha256:35ccfc14a044febaf7161953d6e839400a93168d572e1701567c7c27780e064e`
- `tests/Unit/OwnershipRegistryCoexistenceTest.php` — `sha256:77c7a691a63a853d222cb0249145c390361b37638f2969f246d64209ee63e7b5`
- `tests/Unit/OwnershipRegistryEdgeContractTest.php` — `sha256:4cbd99f688f47308d3eea8ba51d9dd2216784bbf4ff88acc8192344eb374120c`
- `tests/Unit/SeoCoreTest.php` — `sha256:bcdbf3699735a03b67daa28d81beb58bd1af8d0f223bb6ced0b3e7ab17294935`

*(10 file source trong `src/Integrations/RankMath/` giữ nguyên chính xác theo danh sách trong `docs/giai-doan-2-d-r3-report.md`).*

### D4-2 — Đồng bộ hồ sơ prerequisite Increment C trước khi mở Increment E

Như đã ghi tại Mục 1 của `docs/giai-doan-2-Increment-D.md`:
- Hồ sơ C r4 cũ (`docs/giai-doan-2-increment-c-r4-report.md`) neo vào `9c0e2ed40833f784ee9350fce96f122728d3c9a7`, trong khi bản vá C mới hơn nằm ở `9c76c628853dd63024c1e02fb44a70581b022db7`.
- Trước khi chuyển sang **Increment E (Runtime composition & hooks)**, cần chốt rõ quyết định nghiệm thu cho cả snapshot C (`9c76c628...`) và snapshot D (`5aa33321...`).

### D4-3 — Vệ sinh working tree tài liệu và Gate Reviewer / Owner

1. Working tree hiện còn các tài liệu kiến trúc chưa commit (`docs/giai-doan-2-Increment-D.md`, `docs/giai-doan-2-d-r2.md`, `docs/giai-doan-2-d-r3.md`, `docs/giai-doan-2-d-r4.md`). Nếu commit các file tài liệu này, chỉ tạo commit tài liệu riêng, **không chạm vào bất kỳ file nào trong `src/` hoặc `tests/`** để bảo toàn hiệu lực của snapshot `5aa3332133f203f41a8701e83b213c246cf491da` và receipt `VERIFY-RUN-0dafd0d1-ca2a-4714-a828-ef75d6b2ded9`.
2. **Reviewer độc lập** rà soát snapshot `5aa3332133f203f41a8701e83b213c246cf491da` và xác nhận:
   - O1, O2, O3 khớp closed enums, fail-closed normalization và bảng ưu tiên 9 hàng;
   - `shippedDefault()` giữ `0 rule` (không đoán version/meta key thực khi chưa có runtime evidence);
   - `Plugin::boot()` không bị nối thêm runtime hook; không có network, DNS, persistence, writer, Apply, publish hoặc chuyển ownership (`write_allowed = false`).
3. **Project Owner** quyết định nghiệm thu trong phạm vi **offline Rank Math detector / pure read adapter / coexistence ownership contracts**:
   - **Approve Increment D (offline contract scope):** đóng D và cho phép lên kế hoạch Increment E.
   - **Request changes:** nêu điểm cần điều chỉnh trước khi đóng D.

---

## 3. Giới hạn phạm vi nghiệm thu của Increment D (bắt buộc giữ rõ khi sang E)

Ngay cả khi Increment D được approve, quyết định đó **chỉ** xác nhận contract PHP offline trên snapshot `5aa3332133f203f41a8701e83b213c246cf491da`, **không** chứng minh:
- Tương thích runtime với bất kỳ phiên bản Rank Math / WordPress / WooCommerce thực tế nào (`shippedDefault()` hiện có `0` rule);
- Concrete WordPress post-meta / option reader hay Health Check UI/runtime wiring (thuộc phạm vi xem xét của Increment E và bước xác minh compatibility thực tế);
- Quyền ghi dữ liệu, AI Settings/secret, gateway network hoặc Preview/Apply (F–H vẫn khóa).

## 4. Evidence tham chiếu

- Implementation snapshot D: `5aa3332133f203f41a8701e83b213c246cf491da`
- Report commit D r3: `6ed722a170b9f5a298450cb8db2fcaf60276a078` (`docs/giai-doan-2-d-r3-report.md`, `sha256:a3166c31f3f374e12a15a486490bdf4e8d003804132d97e5610bc722d305e4b2`)
- Full Unit Receipt trên snapshot `5aa33321...`: `VERIFY-RUN-0dafd0d1-ca2a-4714-a828-ef75d6b2ded9` (exit `0`)
- Focused Receipts trên snapshot `5aa33321...`: `VERIFY-RUN-da96ae51-459d-4796-95ab-bb445e3c1e66`, `VERIFY-RUN-42b34776-4aca-4df6-b667-71dd4011946b`
- Contract chuẩn Increment D: `docs/giai-doan-2-Increment-D.md` (`sha256:3aa08f8457104653bf14828d3c06e3f9a5d768e1c2714d7d947e2e39abda0267`)
