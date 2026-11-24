# Giai đoạn 2 — Increment D r2: Rà soát báo cáo và hướng dẫn khắc phục cho SE

> Handoff Architecture sau khi đối chiếu `docs/giai-doan-2-d-report.md` với mã nguồn và test thực tế trong working tree theo contract `docs/giai-doan-2-Increment-D.md`. Đây là hướng dẫn kỹ thuật ở cấp increment; không phải Sprint Plan, danh sách ticket hay mutable-file manifest.

## 1. Kết luận rà soát

Báo cáo của SE đi đúng hướng kiến trúc: giữ boundary offline/read-only, không nối `Plugin::boot()`, `shippedDefault()` của matrix/mapping để rỗng (`0 rule`), đóng đúng các enum public và giữ `write_allowed = false`.

Tuy nhiên, **chưa thể nghiệm thu Increment D ở bản hiện tại**. Đối chiếu trực tiếp mã nguồn phát hiện **7 vi phạm fail-closed/contract thực tế** trên O1, O2, O3 (nằm ở các nhánh biên chưa có test phủ), cùng với việc toàn bộ code/test đang nằm trên working tree chưa khóa revision bất biến.

---

## 2. Các phát hiện bắt buộc phải sửa trong mã nguồn (D-R2)

### D2-1 (O1 — Blocker): Xung đột liên probe `installation = absent` với `version = known` và lỗi nâng khống `evidence_state`

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/RankMathDetector.php` (`evaluate`, dòng 175–181; `summarizeDecision`, dòng 248–271): nhánh chặn `plugin_absent` ở dòng 175 yêu cầu đồng thời `$version['state'] === 'unavailable'`. Nếu probe báo `installation = absent`, `activation = inactive` nhưng `version = known` (khớp synthetic matrix), dòng 178 vẫn chạy `$this->matrix->evaluate(...)` và trả `compatibility = 'compatible'` (hoặc `'incompatible'`), trong khi `summarizeDecision` ở dòng 248 ghi đè `reason = 'plugin_absent'`. Kết quả mâu thuẫn: plugin `absent` nhưng `compatibility = 'compatible'`.
- `summarizeDecision` dòng 264 chỉ trả `active_installation_metadata_missing` khi `$compatibility['state'] === 'compatible'`; với `shippedDefault()` (`compatibility = 'unknown'`), tình huống `active` nhưng thiếu installation metadata bị che lấp bởi `matrix_unproven_for_range`.
- `src/Integrations/RankMath/RankMathProbeNormalizer.php` (`cleanEvidence`, dòng 287–290; gọi tại dòng 89, 150, 209): truyền fallback `'observed'` khi `evidence_state` sai kiểu hoặc nằm ngoài enum (ví dụ `'guessed'`, `123`), làm dữ liệu bẩn được nâng thành `'observed'` thay vì fail-closed sang `'invalid'`.

**Yêu cầu sửa:**
1. Phát hiện mâu thuẫn chéo giữa các probe: `installation = absent` đi kèm `version = known` (hoặc capability dương) phải chuyển trạng thái liên quan sang conflict/unknown (`compatibility = 'unknown'`, `evidence_state = 'conflict'`), tuyệt đối không đánh giá matrix ra `compatible`/`incompatible` khi `installation = absent`.
2. Bảo toàn reason thiếu installation metadata khi `activation = active` và `installation = unknown`, không phụ thuộc `compatibility === 'compatible'`.
3. Trong `RankMathProbeNormalizer`, phân biệt rõ **không truyền key** với **truyền giá trị không hợp lệ**: giá trị `evidence_state` ngoài tập cho phép phải fail-closed về `'invalid'`, không fallback sang `'observed'`.

---

### D2-2 (O2 — Blocker): `RankMathReadAdapter` không fail-closed khi thiếu/sai `provenance`, bỏ sót `can_confirm_presence = false` và tự chế `explicit_null`

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/RankMathReadAdapter.php` (`normalizeObservation`, dòng 264–267, 296–299; `readField`, dòng 145–184).
- `src/Integrations/RankMath/RankMathSourceObserver.php` (docblock dòng 33) và `src/Integrations/RankMath/RankMathFixtureSource.php` (`copySources`, dòng 125–137).

**Các lỗi cụ thể:**
1. Dòng 264 gán `$provenanceRaw = $raw['provenance'] ?? 'fixture'`. Mọi custom observer bỏ quên `provenance` đều bị gắn nhãn giả là `'fixture'` và vẫn trả `state = 'value'`. Khi truyền `provenance` lạ (ví dụ `'unknown_origin'`), dòng 267 đổi thành `'unverified_source'` nhưng vẫn cho đọc ra `state = 'value'` (trái với chính docblock hàm và yêu cầu fail-closed). Ngoài ra docblock `RankMathSourceObserver` ghi `runtime_unwired` còn `RankMathReadAdapter` kiểm tra `runtime_verified`.
2. Trong `readField` (dòng 145–156), cờ `$obs['can_confirm_presence']` **chỉ** được kiểm tra khi `$obs['presence'] === 'missing'`. Nếu observer trả `presence = 'present'` kèm `can_confirm_presence = false`, adapter bỏ qua kiểm tra và trả `value` hoặc `explicit_null`.
3. Khi observer trả `['presence' => 'present', 'can_confirm_presence' => true]` nhưng **thiếu key `'value'`** (dữ liệu observation malformed), dòng 299 dùng `$raw['value'] ?? null` (tương tự `RankMathFixtureSource::copySources` dòng 131), biến observation lỗi cấu trúc thành `explicit_null` giả.

**Yêu cầu sửa:**
1. Đồng bộ tập giá trị `provenance` giữa `RankMathSourceObserver` và `RankMathReadAdapter`. Thiếu `provenance` hoặc `provenance` không hợp lệ phải fail-closed thành `state = 'unreadable'` với reason ổn định (không mặc định `'fixture'`, không cho phép trả `value`).
2. Mọi observation có `can_confirm_presence !== true` (dù `presence` là `'present'` hay `'missing'`) đều không đủ điều kiện xác nhận `value`, `explicit_null` hoặc `missing`, phải trả `unreadable` (`presence_unconfirmed`) và không mở fallback.
3. Khi `presence === 'present'`, bắt buộc kiểm tra `array_key_exists('value', $raw)`; nếu thiếu key `'value'` thì fail-closed sang `unreadable` (`malformed_source_observation`), chỉ map sang `explicit_null` khi key `'value'` thực sự tồn tại với giá trị `null`.

---

### D2-3 (O3 — Blocker): Bỏ lọt `observation_id` collision giữa các bản ghi khác `output`/`site`/`snapshot` hoặc cùng bị malformed khác nhau

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/OwnershipObservationNormalizer.php` (`partitionByOutput`, dòng 58–84; `normalizeSingle`, dòng 121–155).

**Các lỗi cụ thể:**
1. `partitionByOutput` chia bucket theo `$output` và lọc bỏ các bản ghi khác `$expectedSite`/`$expectedSnapshot` (dòng 67–69) **trước khi** kiểm tra trùng `$obsId`. Do đó nếu hai bản ghi dùng chung `observation_id = 'obs-1'` nhưng một bản ghi cho `json_ld`, bản kia cho `sitemap` (hoặc khác `site`/`snapshot` hay một bản có `output` không hợp lệ), cả hai không bị phát hiện `identity_collision` mà vẫn có thể thắng `owned`.
2. `normalizeSingle` gán giá trị thay thế mặc định cho dữ liệu hỏng (`invalid_producer`, `absent`, `unknown`, `invalid_source`, `invalid`) trước khi tính `decisionFingerprint`, khiến hai bản ghi cùng `observation_id` nhưng hỏng theo hai kiểu khác nhau có thể sinh cùng fingerprint và bị gộp thay vì báo `identity_collision`.
3. Còn biến thừa chưa hoàn thiện trong `partitionByOutput`: `'unkeyed_Malformed' => 0` (dòng 50) và `'malformed_conflict' => false` (dòng 98).

**Yêu cầu sửa:**
1. Theo dõi `observation_id` (khi caller có cung cấp `observation_id`) trên toàn bộ danh sách input trước khi lọc scope/chia bucket: nếu cùng `observation_id` xuất hiện với dữ kiện quyết định thô/chuẩn hóa khác nhau (kể cả khác `output`, `site`, `snapshot`), đánh dấu `identity_collision` cho mọi output thuộc scope bị ảnh hưởng bởi `observation_id` đó, bất biến theo thứ tự input.
2. Tính fingerprint so sánh collision sao cho hai bản ghi malformed khác nhau trên cùng `observation_id` không bị gộp âm thầm. Dọn sạch các trường chết không dùng.

---

### D2-4 (O3 — Blocker): Assertion `declared` / `missing` vẫn kích hoạt Hàng 3 `conflicting_evidence` (`blocked`)

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/OwnershipRegistry.php` (`hasConflictingEvidence`, dòng 197–208).

**Mô tả lỗi:**
Vòng lặp chỉ bỏ qua `$record['evidence_state'] === 'invalid'`, nên các bản ghi có `evidence_state` là `'declared'` hoặc `'missing'` vẫn được đưa vào `$claims` và `$denies`. Hệ quả:
- Producer A có claim hợp lệ (`claim`, `can_emit = true`, `observed`) nhưng tồn tại thêm một bản ghi `deny` cũ ở trạng thái `declared` hoặc `missing` → bị chặn sai thành `conflicting_evidence` thay vì `owned`.
- Producer B không liên quan có một cặp `claim`/`deny` đều ở trạng thái `declared` trong khi Producer A có claim `observed` sạch → toàn bộ output bị `blocked` oan cho A.

**Yêu cầu sửa:**
Chỉ tính mâu thuẫn `claim` vs `deny` cùng producer ở Hàng 3 khi cả hai assertion được xác nhận ở mức bằng chứng hợp lệ (`evidence_state === 'observed'`), giữ nguyên việc kích hoạt `conflicting_evidence` khi có bản ghi mang `evidence_state === 'conflict'`.

---

### D2-5 (O3 — Major): Lỗi wildcard `['*']` khi `title_meta` không khai báo `fields` và lỗi ghi đè `evidence_state = 'conflict'`

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/OwnershipObservationNormalizer.php` (`normalizeSingle`, dòng 137–155; `normalizeFields`, dòng 183–198).
- `src/Integrations/RankMath/OwnershipRegistry.php` (`hasAssertionCapabilityConflict`, dòng 148, 170).

**Mô tả lỗi:**
1. Khi bản ghi `title_meta` không truyền `fields` (`null`) hoặc truyền `fields: []`, `normalizeFields` trả `fields: []` nhưng giữ nguyên `evidence_state = 'observed'`. Sau đó `hasAssertionCapabilityConflict` biến `fields === []` thành wildcard `['*']` (dòng 148) và khớp với mọi field (dòng 170). Điều này trái quy tắc O3 (*“Chỉ tính claim cho các field được nêu rõ”*): một claim rỗng field lại kích hoạt `assertion_capability_conflict` với bất kỳ field lẻ nào có `can_emit = false`.
2. Trong `normalizeSingle` (dòng 139, 151) và `normalizeFields` (dòng 191, 197), nếu bản ghi đầu vào đã được đánh dấu `evidence_state = 'conflict'` nhưng đồng thời có `assertion`, `can_emit` hoặc `fields` malformed, mã nguồn ghi đè `evidence_state` về `'invalid'`, làm mất tín hiệu conflict tường minh ở Hàng 3.

**Yêu cầu sửa:**
1. Với `title_meta`, observation có `assertion = 'claim'` (hoặc `can_emit = false` dùng cho kiểm tra field) mà thiếu `fields` hoặc `fields = []` không được biến thành wildcard `['*']`; phải chuẩn hóa nhất quán (ví dụ đánh dấu `invalid`/không có field hiệu lực) giữa tất cả các bước kiểm tra.
2. Bảo toàn `evidence_state = 'conflict'` ưu tiên cao hơn `'invalid'` khi chuẩn hóa các trường con bị lỗi, để tín hiệu conflict không bao giờ bị hạ cấp thành bản ghi bị bỏ qua.

---

### D2-6 (O3 — Major): Chuẩn hóa `site`/`snapshot` lỗi về default scope và chuẩn hóa chữ thường `producer`

**Bằng chứng mã nguồn:**
- `src/Integrations/RankMath/OwnershipRegistry.php` (`resolve`, dòng 39–40) và `OwnershipObservationNormalizer.php` (`normalizeSingle`, dòng 121–122, 158).

**Mô tả lỗi:**
1. Khi caller truyền `site` hoặc `snapshot` sai kiểu/rỗng/chứa ký tự điều khiển (ví dụ `site => ''` hoặc `site => 123` trong một observation), `cleanToken(...) ?? 'default_site'` âm thầm ép nó về `'default_site'` / `'default_snapshot'`, khiến observation lỗi scope lại khớp đúng vào default scope của `resolve()`.
2. `strtolower($producer)` biến đổi định danh gốc của producer trong kết quả trả ra (`owner`) mà không được quy định, và cần kiểm tra nhất quán trường hợp inventory hợp lệ chỉ có duy nhất 1 producer chính là owner (`otherProducers = []`) cùng các bản ghi `invalid` từ producer ngoài inventory.

**Yêu cầu sửa:**
1. Phân biệt **không truyền `site`/`snapshot`** (dùng default scope) với **truyền giá trị không hợp lệ** (observation có `site`/`snapshot` malformed không được khớp vào scope hợp lệ; nếu `context['site']` hoặc `context['snapshot']` của `resolve()` malformed thì fail-closed toàn bộ output về `unknown`/`possible` với reason rõ ràng).
2. Giữ định danh canonical nhất quán cho `producer` (chỉ chấp nhận slug hợp lệ hoặc giữ nguyên sau khi validate token, nhất quán giữa observation và inventory) và bổ sung test cho inventory 1-producer hợp lệ + observation `invalid` xen kẽ.

---

### D2-7 (Báo cáo & Verification — Major): Điều chỉnh nhận định trong báo cáo và chuẩn bị snapshot cố định

**Bằng chứng:** `docs/giai-doan-2-d-report.md` mục 4.1 ghi *“Contract behavior đã kiểm thử offline: Đã xác minh đầy đủ”*, trong khi các nhánh biên ở D2-1 → D2-6 chưa có test và chưa đạt; đồng thời kết quả chạy đang ở working tree dirty.

**Yêu cầu thực hiện trong vòng r2:**
1. Sửa triệt để D2-1 → D2-6 trong `src/Integrations/RankMath/`.
2. Bổ sung unit/contract tests trực tiếp cho toàn bộ các ca lỗi trên trong 3 file test của D:
   - O1: `installation = absent` + `version = known`; `active` + `installation = unknown` dưới `shippedDefault()`; `evidence_state` rác không bị nâng thành `'observed'`.
   - O2: observer thiếu `provenance` hoặc `provenance` lạ bị chặn `unreadable`; `presence = 'present'` nhưng `can_confirm_presence = false` trả `unreadable`; `presence = 'present'` nhưng thiếu key `'value'` không thành `explicit_null`; khớp enum `provenance` giữa interface và adapter.
   - O3: trùng `observation_id` khác `output` và khác `site`/`snapshot` (đảo thứ tự A–B, B–A); hai bản ghi malformed khác nhau cùng `observation_id`; `declared`/`missing` `deny` không chặn claim `observed` hợp lệ; `title_meta` thiếu `fields` không nổ wildcard `['*']`; `evidence_state = 'conflict'` kèm trường con lỗi không bị hạ thành `'invalid'`; `site`/`snapshot` truyền giá trị bẩn không lọt vào `default_site`/`default_snapshot`.
3. Chạy lại toàn bộ focused suites và full `tests/Unit` qua `run_check`.
4. Cập nhật báo cáo kết quả vào `docs/giai-doan-2-d-r2-report.md` kèm bảng SHA-256 mới và các `VERIFY-RUN-*` mới (ghi trung thực trạng thái working tree vs commit cố định; không tuyên bố đã đóng gate C hoặc D).

---

## 3. Guardrails giữ nguyên trong D r2

- Không nối `Plugin::boot()`, không đăng ký WordPress hooks, không tạo concrete WordPress reader wiring.
- Giữ `shippedDefault()` của compatibility matrix và mapping catalog ở mức `0 rule` nếu chưa có bằng chứng runtime thật.
- Không network, DNS, persistence, writer, Apply, publish hoặc chuyển ownership; `write_allowed` luôn là `false`.
- Không sửa ngoài phạm vi `src/Integrations/RankMath/`, `tests/Unit/*` liên quan và file báo cáo `docs/giai-doan-2-d-r2-report.md`.

## 4. Evidence đối chiếu

- Báo cáo đã kiểm tra: `docs/giai-doan-2-d-report.md` (`sha256:7e11cde7bb7d3288452c6a532123f09969f83768ba0edf1d892afa2b4300d836`).
- Contract chuẩn: `docs/giai-doan-2-Increment-D.md` (`sha256:3aa08f8457104653bf14828d3c06e3f9a5d768e1c2714d7d947e2e39abda0267`).
- Mã nguồn đã đối chiếu trực tiếp:
  - `src/Integrations/RankMath/RankMathDetector.php` (`sha256:d889027bf96fc347799a3bf822aed18025007d25437e873761493f8e4cc0942f`)
  - `src/Integrations/RankMath/RankMathProbeNormalizer.php` (`sha256:3d8b971b12e77b31949797dcb453b2be65802b9b214d7ff61f63830706166aa5`)
  - `src/Integrations/RankMath/RankMathReadAdapter.php` (`sha256:1674c747eb3fda430aa88fe36cd11aa6d126ed746434a17481373966a4456b97`)
  - `src/Integrations/RankMath/RankMathSourceObserver.php` (`sha256:b145410220e90124fd906bf94b51ab973f498683b28f414b4a8e235aa14c92c5`)
  - `src/Integrations/RankMath/RankMathFixtureSource.php` (`sha256:318a352a58a506a9e40b13f92f181c6aad573ada330bc2ac827a952196ef80a6`)
  - `src/Integrations/RankMath/OwnershipObservationNormalizer.php` (`sha256:7040bfcf713b9787cd90fa5ba17b2f339c26334c8c3d5b340ea27a9eb8e3181c`)
  - `src/Integrations/RankMath/OwnershipRegistry.php` (`sha256:f0119bf7e79342fb440ecb47276aa0b8aa1490ae5a29c2b5dc9853a8d012b35f`)
