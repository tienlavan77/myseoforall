# Increment E r5 — Kết quả rà soát E-r4 và hướng dẫn chốt dứt điểm cho SE

Đối chiếu [báo cáo E-r4](giai-doan-2-Increment-E-r4-report.md) (`sha256:8f3a577cc194dc048bb0cb6a41b5294be103333930921f1cccbce7e68cd28b0a`) với [hướng dẫn E-r4](giai-doan-2-Increment-E-r4.md) (`sha256:4c3b30f17faa2ddf60ee524de972296438c0780a66ce88f4a6a906818433bb31`), [hướng dẫn E](giai-doan-2-Increment-E.md) (`sha256:e4e3bae856605f08a8c1f2b08a8c1f2b0829b17ea439de71a2f063dae7982cf6ae3c7b2ac`) và toàn bộ working tree trên HEAD `2f5e32765b7d26ad357db0741fa4561f862524a7`. Đây là hướng dẫn kiến trúc để xử lý dứt điểm findings trong phạm vi Increment E, không phải Sprint Plan, manifest sửa file hay quyền triển khai.

**Kết luận: Chưa nghiệm thu Increment E-r4.** Báo cáo E-r4 đã xác nhận working tree chưa được commit sạch, `RuntimeCompositionTest` đang fail (`exit 1`), và các mục Rank Math bridge + ma trận kiểm thử (`E4-4`, `E4-5`, `E4-6`) chưa hoàn tất. Lượt rà soát này đọc trực tiếp source, test và diff (không tự chạy PHPUnit); gate C/D/E tiếp tục tuân thủ mục 1.1 của hướng dẫn E.

---

## 1. Ghi nhận phần đã đạt trong E-r4 và chẩn đoán chính xác nguyên nhân `RuntimeCompositionTest` fail (`exit 1`)

### 1.1 Các phần E-r4 đã sửa đúng (giữ nguyên)
- `WooCommerceProductReader`: đã khôi phục chuỗi fallback `$parentLoader -> $loader -> wc_get_product`, dùng `isExactPositiveId()` chặn `'9abc'` trước khi đọc các getter phụ trên nhánh object, và chuẩn hóa nested `variation` trên cả nhánh array lẫn object.
- `Plugin::safeEnvironment()`, `safeModule()`, `safeCoexistence()` và `EnvironmentCheck::isReady()` / default `wordpress` ngoài runtime WP: đã khóa đúng enum `['none', 'possible', 'blocked']`, allowlist `owner`/`reason`, pattern `version`, `write_allowed = false`, `frontend_owned = false`, `dependency_ready`.
- `HookDispatcher`: đã có `$forwarder` tùy chọn, `canDispatch()`, chặn `\Closure` vô danh và không ghi nhận đăng ký giả khi `!canDispatch()`.

### 1.2 Nguyên nhân chính xác khiến `RuntimeCompositionTest` bị `exit 1`
SE báo Forge không hiển thị chi tiết assertion; qua đọc trực tiếp `tests/Unit/RuntimeCompositionTest.php` (`65–78`) đối chiếu với `src/Core/HookDispatcher.php` (`69–71`), nguyên nhân chính xác là:
- `RuntimeCompositionTest::testHookDispatcherDeduplicatesNamedListeners()` khởi tạo `$dispatcher = new HookDispatcher();` **không truyền `$forwarder`** trong môi trường CLI (không có `add_action`).
- Vì `HookDispatcher::addAction()` dòng `69` trả `false` khi `!$this->canDispatch()`, dòng `75` của `RuntimeCompositionTest` (`self::assertTrue($dispatcher->addAction('diagnostics', $listener));`) **fail ngay lập tức**!
- Đồng thời ở dòng `118` và `133` của `RuntimeCompositionTest`, test cũ vẫn truyền khóa `'parent_exists'` vốn đã bỏ; khi cập nhật `HookDispatcher`, SE bắt buộc phải cập nhật `RuntimeCompositionTest` truyền spy forwarder `new HookDispatcher(static function (): void {})` cho bài test dedup (và thêm bài test riêng xác nhận `new HookDispatcher()` không có forwarder trả `false` + `registrations() === []`).

---

## 2. Năm hạng mục còn lại SE cần hoàn tất trọn vẹn trong E-r5 (`E5-1` → `E5-5`)

### E5-1 — Blocker: Hoàn thiện nốt `isAvailable()`, callback `registerDiagnosticsSurface` & `AdminModule::renderPage()` (O1/O3)

**Bằng chứng trên code hiện tại:**
1. **`Plugin::boot()` vẫn bỏ qua `AdminModule` và `SeoModule` khi truyền spy `HookDispatcher`:** Trong `Plugin::boot()` (`src/Core/Plugin.php:79–81`), `boot()` chỉ gọi `$module->boot()` nếu `$module->isAvailable()` là `true`. Nhưng `AdminModule::isAvailable()` (`src/Admin/AdminModule.php:76`) và `SeoModule::isAvailable()` (`src/SEO/Runtime/SeoModule.php:81`) vẫn viết cứng `return function_exists('add_action');` thay vì kiểm tra `($this->dispatcher !== null && $this->dispatcher->canDispatch()) || function_exists('add_action')`! Hệ quả: khi inject `HookDispatcher` có spy forwarder vào `Plugin`, `Plugin::boot()` vẫn không gọi `boot()` của hai module này! (Tương tự `WooCommerceModule` nên nhận optional `$availabilityDetector` callable mặc định `class_exists('WooCommerce') || defined('WC_VERSION')` để test được nhánh `woocommerce_init` qua `Plugin::boot()` mà không cần định nghĩa class/constant toàn cục).
2. **`SeoModule::registerDiagnosticsSurface` vẫn giữ chữ ký cũ 1 tham số int:** Ở `src/SEO/Runtime/SeoModule.php:72`, `boot()` đã sửa thành `acceptedArgs = 2`, nhưng dòng `106–113` vẫn là `public function registerDiagnosticsSurface($postId = 0): void` với `$postId = is_int($postId) ? $postId : 0;` -> WordPress truyền `('post', $wpPost)` vẫn bị biến thành `0` và dừng ngay, chưa có cổng kiểm quyền `edit_post`, và chưa định tuyến `'product'` / `'product_variation'` sang `ProductSeoInspector`!
3. **`AdminModule::renderPage()` vẫn chưa có capability seam, vẫn gọi lặp `environment->evaluate()` và làm mất trạng thái chi tiết:** Ở `src/Admin/AdminModule.php:148–171`, hàm vẫn gọi cứng `function_exists('current_user_can')` (nên không test được `renderPage()` trên CLI), vẫn gọi `$this->environment->evaluate()` ở dòng `152` trước khi đọc `$this->healthProvider`, vẫn thiếu `version` regex validation trong `EnvironmentCheck::normalizeProbe()` (`113–116`) + `Plugin::safeConfiguration()` (`240`), và dòng `167–168` vẫn ghi đè `message` chỉ từ `compatible` (làm mất nhãn `installed-inactive` và `mbstring` fallback đã được `Plugin::safeEnvironment()` chuẩn hóa).

**Hướng sửa cụ thể:**
- Trong `AdminModule::isAvailable()` và `SeoModule::isAvailable()`: trả `true` khi `$this->dispatcher !== null ? $this->dispatcher->canDispatch() : function_exists('add_action')`. Trong `WooCommerceModule::__construct`: cho phép truyền optional `?callable $availabilityProbe = null` (mặc định `class_exists('WooCommerce') || defined('WC_VERSION')`).
- Trong `SeoModule`: thêm optional `?callable $capabilityChecker = null` và `?callable $wooAvailability = null` vào constructor (có default `null` để giữ tương thích 100% lời gọi `new SeoModule($this->container, $dispatcher)` trong `Plugin`). Sửa `registerDiagnosticsSurface($postType = '', $post = null): void`:
  - Xác thực `$postType` là string không rỗng; trích xuất ID nguyên dương từ `$post` (`is_object($post) && isset($post->ID)` khớp `post_type` của `$post` nếu thuộc tính đó tồn tại).
  - Kiểm quyền đối tượng qua `$this->capabilityChecker` (nếu có: `($this->capabilityChecker)('edit_post', $id) === true`; nếu không: `function_exists('current_user_can') && current_user_can('edit_post', $id)`). Nếu `false` -> `return` ngay, không gọi inspector.
  - Nếu `$postType` là `'post'` hoặc `'page'` -> gọi `ContentSeoInspector::inspect($id)`. Nếu là `'product'` hoặc `'product_variation'` và WooCommerce khả dụng -> gọi `ProductSeoInspector::inspect($id)`. Không echo/in ra output buffer.
- Trong `AdminModule`: thêm optional `?callable $capabilityChecker = null` vào cuối `__construct(...)`. Trong `renderPage()`:
  - Kiểm quyền `manage_options` qua `$this->capabilityChecker` (hoặc fallback `function_exists('current_user_can') && current_user_can('manage_options')`); nếu `false` -> `return` ngay trước mọi lời gọi khác.
  - Nếu `is_callable($this->healthProvider)`, gọi `($this->healthProvider)()` và lấy `$diagnostics['environment']` mà **không gọi** `$this->environment->evaluate()`; dùng nhãn trạng thái từ dữ liệu đã chiếu an toàn (hoặc hàm chiếu chung có đủ nhánh `inactive` và `mbstring` fallback) và hiển thị đủ 4 modules + `coexistence` (kể cả `duplicate_risk=blocked`).
- Trong `EnvironmentCheck::normalizeProbe()` và `Plugin::safeConfiguration()`: áp dụng cùng pattern phiên bản an toàn `/^[0-9][A-Za-z0-9._+-]{0,31}$/` (cho phép thêm số nguyên dương nếu `schema_version` là `int`, ép sang chuỗi phiên bản hợp lệ như `'1'`).

---

### E5-2 — Blocker: Khép kín các chi tiết còn sót trong `WordPressContentReader` & `WooCommerceProductReader` (O2)

**Bằng chứng trên code hiện tại:**
- `WordPressContentReader::normalize()` (`120, 131–136`): trên nhánh `is_object($raw)` vẫn ép `(int) $raw->ID` (biến object có `$raw->ID = '7abc'` thành `7` vượt qua `isExactPositiveId()`!), gọi `get_permalink`/`get_the_title` trước khi xác nhận ID khớp `$postId` và chưa có permalink loader seam; trên nhánh `is_array($raw)` chưa lọc allowlist khóa của `SeoContext` và `dropNulls()` chỉ lọc 1 cấp.
- `WooCommerceProductReader`: nhánh `is_array($raw)` chưa lọc allowlist khóa cho `SeoContext`; `dropNulls()` chưa đệ quy vào `seo` / `images` nếu mảng con chứa `null`.
- Cả hai reader và `SeoModule::register()` chưa nối optional `RankMathReadAdapter` + `RankMathSourceObserver` theo bảng O2.

**Hướng sửa cụ thể:**
- Trong `WordPressContentReader`: kiểm tra `$raw->ID` trên nhánh object bằng chính `$this->isExactPositiveId($raw->ID)` trước khi ép sang `int`; chỉ gọi permalink loader (injectable optional `$permalinkLoader`, fallback `get_permalink`) **sau khi** đã xác nhận ID khớp `$postId` và `post_type` thuộc `['post', 'page']`; lọc allowlist khóa trước khi gọi `SeoContext::fromArray()` và loại bỏ `null` đệ quy.
- Trong cả hai reader (và nối trong `SeoModule::register()` sau khi `RankMathModule::register()` chạy, hoặc lấy lazy từ `$this->container` khi `RankMathModule` đăng ký sau `SeoModule` — **lưu ý thứ tự trong `Plugin`: `SeoModule` đứng thứ 3, `RankMathModule` đứng thứ 4, nên `SeoModule` nên truyền closure/container lookup lazy hoặc nối sau khi đăng ký để lấy được `RankMathReadAdapter` & `RankMathSourceObserver`!**):
  - Khi đọc đối tượng có ID dương hợp lệ và có `RankMathReadAdapter` + `RankMathSourceObserver`, gọi adapter đọc `post:{id}`; **chỉ** những field trả `state === 'value'` và chuỗi không rỗng mới đặt vào `seo.{title|meta_description|canonical}` kèm provenance `'rank_math'` tương ứng trong `ProviderPayloadBuilder::build()`. Với default zero-rule hiện tại, adapter trả `unsupported` cho cả 3 field -> giữ nguyên provenance `'wordpress'` / `'woocommerce'` và **0 lần gọi post meta API**.

---

### E5-3 — Major: Hoàn tất `WordPressRankMathProbe` & `WordPressRankMathSourceObserver` (O2/O3)

**Bằng chứng:** Hai file `src/Integrations/RankMath/WordPressRankMathProbe.php` và `WordPressRankMathSourceObserver.php` chưa được cập nhật trong E-r4.

**Hướng sửa cụ thể:**
1. **`WordPressRankMathProbe`:**
   - Thêm optional platform reader vào `__construct(?callable $optionReader = null, ?callable $pluginsReader = null, ?callable $versionReader = null)` (mặc định `null` dùng `get_option`, `get_plugins`, `defined('RANK_MATH_VERSION')`).
   - `observeInstallation()`: **chỉ** kiểm tra từ nguồn plugin inventory (`$this->pluginsReader` hoặc `function_exists('get_plugins') ? get_plugins() : null`). Nếu danh sách trả về là mảng: có `'seo-by-rank-math/rank-math.php'` -> `signal('installed', 'plugin_metadata')`; không có -> `signal('absent', 'plugin_metadata')`. Nếu nguồn inventory không khả dụng (`null`) -> trả `unknown('installation_metadata_unavailable')`. **Xóa hoàn toàn dòng suy `installed` từ `$this->activePlugins()`** để bảo toàn phát hiện xung đột `active_installation_metadata_missing`.
   - `observeActivation()`: nếu không có nguồn đọc option (`!$this->hasOptionReader()`) -> trả `state = 'unknown'`, `evidence_state = 'unobserved'`. Nếu đọc được option `active_plugins` nhưng giá trị **không phải `array`** hoặc có phần tử không phải `string` -> trả `state = 'unknown'`, `evidence_state = 'invalid'`. Nếu là mảng chuỗi hợp lệ -> trả `active` hoặc `inactive` (`evidence_state = 'observed'`).
2. **`WordPressRankMathSourceObserver` & `RankMathModule`:**
   - Thêm optional `?callable $metaExists = null`, `?callable $metaReader = null`, `?callable $capabilityChecker = null` vào `WordPressRankMathSourceObserver::__construct(array $allowedSlots = [], ...)`.
   - Trong `observe(string $sourceId, string $slotKey)`:
     1. Kiểm `$postId > 0`, `$slotKey !== ''` và `in_array($slotKey, $this->allowedSlots, true)` -> nếu không đạt, trả `unreadable('unmapped_source')` ngay (**0 lần** chạm capability/meta API).
     2. Kiểm quyền đọc đối tượng: nếu có `$this->capabilityChecker` thì yêu cầu `($this->capabilityChecker)('edit_post', $postId) === true`; nếu chạy runtime WP mặc định mà có `function_exists('current_user_can')` thì yêu cầu `current_user_can('edit_post', $postId)` (nếu không đạt -> trả `unreadable('unauthorized_source')`, **0 lần** chạm meta API).
     3. Kiểm sự tồn tại của cổng `metaExists` + `metaReader` (hoặc `function_exists('metadata_exists') && function_exists('get_post_meta')`) -> nếu thiếu, trả `unreadable('wordpress_metadata_unavailable')` (`can_confirm_presence = false`, `provenance = 'synthetic'`).
     4. Khi đầy đủ mới gọi `metaExists` / `metaReader` và gắn `'provenance' => 'runtime_verified'`.
   - Trong `RankMathModule::register()`: lấy danh sách slot được phép từ `$catalog` (với `shippedDefault()` là `[]`) truyền vào `new WordPressRankMathSourceObserver(...)`.

---

### E5-4 — Blocker: Cập nhật `RuntimeCompositionTest` & bổ sung đầy đủ ma trận test O1–O4 (O4)

1. **Sửa ngay test cũ đang đỏ trong `tests/Unit/RuntimeCompositionTest.php`:**
   - Ở `testHookDispatcherDeduplicatesNamedListeners()`: truyền spy forwarder vào `new HookDispatcher(static function (): void {})` cho ca dedup dương, và thêm assertion cho `new HookDispatcher()` mặc định không có forwarder trả `false` + `registrations() === []` + ném `\InvalidArgumentException` với `\Closure`.
   - Bỏ khóa `'parent_exists'` ở `testWooCommerceReaderPreservesDecimalAndFailsClosedForParent()` (dùng loader trả `null` cho parent `20`).
2. **Đóng đầy đủ 4 nhóm kiểm thử bắt buộc (trong `IncrementER2SafetyTest.php` hoặc file test E chuyên biệt):**
   - **Nhóm 1 — Object-backed Readers (4 loại resource):** fake `WP_Post` object (`post` có `post_name`/slug + thuộc tính lạ, và `page`), fake `WC_Product` object (`get_type() === 'simple'`) và fake `WC_Product_Variation` object (`get_type() === 'variation'`, `get_sku() === null`, `WC_Product_Attribute`-like object, parent `'variable'` hợp lệ tra qua cùng loader đúng **1 lần**): cả 4 đạt `status === 'ok'` và qua `ProviderPayloadBuilder::validatePayload($result['provider_payload'])`. Kèm đủ ca biên: `unavailable`, `not_found`, ID bẩn `'7abc'`/`'9abc'`/`<= 0`/lệch ID (0 lần gọi permalink/currency helper), variation tự tham chiếu/parent mất/parent là variation, giá `''` vs `'-5'`, thiếu currency, stock `0` vs unmanaged, PII bị từ chối ở payload (`payload_status = 'rejected'`, `payload_reason = 'privacy_policy'`) nhưng giữ `findings`, và không mutate object nguồn.
   - **Nhóm 2 — Boot Idempotency & Callbacks:** qua spy forwarder truyền vào `HookDispatcher`, gọi `boot()` lặp trên 1 `Plugin` và trên **2 instance `Plugin`** dùng chung dispatcher -> mỗi listener định danh chỉ forward **1 lần** (`add_meta_boxes` có `acceptedArgs = 2`); kích hoạt thật callback `registerDiagnosticsSurface` đã đăng ký cho ca từ chối quyền (`edit_post` = false -> 0 lần đọc) và ca cho phép (`('post', $wpPost)` -> gọi content reader, `('product', $wcPost)` -> gọi product reader, output buffer `=== ''`); kích hoạt `onWooCommerceInit()` đặt `lifecycle_observed === true`.
   - **Nhóm 3 — Health Check, Coexistence `blocked`, Capability Gate & Escape:** inject `OwnershipRegistry` / snapshot có conflict `duplicate_risk = 'blocked'` -> cả `Plugin::health()['coexistence']` và HTML của `AdminModule::renderPage()` đều chứa `duplicate_risk=blocked`; khi `capabilityChecker` của `AdminModule` trả `false` -> `healthProvider` và `environment` gọi **0 lần**, HTML rỗng `''`; khi trả `true` -> `environment->evaluate()` không bị gọi thừa ngoài `healthProvider`, hiển thị đúng `inactive` và `mbstring` fallback; bơm sentinel secret/path/`<script>` vào cả trường lạ lẫn trường đúng tên sai giá trị (`owner`, `reason`, `version`, `message`) -> không xuất hiện trong JSON/HTML; test riêng `escapeHtml()` fallback mã hóa `<script>"'&`.
   - **Nhóm 4 — Rank Math Probe/Observer & Negative Spies:** test `WordPressRankMathProbe` qua bridge cho `unobserved`, `absent`, `installed-inactive`, `active` (`compatibility = 'unknown'`), conflict active-nhưng-absent (`active_installation_metadata_missing`), và `active_plugins` sai kiểu (`invalid`); test `WordPressRankMathSourceObserver` với spy meta reader xác nhận **0 meta calls** khi zero-rule, ngoài slot, ID sai hoặc thiếu quyền, và test synthetic catalog nối qua reader ghi override `rank_math` khi `state = 'value'`; assert cứng qua spies được inject thật vào luồng chạy: **0 frontend hooks, 0 writes, 0 network/DNS** trong `new Plugin()`, `boot()`, `health()`, `renderPage()`, callbacks và readers.

---

### E5-5 — Blocker: Commit Snapshot sạch TRƯỚC khi chạy `run_check` & ghi báo cáo `docs/giai-doan-2-Increment-E-r5-report.md` (O4)

*(Nhắc lại nguyên nhân khiến mọi lượt chạy `run_check` ở r3 và r4 đều báo `"Revision reported: 2f5e327..."`: công cụ `run_check` của Forge chạy kiểm thử trên **commit HEAD đã khóa**, KHÔNG chạy trên thay đổi unstaged ở working tree!)*

Vì vậy SE **bắt buộc** thực hiện đúng trình tự 4 bước sau:
1. Hoàn tất toàn bộ code và test của `E5-1` → `E5-4` trong đúng ranh giới file của Increment E (không sửa logic contract lõi đã nghiệm thu của A–D).
2. **Commit toàn bộ thay đổi `src/` và `tests/` thuộc Increment E thành một commit snapshot sạch `<E_R5_SNAPSHOT_SHA>` TRƯỚC TIÊN** (không stage `.phpunit.result.cache` và không trộn các file nháp ngoài phạm vi; kiểm tra `git status` của `src/` và `tests/` sạch 100%).
3. Ngay trên HEAD `<E_R5_SNAPSHOT_SHA>`, gọi `run_check` cho:
   - Từng file test focused thuộc Increment E (`FoundationTest.php`, `OwnershipRegistryCoexistenceTest.php`, `RuntimeCompositionTest.php`, `RuntimeReaderSmokeTest.php`, `RankMathRuntimeSmokeTest.php`, `IncrementER2SafetyTest.php` / file test E mới);
   - Toàn bộ unit suite: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`.
   - *(Nếu có bất kỳ lệnh nào khác `exit 0`, sửa lỗi, tạo commit snapshot mới rồi chạy lại `run_check` trên commit mới đó cho tới khi toàn bộ đạt `exit 0` và `Revision reported` khớp chính xác `<E_R5_SNAPSHOT_SHA>`.)*
4. Sau khi tất cả lệnh đạt `exit 0` trên `<E_R5_SNAPSHOT_SHA>`, viết báo cáo bàn giao vào `docs/giai-doan-2-Increment-E-r5-report.md` và commit riêng file báo cáo (không chạm lại `src/` hoặc `tests/`), gồm đầy đủ: `base_sha`, `implementation_snapshot_sha`, mốc D (`5aa3332133f203f41a8701e83b213c246cf491da`), bảng đối chiếu `E5-1` → `E5-5`, bảng `VERIFY-RUN-*` đầy đủ (Run ID, full command, scope, revision chạy thực tế, status, exit code), SHA-256 của toàn bộ file `src/` và `tests/` thuộc Increment E, và mục giới hạn rõ ràng (xác minh offline không chứng minh tương thích production WordPress/WooCommerce/Rank Math, default Rank Math vẫn zero-rule, báo cáo không tự đóng gate C/D/E hay mở F–H).
