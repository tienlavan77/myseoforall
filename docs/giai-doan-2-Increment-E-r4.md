# Increment E r4 — Kết quả rà soát E-r3 và hướng dẫn khắc phục cho SE

Đối chiếu [báo cáo E-r3](giai-doan-2-Increment-E-r3-report.md) (`sha256:22690d89d204c5b730d310c9e60f56b51e87f9546824d285aa4e48b5db9db8e4`) với [hướng dẫn E-r3](giai-doan-2-Increment-E-r3.md) (`sha256:d6cc963935b1f66261dd49ad939fb473fe49bb3b939323d985a93a5c64703606`), [hướng dẫn E](giai-doan-2-Increment-E.md) (`sha256:e4e3bae856605f08a8c1f2b0829b17ea439de71a2f063dae7982cf6ae3c7b2ac`) và diff trên HEAD `2f5e32765b7d26ad357db0741fa4561f862524a7`. Đây là hướng dẫn kiến trúc để xử lý dứt điểm findings trong phạm vi Increment E, không phải Sprint Plan, manifest sửa file hay quyền triển khai.

**Kết luận: Chưa nghiệm thu Increment E-r3.** Báo cáo E-r3 đã xác nhận mới sửa một phần `E3-4` trên working tree chưa commit, chưa xử lý `E3-1`, `E3-2`, `E3-3`, `E3-5`, `E3-6`, và lệnh `phpunit` hiện thất bại (exit `1`). Lượt rà soát này đọc trực tiếp source, test và diff (không tự chạy PHPUnit); gate C/D/E tiếp tục tuân thủ mục 1.1 của hướng dẫn E.

---

## 1. Ghi nhận tiến độ và nguyên nhân lỗi mới sinh trong bản vá E-r3

- **Điểm đã tiến bộ ở `E3-4`:** `WordPressContentReader::isExactPositiveId()` đã chặn ID thiếu/chuỗi bẩn; `WooCommerceProductReader` đã bổ sung `$currencyLoader`, hỗ trợ đọc `WC_Product_Attribute` object (`get_name()`/`get_options()`), và lọc `sku` rỗng/`null` bên trong `variation` của nhánh object.
- **4 lỗi mới / sót ngay trong chính bản vá `E3-4` (gây gãy runtime & làm đỏ PHPUnit exit `1`):**
  1. **Mất đường đọc parent trên WordPress/WooCommerce thật (`WooCommerceProductReader::parentExists`, dòng `340–345`):** Bản vá xóa hẳn nhánh fallback `function_exists('wc_get_product') ? wc_get_product($parentId) : null`! Khi chạy trong WordPress thật (`$this->loader === null` và `$this->parentLoader === null`), `$parent` luôn là `null` -> **100% variation sản phẩm thật trên production luôn bị báo lỗi `parent_product_missing`!**
  2. **Nguyên nhân `RuntimeReaderSmokeTest` / `RuntimeCompositionTest` bị exit `1` và cách hiểu đúng `run_check`:**
     - Trong `RuntimeReaderSmokeTest::testMalformedProductIdentifierIsRejected()` (`105–113`), sản phẩm `'id' => '9abc'` đi qua `(int) ($source['id'] ?? 0)` ở `WooCommerceProductReader.php:70` thành `$sourceId = 9`! Do `9 === 9`, reader **không trả `invalid_product_id` mà trả `'ok'`**, làm assertion dòng `112` fail ngay lập tức! `WooCommerceProductReader` vẫn dùng ép kiểu `(int)` thay vì kiểm tra `isExactPositiveId()` như bên `WordPressContentReader`.
     - Trong `RuntimeCompositionTest::testWooCommerceReaderPreservesDecimalAndFailsClosedForParent()` (`126–135`), loader giả trả `'resource_type' => 'variation'` cho mọi `$id` (kể cả khi `parentExists(20)` gọi lại `$this->loader`); nhánh đó vô tình pass vì parent trả về là `'variation'`, nhưng bất kỳ fixture mảng nào truyền `variation` có `'sku' => null` hoặc `'sku' => ''` ở nhánh `is_array($raw)` (`110–116`) vẫn giữ nguyên nested null vì `dropNulls()` chỉ chạy ở nhánh object (`162–170`)!
     - **Về hành vi `run_check` trên Forge:** `run_check` kiểm tra trạng thái repo/commit theo quy trình snapshot. Vì SE chưa commit thay đổi hoặc để assertion lỗi như dòng `112` ở trên, kết quả tất yếu fail/stale. SE phải sửa sạch lỗi logic trước, khóa commit snapshot code/test sạch, rồi mới chạy `run_check` trên đúng commit đó.
  3. **Gọi helper phụ trước khi xác thực ID nguồn:** Trong `WordPressContentReader::normalize()` (`120, 131–136`), `(int) $raw->ID` vẫn ép chuỗi bẩn `'7abc'` thành `7` trên object và gọi `get_permalink()`/`get_the_title()` **trước khi** `readPost()` kiểm tra ID khớp. Trong `WooCommerceProductReader::normalize()` (`136–157`), toàn bộ getter/currency/permalink vẫn được gọi trước khi kiểm tra ID hợp lệ và khớp `$productId`.
  4. **Nhánh mảng chưa lọc allowlist & chưa nối `RankMathReadAdapter`:** Nhánh `is_array($raw)` của cả hai reader vẫn giữ các khóa thừa vào `SeoContext`, và cả hai reader chưa nhận tùy chọn `RankMathReadAdapter` + `RankMathSourceObserver` theo bảng O2.

---

## 2. Danh mục đầy đủ SE phải hoàn tất trong E-r4 (`E4-1` → `E4-6`)

### E4-1 — Blocker: Sửa dứt điểm `WordPressContentReader` & `WooCommerceProductReader` (O2)

- **Xác thực identity nghiêm ngặt trước mọi lệnh đọc phụ:**
  - Dùng chung quy tắc `isExactPositiveId($value)` (chỉ nhận `int > 0` hoặc chuỗi chữ số nguyên dương khớp tuyệt đối không có ký tự thừa, không ép `'9abc'` thành `9`) trên **cả hai reader** và trên **cả nhánh object lẫn nhánh array**.
  - Kiểm tra ID hợp lệ và `=== $requestedId` (cùng `post_type` / `product type` được hỗ trợ) **trước khi** gọi permalink/currency/title helpers hoặc tra cứu parent.
- **Sửa `WooCommerceProductReader::parentExists(int $parentId)`:**
  - Thứ tự cổng đọc duy nhất (gọi tối đa **1 lần** cho mỗi lần đọc variation khi `$parentId > 0 && $parentId !== $sourceId`): ưu tiên `$this->parentLoader` (nếu có) -> `$this->loader` (nếu có) -> `function_exists('wc_get_product') ? wc_get_product($parentId) : null`.
  - Xác nhận đối tượng/bản ghi parent trả về khác `null`/`false`, có ID hợp lệ qua `isExactPositiveId` đúng bằng `$parentId`, và có loại sản phẩm hợp lệ (`'variable'` hoặc `'product'`/`'simple'`, tuyệt đối từ chối `'variation'` hoặc loại rỗng/lạ). Không dùng cờ `'parent_exists'`, không kế thừa giá/tồn kho của parent sang variation.
- **Đóng allowlist và làm sạch nested fields ở cả hai nhánh (object & array):**
  - Chuẩn hóa `variation` ở **cả nhánh array lẫn object** theo schema `{id, parent_id, optional sku (non-empty string), optional attributes}` (đã qua `$this->attributes()` hỗ trợ cả mảng lẫn object có `get_name()`/`get_options()`), loại bỏ `null` đệ quy trên mọi trường lồng nhau (`variation`, `offer`, `seo`, `images`, `attributes`).
  - Lọc allowlist riêng cho `SeoContext` và `ProviderPayloadBuilder`; thêm optional permalink bridge cho `WordPressContentReader` để test không phụ thuộc hàm global; nối optional `RankMathReadAdapter` + `RankMathSourceObserver` sao cho chỉ khi field trả `state === 'value'` mới ghi vào `seo.{title|meta_description|canonical}` kèm provenance `'rank_math'` (dưới default zero-rule trả `unsupported` -> giữ nguyên provenance `'wordpress'`/`'woocommerce'` và **0 lần gọi meta API**).

---

### E4-2 — Blocker: Khóa enum coexistence (`blocked`), miền giá trị Health Check & capability gate (O3)

*(Chưa được sửa trong E-r3 — giữ nguyên yêu cầu bắt buộc tại `src/Core/Plugin.php`, `src/Core/Environment/EnvironmentCheck.php`, `src/Admin/AdminModule.php`):*

1. **Sửa lỗi rơi trạng thái `blocked`:** Trong cả `Plugin::safeCoexistence()` (`Plugin.php:210–213`) và `AdminModule::renderPage()` (`AdminModule.php:190–191`), đổi enum sang khớp chính xác `OwnershipRegistry`:
   - `state`: `['owned', 'unknown', 'conflict']` (mặc định `'unknown'`).
   - `duplicate_risk`: `['none', 'possible', 'blocked']` (mặc định `'possible'`; `blocked` biểu thị quyết định bị policy chặn, không tuyên bố đã chặn frontend output).
2. **Đóng kín miền giá trị công khai:**
   - `owner`: chỉ nhận `'unknown'` hoặc canonical producer slug trong allowlist cố định đã biết (`myseo_for_all`, `rank_math`, `yoast_seo`, `aioseo`, `seopress`, `the_seo_framework`); ngoài tập thì ép về `'unknown'`.
   - `reason`: chỉ nhận mã thuộc tập đóng của `OwnershipRegistry` (`identity_collision`, `assertion_capability_conflict`, `conflicting_evidence`, `overlapping_claims`, `split_ownership`, `partial_ownership`, `no_valid_claim`, `other_emitters_excluded`, `other_emitters_not_excluded`, `invalid_context_scope`, `unavailable`).
   - `version` (trong `normalizeProbe()` và `safeEnvironment()`): chỉ nhận chuỗi khớp pattern phiên bản an toàn (`^[0-9][A-Za-z0-9._+-]{0,31}$`), sai thì đặt `null`; thu hẹp `version` trong `safeConfiguration()` tương ứng.
   - `safeModule()`: khóa cứng `'write_allowed' => false`, `'frontend_owned' => false` cho mọi module trong E và giữ lại `'dependency_ready' => ($module['dependency_ready'] ?? false) === true` cho `rank_math`.
3. **`EnvironmentCheck::isReady()` & default ngoài WordPress:**
   - `isReady()` chỉ trả `true` khi cả `php` và `wordpress` đều có `installation === 'installed'`, `activation === 'active'` và `compatible === true`.
   - Khi chạy ngoài WordPress runtime (không có probe và `!function_exists('get_bloginfo')`), `wordpress` trả `installation = 'unknown'`, `activation = 'unknown'`, `compatible = 'unknown'` nhưng giữ khóa boolean legacy `'installed' => false`, `'active' => false` để tương thích ngược với `FoundationTest::testEnvironmentCheckIsSafeWithoutWordPress()`.
4. **`AdminModule::renderPage()`:**
   - Cho phép truyền optional capability checker (mặc định `function_exists('current_user_can') && current_user_can('manage_options')`); nếu thiếu quyền thì `return` ngay, **0 lần** gọi `healthProvider` hay `environment->evaluate()`.
   - Khi có `$this->healthProvider`, lấy trực tiếp snapshot đã chiếu từ provider mà **không gọi lặp** `$this->environment->evaluate()`; hiển thị đầy đủ nhãn trạng thái đã chuẩn hóa (gồm `installed` nhưng `inactive`, `mbstring` fallback), 4 modules (kèm `compatibility`/`dependency_ready` của `rank_math`) và `coexistence` (`owner`, `state`, `duplicate_risk` gồm cả `'blocked'`).

---

### E4-3 — Blocker: Sửa chữ ký `add_meta_boxes`, kiểm quyền đối tượng & seam `HookDispatcher` (O1/O2/O4)

*(Chưa được sửa trong E-r3 — bắt buộc hoàn tất tại `src/Core/HookDispatcher.php`, `src/SEO/Runtime/SeoModule.php`, `src/Admin/AdminModule.php`, `src/WooCommerce/WooCommerceModule.php`):*

1. **Seam chuyển tiếp trên `HookDispatcher`:**
   - Cho phép truyền optional forwarder callable vào `HookDispatcher::__construct(?callable $forwarder = null)` (mặc định dùng `add_action` khi `function_exists('add_action')`) và cung cấp `canDispatch(): bool` (`is_callable($this->forwarder) || function_exists('add_action')`).
   - Từ chối `\Closure` vô danh làm `$listener` (ném `\InvalidArgumentException` theo O1).
   - Nếu `!$this->canDispatch()`, `addAction()` trả `false` và **không ghi vào `$this->registrations`**. Các module (`AdminModule`, `SeoModule`, `WooCommerceModule`) dùng `$this->dispatcher->canDispatch()` thay vì gọi cứng `function_exists('add_action')`, giúp kiểm thử trọn vẹn `Plugin::boot()` -> đăng ký hook -> gọi callback thật qua spy dispatcher trên CLI.
2. **Sửa `SeoModule::boot()` & `SeoModule::registerDiagnosticsSurface($postType = '', $post = null)`:**
   - Đăng ký `$this->dispatcher->addAction('add_meta_boxes', [$this, 'registerDiagnosticsSurface'], 10, 2)`.
   - Trong `registerDiagnosticsSurface($postType = '', $post = null)`: xác thực `$postType` là chuỗi hợp lệ và trích xuất ID nguyên dương từ `$post` (`is_object($post) && isset($post->ID)` khớp `post_type` trên `$post` nếu có), kiểm tra quyền trên đối tượng qua capability gate inject được (mặc định `function_exists('current_user_can') && current_user_can('edit_post', $id)` — thiếu quyền thì dừng ngay, 0 lần gọi reader).
   - Định tuyến `'post' | 'page'` -> `ContentSeoInspector::inspect($id)`; `'product' | 'product_variation'` (khi WooCommerce sẵn sàng qua cổng kiểm tra inject được / `WooCommerceModule::isAvailable()`) -> `ProductSeoInspector::inspect($id)`; loại khác bỏ qua, không echo ra output buffer.

---

### E4-4 — Major: Tách độc lập nguồn cài đặt Rank Math & khóa cổng đọc meta (O2/O3)

*(Chưa được sửa trong E-r3 — bắt buộc hoàn tất tại `src/Integrations/RankMath/WordPressRankMathProbe.php`, `WordPressRankMathSourceObserver.php`, `RankMathModule.php`, `SeoModule.php`):*

1. **`WordPressRankMathProbe`:**
   - Cho phép truyền optional read-only platform bridge (đọc `installed_plugins` inventory, `active_plugins` option, hằng/giá trị `version`) để kiểm thử đầy đủ ma trận trạng thái mà không cần hàm global WP.
   - `observeInstallation()` đọc **độc lập** từ nguồn inventory cài đặt (`get_plugins()` hoặc inventory bridge): có mặt -> `'installed'`; nguồn inventory xác nhận vắng mặt -> `'absent'` (để `RankMathDetector` bắt đúng conflict `active_installation_metadata_missing` nếu `active_plugins` vẫn chứa Rank Math); không có nguồn inventory -> `'unknown'` (`installation_metadata_unavailable`). **Tuyệt đối không suy `installed` từ `active_plugins`**.
   - `observeActivation()` phân biệt rõ thiếu API option (`state = 'unknown'`, `evidence_state = 'unobserved'`) với option `active_plugins` sai kiểu/chứa phần tử không phải string (`state = 'unknown'`, `evidence_state = 'invalid'`).
2. **`WordPressRankMathSourceObserver` & wiring:**
   - Cho phép truyền optional meta reader + capability gate vào `WordPressRankMathSourceObserver`; trong `RankMathModule::register()`, khởi tạo observer với danh sách slot lấy từ `$catalog` (với `shippedDefault()` là `[]`).
   - Kiểm tra ID dương + slot thuộc mapping được duyệt + quyền đọc đối tượng (`edit_post` khi có cổng quyền) **trước khi** chạm vào cổng đọc meta; thiếu `metadata_exists` hoặc không xác nhận được presence trả `unreadable` (`can_confirm_presence = false`, không gắn `'runtime_verified'`).
   - Trong `SeoModule::register()`, nối `RankMathReadAdapter` + `RankMathSourceObserver` từ `Container` (khi đã đăng ký) vào readers theo đúng quy tắc O2.

---

### E4-5 — Blocker: Bổ sung đầy đủ ma trận kiểm thử bắt buộc O1–O4 (O4)

Bổ sung các bài kiểm thử hành vi đi qua đúng các cổng/spies được hệ thống gọi thật:

1. **Object-backed Readers cho đủ 4 loại (`post`, `page`, `simple product`, `variation`):**
   - Fake `WP_Post` object (có `ID`, `post_type` là `'post'` và `'page'`, `post_title`, `post_content`, `post_excerpt`, `post_name`/slug, property thừa) và fake `WC_Product` / `WC_Product_Variation` object (`get_type()` là `'simple'` và `'variation'`, `WC_Product_Attribute`-like object, `get_sku()` trả `null`/`''` trên variation, parent hợp lệ tra qua cùng product loader đúng **1 lần**): cả 4 trả `status === 'ok'`, có `findings` và qua `ProviderPayloadBuilder::validatePayload($result['provider_payload'])`.
   - Đủ ca biên: thiếu API (`unavailable`), `not_found`, ID thiếu/sai kiểu `'9abc'`/`<= 0`/lệch ID (`invalid_source`, 0 lần gọi helper phụ), variation tự tham chiếu `id === parent_id` hoặc parent mất / parent là `'variation'` (`parent_product_missing`), giá rỗng `''` (missing) vs giá sai `'-5'` (`product_fields_invalid` + `payload_status = 'rejected'`), thiếu currency (không sinh `offer`), `manage_stock = true` + `stock_quantity = 0` (giữ `0`) vs `manage_stock = false` (bỏ `stock`), nội dung có PII/secret bị chặn ở `provider_payload === null` (`payload_status = 'rejected'`, `payload_reason = 'privacy_policy'`) nhưng giữ `findings` cục bộ, và không mutate object nguồn.
2. **Lifecycle, Hook Forwarding & Admin Diagnostics Callback:**
   - Qua spy forwarder truyền vào `HookDispatcher` của `Plugin`: gọi `boot()` lặp trên 1 instance và trên **2 instance `Plugin`** dùng chung dispatcher chỉ forward mỗi listener định danh **1 lần** (`add_meta_boxes` nhận `acceptedArgs = 2`); truyền `\Closure` ném `\InvalidArgumentException`; thiếu hook API mặc định (`new HookDispatcher()` trên CLI) trả `false` và `registrations()` rỗng.
   - Kích hoạt chính callback `SeoModule::registerDiagnosticsSurface` đã được forward cho `('post', $wpPost)` và `('product', $wcPost)`: thiếu quyền `edit_post` -> 0 lần gọi reader; đủ quyền -> gọi đúng content/product inspector và output buffer rỗng `''`; kích hoạt `WooCommerceModule::onWooCommerceInit()` đặt `lifecycle_observed === true` mà 0 lần đọc/ghi sản phẩm.
3. **Health Check, Coexistence `blocked`, Capability Gate & Escape Fallback:**
   - Khi `OwnershipRegistry` có conflict trả `duplicate_risk = 'blocked'`, cả `Plugin::health()['coexistence']` lẫn HTML của `AdminModule::renderPage()` đều giữ đúng `duplicate_risk=blocked`; kiểm `EnvironmentCheck::isReady()` trả `false` khi `unknown` hoặc khi `installation`/`activation` mâu thuẫn với `compatible = true`.
   - `AdminModule::renderPage()`: thiếu quyền `manage_options` -> **0 lần** gọi `healthProvider`/`environment`, output rỗng `''`; đủ quyền và có `healthProvider` -> không gọi lặp `environment->evaluate()`, hiển thị đúng `inactive` và `mbstring` fallback; bơm sentinel secret/path/`<script>` vào cả trường lạ lẫn trường đúng tên sai giá trị (`owner`, `reason`, `version`, `message`) -> không xuất hiện trong JSON/HTML; test riêng fallback `escapeHtml()` khi không có `esc_html`.
4. **Rank Math Probe, Observer & Negative Side-Effect Spies:**
   - Test `WordPressRankMathProbe` qua platform bridge cho: ngoài WP (`unknown`/`unobserved`), `absent`, `installed-inactive`, `active` (`compatibility = 'unknown'` dưới zero-rule), conflict `active` trong `active_plugins` nhưng `absent` trong inventory (`active_installation_metadata_missing`), và `active_plugins` hỏng kiểu (`evidence_state = 'invalid'`).
   - Test `WordPressRankMathSourceObserver` với spy meta reader: default zero-rule, slot ngoài mapping, ID sai hoặc thiếu quyền đối tượng đều gọi meta API **0 lần**; synthetic mapping trong fixture + đủ quyền phân biệt đúng `missing` vs `present` (`runtime_verified`), thiếu `metadata_exists` trả `unreadable` (`can_confirm_presence = false`, không gắn `runtime_verified`).
   - Assert cứng qua spies được inject thật vào luồng chạy: **0 frontend hooks** (`wp_head`, `document_title_parts`, `wpseo_*`, `rank_math/*`, sitemap), **0 writes** (`update_option`, `update_post_meta`, `wp_update_post`, product `save()`), **0 network/DNS calls** suốt `new Plugin()`, `boot()`, `health()`, `renderPage()`, callbacks và readers.

---

### E4-6 — Blocker: Quy trình khóa Snapshot sạch trước khi gọi `run_check` & xuất báo cáo `docs/giai-doan-2-Increment-E-r4-report.md` (O4)

*(Lưu ý kỹ thuật cực kỳ quan trọng để không lặp lại lỗi "Forge evaluated stale commit" của E-r3):*

1. Chỉ sửa trong ranh giới file của Increment E; không sửa contract lõi đã nghiệm thu của A–D (`LocalSeoAnalyzer`, `ProviderPayloadSchema`, `ProviderPayloadRules`, `AiOutputValidator`, `RankMathDetector`, `RankMathReadAdapter`, `OwnershipRegistry`).
2. **Bắt buộc commit trước khi chạy `run_check`:** Sau khi viết xong toàn bộ code + test của `E4-1` → `E4-5`, tạo commit snapshot `<E_R4_SNAPSHOT_SHA>` chỉ gồm các file `src/` và `tests/` thuộc Increment E (không stage `.phpunit.result.cache` hay các file `docs/` chưa liên quan; đảm bảo `src/` và `tests/` sạch 100% trên HEAD mới).
3. Ngay trên `<E_R4_SNAPSHOT_SHA>`, gọi `run_check` cho từng file test focused thuộc E và toàn bộ suite `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`. Nếu có bất kỳ test nào fail, sửa xong phải tạo commit snapshot mới rồi chạy lại `run_check` trên commit mới đó cho tới khi toàn bộ đạt `exit 0`.
4. Chỉ sau khi toàn bộ `run_check` đạt `exit 0` trên đúng `<E_R4_SNAPSHOT_SHA>`, ghi báo cáo `docs/giai-doan-2-Increment-E-r4-report.md` (commit riêng, không chạm lại `src/` hoặc `tests/`) gồm: `base_sha`, `implementation_snapshot_sha` (`<E_R4_SNAPSHOT_SHA>`), mốc D (`5aa3332133f203f41a8701e83b213c246cf491da`), bảng đối chiếu `E4-1` → `E4-6` sang file/test, bảng `VERIFY-RUN-*` đầy đủ (Run ID, full command, scope, revision chạy thực tế, status, exit code), đầy đủ SHA-256 của toàn bộ file `src/` và `tests/` thuộc E, và mục giới hạn rõ ràng (xác minh offline không chứng minh tương thích production WordPress/WooCommerce/Rank Math, default Rank Math vẫn zero-rule, báo cáo không tự đóng gate C/D/E hay mở F–H).
