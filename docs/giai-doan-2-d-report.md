# Giai đoạn 2 — Increment D: Báo cáo triển khai contract offline và verification

- **Ngày:** 2026-11-20
- **Người nhận:** Owner / Architecture / Reviewer độc lập
- **Nhánh & HEAD cơ sở:** `codex/update-r3-report` @ `19e513751ed3be71fb781ce1d4773e4232dd9f31` (base SHA đối chiếu của `run_check`: `9c76c628853dd63024c1e02fb44a70581b022db7`)
- **Phạm vi thực hiện:** Theo xác nhận của Owner chọn **hướng 2** — hoàn tất triển khai và kiểm thử Increment D **offline trước khi đóng gate C**; kết quả này **không coi là acceptance C hoặc D**, chưa commit/push, không nối WordPress runtime, không Apply/publish và không chuyển ownership.

---

## 1. Tóm tắt thay đổi theo các Outcome của `docs/giai-doan-2-Increment-D.md`

### O1 — Detector và Compatibility Matrix (`src/Integrations/RankMath/`)
- Tách độc lập 4 chiều trạng thái trong `RankMathDetector` (`myseo.rank_math.detector.v1`):
  - `installation`: `absent | installed | unknown`
  - `activation`: `inactive | active | unknown`
  - `version_state`: `known | unavailable | invalid` (`version` chỉ giữ chuỗi hợp lệ khi `known`)
  - `compatibility`: đóng đúng `compatible | incompatible | unknown` (không mở enum `unsupported`; thể hiện qua `reason` và `evidence_state`).
- Injectable probe qua `RankMathEnvironmentProbe` và chuẩn hóa fail-closed qua `RankMathProbeNormalizer` (không phụ thuộc cứng vào hằng số toàn cục duy nhất trong test; không fatal khi thiếu Rank Math/WordPress).
- `RankMathCompatibilityMatrix` (`rank-math-compatibility-matrix-d-r1`, revision `2026-11-20.r1`): đánh giá theo `scope` capability (`read_title`, `read_meta_description`, `read_canonical`), kiểm tra precedence/ambiguous tie, và mặc định bàn giao (`shippedDefault()`) có `rule_count = 0` để mọi version chưa có bằng chứng runtime thực đều trả `unknown` (`matrix_unproven_for_range`).

### O2 — Rank Math Read Adapter, Mapping Catalog và Source Observation (`src/Integrations/RankMath/`)
- Cô lập toàn bộ mapping vào `RankMathMappingCatalog` (`rank-math-mapping-catalog-d-r1`, revision `2026-11-20.r1`) và `RankMathReadAdapter` (`myseo.rank_math.read_adapter.v1`). Không rải meta key/API knowledge sang SEO Core và không dùng `ProviderPayloadBuilder` thay thế.
- Allowlist cứng 3 field: `title`, `meta_description`, `canonical`. Yêu cầu ngoài allowlist bị từ chối bằng `\InvalidArgumentException`; các slot dư trong source không bao giờ được trả ra.
- Closed field states: `value | missing | explicit_null | invalid | unsupported | unreadable`; khóa `value` chỉ xuất hiện khi `state = value`. Ambiguous precedence trả `unsupported` với `reason = mapping_ambiguous`.
- `RankMathSourceObserver` và `RankMathFixtureSource` phân biệt rõ `presence` (`present | missing | unreadable`), `can_confirm_presence` và `provenance = fixture`. Source không xác nhận được presence trả `unreadable` (`presence_unconfirmed`) và chặn fallback. Fallback chỉ chạy khi nguồn ưu tiên xác nhận `missing`; không che `explicit_null`, `invalid`, `unsupported` hoặc `unreadable`.
- Kiểm tra nghiêm ngặt kiểu dữ liệu, UTF-8, ký tự điều khiển, chuỗi rỗng/khoảng trắng, giới hạn độ dài, template chưa render (`%title%`, `{{...}}`), secret/credential và cú pháp canonical HTTP(S) offline (không userinfo, không fragment, không DNS/HTTP fetch). Không mutate input.

### O3 — Ownership Registry & Coexistence Policy (`src/Integrations/RankMath/`)
- `OwnershipRegistry` (`myseo.ownership.registry.v1`) và `OwnershipObservationNormalizer` đánh giá độc lập `title_meta` (gồm đủ `title`, `meta_description`, `canonical`), `json_ld`, `sitemap`, `ai_draft`; giữ `open_graph` và `robots` ở `out_of_scope_outputs`.
- Bỏ hoàn toàn giả định cũ `active => ownership` và không có trường `decision_state`; `write_allowed = false` trên mọi output.
- Thực thi đúng bảng ưu tiên 9 hàng từ trên xuống: `identity_collision` -> `assertion_capability_conflict` -> `conflicting_evidence` -> `overlapping_claims` -> `split_ownership` -> `partial_ownership` -> `no_valid_claim` -> `other_emitters_excluded` (`duplicate_risk = none`) -> `other_emitters_not_excluded` (`duplicate_risk = possible`).
- Gộp bản sao chỉ khi trùng cả `observation_id` lẫn toàn bộ dữ kiện quyết định; khác dữ kiện trên cùng `observation_id` trả `identity_collision` bất biến với thứ tự A–B, B–A, A–B–A.
- `duplicate_risk = none` đòi hỏi đồng thời inventory xác thực (đủ `source_id`, `revision`, `site`, `snapshot`, `output`, `complete=true`, danh sách producer không rỗng) và bằng chứng `observed` xác nhận `can_emit = false` trên đủ mọi field bắt buộc cho từng producer khác (`deny` với `can_emit = true` vẫn giữ `possible`).
- `ai_draft` luôn gắn `frontend_owned = false`, `publish_capability = false`, `write_allowed = false`. `Plugin::boot()` không nối thêm module Rank Math hay runtime hook.

### Consumer & Migration
- Khảo sát toàn bộ `src/` và `tests/`: không có runtime consumer nào của `RankMathDetector` hoặc `OwnershipRegistry` trong `src/`; consumer duy nhất là `tests/Unit/SeoCoreTest.php`, nên không tạo legacy shim dư thừa mà cập nhật test cũ để xóa giả định `active => ownership` và bổ sung 3 file contract/coexistence tests chuyên biệt.

---

## 2. Danh mục artifact và SHA-256 trong working tree

### Mã nguồn (`src/Integrations/RankMath/`)
- `src/Integrations/RankMath/RankMathCompatibilityMatrix.php` — `sha256:3ebeaa4280dda6947e53230745bc3ecfcd54c8164be4bcb1c311f39882f324d2`
- `src/Integrations/RankMath/RankMathEnvironmentProbe.php` — `sha256:67ce76d12f50c6b219fc8e00b75a0ba9107f314ee3a1deac3f87a038c72a5a6d`
- `src/Integrations/RankMath/RankMathProbeNormalizer.php` — `sha256:3d8b971b12e77b31949797dcb453b2be65802b9b214d7ff61f63830706166aa5`
- `src/Integrations/RankMath/RankMathDetector.php` — `sha256:d889027bf96fc347799a3bf822aed18025007d25437e873761493f8e4cc0942f`
- `src/Integrations/RankMath/RankMathSourceObserver.php` — `sha256:b145410220e90124fd906bf94b51ab973f498683b28f414b4a8e235aa14c92c5`
- `src/Integrations/RankMath/RankMathFixtureSource.php` — `sha256:318a352a58a506a9e40b13f92f181c6aad573ada330bc2ac827a952196ef80a6`
- `src/Integrations/RankMath/RankMathMappingCatalog.php` — `sha256:93d44750fc1989b3f8157664b79eaab65e9387f7aec0c9d1e7c3bc3f801a4116`
- `src/Integrations/RankMath/RankMathReadAdapter.php` — `sha256:1674c747eb3fda430aa88fe36cd11aa6d126ed746434a17481373966a4456b97`
- `src/Integrations/RankMath/OwnershipObservationNormalizer.php` — `sha256:7040bfcf713b9787cd90fa5ba17b2f339c26334c8c3d5b340ea27a9eb8e3181c`
- `src/Integrations/RankMath/OwnershipRegistry.php` — `sha256:f0119bf7e79342fb440ecb47276aa0b8aa1490ae5a29c2b5dc9853a8d012b35f`

### Kiểm thử (`tests/Unit/`)
- `tests/Unit/SeoCoreTest.php` — `sha256:bcdbf3699735a03b67daa28d81beb58bd1af8d0f223bb6ced0b3e7ab17294935`
- `tests/Unit/RankMathDetectorContractTest.php` — `sha256:5759dd09c9869b4c8ed1135f3b82fd92ca5aef05bf58a37a6d3c33b9b4adc398`
- `tests/Unit/RankMathReadAdapterContractTest.php` — `sha256:98c9a9832bb13738338a049ecf5dd9065478ac4c0e14f56e39ecd5d291f55cfd`
- `tests/Unit/OwnershipRegistryCoexistenceTest.php` — `sha256:77c7a691a63a853d222cb0249145c390361b37638f2969f246d64209ee63e7b5`

### Tài liệu kế hoạch giữ nguyên
- `docs/giai-doan-2-Increment-D.md` — `sha256:3aa08f8457104653bf14828d3c06e3f9a5d768e1c2714d7d947e2e39abda0267` (giữ nguyên thay đổi sẵn có trong working tree).

---

## 3. Verification Receipts đã chạy thực tế (working-tree pre-commit)

Tất cả chạy qua Forge `run_check` trên HEAD `19e513751ed3be71fb781ce1d4773e4232dd9f31` (`base_sha`: `9c76c628853dd63024c1e02fb44a70581b022db7`):

| Run ID | Command | Scope | Status | Exit Code | Thời điểm (UTC) |
| --- | --- | --- | --- | --- | --- |
| `VERIFY-RUN-9c72a1be-fa55-495e-85a3-f3b3df15059c` | `php vendor/bin/phpunit tests/Unit/RankMathDetectorContractTest.php` | Focused O1 | `passed` | `0` | `2026-11-20T12:56:21.886Z` |
| `VERIFY-RUN-13711a60-277e-450d-a1eb-122e30858e9b` | `php vendor/bin/phpunit tests/Unit/RankMathReadAdapterContractTest.php` | Focused O2 | `passed` | `0` | `2026-11-20T12:56:35.700Z` |
| `VERIFY-RUN-ae704a05-7be6-4d3e-9b23-306daf42364b` | `php vendor/bin/phpunit tests/Unit/OwnershipRegistryCoexistenceTest.php` | Focused O3 | `passed` | `0` | `2026-11-20T12:56:37.201Z` |
| `VERIFY-RUN-082599e5-6561-4f70-9933-68dcbca1ae53` | `php vendor/bin/phpunit tests/Unit/SeoCoreTest.php` | Migration / Core | `passed` | `0` | `2026-11-20T12:56:50.625Z` |
| `VERIFY-RUN-32a94e29-2146-4dcb-a1b9-d7c0bdfb8909` | `php vendor/bin/phpunit tests/Unit` | Full Unit Suite | `passed` | `0` | `2026-11-20T12:56:51.955Z` |

Lint/typecheck/build: `composer.json` hiện chỉ cấu hình PHPUnit (`not_applicable` trong `run_check`), không chạy thêm công cụ ngoài repo.

---

## 4. Phân tách bắt buộc & giới hạn còn lại

1. **Contract behavior đã kiểm thử offline:** Đã xác minh đầy đủ bằng unit/contract tests ở mục 3 (orthogonal detector states, synthetic matrix/mapping precedence & tie, presence/null/missing/unreadable separation, fallback-on-missing-only, canonical & template/secret validation, 9 hàng ưu tiên O3, permutation A–B/B–A/A–B–A, `ai_draft` internal flags, input non-mutation và `Plugin::boot()` không nối runtime).
2. **Mapping/range thực đã có evidence:** **Chưa có (0 rule).** Cả `RankMathCompatibilityMatrix::shippedDefault()` lẫn `RankMathMappingCatalog::shippedDefault()` đều để rỗng theo đúng nguyên tắc fail-closed; với mọi version thực chưa có bằng chứng runtime, kết quả bàn giao là `compatibility = unknown` và field `state = unsupported`.
3. **Capability & runtime chưa được xác minh:** Chưa kiểm chứng với plugin Rank Math thật chạy trên WordPress/database thực; chưa có concrete WordPress reader wiring, Health Check runtime wiring, Apply, publish hay ownership transfer. Fixture tests **không** chứng minh tương thích production hoặc runtime migration.
4. **Trạng thái gate & snapshot Git:** Các thay đổi hiện đang ở working tree (chưa tạo immutable Git commit cho D vì chưa có yêu cầu commit, và `.phpunit.result.cache` cùng `docs/giai-doan-2-Increment-D.md` đang dirty sẵn). Trước khi đóng gate nghiệm thu chính thức theo mục 1 & mục 5 của kế hoạch D, cần: (a) chốt canonical handoff C, (b) khóa một commit SHA bất biến riêng cho D (loại trừ `.phpunit.result.cache`), chạy lại receipt neo vào commit SHA đó và chuyển Reviewer độc lập đánh giá.
