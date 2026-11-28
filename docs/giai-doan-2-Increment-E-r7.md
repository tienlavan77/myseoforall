# Increment E r7 — Hướng dẫn sửa lỗi namespace gây exit 2 và đóng đủ test E6-4

Đối chiếu [báo cáo E-r6](giai-doan-2-Increment-E-r6-report.md) (`sha256:4de6e2fbed1bf0e00b5d5a43da5b751b89fbf01a2df7bebbc3ccf4dd8ba7dd87`) trên snapshot `9c9b9b8a2399d65db670ebfac2cbd16b844a4698` với [hướng dẫn E-r6](giai-doan-2-Increment-E-r6.md) (`sha256:8b7dcd34b601087d5acd1980a35b02de3d4c4be6d4aa8d8524a77f6f765070a0`). Đây là hướng dẫn kiến trúc cho vòng chốt cuối của Increment E, không phải Sprint Plan, manifest sửa file hay quyền triển khai. Gate C/D/E tiếp tục tuân thủ mục 1.1 của hướng dẫn E.

**Kết luận: Mã nguồn `src/` của E-r6 ĐÃ ĐẠT (cần 2 chỉnh nhỏ 5 dòng bên dưới), nhưng CHƯA thể nghiệm thu vì SE đã cắt bỏ gần hết `IncrementER6VerificationTest.php` do gõ sai namespace class trong test.**

---

## 1. Nguyên nhân chính xác khiến SE bị PHPUnit `exit 2` ở E-r6

Qua đối chiếu các commit `5de2b30` và `7a42ceb`, nguyên nhân duy nhất khiến PHPUnit dừng với `exit 2` **không phải do lỗi code**, mà là do test viết sai FQCN:
- Trong `7a42ceb`, dòng `54` viết `new \MySeoForAll\Core\Configuration([])` và `new \MySeoForAll\Core\OptionStore()`, trong khi namespace thật trong repo là:
  - **`\MySeoForAll\Core\Configuration\Configuration`** (`src/Core/Configuration/Configuration.php`)
  - **`\MySeoForAll\Infrastructure\WordPress\OptionStore`** (`src/Infrastructure/WordPress/OptionStore.php`)
- Khi gặp `Class "MySeoForAll\Core\Configuration" not found`, PHP ném `Error` làm PHPUnit trả `exit 2`! Thay vì sửa đúng `use MySeoForAll\Core\Configuration\Configuration;`, SE lại xóa hết các hàm test chỉ chừa lại 1 hàm 25 dòng.

---

## 2. Hai chỉnh nhỏ cuối cùng trong `src/` (`E7-1`)

Giữ nguyên toàn bộ code `src/` đã đạt trên `9c9b9b8a2399d65db670ebfac2cbd16b844a4698`, chỉ chỉnh đúng **2 chi tiết nhỏ** sau:

1. **`src/Admin/AdminModule.php` (`210–211`):** Khi `renderPage()` chạy độc lập không qua `Plugin::health()`, `$decision['owner']` và `$decision['state']` hiện chỉ kiểm `is_string(...)`. Hãy khóa allowlist giống hệt `Plugin::safeCoexistence()`:
   - `$owner`: `in_array($decision['owner'] ?? null, ['unknown', 'myseo_for_all', 'rank_math', 'yoast_seo', 'aioseo', 'seopress', 'the_seo_framework'], true) ? $decision['owner'] : 'unknown'`
   - `$state`: `in_array($decision['state'] ?? null, ['owned', 'unknown', 'conflict'], true) ? $decision['state'] : 'unknown'`
2. **`src/WordPressContentReader.php` (`144–153`) & `src/SEO/Runtime/WooCommerceProductReader.php` (`440–448`):**
   - Trong `WordPressContentReader`: dời khối gọi `$this->permalinkLoader` / `get_permalink` / `get_the_title` từ `normalize()` sang `readPost()` **sau dòng `74`** (sau khi đã xác nhận `(int) $source['id'] === $postId` và `in_array($postType, ['post', 'page'], true)`), để khi đối tượng trả về lệch ID (ví dụ yêu cầu `7` nhưng object có `ID = 99`) hoặc sai `post_type` (`'revision'`), permalink helper được gọi **0 lần**.
   - Trong `WooCommerceProductReader::dropNulls()`: thêm nhánh đệ quy `elseif (is_array($value)) { $source[$key] = $this->dropNulls($value); }` giống `WordPressContentReader` để lọc sạch `null` lồng nhau.

---

## 3. Khôi phục & viết đủ bộ kiểm thử hành vi trong `tests/Unit/IncrementER6VerificationTest.php` (`E7-2`)

Dùng đúng `use` statements từ các file có thật trong `src/` (kiểm tra kỹ `use MySeoForAll\Core\Configuration\Configuration;` và `use MySeoForAll\Infrastructure\WordPress\OptionStore;`!) để viết đầy đủ 4 nhóm kiểm thử bắt buộc của `E6-4` trong `tests/Unit/IncrementER6VerificationTest.php` (dưới 350 code lines):

1. **`testObjectBackedFourResourcesAndEdgeBoundaries()` (`O2`):**
   - Fake `WP_Post` object cho `'post'` (có `ID`, `post_type`, `post_title`, `post_content`, `post_excerpt`, `post_name` = `'bai-viet-slug'`, kèm thuộc tính lạ) và `'page'`, fake `WC_Product` object (`get_type() === 'simple'`) và fake `WC_Product_Variation` object (`get_type() === 'variation'`, `get_sku() === null`, attribute object có `get_name()`/`get_options()`, parent `'variable'` hợp lệ trong cùng loader tra đúng **1 lần**): cả 4 trả `status === 'ok'`, có `findings` và qua `(new ProviderPayloadBuilder())->validatePayload($result['provider_payload'])`.
   - Ca biên: object `$raw->ID = '7abc'` và object lệch `ID = 99` trả `invalid_post_source` với **0 lần** gọi `$permalinkLoader`; thiếu API trả `unavailable`; `not_found`; variation tự tham chiếu `id === parent_id` và parent có `get_type() === 'variation'` trả `parent_product_missing`; giá `''` (missing) vs giá sai `'-5'` (`product_fields_invalid` + `payload_status = 'rejected'`); thiếu currency không sinh `offer`; `manage_stock = true` + `stock_quantity = 0` giữ `0`; bài viết chứa email `user@example.com` trả `provider_payload === null`, `payload_status = 'rejected'`, `payload_reason = 'privacy_policy'` nhưng giữ `findings` cục bộ; object nguồn không bị mutate.
   - Ca Rank Math override: truyền resolver trả `['canonical' => ['state' => 'value', 'value' => 'https://example.test/rm-canonical'], 'title' => ['state' => 'unsupported']]` -> `provider_payload['seo'] === ['canonical' => 'https://example.test/rm-canonical']`, `provider_payload['canonical'] === 'https://example.test/rm-canonical'` và `provenance` ghi `seo.canonical` + `canonical` là `'rank_math'`.
2. **`testBootIdempotencyAcrossTwoPluginsAndCallbacks()` (`O1/O4`):**
   - Tạo 1 `HookDispatcher` có spy forwarder lưu danh sách `[$hook, $listener, $priority, $acceptedArgs]`; tạo **2 instance `Plugin`** (có `WooCommerceModule` với `$availabilityProbe` trả `true` và `SeoModule` có `$capabilityChecker` + `$wooAvailability`) cùng dùng dispatcher đó và gọi `boot()` lặp lại: mỗi listener định danh (`admin_menu`, `admin_init`, `add_meta_boxes` với `acceptedArgs = 2`, `woocommerce_init`) chỉ được forward **1 lần** và **0 lần** đăng ký frontend hooks (`wp_head`, `document_title_parts`, `wpseo_*`, `rank_math/*`).
   - Gọi thật callback `registerDiagnosticsSurface` đã forward: khi `$capabilityChecker` trả `false` -> content/product loader gọi **0 lần**; khi trả `true` -> `('post', $wpPost)` gọi content reader, `('product', $wcPost)` gọi product reader, và `ob_get_clean() === ''`; gọi `onWooCommerceInit()` đặt `health()['lifecycle_observed'] === true`.
3. **`testAdminCapabilityGateCoexistenceBlockedAndEscaping()` (`O3`):**
   - Khi `$capabilityChecker` của `AdminModule` trả `false`: `healthProvider` và `environment` probe gọi **0 lần**, HTML `=== ''`.
   - Khi `$capabilityChecker` trả `true` và `healthProvider` trả snapshot có `coexistence.outputs.title_meta` mang `duplicate_risk = 'blocked'`, `woocommerce` mang `activation = 'inactive'`, `mbstring` mang `fallback = true`, kèm dữ liệu bẩn ở `owner => '<script>bad</script>'`: `environment` probe bên ngoài gọi **0 lần**; HTML chứa `duplicate_risk=blocked`, `owner=unknown`, nhãn `đã cài nhưng chưa kích hoạt.` và `fallback UTF-8 deterministic.`, và **không chứa** `<script>` thô (kiểm chứng luôn fallback `escapeHtml()` mã hóa `<>&"'`).
   - Bơm sentinel `sk-ant-secret123456` và `/etc/passwd` vào `Configuration`, `EnvironmentCheck` probe (`version`, `message`, khóa lạ) và `OwnershipRegistry` -> không xuất hiện trong `json_encode($plugin->health())`; kiểm `EnvironmentCheck::isReady()` trả `false` khi `installation = 'absent'` dù `compatible = true`.
4. **`testRankMathProbeMatrixAndObserverZeroMetaCalls()` (`O3/O4`):**
   - Kiểm `WordPressRankMathProbe` đủ các ca: ngoài WP (`unobserved`), `absent`, `installed` + `inactive`, `installed` + `active` + version `'1.0.210'` (`compatibility = 'unknown'` dưới default zero-rule), conflict `active` trong `active_plugins` nhưng `absent` trong `pluginsReader` (`reason = 'active_installation_metadata_missing'`), và `active_plugins` sai kiểu (`evidence_state = 'invalid'`).
   - Kiểm `WordPressRankMathSourceObserver` với spy `$metaExists`/`$metaReader`: default zero-rule (`[]`), slot ngoài mapping, ID sai, và thiếu quyền (`capabilityChecker => false`) đều có `$metaCalls === 0`; khi đủ slot + đủ quyền thì phân biệt đúng `missing` vs `present` (`provenance = 'runtime_verified'`); thiếu meta bridge trả `unreadable` (`can_confirm_presence = false`, `provenance = 'synthetic'`).

---

## 4. Khóa Snapshot sạch, chạy `run_check` & ghi `docs/giai-doan-2-Increment-E-r7-report.md` (`E7-3`)

1. Chạy `php -l` trước nếu cần hoặc kiểm kỹ toàn bộ `use` statements để không vướng lỗi class-not-found (`exit 2`).
2. Commit **chỉ** các file `src/` và `tests/` sửa trong E-r7 thành một commit snapshot sạch `<E_R7_SNAPSHOT_SHA>` (không stage `.phpunit.result.cache` hay `docs/*.md`).
3. Ngay trên `<E_R7_SNAPSHOT_SHA>`, chạy `run_check` cho `tests/Unit/IncrementER6VerificationTest.php`, các file test focused của E và full suite `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` đạt toàn bộ `exit 0` gắn đúng `<E_R7_SNAPSHOT_SHA>`.
4. Ghi báo cáo bàn giao vào `docs/giai-doan-2-Increment-E-r7-report.md` (commit riêng, không sửa lại `src/` hoặc `tests/`) với đầy đủ `implementation_snapshot_sha`, `base_sha`, mốc D (`5aa3332133f203f41a8701e83b213c246cf491da`), bảng `VERIFY-RUN-*`, toàn bộ SHA-256 của `src/` + `tests/` thuộc E và mục giới hạn rõ ràng.
