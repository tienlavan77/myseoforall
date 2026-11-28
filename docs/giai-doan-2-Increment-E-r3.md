# Increment E r3 — Kết quả rà soát E-r2 và hướng dẫn khắc phục cho SE

Đối chiếu [báo cáo E-r2](giai-doan-2-Increment-e-r2-report.md) (`sha256:3b909b0ddf08acb9e69718d4edad5885bcbde83ced4d488931770fb23165cffe`) với [hướng dẫn E](giai-doan-2-Increment-E.md) (`sha256:e4e3bae856605f08a8c1f2b0829b17ea439de71a2f063dae7982cf6ae3c7b2ac`), [hướng dẫn E-r2](giai-doan-2-Increment-E-r2.md) (`sha256:cce378ac3dc6777394e3d7a23b89b4928b7e8f0c586fba701de3ee034d18c0aa`), snapshot `a31cc2bd7568702a8c05fc8d9017efd72a91a159` và working tree hiện tại trên HEAD `2f5e32765b7d26ad357db0741fa4561f862524a7`. Đây là hướng dẫn kiến trúc để xử lý findings trong phạm vi Increment E, không phải Sprint Plan, manifest sửa file hay quyền triển khai.

**Kết luận: Chưa nghiệm thu Increment E-r2.** Chính báo cáo E-r2 đã tự xác nhận bằng chứng hiện có chưa đủ để nghiệm thu và đang để hai file sửa dở trên working tree sau snapshot. Lượt rà soát này đọc trực tiếp source, test và diff (không tự chạy PHPUnit); gate C/D/E tiếp tục tuân thủ mục 1.1 của hướng dẫn E.

---

## 1. Các điểm E-r2 đã sửa đúng hướng (giữ nguyên)

- `EnvironmentCheck::isReady()` đã kiểm tra tường minh `compatible === true` thay vì ép chuỗi `'unknown'` sang boolean.
- `EnvironmentCheck::normalizeProbe()`, `Plugin::safeEnvironment()` và `Plugin::safeConfiguration()` đã chuyển sang chiếu theo allowlist trường và kiểu/pattern thay cho blacklist chuỗi.
- `AdminModule::escapeHtml()` đã dùng `esc_html()` khi có WordPress và fallback sang `htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- `RankMathModule::health()` đã đọc đúng khóa `compatibility` của detector và tách `available` của service với `dependency_ready`; `WordPressRankMathSourceObserver` mặc định rỗng slot và không gắn `'runtime_verified'` cho nhánh `unreadable()`.

---

## 2. Các phát hiện cần SE xử lý dứt điểm trong E-r3 (`E3-1` → `E3-6`)

### E3-1 — Blocker: Working tree bẩn sau snapshot và chưa có biên nhận verification hợp lệ (O4)

**Bằng chứng:**
- Trên HEAD `2f5e32765b7d26ad357db0741fa4561f862524a7`, `git_status` / `git_diff` đang có hai thay đổi chưa commit tại `src/SEO/Runtime/WooCommerceProductReader.php` (`sha256:204f41c8b0058a18cec51acb23d58d1249ec03d707876f1835cf76e8b31ff0c9`, lệch với hash `634a4eec...` của commit `a31cc2bd...`) và `tests/Unit/RuntimeReaderSmokeTest.php` (`sha256:2ac377275d2971c546c8509be07213d6c03c7dca70f9cab9a528847a864af110`).
- Báo cáo E-r2 xác nhận lời gọi verification sau sửa đổi không giữ command, run ID hay exit code, còn các `VERIFY-RUN-*` trong bảng thuộc snapshot cũ `a31cc2bd...`.

**Hướng sửa và tiêu chí đạt:**
- Sau khi hoàn tất `E3-2` → `E3-6`, khóa **một commit snapshot source/test duy nhất** `<E_R3_SNAPSHOT_SHA>` sạch 100% ở `src/` và `tests/` (không stage `.phpunit.result.cache` hay tài liệu rời).
- Chạy lại toàn bộ focused tests O1–O4 và full `tests/Unit` trực tiếp trên đúng `<E_R3_SNAPSHOT_SHA>`, sau đó mới commit báo cáo `docs/giai-doan-2-Increment-e-r3-report.md` riêng.

---

### E3-2 — Blocker: Health Check lệch enum coexistence (`blocked`) và chưa khóa kín miền giá trị công khai (O3)

**Bằng chứng:**
1. **Sai enum `duplicate_risk` làm rơi trạng thái `blocked`:** `OwnershipRegistry::evaluateOutput()` (`src/Integrations/RankMath/OwnershipRegistry.php:125–167`) trả `state` thuộc `['owned', 'unknown', 'conflict']` và `duplicate_risk` thuộc `['none', 'possible', 'blocked']`. Thế nhưng `Plugin::safeCoexistence()` (`src/Core/Plugin.php:210–213`) và `AdminModule::renderPage()` (`src/Admin/AdminModule.php:190–191`) lại kiểm tra `['none', 'possible', 'high']` (và thêm `'excluded'` không tồn tại ở `state`). Hệ quả: mọi ca xung đột có `duplicate_risk = 'blocked'` đều bị hạ cấp âm thầm thành `'possible'`.
2. **Các trường chuỗi công khai và cờ module chưa đóng chặt:** `safeCoexistence()` (`209, 214`) chấp nhận chuỗi `owner` và `reason` bất kỳ bằng `is_string()`; `safeEnvironment()` (`142`) chuyển tiếp nguyên chuỗi `version` từ probe; `safeModule()` (`189–190`) vẫn cho phép `write_allowed`/`frontend_owned` thành `true` từ dữ liệu đầu vào module và làm rơi trường `dependency_ready` của `rank_math`.
3. **`AdminModule::renderPage()` gọi thừa `environment->evaluate()` và làm mất trạng thái chi tiết:** Ở `src/Admin/AdminModule.php:152–171`, `renderPage()` luôn gọi `$this->environment->evaluate()` trước cả khi đọc `$this->healthProvider`, rồi tự dựng lại thông điệp chỉ từ `compatible` (`true | false | 'unknown'`), làm mất nhãn `installed` nhưng `inactive` và trạng thái `mbstring` fallback. Ngoài ra `EnvironmentCheck::isReady()` (`163–168`) chỉ kiểm `compatible === true` mà chưa đòi hỏi `installation === 'installed'` và `activation === 'active'` (nên tín hiệu mâu thuẫn như `absent + compatible=true` vẫn qua `isReady()`).

**Hướng sửa và tiêu chí đạt:**
- Khớp chính xác enum đã nghiệm thu của Increment D trong `Plugin::safeCoexistence()` và `AdminModule::renderPage()` (`blocked` nghĩa là quyết định bị policy chặn, không diễn giải thành đã chặn frontend output):
  - `state`: `['owned', 'unknown', 'conflict']` (mặc định `'unknown'`).
  - `duplicate_risk`: `['none', 'possible', 'blocked']` (mặc định `'possible'`).
  - `owner`: chỉ nhận `'unknown'` hoặc canonical producer slug thuộc allowlist đã biết (`myseo_for_all`, `rank_math`, `yoast_seo`, `aioseo`, `seopress`, `the_seo_framework`, ... hoặc allowlist cố định không phản chiếu slug tùy ý của caller); ngoài miền thì ép về `'unknown'`.
  - `reason`: chỉ nhận mã thuộc tập đóng của `OwnershipRegistry` (`identity_collision`, `assertion_capability_conflict`, `conflicting_evidence`, `overlapping_claims`, `split_ownership`, `partial_ownership`, `no_valid_claim`, `other_emitters_excluded`, `other_emitters_not_excluded`, `invalid_context_scope`, `unavailable`).
- Trong `safeEnvironment()` / `normalizeProbe()`: xác thực `version` theo định dạng phiên bản an toàn (ví dụ `^[0-9][A-Za-z0-9._+-]{0,31}$`), nếu sai thì đặt `null`. Trong `safeModule()`: khóa cứng `write_allowed => false`, `frontend_owned => false` cho toàn bộ Increment E và giữ `dependency_ready` (`bool`) cho `rank_math`. Trong `EnvironmentCheck::isReady()`: chỉ trả `true` khi cả `php` và `wordpress` đều có `installation === 'installed'`, `activation === 'active'` và `compatible === true`.
- Trong `AdminModule::renderPage()`: kiểm quyền `manage_options` qua cổng capability inject được (mặc định `function_exists('current_user_can') && current_user_can('manage_options')`; thiếu quyền return ngay trước khi gọi bất kỳ provider nào); khi có `$this->healthProvider` thì không gọi lặp `$this->environment->evaluate()`; hiển thị đầy đủ trạng thái environment (gồm `inactive` và `mbstring` fallback), 4 modules (gồm `compatibility`/`dependency_ready` của `rank_math`) và `coexistence` (`owner`, `state`, `duplicate_risk` gồm cả `'blocked'`).

---

### E3-3 — Blocker: Callback `add_meta_boxes` sai chữ ký WordPress, thiếu kiểm quyền đối tượng & thiếu seam `HookDispatcher` (O1/O2/O4)

**Bằng chứng:**
1. **Sai chữ ký callback WordPress `add_meta_boxes`:** WordPress gọi `do_action('add_meta_boxes', string $post_type, WP_Post $post)`. Hiện tại `SeoModule::boot()` (`src/SEO/Runtime/SeoModule.php:72`) đăng ký `$acceptedArgs = 1` mặc định, còn `registerDiagnosticsSurface($postId = 0)` (`106–113`) kiểm tra `is_int($postId)` -> khi WordPress truyền `('post', $wpPost)`, `$postId` là chuỗi `'post'`, bị ép về `0` và `return` ngay lập tức (listener không bao giờ chạy trên đường thật).
2. **Thiếu kiểm quyền đối tượng & phân nhánh Product/Content:** `registerDiagnosticsSurface` chưa kiểm tra quyền trên đúng đối tượng (`current_user_can('edit_post', $id)`) trước khi đọc, và chỉ gọi `ContentSeoInspector` mà chưa định tuyến `'product'` / `'product_variation'` sang `ProductSeoInspector` khi WooCommerce sẵn sàng.
3. **Thiếu seam ở `HookDispatcher` & ghi nhận đăng ký giả:** `HookDispatcher` (`src/Core/HookDispatcher.php:11, 44–64, 90–92`) chưa có injectable hook sink/bridge và các module gọi cứng `function_exists('add_action')`, khiến test CLI không thể chạy luồng `Plugin::boot()` -> đăng ký hook -> kích hoạt callback thật qua spy. Đồng thời `HookDispatcher::addAction()` vẫn chấp nhận `\Closure` và ghi vào `$this->registrations` trả `true` ngay cả khi không có WordPress `add_action` lẫn custom sink.

**Hướng sửa và tiêu chí đạt:**
- Bổ sung contract/seam cho `HookDispatcher` (interface hoặc optional forwarder/platform sink truyền vào `HookDispatcher` giữ tương thích ngược):
  - Từ chối `\Closure` vô danh (ném `\InvalidArgumentException` theo O1).
  - Khi dùng runtime mặc định mà thiếu `function_exists('add_action')` (không có custom forwarder/spy), dispatcher báo không khả dụng và `addAction()` trả `false`, **không** ghi nhận đăng ký runtime giả; khi inject spy forwarder trong test, các module nhận biết hook bridge khả dụng và đi trọn luồng `Plugin::boot()` mà không cần tạo hàm global giả.
- Sửa `SeoModule::boot()` đăng ký `addAction('add_meta_boxes', [$this, 'registerDiagnosticsSurface'], 10, 2)` và sửa `registerDiagnosticsSurface($postType = '', $post = null)`:
  - Xác thực `$postType` là chuỗi hợp lệ và trích xuất ID nguyên dương từ `$post` (kiểm tra `post_type` trên `$post` khớp `$postType` nếu có), kiểm tra quyền trên đối tượng qua cổng capability inject được (mặc định `current_user_can('edit_post', $id)` — thiếu cổng quyền hoặc trả `false` thì dừng ngay trước khi gọi reader).
  - Định tuyến `'post' | 'page'` -> `ContentSeoInspector::inspect($id)`; `'product' | 'product_variation'` (khi WooCommerce khả dụng) -> `ProductSeoInspector::inspect($id)`; các loại khác bỏ qua an toàn, không in dữ liệu ra HTML/output buffer.

---

### E3-4 — Blocker: Reader runtime chưa xác thực identity trước khi đọc phụ trợ, bỏ qua loader ở parent lookup & hở nested variation (O2)

**Bằng chứng:**
1. **Kiểm tra ID muộn / ép kiểu lỏng:** Trong `WordPressContentReader::readPost()` (`59–62`), nếu `$source` thiếu key `'id'` thì điều kiện `isset($source['id']) && (int) $source['id'] !== $postId` bị bỏ qua; trong `normalize()` (`121–126`), `get_permalink` / `get_the_title` được gọi trước cả khi xác nhận ID đối tượng khớp `$postId`, và ép `(int)` trên giá trị bẩn như `'7abc'` có thể tạo khớp giả. Tương tự ở `WooCommerceProductReader::normalize()`, toàn bộ getter/currency/permalink bị gọi trước khi kiểm tra ID dương khớp `$productId`.
2. **`WooCommerceProductReader::parentExists()` bỏ qua `$this->loader` và xác thực parent sơ sài:** Ở `src/SEO/Runtime/WooCommerceProductReader.php:307–320`, khi đọc object `WC_Product_Variation` qua `$this->loader` (không có cờ mảng `'parent_exists'` và không có global `wc_get_product`), `parentExists()` trả `false` làm mọi variation object-backed bị đánh rớt thành `parent_product_missing`! Ngược lại khi có `wc_get_product($parentId)`, hàm chỉ kiểm `!== false && !== null` mà không xác nhận parent có ID nguyên dương đúng bằng `$parentId` và có loại sản phẩm hợp lệ (không phải `'variation'` hay đối tượng hỏng).
3. **Nested `variation` chứa `null` / raw attribute objects làm hỏng payload:** Trong `WooCommerceProductReader::normalize()` (`157–164`), `$source['variation']` gán `'sku' => $source['sku']` và `'attributes' => $source['attributes']` nguyên dạng (có thể là `null`, `''`, hoặc mảng `WC_Product_Attribute` objects chưa qua `$this->attributes()`), trong khi `dropNulls()` (`327–335`) chỉ xóa `null` ở cấp ngoài cùng. Hệ quả: variation object thật không có SKU riêng hoặc có attribute objects sẽ làm `ProviderPayloadBuilder::build()` ném `InvalidArgumentException`! Ngoài ra `attributes()` (`285–299`) hiện chưa hỗ trợ đọc object kiểu `WC_Product_Attribute` (`get_name()`, `get_options()`), và nhánh mảng của `WordPressContentReader::normalize()` (`106–108`) chưa lọc allowlist cho `SeoContext`.

**Hướng sửa và tiêu chí đạt:**
- Đưa các lời gọi đọc nền tảng (`get_post`, `get_permalink`, `wc_get_product`, `get_woocommerce_currency`) qua reader contract / platform bridge inject được.
- Xác thực ID nguyên dương nghiêm ngặt (không ép chuỗi bẩn kiểu `'7abc'` thành `7`) và khớp đúng ID được yêu cầu ngay khi đọc đối tượng nguồn, trước khi gọi permalink/helper phụ trợ hay tra parent.
- Trong `WooCommerceProductReader`:
  - Xác nhận parent của variation qua **cùng cổng product loader** đúng **1 lần** (khi `$parentId > 0 && $parentId !== $sourceId`), kiểm tra parent tồn tại, có ID đúng bằng `$parentId` và loại parent hợp lệ (ví dụ `'variable'`/`'product'`, tuyệt đối không chấp nhận một `'variation'` khác làm parent); bỏ lối tắt cờ `'parent_exists'` khỏi đường kiểm thử chính thức.
  - Chuẩn hóa `variation` theo schema đóng `{id, parent_id, optional sku (non-empty string), optional attributes}` (đã chuẩn hóa qua `$this->attributes()` hỗ trợ cả mảng lẫn object có `get_name()`/`get_options()`), loại bỏ optional `null`/chuỗi SKU rỗng ở mọi cấp lồng nhau (`variation`, `offer`, `seo`, `images`, `attributes`).
- Nối tùy chọn `RankMathReadAdapter` + `RankMathSourceObserver` vào đường đọc theo bảng O2: chỉ các field adapter trả `state === 'value'` mới ghi vào `seo.{title|meta_description|canonical}` với provenance `'rank_math'`; dưới default zero-rule (`unsupported`), giữ nguyên provenance `'wordpress'` / `'woocommerce'` và 0 lần đọc post meta.

---

### E3-5 — Major: Tách độc lập nguồn bằng chứng cài đặt Rank Math và kiểm quyền trước khi đọc meta (O2/O3)

**Bằng chứng:**
1. **`WordPressRankMathProbe::observeInstallation()` vẫn suy `installed` từ `active_plugins`:** Ở `src/Integrations/RankMath/WordPressRankMathProbe.php:22–28`, nếu `activePlugins()` trả `null` thì hàm bỏ qua luôn `get_plugins()`; nếu `active_plugins` chứa Rank Math thì trả ngay `installed` (`signal_source = 'active_plugins'`) trước khi kiểm tra `get_plugins()`! Việc này che mất hoàn toàn tình huống mâu thuẫn `active_installation_metadata_missing` (plugin còn trong `active_plugins` nhưng thư mục plugin đã bị xóa / `get_plugins()` báo `absent`). Đồng thời `activePlugins()` (`108–122`) gộp chung thiếu `get_option` với option sai kiểu thành `null` (`evidence_state = 'unobserved'` thay vì `'invalid'`).
2. **Thiếu platform seam & cổng kiểm quyền trên `WordPressRankMathSourceObserver`:** Cả `WordPressRankMathProbe` lẫn `WordPressRankMathSourceObserver` gọi cứng hàm global WordPress nên chưa test được ma trận trạng thái hoặc đếm số lần gọi meta API qua spy; `RankMathModule::register()` (`52`) tạo `WordPressRankMathSourceObserver` tách rời khỏi `$catalog`; và `WordPressRankMathSourceObserver::observe()` (`41–52`) chưa kiểm tra quyền đọc đối tượng (`edit_post` / capability gate) khi có slot hợp lệ.
3. **`EnvironmentCheck` mặc định ngoài WordPress runtime:** Dòng `63–65` của `EnvironmentCheck.php` đang gán `'installation' => 'absent', 'activation' => 'inactive', 'compatible' => false` cho `wordpress` khi `!function_exists('get_bloginfo')` thay vì tri-state `'unknown'` (vẫn giữ khóa legacy `active => false` để tương thích `FoundationTest` và `isReady() === false`).

**Hướng sửa và tiêu chí đạt:**
- Cho phép truyền optional read-only platform bridge vào `WordPressRankMathProbe` và `WordPressRankMathSourceObserver`:
  - `observeInstallation()` đọc độc lập từ nguồn inventory cài đặt (`get_plugins()` hoặc kiểm tra plugin inventory chỉ đọc qua bridge): có mặt -> `'installed'`; inventory xác nhận không có -> `'absent'` (để `RankMathDetector` giữ nguyên conflict khi `observeActivation()` báo `'active'`); không có nguồn inventory -> `'unknown'` (`installation_metadata_unavailable`), **không suy `installed` từ `active_plugins`**.
  - `observeActivation()` phân biệt thiếu API (`state = 'unknown'`, `evidence_state = 'unobserved'`) với option `active_plugins` sai kiểu/chứa phần tử phi chuỗi (`state = 'unknown'`, `evidence_state = 'invalid'`).
  - `WordPressRankMathSourceObserver` lấy danh sách slot từ mapping catalog được phê duyệt (mặc định `shippedDefault()` là zero-rule -> 0 slot), kiểm tra ID dương + slot được phép + quyền trên đối tượng qua capability gate **trước khi** chạm vào cổng đọc meta; thiếu `metadata_exists` hoặc không xác nhận được presence thì trả `unreadable` (`can_confirm_presence = false`, không gắn `'runtime_verified'`).

---

### E3-6 — Blocker: Đóng đầy đủ ma trận kiểm thử bắt buộc O1–O4 trước khi khóa snapshot (O4)

Bổ sung đầy đủ các bài test hành vi qua seams/spies thực sự được hệ thống gọi (không chỉ test mảng tĩnh hoặc gọi rời rạc):

1. **Object-backed Readers cho đủ 4 loại resource (`post`, `page`, `simple product`, `variation`):**
   - Dùng fake `WP_Post` object (có `ID`, `post_type` là `'post'` và `'page'`, `post_title`, `post_content`, `post_excerpt`, `post_name`/slug, kèm thuộc tính lạ) và fake `WC_Product` / `WC_Product_Variation` object (`get_type()` là `'simple'` và `'variation'`, `WC_Product_Attribute`-like object, `get_sku()` trả `null`/`''` trên variation, parent hợp lệ tra qua cùng product loader đúng **1 lần**): cả 4 trả `status === 'ok'`, có `seo_context`, `findings`, và qua `ProviderPayloadBuilder::validatePayload($result['provider_payload'])`.
   - Ca biên bắt buộc: thiếu API (`unavailable`), `not_found`, ID thiếu/sai kiểu/`<= 0`/lệch ID (`invalid_source`, 0 lần gọi helper phụ trợ), variation tự tham chiếu `id === parent_id` hoặc parent thiếu / parent cũng là `variation` (`parent_product_missing`, không dùng cờ `parent_exists`), giá trống `''` (missing) vs giá sai `'-5'` (`product_fields_invalid` + `payload_status = 'rejected'`), thiếu currency (không dựng `offer`), `manage_stock = true` + `stock_quantity = 0` (giữ `0`) vs `manage_stock = false` (bỏ `stock`), nội dung có PII/secret bị chặn ở `provider_payload === null` (`payload_status = 'rejected'`, `payload_reason = 'privacy_policy'`) nhưng giữ `findings` cục bộ, và object nguồn không bị mutate.
2. **Lifecycle, Hook Forwarding & Admin Diagnostics Callback:**
   - Qua `SpyHookDispatcher` được truyền vào `Plugin`: gọi `boot()` lặp trên 1 instance và trên **2 instance `Plugin`** dùng chung dispatcher chỉ forward mỗi listener định danh đúng **1 lần** (`add_meta_boxes` forward đủ `acceptedArgs = 2`); truyền `\Closure` bị từ chối; thiếu hook API mặc định không ghi nhận đăng ký giả.
   - Kích hoạt chính callback `SeoModule::registerDiagnosticsSurface` đã đăng ký với `('post', $wpPost)` và `('product', $wcPost)`: khi thiếu quyền `edit_post` -> 0 lần gọi reader; khi đủ quyền -> gọi đúng content/product inspector tương ứng và output buffer rỗng `''`; kích hoạt `WooCommerceModule::onWooCommerceInit()` cập nhật `lifecycle_observed === true` mà 0 lần đọc/ghi sản phẩm.
3. **Health Check, Coexistence `blocked`, Admin Capability Gate & Escape Fallback:**
   - Test `Plugin::health()` và `AdminModule::renderPage()` khi `OwnershipRegistry` có conflict trả `duplicate_risk = 'blocked'`: cả DTO lẫn HTML đều giữ đúng `duplicate_risk=blocked`; kiểm `EnvironmentCheck::isReady()` trả `false` cả khi `unknown` lẫn khi tín hiệu installation/activation mâu thuẫn.
   - Test `AdminModule::renderPage()`: thiếu quyền `manage_options` -> 0 lần gọi `healthProvider`/`environment`, output rỗng `''`; đủ quyền và có `healthProvider` -> không gọi lặp `environment->evaluate()`, hiển thị đúng `inactive` và `mbstring` fallback; bơm sentinel secret/path/`<script>` vào cả trường lạ lẫn trường đúng tên nhưng sai giá trị (`owner`, `reason`, `version`, `message`) -> không xuất hiện trong JSON/HTML; test riêng fallback `escapeHtml()` khi không có `esc_html`.
4. **Rank Math Probe, Observer & Negative Side-Effect Spies:**
   - Test `WordPressRankMathProbe` qua platform bridge cho: ngoài WP (`unknown`/`unobserved`), `absent`, `installed-inactive`, `active` (`compatibility = 'unknown'` dưới zero-rule), mâu thuẫn `active` trong `active_plugins` nhưng `absent` trong inventory (`active_installation_metadata_missing`), và `active_plugins` hỏng kiểu (`evidence_state = 'invalid'`).
   - Test `WordPressRankMathSourceObserver` với spy meta reader: default zero-rule, slot ngoài mapping, ID sai hoặc thiếu quyền đối tượng đều gọi meta API **0 lần**; synthetic mapping trong fixture + đủ quyền phân biệt đúng `missing` vs `present` (`runtime_verified`), thiếu `metadata_exists` trả `unreadable` (`can_confirm_presence = false`, không gắn `runtime_verified`).
   - Assert cứng qua spies được inject thật vào luồng chạy: **0 frontend hooks** (`wp_head`, `document_title_parts`, `wpseo_*`, `rank_math/*`, sitemap), **0 writes** (`update_option`, `update_post_meta`, `wp_update_post`, product `save()`), **0 network/DNS calls** trong `new Plugin()`, `boot()`, `health()`, `renderPage()`, callbacks và readers.

---

## 3. Quy trình khóa Snapshot & Báo cáo bàn giao `docs/giai-doan-2-Increment-e-r3-report.md`

1. Chỉ sửa trong ranh giới file của Increment E; giữ nguyên contract lõi đã nghiệm thu của A–D (`LocalSeoAnalyzer`, `ProviderPayloadSchema`, `ProviderPayloadRules`, `AiOutputValidator`, `RankMathDetector`, `RankMathReadAdapter`, `OwnershipRegistry`).
2. Commit toàn bộ source và test của E-r3 thành **một snapshot sạch** `<E_R3_SNAPSHOT_SHA>` (working tree của `src/` và `tests/` sạch 100%, không stage `.phpunit.result.cache`).
3. Ngay trên `<E_R3_SNAPSHOT_SHA>`, chạy bằng `run_check` đầy đủ các lệnh focused cho từng file test thuộc phạm vi E và lệnh full suite `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`.
4. Sau khi tất cả lệnh pass exit `0` trên đúng `<E_R3_SNAPSHOT_SHA>`, ghi báo cáo `docs/giai-doan-2-Increment-e-r3-report.md` (commit riêng, không sửa lại `src/` hoặc `tests/`) gồm: `base_sha`, `implementation_snapshot_sha`, mốc kiến trúc D (`5aa3332133f203f41a8701e83b213c246cf491da`), bảng đối chiếu `E3-1` → `E3-6` với file/test tương ứng, bảng `VERIFY-RUN-*` đầy đủ (Run ID, full command, scope, revision chạy thực tế, exit code), toàn bộ SHA-256 của `src/` và `tests/` thuộc Increment E, và phần giới hạn (xác minh offline không chứng minh tương thích production WordPress/WooCommerce/Rank Math, default Rank Math vẫn zero-rule, báo cáo không tự đóng gate C/D/E hay mở F–H).
