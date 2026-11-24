# Giai đoạn 2 — Increment D r2: Báo cáo khắc phục D2-1..D2-7 và kết quả verification

- **Ngày:** 2026-11-20
- **Người nhận:** Owner / Architecture / Reviewer độc lập
- **Tài liệu rà soát đối chiếu:** `docs/giai-doan-2-d-r2.md` (`sha256:96d4cb03c46bca632b1c13d4085825940bd5894b0fafcd40d9a48bcf6adc1848`)
- **Contract chuẩn đối chiếu:** `docs/giai-doan-2-Increment-D.md` (`sha256:3aa08f8457104653bf14828d3c06e3f9a5d768e1c2714d7d947e2e39abda0267`)
- **Nhánh & HEAD cơ sở:** `codex/update-r3-report` @ `19e513751ed3be71fb781ce1d4773e4232dd9f31` (`base_sha` của `run_check`: `9c76c628853dd63024c1e02fb44a70581b022db7`)
- **Trạng thái quản trị:** Triển khai và kiểm thử offline trên working tree trước khi đóng gate C theo hướng 2 của Owner; **không coi là acceptance C hoặc D**, chưa commit/push, không nối runtime WordPress, không Apply/publish và không chuyển ownership.

---

## 1. Chi tiết khắc phục từng phát hiện trong `docs/giai-doan-2-d-r2.md`

### D2-1 (O1 — Blocker): Xung đột liên probe `installation = absent` và nâng khống `evidence_state`
- **`RankMathProbeNormalizer::resolveEvidenceState()`:** Tách rõ trường hợp **không truyền key `evidence_state`** (nhận giá trị mặc định `'observed'`) với trường hợp **có truyền key nhưng giá trị sai kiểu/ngoài enum** (ví dụ `'guessed'`, `123`) → fail-closed sang `'invalid'` trên cả 4 chiều (`installation`, `activation`, `version`, `capabilities`).
- **`RankMathDetector::evaluate()`:** Phát hiện đầy đủ mâu thuẫn chéo khi `installation = absent` đi kèm:
  - `activation = active` → `installation_activation_conflict`
  - `version_state = known` → `installation_version_conflict`
  - capability dương (`true` hoặc `'supported'`) → `installation_capability_conflict`
  Trong mọi trường hợp `installation = absent`, detector **không bao giờ** chạy `$this->matrix->evaluate(...)` để sinh ra `compatible`/`incompatible`; kết quả luôn giữ `compatibility = unknown` kèm `evidence_state = conflict` (hoặc `missing`/`invalid` tương ứng).
- **`RankMathDetector::summarizeDecision()`:** Bảo toàn `reason = active_installation_metadata_missing` (`evidence_state = incomplete`) khi `activation = active` và `installation = unknown`, kể cả dưới `shippedDefault()` (`compatibility = unknown`).

### D2-2 (O2 — Blocker): Fail-closed `provenance`, `can_confirm_presence` và key `'value'`
- **Đồng bộ `ALLOWED_PROVENANCE`:** Định nghĩa hằng số `RankMathSourceObserver::ALLOWED_PROVENANCE = ['fixture', 'synthetic', 'runtime_verified']` và dùng trực tiếp trong `RankMathReadAdapter::normalizeObservation()`.
- **Chặn thiếu/sai `provenance`:** Bỏ mặc định `'fixture'`; nếu custom observer thiếu key `'provenance'` hoặc truyền giá trị ngoài `ALLOWED_PROVENANCE`, adapter trả ngay `state = unreadable`, `reason = invalid_source_provenance` và không có khóa `'value'`.
- **Kiểm tra `can_confirm_presence` trên mọi nhánh:** Trong `RankMathReadAdapter::readField()`, kiểm tra `!$obs['can_confirm_presence']` trước cả nhánh `'missing'` lẫn `'present'`; nếu `can_confirm_presence !== true` (kể cả khi `presence = present`), trả `state = unreadable` (`reason = presence_unconfirmed`) và **không mở fallback**.
- **Không tự chế `explicit_null` khi thiếu key `'value'`:** Cả `RankMathReadAdapter::normalizeObservation()` và `RankMathFixtureSource::copySources()` đều kiểm tra `array_key_exists('value', $raw)` khi `presence === 'present'`. Nếu thiếu key `'value'`, trả `unreadable` (`malformed_source_observation`); chỉ map sang `explicit_null` khi key `'value'` thực sự tồn tại với giá trị `null`.

### D2-3 (O3 — Blocker): Global `observation_id` collision trên toàn bộ input và trên các bản ghi malformed khác nhau
- **`OwnershipObservationNormalizer::partitionByOutput()`:** Quét toàn bộ `$rawObservations` ở Pass 1 **trước khi** lọc `site`/`snapshot` hay chia bucket `output`. Mọi bản ghi có truyền `observation_id` hợp lệ đều được ghi nhận fingerprint theo `observation_id`. Nếu một `observation_id` xuất hiện với từ 2 fingerprint khác nhau trở lên (kể cả khác `output`, khác `site`/`snapshot`, `output` không hợp lệ, hoặc hai bản ghi malformed khác nhau), mọi output thuộc scope hiện tại có mặt `observation_id` đó đều bị đánh dấu `collision = true` → `identity_collision`, bất biến theo thứ tự A–B, B–A, A–B–A.
- **Fingerprint phân biệt kiểu malformed (`rawFingerprint()`):** Khi bản ghi có bất kỳ lỗi cấu trúc nào, collision fingerprint băm trực tiếp cấu trúc kiểu + giá trị thô đã chuẩn hóa thứ tự key (`malformed:...`) thay vì bản ghi đã gán placeholder mặc định, nên hai bản ghi hỏng theo hai kiểu khác nhau trên cùng `observation_id` không bao giờ bị gộp âm thầm. Đã xóa sạch các trường chết `'unkeyed_Malformed'` và `'malformed_conflict'`.

### D2-4 (O3 — Blocker): Assertion `declared` / `missing` không còn kích hoạt `conflicting_evidence`
- **`OwnershipRegistry::hasConflictingEvidence()`:** Giữ nguyên kích hoạt ngay khi có bản ghi `evidence_state === 'conflict'`, nhưng chỉ đối chiếu mâu thuẫn `claim` vs `deny` cùng producer trên các bản ghi có `evidence_state === 'observed'`. Các bản ghi `declared`, `missing`, `invalid` không còn chặn claim `observed` hợp lệ.

### D2-5 (O3 — Major): Xóa wildcard `['*']` trên `title_meta` và bảo toàn `evidence_state = conflict`
- **`OwnershipObservationNormalizer::normalizeFields()` & `OwnershipRegistry::hasAssertionCapabilityConflict()`:** Xóa hoàn toàn logic wildcard `['*']`. Với `title_meta`, bản ghi thiếu `fields` hoặc `fields = []` giữ `fields = []` và nâng `evidence_state` qua `escalateInvalid()`.
- **`OwnershipObservationNormalizer::escalateInvalid()`:** Mọi lỗi trường con (`producer`, `source`, `assertion`, `can_emit`, `fields`) chỉ chuyển sang `'invalid'` nếu `evidence_state` hiện tại **không phải** `'conflict'`, đảm bảo tín hiệu `evidence_state = 'conflict'` luôn được bảo toàn tới Hàng 3 (`conflicting_evidence`).

### D2-6 (O3 — Major): Không ép `site`/`snapshot` bẩn về default scope & giữ canonical `producer` slug
- **`resolveContextDimension()` & `resolveScopeDimension()`:** Phân biệt rõ **không truyền key `site`/`snapshot`** (dùng `'default_site'` / `'default_snapshot'`) với **truyền giá trị rỗng/sai kiểu/chứa control char**:
  - Ở cấp observation: đánh dấu `scope_valid = false`, không bao giờ lọt vào `default_site`/`default_snapshot`.
  - Ở cấp `OwnershipRegistry::resolve($context)`: nếu `context['site']` hoặc `context['snapshot']` malformed, fail-closed toàn bộ 4 output về `owner = unknown`, `state = unknown`, `duplicate_risk = possible`, `reason = invalid_scope`, `write_allowed = false` (vẫn giữ đủ cờ `ai_draft`).
- **`cleanProducerSlug()`:** Bỏ `strtolower($producer)`; chỉ chấp nhận định danh slug canonical hợp lệ (`[a-z0-9][a-z0-9._:-]{0,63}`), nhất quán giữa observation và inventory. Đã kiểm thử trường hợp inventory hợp lệ chỉ gồm 1 producer duy nhất chính là owner kèm bản ghi `invalid` nhiễu từ producer ngoài inventory → trả đúng `owner / owned / none / other_emitters_excluded`.

---

## 2. Bảng SHA-256 hiện tại trong working tree (vòng r2)

### Mã nguồn (`src/Integrations/RankMath/`)
- `src/Integrations/RankMath/RankMathCompatibilityMatrix.php` — `sha256:3ebeaa4280dda6947e53230745bc3ecfcd54c8164be4bcb1c311f39882f324d2`
- `src/Integrations/RankMath/RankMathEnvironmentProbe.php` — `sha256:67ce76d12f50c6b219fc8e00b75a0ba9107f314ee3a1deac3f87a038c72a5a6d`
- `src/Integrations/RankMath/RankMathProbeNormalizer.php` — `sha256:cb1c7f3657aa65d6f46aa169124e38ae1255bdc7685eea0931b5e8e9f04ab39b`
- `src/Integrations/RankMath/RankMathDetector.php` — `sha256:8b80f5382610e693c6fb0da0655abf79db66099e0691ea9d0d47d7984e9df9fb`
- `src/Integrations/RankMath/RankMathSourceObserver.php` — `sha256:b086a989f2d34794c0d6f3bf8d044ebc4f54d6e744197b3da3dd891fda8e1f1e`
- `src/Integrations/RankMath/RankMathFixtureSource.php` — `sha256:5099e13287f6c8f81f16b3b9e667dfaa2100c408e93038e4166dbfaa49ad5294`
- `src/Integrations/RankMath/RankMathMappingCatalog.php` — `sha256:93d44750fc1989b3f8157664b79eaab65e9387f7aec0c9d1e7c3bc3f801a4116`
- `src/Integrations/RankMath/RankMathReadAdapter.php` — `sha256:7b7e47ba316f62139df470f0728b47cfe28e4c8a30ab0e45f844a3d099b20992`
- `src/Integrations/RankMath/OwnershipObservationNormalizer.php` — `sha256:75211bf555a5240c4daf07cc9cd339682bff491771dac4b315a70866259f79dd`
- `src/Integrations/RankMath/OwnershipRegistry.php` — `sha256:22686cf991a3fed05a07725b550f330bc801b71cbdf0e8c1cc5d54680065a8c9`

### Kiểm thử (`tests/Unit/`)
- `tests/Unit/SeoCoreTest.php` — `sha256:bcdbf3699735a03b67daa28d81beb58bd1af8d0f223bb6ced0b3e7ab17294935`
- `tests/Unit/RankMathDetectorContractTest.php` — `sha256:6312be2189741aa6cb72076748cc4abbb609ca8ce829af612091b92df878e440`
- `tests/Unit/RankMathReadAdapterContractTest.php` — `sha256:35ccfc14a044febaf7161953d6e839400a93168d572e1701567c7c27780e064e`
- `tests/Unit/OwnershipRegistryCoexistenceTest.php` — `sha256:77c7a691a63a853d222cb0249145c390361b37638f2969f246d64209ee63e7b5`
- `tests/Unit/OwnershipRegistryEdgeContractTest.php` — `sha256:4e76c36568668180e97ad8c211721b962bfff98b2972d4f136e7e58716fb511b`

---

## 3. Verification Receipts thực tế (vòng r2 — pre-commit working tree)

Tất cả chạy qua Forge `run_check` trên HEAD `19e513751ed3be71fb781ce1d4773e4232dd9f31` (`base_sha`: `9c76c628853dd63024c1e02fb44a70581b022db7`):

| Run ID | Command | Scope | Status | Exit Code | Thời điểm (UTC) |
| --- | --- | --- | --- | --- | --- |
| `VERIFY-RUN-98a17954-84dd-40f2-ad28-306fa0fdf6f0` | `php vendor/bin/phpunit tests/Unit/OwnershipRegistryEdgeContractTest.php` | Focused O3 Edge (D2-3..D2-6) | `passed` | `0` | `2026-11-20T13:25:45.808Z` |
| `VERIFY-RUN-7548cce3-49d0-459d-bb0c-1b6a2a21fe9c` | `php vendor/bin/phpunit tests/Unit/RankMathDetectorContractTest.php` | Focused O1 + D2-1 | `passed` | `0` | `2026-11-20T13:25:53.462Z` |
| `VERIFY-RUN-8c67ad65-15ad-4dd7-9def-bf0d26345444` | `php vendor/bin/phpunit tests/Unit/RankMathReadAdapterContractTest.php` | Focused O2 + D2-2 | `passed` | `0` | `2026-11-20T13:25:54.912Z` |
| `VERIFY-RUN-1317d01e-6d61-4f46-847f-84c925414776` | `php vendor/bin/phpunit tests/Unit/OwnershipRegistryCoexistenceTest.php` | Focused O3 Coexistence | `passed` | `0` | `2026-11-20T13:25:56.192Z` |
| `VERIFY-RUN-b0cda75f-a9f0-4d76-ad16-64bf4cf65247` | `php vendor/bin/phpunit tests/Unit/SeoCoreTest.php` | Migration / Core | `passed` | `0` | `2026-11-20T13:26:04.750Z` |
| `VERIFY-RUN-9af52031-3165-41f2-85b3-658e3dfab553` | `php vendor/bin/phpunit tests/Unit` | Full Unit Suite | `passed` | `0` | `2026-11-20T13:26:06.124Z` |

---

## 4. Trạng thái hiện tại & các giới hạn chưa chứng minh (ghi nhận trung thực theo D2-7)

1. **Phạm vi đã kiểm thử bằng unit/contract tests offline:** Các hợp đồng O1–O3 và toàn bộ 6 nhóm nhánh biên D2-1 → D2-6 nêu trong `docs/giai-doan-2-d-r2.md` hiện đã có bài test trực tiếp và chạy pass trên working tree. Tuy nhiên unit tests offline chỉ chứng minh hành vi của các lớp PHP trong phạm vi ca kiểm thử đã viết, chưa thay thế cho review độc lập trên snapshot cố định.
2. **Mapping & compatibility range thực tế:** **0 rule.** `RankMathCompatibilityMatrix::shippedDefault()` và `RankMathMappingCatalog::shippedDefault()` giữ nguyên `0` range thực; mọi version thực chưa có bằng chứng runtime trả `compatibility = unknown` và field `state = unsupported`. Không tuyên bố hỗ trợ hay tương thích Rank Math runtime.
3. **Runtime & production readiness:** Chưa xác minh với WordPress/Rank Math thật, không nối `Plugin::boot()`, không đăng ký WordPress hooks, không network/persistence/Apply/publish/chuyển ownership.
4. **Working tree vs. immutable revision:** Toàn bộ code, test và báo cáo đang nằm trên **working tree dirty** (chưa commit; ngoài các file của D còn có thay đổi sẵn ở `docs/giai-doan-2-Increment-D.md`, `docs/giai-doan-2-d-r2.md`, `docs/giai-doan-2-d-report.md` và `.phpunit.result.cache`). Chưa đóng gate C và chưa khóa revision bất biến cho D; khi Owner cho phép khóa snapshot để chuyển Reviewer độc lập nghiệm thu chính thức, cần commit riêng các artifact hợp lệ của D (không gồm `.phpunit.result.cache`) và chạy lại verification receipt neo trực tiếp vào commit SHA đó.
