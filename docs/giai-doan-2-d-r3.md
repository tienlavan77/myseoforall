# Giai đoạn 2 — Increment D r3: Rà soát báo cáo r2 và hướng dẫn chốt snapshot

> Handoff Architecture sau khi đối chiếu `docs/giai-doan-2-d-r2-report.md` và commit `af4bf0cd0abda813c28c6bd1f422dba4303aefd7` với contract `docs/giai-doan-2-Increment-D.md`. Đây là hướng dẫn kỹ thuật ở cấp increment; không phải Sprint Plan, danh sách ticket hay mutable-file manifest.

## 1. Kết luận rà soát vòng r2

SE đã xử lý tốt phần lớn yêu cầu của `docs/giai-doan-2-d-r2.md`:
- Toàn bộ SHA-256 trong `docs/giai-doan-2-d-r2-report.md` khớp chính xác 100% với mã nguồn và test thực tế.
- **O2 (`RankMathReadAdapter`, `RankMathSourceObserver`, `RankMathFixtureSource`) đã đạt contract:** đồng bộ `ALLOWED_PROVENANCE`, chặn thiếu/sai `provenance`, kiểm tra `can_confirm_presence === true` trên mọi nhánh `presence`, và yêu cầu `array_key_exists('value', $raw)` khi `presence === 'present'`.
- **O1 & O3:** các lỗi chính (nâng khống `evidence_state`, chặn đánh giá matrix khi `absent`, global `observation_id` pass, `declared`/`missing` không kích hoạt `conflicting_evidence`, xóa wildcard `['*']`, bảo toàn `conflict`, tách `site`/`snapshot` bẩn khỏi default scope) đã được sửa và có unit test.

Tuy nhiên, rà soát sâu mã nguồn và lịch sử Git cho thấy **còn 2 lỗi biên nhỏ trong O1/O3 và 1 điểm lệch quy trình snapshot/receipt** cần hoàn tất ở vòng r3 để đủ điều kiện bàn giao Reviewer độc lập.

---

## 2. Các điểm cần xử lý trong vòng r3 (D-R3)

### D3-1 (O1 — Minor/Major): Chuỗi `if / elseif` làm sót đánh dấu `conflict` trên sub-signals khi nhiều probe mâu thuẫn đồng thời với `installation = absent`

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/RankMathDetector.php` (`evaluate`, dòng 158–172):
  Khi `$installation['state'] === 'absent'`, code dùng `if ($activation['state'] === 'active') ... elseif ($version['state'] === 'known') ... elseif ($this->hasPositiveCapability(...))`.
- Hệ quả: nếu môi trường báo đồng thời `installation = absent`, `activation = active` **và** `version = known` (hoặc có capability dương), nhánh `if` đầu tiên chạy và bỏ qua hai nhánh `elseif` sau. Top-level vẫn trả `unknown`/`conflict`, nhưng trong `$result['signals']['version']` và `$result['signals']['capabilities']` vẫn giữ `evidence_state = 'observed'` và `reason = 'version_parsed'` / `'capabilities_observed'` như thể không có mâu thuẫn với `installation = absent`.

**Yêu cầu sửa:**
- Thay chuỗi `if / elseif` bằng các kiểm tra độc lập trên từng chiều đối chiếu với trạng thái gốc `installation === 'absent'`:
  - Nếu `activation === 'active'`, gắn conflict cho `activation`.
  - Nếu `version === 'known'`, gắn conflict cho `version`.
  - Nếu có capability dương, gắn conflict cho `capabilitiesProbe`.
  - Chọn `$crossConflictReason` ổn định theo thứ tự ưu tiên đã định (`installation_activation_conflict` → `installation_version_conflict` → `installation_capability_conflict`) và cập nhật `installation` sang `unknown`/`conflict`.
- Thêm assertion trong `tests/Unit/RankMathDetectorContractTest.php` cho ca tổ hợp đồng thời `absent + active + known version + positive capability`.

---

### D3-2 (O3 — Major): Gộp nhầm `observation_id` khi `title_meta` thiếu key `fields` hoặc khác biệt dữ kiện thô trước khi ép kiểu

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/OwnershipObservationNormalizer.php`:
  - `normalizeFields()` (dòng 300–307): khi `$output === 'title_meta'` và không truyền key `'fields'` (`$hasFieldsKey === false`), hàm nâng `evidence_state` lên `'invalid'` qua `escalateInvalid()`, nhưng lại đặt `'had_error' => $hasFieldsKey` (`false`).
  - `parseObservation()` (dòng 217–221, 248–250): khi `$hadStructureError === false`, `collision_fingerprint` dùng `'valid:' . $this->decisionFingerprint($record)` thay vì `rawFingerprint($raw)`.
- Hệ quả:
  1. Bản ghi A (`observation_id: 'obs-1'`, `output: 'title_meta'`, không có key `'fields'`, `evidence_state: 'observed'`) và Bản ghi B (cùng `observation_id: 'obs-1'`, không có key `'fields'`, `evidence_state: 'declared'`) đều bị ép `evidence_state` thành `'invalid'` với `$hadStructureError === false`, sinh ra cùng một `decisionFingerprint` và **bị gộp âm thầm thay vì báo `identity_collision`**, dù dữ kiện gốc (`observed` vs `declared`) khác nhau.
  2. Tương tự ở dòng 220–221, một bản ghi truyền `can_emit => null` và một bản ghi truyền `can_emit => 'unknown'` (hoặc không truyền key `can_emit`) hay truyền trùng phần tử trong mảng `fields` (`['title', 'title']` vs `['title']`) cần có quy tắc rõ ràng; đặc biệt mọi trường hợp bị hạ/ép sang `'invalid'` (như `title_meta` thiếu `fields` hoặc `fields === null`) **bắt buộc** phải đặt `'had_error' => true` để dùng `rawFingerprint`, không để phép ép `'invalid'` xóa dấu vết khác biệt của các trường khác trên cùng `observation_id`.

**Yêu cầu sửa:**
1. Trong `normalizeFields()`, khi `$output === 'title_meta'` và `(!$hasFieldsKey || $rawFields === null)`, đặt `'had_error' => true` (vì `title_meta` bắt buộc phải khai báo danh sách `fields` hợp lệ; thiếu `fields` là lỗi cấu trúc observation).
2. Bảo đảm bất kỳ nhánh chuẩn hóa nào làm thay đổi ngữ nghĩa/hạ cấp `evidence_state` sang `'invalid'` đều bật `$hadStructureError = true`, để hai bản ghi khác dữ kiện gốc trên cùng `observation_id` luôn bị bắt `identity_collision` (bất biến theo thứ tự A–B, B–A, A–B–A).
3. Bổ sung bài test trực tiếp cho ca `title_meta` thiếu `fields` có `evidence_state` gốc khác nhau (`observed` vs `declared`) trên cùng `observation_id` trong `tests/Unit/OwnershipRegistryEdgeContractTest.php`.

---

### D3-3 (Snapshot & Verification Gate — Blocker cho bàn giao Reviewer): Khóa commit SHA cố định trước khi lấy Verification Receipt

**Bằng chứng Git & Báo cáo:**
- Trong `docs/giai-doan-2-d-r2-report.md`, toàn bộ 6 `VERIFY-RUN-*` được chạy lúc `13:25–13:26 UTC` trên working tree khi `HEAD` còn là `19e513751ed3be71fb781ce1d4773e4232dd9f31`.
- Sau đó lúc `13:29:55 UTC`, commit `af4bf0cd0abda813c28c6bd1f422dba4303aefd7` mới được tạo (gồm cả code/test và `docs/giai-doan-2-d-r2-report.md`). Vì vậy:
  1. Các receipt trong báo cáo r2 là pre-commit receipts neo vào `19e51375...`, chưa có receipt nào neo trực tiếp vào commit SHA chứa code D.
  2. Báo cáo r2 nằm bên trong chính commit `af4bf0c` lại tự ghi trạng thái là *“chưa commit/push... đang nằm trên working tree dirty”* — mâu thuẫn với trạng thái Git thực tế sau khi commit.

**Quy trình bắt buộc trong vòng r3 (theo đúng mục 5 Snapshot Verification của `docs/giai-doan-2-Increment-D.md`):**
1. Sửa D3-1 và D3-2, chạy thử test để chắc chắn xanh.
2. **Tạo commit code/test cố định của Increment D r3 trước** (chỉ commit các file mã nguồn `src/Integrations/RankMath/*` và `tests/Unit/*` thuộc phạm vi D; **không** commit `.phpunit.result.cache` và không sửa/stage các file tài liệu kế hoạch đang có sẵn của Architecture nếu chưa được yêu cầu).
3. **Ngay trên commit SHA cố định vừa tạo** (khi source/test của D hoàn toàn clean, không đổi thêm bất kỳ dòng code/test nào sau đó), chạy lại qua `run_check`:
   - Các bộ focused tests cho O1, O2, O3 (gồm cả `RankMathDetectorContractTest`, `RankMathReadAdapterContractTest`, `OwnershipRegistryCoexistenceTest`, `OwnershipRegistryEdgeContractTest`, `SeoCoreTest`).
   - Toàn bộ `php vendor/bin/phpunit tests/Unit`.
4. Ghi báo cáo bàn giao cuối vào `docs/giai-doan-2-d-r3-report.md` (có thể để ở working tree hoặc commit tài liệu riêng biệt **sau** commit code/test, giống quy ước C r3/r4 để không làm đổi commit SHA của snapshot source/test đã chạy `run_check`), trong đó ghi chính xác:
   - `implementation_snapshot_sha` (commit SHA cố định chứa source/test D r3 đã được `run_check` xác nhận);
   - Bảng SHA-256 của toàn bộ file `src/Integrations/RankMath/*` và `tests/Unit/*` khớp đúng commit SHA đó;
   - Bảng `VERIFY-RUN-*` chạy trực tiếp trên `implementation_snapshot_sha`;
   - Giữ rõ phân tách: (1) contract behavior đã kiểm thử offline; (2) `shippedDefault()` = `0 rule` (chưa có compatibility/mapping runtime thực); (3) trạng thái prerequisite C và yêu cầu Reviewer độc lập + Owner đóng gate trước khi xem xét Increment E.

---

## 3. Guardrails không đổi

- Không nối `Plugin::boot()`, không thêm WordPress runtime hooks, không tạo concrete WordPress reader wiring.
- Giữ nguyên `shippedDefault()` có `0 rule` cho cả matrix và mapping catalog.
- Không network, DNS, persistence, writer, Apply, publish hoặc chuyển ownership; `write_allowed` luôn là `false`.
- Không tự đóng exit gate C hoặc D trong báo cáo.

## 4. Evidence đối chiếu

- Báo cáo r2: `docs/giai-doan-2-d-r2-report.md` (`sha256:2fd65f34ee31feb31c356fbcf6c30bc89a750aa9cd2263fa5cf7c86ad4cebb94`).
- Hướng dẫn r2: `docs/giai-doan-2-d-r2.md` (`sha256:96d4cb03c46bca632b1c13d4085825940bd5894b0fafcd40d9a48bcf6adc1848`).
- Contract chuẩn: `docs/giai-doan-2-Increment-D.md` (`sha256:3aa08f8457104653bf14828d3c06e3f9a5d768e1c2714d7d947e2e39abda0267`).
- Commit đã kiểm tra: `af4bf0cd0abda813c28c6bd1f422dba4303aefd7`.
- Mã nguồn đã đọc trực tiếp:
  - `src/Integrations/RankMath/RankMathDetector.php` (`sha256:8b80f5382610e693c6fb0da0655abf79db66099e0691ea9d0d47d7984e9df9fb`)
  - `src/Integrations/RankMath/OwnershipObservationNormalizer.php` (`sha256:75211bf555a5240c4daf07cc9cd339682bff491771dac4b315a70866259f79dd`)
  - `src/Integrations/RankMath/RankMathReadAdapter.php` (`sha256:7b7e47ba316f62139df470f0728b47cfe28e4c8a30ab0e45f844a3d099b20992`)
  - `src/Integrations/RankMath/OwnershipRegistry.php` (`sha256:22686cf991a3fed05a07725b550f330bc801b71cbdf0e8c1cc5d54680065a8c9`)
