# Increment E r6 — Hướng dẫn chốt sạch các mối nối còn lại và đóng ma trận kiểm thử

Đối chiếu [báo cáo E-r5](giai-doan-2-Increment-E-r5-report.md) (`sha256:b17a615886fb3eee15885e5684d71a6c2f18e2608731fb7ebd2a9fdb4869d7b5`) trên snapshot `613a6a171be0aca2cf1b90218d4341e890eb6d4c` với [hướng dẫn E-r5](giai-doan-2-Increment-E-r5.md) (`sha256:4ccf08dd81413c96d501d5d5c871b587b04760653c4667f43ead69af3220dece`) và [hướng dẫn E](giai-doan-2-Increment-E.md) (`sha256:e4e3bae856605f08a8c1f2b0829b17ea439de71a2f063dae7982cf6ae3c7b2ac`). Đây là hướng dẫn kiến trúc cho lượt chốt cuối của Increment E, không phải Sprint Plan, manifest sửa file hay quyền triển khai. Gate C/D/E giữ nguyên quy tắc tại mục 1.1 của hướng dẫn E.

---

## 1. Phần E-r5 đã đạt (tuyệt đối giữ nguyên, không sửa lùi)

- Quy trình khóa snapshot sạch trước khi gọi `run_check` đã chuẩn: toàn bộ 7 lượt chạy trên `613a6a171be0aca2cf1b90218d4341e890eb6d4c` đều `exit 0`.
- `HookDispatcher` (`canDispatch`, chặn `\Closure`, không ghi đăng ký giả), `SeoModule::registerDiagnosticsSurface($postType, $post)` (2 đối số, kiểm `edit_post`, phân luồng content/product), `Plugin::safeCoexistence()` (`duplicate_risk = 'blocked'`, allowlist `owner`/`reason`), `EnvironmentCheck::isReady()` và `WooCommerceProductReader::parentExists()` đã đúng contract.

---

## 2. Năm việc cụ thể cần hoàn tất sạch trong 1 lượt E-r6 (`E6-1` → `E6-5`)

### E6-1 — Nối nốt constructor seams còn để `null` trong `AdminModule` & `WooCommerceModule`, và tránh gọi lặp `environment` (O1/O3)

**Vị trí & vấn đề hiện tại:**
1. `src/Admin/AdminModule.php`: dòng `26` và `47` đã khai báo `private $capabilityChecker;` nhưng `__construct` chưa có tham số nhận vào (gán cứng `null`); `renderPage()` dòng `150` vẫn gọi cứng `function_exists('current_user_can')`, dòng `154` vẫn gọi `$this->environment->evaluate()` trước cả `$this->healthProvider`, và dòng `167–170` tự ghép lại nhãn chỉ từ `compatible` (làm mất nhãn `installed` nhưng `inactive` và `mbstring` fallback).
2. `src/WooCommerce/WooCommerceModule.php`: dòng `19` và `31` đã khai báo `private $availabilityProbe;` nhưng `__construct` dòng `28` chưa có tham số nhận vào (gán cứng `null`).
3. `src/SEO/Runtime/SeoModule.php`: dòng `116` kiểm `ctype_digit($postId)` cần thêm `(string) (int) $postId === (string) $postId` để từ chối chuỗi có số `0` đứng đầu như `'07'`.
4. `src/Core/Environment/EnvironmentCheck.php` (`113–116`) & `src/Core/Plugin.php` (`240`): đồng bộ kiểm tra `version` an toàn (`/^[0-9][A-Za-z0-9._+-]{0,31}$/`, cho phép `schema_version` kiểu `int > 0` chuyển thành chuỗi chữ số như `'1'`).

**Yêu cầu sửa:**
- `AdminModule::__construct(..., ?HookDispatcher $dispatcher = null, ?callable $capabilityChecker = null)`: lưu `$this->capabilityChecker = $capabilityChecker;`. Trong `renderPage()`:
  - Kiểm quyền qua `is_callable($this->capabilityChecker) ? (($this->capabilityChecker)('manage_options') === true) : (function_exists('current_user_can') && current_user_can('manage_options'))`. Nếu `false` -> `return` ngay (**0 lần** chạm `healthProvider` hay `environment`).
  - Chỉ gọi `$this->environment->evaluate()` khi `!is_callable($this->healthProvider)` hoặc provider không trả mảng `'environment'`.
  - Hiển thị nhãn trạng thái của `environment` có phân biệt đủ: `compatible === false` -> chưa tương thích; `mbstring` với `fallback === true` (hoặc `installation === 'absent'`) -> đang dùng fallback UTF-8; `installation/activation/compatible === 'unknown'` -> chưa đủ bằng chứng runtime; `activation === 'inactive'` -> đã cài nhưng chưa kích hoạt; còn lại -> sẵn sàng. Giữ hiển thị 4 modules và `coexistence` (gồm `owner`, `state`, `duplicate_risk` kể cả `'blocked'`), mọi chuỗi qua `$this->escapeHtml()`.
- `WooCommerceModule::__construct(?HookDispatcher $dispatcher = null, ?callable $availabilityProbe = null)`: gán `$this->availabilityProbe = $availabilityProbe;`.

---

### E6-2 — Phân biệt `'invalid'` vs `'unobserved'` ở `WordPressRankMathProbe` & hoàn thiện `WordPressRankMathSourceObserver` (O3)

**Vị trí & vấn đề hiện tại:**
1. `src/Integrations/RankMath/WordPressRankMathProbe.php` (`50–58, 64–83, 108–124`):
   - Khi `$optionReader` trả về dữ liệu hỏng kiểu (ví dụ chuỗi `'broken'` hoặc mảng chứa phần tử không phải string `[123]`), `activePlugins()` trả `null` giống hệt trường hợp không có `get_option`, làm `observeActivation()` gắn `evidence_state = 'unobserved'` thay vì `'invalid'`.
   - Thêm optional `?callable $versionReader = null` vào `__construct(?callable $optionReader = null, ?callable $pluginsReader = null, ?callable $versionReader = null)` để test được cả nhánh có/không có version mà không cần `define('RANK_MATH_VERSION', ...)` toàn cục.
2. `src/Integrations/RankMath/WordPressRankMathSourceObserver.php` & `RankMathModule.php`: hai file này chưa cập nhật ở E-r5.

**Yêu cầu sửa:**
- Trong `WordPressRankMathProbe`:
  - Nếu không có `$this->optionReader` và `!function_exists('get_option')` -> `observeActivation()` trả `state = 'unknown'`, `evidence_state = 'unobserved'`.
  - Nếu có nguồn đọc option nhưng giá trị trả về **không phải `array`** hoặc có phần tử không phải `string` -> `observeActivation()` trả `state = 'unknown'`, `signal_source = 'active_plugins'`, `evidence_state = 'invalid'`, `evidence_identity = 'wordpress:rank_math:active_plugins'`.
  - Trong `observeVersion()`: nếu `is_callable($this->versionReader)` trả chuỗi khác `null` (hoặc `defined('RANK_MATH_VERSION')`), trả `state = 'present'`, `value = (string) ...`, `evidence_state = 'observed'`; ngược lại trả `state = 'unavailable'`, `evidence_state` là `'missing'` khi có nguồn option/WP, hoặc `'unobserved'` khi chạy ngoài WP.
- Trong `WordPressRankMathSourceObserver`:
  - Cho phép truyền optional `?callable $metaExists = null`, `?callable $metaReader = null`, `?callable $capabilityChecker = null` vào `__construct(array $allowedSlots = [], ...)`.
  - Thứ tự kiểm tra trong `observe(string $sourceId, string $slotKey)`:
    1. Kiểm `$postId > 0`, `$slotKey !== ''` và `in_array($slotKey, $this->allowedSlots, true)` -> sai trả `unreadable('unmapped_source')` (**0 lần** gọi capability/meta).
    2. Kiểm quyền đọc đối tượng: nếu `is_callable($this->capabilityChecker)` thì đòi `($this->capabilityChecker)('edit_post', $postId) === true`; nếu không có `$capabilityChecker` nhưng `function_exists('current_user_can')` thì đòi `current_user_can('edit_post', $postId)` -> sai trả `unreadable('unauthorized_source')` (**0 lần** gọi meta).
    3. Kiểm cổng meta khả dụng (`(is_callable($this->metaExists) && is_callable($this->metaReader)) || (function_exists('metadata_exists') && function_exists('get_post_meta'))`) -> thiếu trả `unreadable('wordpress_metadata_unavailable')` (`can_confirm_presence = false`, `provenance = 'synthetic'`).
    4. Khi đủ điều kiện mới đọc `metaExists` + `metaReader` và trả `'provenance' => 'runtime_verified'`.
  - *(Lưu ý kỹ thuật cho `RankMathReadAdapter` khi đọc theo bài viết cụ thể: `RankMathMappingCatalog` lưu `candidate['source']` dạng `'wordpress_post_meta'` và `candidate['slot']` dạng `'rank_math_title'`, nên khi gọi từ reader cho một `$postId` cụ thể, có thể tạo một scoped observer nhẹ bọc `WordPressRankMathSourceObserver` truyền `'post:' . $postId` vào `$sourceId` mà vẫn đi qua đúng cổng kiểm tra slot + capability + meta seam của `WordPressRankMathSourceObserver`.)*

---

### E6-3 — Đóng kín `WordPressContentReader` nhánh object, allowlist lồng nhau & nối tùy chọn `RankMathReadAdapter` (O2)

**Vị trí & vấn đề hiện tại:**
- `src/SEO/Runtime/WordPressContentReader.php` (`119–144`): nhánh `is_object($raw)` vẫn ép `(int) $raw->ID` (biến `$raw->ID = '7abc'` thành `7`) và gọi `get_permalink`/`get_the_title` trước khi kiểm tra ID khớp `$postId`; nhánh `is_array($raw)` chưa lọc allowlist khóa trước khi đưa vào `SeoContext::fromArray()`; chưa có optional permalink loader seam.
- Cả `WordPressContentReader` và `WooCommerceProductReader` chưa nhận optional Rank Math override resolver theo bảng O2.

**Yêu cầu sửa:**
- Trong `WordPressContentReader`:
  - Giữ nguyên `$raw->ID` chưa ép kiểu ở `normalize()` để `readPost()` kiểm tra bằng `$this->isExactPositiveId()` và `(int) $rawId === $postId` + `in_array($postType, ['post', 'page'], true)` **trước khi** gọi permalink helper (thêm optional `?callable $permalinkLoader = null`, fallback `get_permalink`).
  - Lọc allowlist khóa cho cả `SeoContext` (`id`, `title`, `content`, `excerpt`, `meta_description`, `url`, `canonical`, `images`, `categories`, `tags`, `internal_links`, `seo`) và `ProviderPayloadBuilder`, loại bỏ `null` đệ quy (kể cả bên trong `seo`).
- Trong cả `WordPressContentReader` và `WooCommerceProductReader` (và nối lazy qua closure trong `SeoModule::register()` lấy `RankMathReadAdapter`, `RankMathDetector` và `RankMathSourceObserver` từ `$this->container` vì `RankMathModule::register()` chạy sau `SeoModule::register()`):
  - Thêm optional `?callable $seoOverrideResolver = null` nhận `(int $objectId): array`.
  - Khi resolver được cấu hình, nó đọc qua `RankMathReadAdapter` cho đối tượng `$objectId` (với version lấy từ `RankMathDetector::detect()` khi `version` hợp lệ, hoặc `null` dưới default). **Chỉ** các field trong `['title', 'meta_description', 'canonical']` có `state === 'value'` và chuỗi không rỗng mới được gán vào `$source['seo'][$field]`, đồng thời truyền provenance `'seo.' . $field => 'rank_math'` vào `ProviderPayloadBuilder::build(...)`!
  - Dưới `shippedDefault()` (`0 rule`), cả 3 field trả `state === 'unsupported'` ngay tại `RankMathMappingCatalog` -> **0 lần gọi meta API**, không thêm khóa `seo`, giữ nguyên provenance `'wordpress'` / `'woocommerce'`.

---

### E6-4 — Bổ sung trọn vẹn bộ kiểm thử hành vi O1–O4 còn thiếu (O4)

Giữ xanh toàn bộ các file test hiện có và bổ sung đầy đủ các bài test sau (có thể đặt gọn trong `tests/Unit/IncrementER6VerificationTest.php` hoặc mở rộng `IncrementER2SafetyTest.php`, chú ý dưới 400 code lines mỗi file):

1. **4 loại resource dạng Object thật + Edge cases & Rank Math override (`O2`):**
   - Dùng fake `WP_Post` object cho `'post'` (có `ID`, `post_type`, `post_title`, `post_content`, `post_excerpt`, `post_name`/slug, property lạ) và `'page'`, cùng fake `WC_Product` (`get_type() === 'simple'`) và `WC_Product_Variation` (`get_type() === 'variation'`, `get_sku() === null`, object attribute có `get_name()`/`get_options()`, parent `'variable'` hợp lệ trong cùng loader tra đúng **1 lần**): cả 4 đạt `status === 'ok'`, có `findings` và qua `ProviderPayloadBuilder::validatePayload($result['provider_payload'])` không ném ngoại lệ.
   - Kiểm tra object `$raw->ID = '7abc'` bị từ chối `invalid_post_source` với **0 lần** gọi `$permalinkLoader`; thiếu API trả `unavailable`; `not_found`; variation tự tham chiếu `id === parent_id` hoặc parent là `'variation'` trả `parent_product_missing`; giá trống `''` (missing) vs giá sai `'-5'` (`product_fields_invalid` + `payload_status = 'rejected'`); thiếu currency không sinh `offer`; `manage_stock = true` + `stock_quantity = 0` giữ `0`; bài viết chứa email/API key trả `provider_payload === null`, `payload_status = 'rejected'`, `payload_reason = 'privacy_policy'` nhưng vẫn giữ `findings` cục bộ và không lộ PII; object đầu vào không bị mutate.   - Kiểm tra Rank Math override qua synthetic `RankMathMappingCatalog` + fixture/scoped observer: khi `canonical` / `title` trả `state = 'value'`, `provider_payload['seo']` và `provenance` mang `'source' => 'rank_math'`; dưới default zero-rule gọi meta spy **0 lần**.
2. **Boot Idempotency (1 & 2 `Plugin` instances) & Thực thi Callbacks thật (`O1/O4`):**
   - Khởi tạo **2 instance `Plugin`** dùng chung 1 `HookDispatcher` có spy forwarder và gọi `boot()` lặp lại: mỗi listener định danh (`admin_menu`, `admin_init`, `add_meta_boxes` với `acceptedArgs = 2`, và `woocommerce_init` khi `WooCommerceModule` có `$availabilityProbe` trả `true`) chỉ được forward đúng **1 lần**.
   - Lấy chính callback `registerDiagnosticsSurface` đã forward vào spy và gọi trực tiếp với `('post', $wpPost)` và `('product', $wcPost)`: khi `capabilityChecker` trả `false` -> content/product loader gọi **0 lần**; khi trả `true` -> gọi đúng reader tương ứng và output buffer `ob_get_clean() === ''`; gọi `onWooCommerceInit()` đặt `health()['lifecycle_observed'] === true`.
3. **Admin Render, Coexistence `blocked`, Capability Gate & Fallback Escape (`O3`):**
   - Bơm conflict vào `OwnershipRegistry` trong `Container` để `duplicate_risk = 'blocked'`: cả `Plugin::health()['coexistence']` và HTML của `AdminModule::renderPage()` đều chứa `duplicate_risk=blocked` và nhãn `inactive` / `mbstring` fallback.
   - Khi `capabilityChecker` của `AdminModule` trả `false`: `healthProvider` và `environment` probe gọi **0 lần**, HTML rỗng `''`. Khi trả `true`: `environment` probe bên ngoài `healthProvider` gọi **0 lần** (không probe lặp).
   - Bơm sentinel (`sk-ant-secret123456`, `/etc/passwd`, `<script>alert(1)</script>`) vào cả khóa lạ lẫn khóa đúng tên sai miền giá trị (`owner`, `reason`, `version`, `message`) -> không xuất hiện trong `json_encode($plugin->health())` và không xuất hiện thẻ `<script>` thô trong HTML; test riêng fallback `escapeHtml()` mã hóa `<>&"'`.
4. **Ma trận `WordPressRankMathProbe`, `WordPressRankMathSourceObserver` & Negative Spies (`O3/O4`):**
   - Test `WordPressRankMathProbe` qua bridge cho đủ 6 trạng thái: ngoài WP (`unobserved`), `absent`, `installed` + `inactive`, `installed` + `active` + version `'1.0.210'` (`compatibility = 'unknown'` dưới zero-rule), mâu thuẫn `active` trong `active_plugins` nhưng `absent` trong `pluginsReader` (`reason = 'active_installation_metadata_missing'`), và `active_plugins` sai kiểu (`evidence_state = 'invalid'`).
   - Test `WordPressRankMathSourceObserver` với spy meta reader: default zero-rule, slot ngoài mapping, ID sai hoặc `capabilityChecker` trả `false` đều gọi meta spy **0 lần**; đủ slot + đủ quyền phân biệt `missing` vs `present` (`runtime_verified`); thiếu meta bridge trả `unreadable` (`can_confirm_presence = false`, không gắn `runtime_verified`).
   - Assert cứng qua spies được inject thật vào toàn bộ luồng chạy (`new Plugin()`, `boot()`, `health()`, `renderPage()`, callbacks, readers): **0 frontend hooks** (`wp_head`, `document_title_parts`, `wpseo_*`, `rank_math/*`, sitemap), **0 writes** (`update_option`, `update_post_meta`, `wp_update_post`, product `save()`), **0 network/DNS calls**.

---

### E6-5 — Khóa Snapshot sạch (chỉ `src/` + `tests/`), chạy `run_check` & xuất báo cáo `docs/giai-doan-2-Increment-E-r6-report.md` (O4)

1. Không sửa logic contract lõi đã nghiệm thu của A–D (`LocalSeoAnalyzer`, `ProviderPayloadSchema`, `ProviderPayloadRules`, `AiOutputValidator`, `RankMathDetector`, `RankMathReadAdapter`, `OwnershipRegistry`).
2. **Tách bạch commit code/test với tài liệu:** Commit **chỉ** các file `src/` và `tests/` thuộc Increment E thành một snapshot sạch `<E_R6_SNAPSHOT_SHA>` (không stage `.phpunit.result.cache` và không trộn các file `docs/*.md` vào commit snapshot).
3. Ngay trên `<E_R6_SNAPSHOT_SHA>`, chạy bằng `run_check` cho toàn bộ các file test focused thuộc E (kể cả file test mới của r6) và full suite `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit` cho tới khi tất cả đạt `exit 0` với `Revision` khớp đúng `<E_R6_SNAPSHOT_SHA>`.
4. Ghi báo cáo bàn giao vào `docs/giai-doan-2-Increment-E-r6-report.md` và commit riêng file báo cáo (không chạm lại `src/` hoặc `tests/`), nêu đầy đủ: `base_sha`, `implementation_snapshot_sha` (`<E_R6_SNAPSHOT_SHA>`), mốc D (`5aa3332133f203f41a8701e83b213c246cf491da`), bảng đối chiếu `E6-1` → `E6-5`, toàn bộ `VERIFY-RUN-*` (Run ID, full command, revision, status, exit code), đầy đủ SHA-256 của toàn bộ file `src/` và `tests/` thuộc Increment E trên `<E_R6_SNAPSHOT_SHA>`, và phần giới hạn rõ ràng (xác minh offline không chứng minh tương thích production WordPress/WooCommerce/Rank Math, default Rank Math vẫn zero-rule, báo cáo không tự đóng gate C/D/E hay mở F–H).
