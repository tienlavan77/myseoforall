# Giai đoạn 2 — Increment E: Runtime composition, read-only adapters và Health Check wiring

> Hướng dẫn Architecture cho System Engineer (SE), cập nhật theo `docs/giai-doan-2-Increment-E-feedback.md`. Đây là boundary, contract và acceptance ở cấp increment; không phải Sprint Plan, danh sách ticket hay mutable-file manifest.

## 1. Prerequisite Gate & Mục tiêu

### 1.1 Trạng thái Prerequisite C & D (Blocker Gate)
- **Increment B:** Đã chốt tại snapshot `c7793d992da7f27b08c18efe9c76fbd3eb176b7e`.
- **Increment C:** Snapshot code/test mới nhất `9c76c628853dd63024c1e02fb44a70581b022db7` (`VERIFY-RUN-f0b52cde-8a26-431e-ad85-e329d86a0d83`, `VERIFY-RUN-83aa3c99-cb89-4ff0-98f4-b10216c81929`). Hồ sơ `docs/giai-doan-2-increment-c-r4-report.md` vẫn neo ở `9c0e2ed40833f784ee9350fce96f122728d3c9a7`.
- **Increment D:** Snapshot code/test `5aa3332133f203f41a8701e83b213c246cf491da` (`VERIFY-RUN-0dafd0d1-ca2a-4714-a828-ef75d6b2ded9`), báo cáo `docs/giai-doan-2-d-r3-report.md`; Architecture review kỹ thuật đạt và Project Owner đã xác nhận nghiệm thu trong hội thoại, nhưng `docs/giai-doan-2-d-r3-report.md` / `r4.md` ghi rõ gate chính thức đòi hỏi bản ghi Reviewer độc lập + Owner confirmation gắn đúng SHA/checksum/authority/scope.
- **Quy tắc thực thi:** Increment E ở trạng thái **prepared** cho tới khi workflow lưu/xác nhận canonical handoff đóng gate cho cả C (`9c76c628...`) và D (`5aa33321...`), hoặc Owner chỉ đạo rõ thực thi offline kiểu hướng 2 như D (khi đó kết quả E chỉ là *offline technical verification*, không tự đóng gate C/D/E). Không dùng receipt PHPUnit thay cho Reviewer/Owner approval.

### 1.2 Mục tiêu
Đưa các contract offline A–D vào **composition root**, bổ sung **read-only platform readers/adapters** cho WordPress (`post`, `page`) và WooCommerce (`product`, `variation`) cùng **Health Check diagnostics tiếng Việt**, bảo đảm: idempotent, fail-closed khi thiếu dependency, không network, không frontend SEO emission, không ghi dữ liệu và `write_allowed = false`.

---

## 2. Phạm vi & Loại trừ

- **In scope:** (O1) Composition contract duy nhất qua `Container` + `HookDispatcher` idempotent; (O2) Read-only Content/Product reader contracts & dual-projection sang `SeoContext` + `ProviderPayloadBuilder`; (O3) Injectable tri-state `EnvironmentCheck`, WordPress Rank Math probes/observers chỉ đọc & safe Health Check DTO/HTML; (O4) Negative side-effect spies, regression updates & commit-bound verification.
- **Out of scope:** Frontend SEO hooks (`wp_head`, `document_title_parts`, `wpseo_*`, `rank_math/*` filters sửa output, canonical/OG/robots/JSON-LD/sitemap emission); mọi writer (`update_option` ngoài `Plugin::activate` và sanitize setting foundation hiện hữu, `update_post_meta`, `$product->save()`, ownership transfer, plugin toggle); AI Settings/secret/gateway/network/Preview/Apply (Increment F–H); thêm speculative rules vào `shippedDefault()` của Rank Math (`0 rule` giữ nguyên).

---

## 3. Outcomes & Contract bắt buộc

### O1 — Composition Contract duy nhất & Hook Idempotency

1. **Khóa 1 phương án Composition duy nhất (Constructor-injected `Container` + `HookDispatcher`, giữ nguyên chữ ký `Module`):**
   - Giữ nguyên interface `MySeoForAll\Core\Contracts\Module` (`register(): void`, `boot(): void`, `isAvailable(): bool`, `health(): array`) để không phá vỡ backward compatibility.
   - Các module nhận `Container` và `HookDispatcher` qua **constructor**. Danh sách module canonical mặc định trong `Plugin` theo đúng thứ tự:
     1. `AdminModule` (`name = 'admin'`)
     2. `WooCommerceModule` (`name = 'woocommerce'`)
     3. `SeoModule` (`name = 'seo'`)
     4. `RankMathModule` (`name = 'rank_math'`)
2. **Service IDs bắt buộc trong `Container` & chính sách đăng ký:**
   - Trong `Plugin::__construct()`: đăng ký `Configuration::class`, `EnvironmentCheck::class`, `OptionStore::class`, `HookDispatcher::class`.
   - Trong `Module::register()` (luôn chạy cho cả 4 module trước vòng lặp `boot()`, kể cả khi `isAvailable() === false` đối với các pure offline services):
     - `RankMathModule::register()`: đăng ký `RankMathCompatibilityMatrix::class`, `RankMathDetector::class`, `RankMathMappingCatalog::class`, `RankMathReadAdapter::class`, `OwnershipRegistry::class`.
     - `SeoModule::register()`: đăng ký `ProviderPayloadBuilder::class`, `AiOutputValidator::class`, `LocalSeoAnalyzer::class`, cùng hai runtime projection/inspection services của O2 (`ContentSeoInspector::class`, `ProductSeoInspector::class` — hoặc tên class được chốt trong `src/SEO/` nhưng bắt buộc đăng ký theo FQCN vào `Container`).
   - **Duplicate registration policy:** `Container::set()` chỉ cho phép ghi mới hoặc cùng instance; nếu một module đăng ký đè ID đã tồn tại bằng instance khác trong cùng lifecycle thì ném `\LogicException` để phát hiện xung đột composition ngay lập tức.
3. **Contract `HookDispatcher` & Phạm vi Idempotency:**
   - Định nghĩa interface `HookDispatcher` (ví dụ trong `src/Core/Contracts/` hoặc `src/Infrastructure/WordPress/`) với `addAction(string $hook, callable|array $listener, int $priority = 10, int $acceptedArgs = 1): bool` và danh sách đọc lại các registration (cho spy/diagnostics).
   - **Phạm vi idempotency:** Theo **cặp `(hook, listener_key, priority)` ở mức dispatcher/request** (WordPress runtime implementation giữ registry tĩnh hoặc deduplication key theo request để dù tạo **2 instance `Plugin`** trong cùng request cũng **không bao giờ** gọi `add_action` trùng cho cùng listener của plugin), đồng thời giữ `$this->booted` trên từng `Plugin` instance.
   - Không dùng `Closure` vô danh cho WordPress hooks; mọi listener phải là `[object, 'method']` định danh được.
   - **Hooks được phép đăng ký trong Increment E:**
     - `AdminModule`: `admin_menu` -> `[AdminModule, 'registerMenu']`; `admin_init` -> `[AdminModule, 'registerSettings']`.
     - `SeoModule`: `add_meta_boxes` (hoặc `current_screen` chỉ đọc trong admin) -> `[SeoModule, 'registerDiagnosticsSurface']` (chỉ đọc chẩn đoán khi mở màn hình edit, không ghi meta, không in frontend).
     - `WooCommerceModule`: `woocommerce_init` -> `[WooCommerceModule, 'onWooCommerceInit']` (đánh dấu runtime product reader sẵn sàng, không ghi dữ liệu).
     - `RankMathModule`: không đăng ký frontend/write hook; chỉ cung cấp read-only probes/services.

---

### O2 — Public Contract cho Read-Only Readers & Bảng Mapping Hai Kênh

1. **Reader Interfaces & Closed Result Envelope (không ném fatal khi thiếu dữ liệu nền tảng):**
   - Tạo 2 read-only reader interfaces có thể inject fake trong PHPUnit và WordPress/WooCommerce implementation chỉ gọi hàm nền tảng khi khả dụng:
     - `WordPressContentReader::readPost(int $postId): array` (hỗ trợ `post_type` là `'post'` hoặc `'page'`).
     - `WooCommerceProductReader::readProduct(int $productId): array` (hỗ trợ simple `'product'` và `'variation'` qua `wc_get_product()` + CRUD getters: `get_id()`, `get_type()`, `get_name()`, `get_description()`, `get_short_description()`, `get_sku()`, `get_price('edit')`, `get_manage_stock()`, `get_stock_quantity('edit')`, `get_stock_status('edit')`, `get_parent_id()`, `get_attributes()`, `get_permalink()`, `get_woocommerce_currency()`).
   - **Envelope trả về đóng của Reader/Inspector:**
     - `status`: `'ok' | 'unavailable' | 'not_found' | 'unsupported_type' | 'invalid_source'`
     - `reason`: mã chuỗi ổn định (ví dụ `'wordpress_unavailable'`, `'woocommerce_unavailable'`, `'parent_product_missing'`, `'unmanaged_stock'`, ...)
     - `resource_type`: `'article' | 'page' | 'product' | 'variation' | null`
     - `seo_context`: `?SeoContext` (chỉ có khi `status === 'ok'`)
     - `findings`: `list<array>` từ `LocalSeoAnalyzer::analyze()` (rỗng khi `status !== 'ok'`)
     - `provider_payload`: `?array` từ `ProviderPayloadBuilder::build()` — nếu dữ liệu nguồn vi phạm điều kiện chặt của `ProviderPayloadBuilder` (ví dụ thiếu `title`/`product_name` rỗng, hoặc chứa email/PII bị `ProviderPayloadRules` từ chối), inspector bắt `\InvalidArgumentException` an toàn và đặt `provider_payload = null` kèm `payload_status = 'rejected'` + `payload_reason` an toàn (không chứa raw PII/secret), **vẫn trả được `findings` cục bộ từ `LocalSeoAnalyzer`**.
   - **Request-scope behavior & Non-mutation:** Reader đọc snapshot tại thời điểm gọi (không cache tĩnh xuyên request; không clone/giữ reference mở làm mutate object `WP_Post`/`WC_Product` của caller).
2. **Xử lý trường biên của Product / Variation & Missing/Null/Invalid:**
   - **Giá (`price`):** Chỉ nhận chuỗi số thập phân không âm chuẩn hóa (`^\d+(?:\.\d+)?$`) từ `get_price('edit')`; tuyệt đối không ép sang `float`. Nếu trống -> coi là **missing** (bỏ key `price` khi sang `ProviderPayloadBuilder`; để trống/missing trong `SeoContext` để analyzer báo `product_fields_missing`). Nếu sai định dạng (ví dụ âm hoặc chữ) -> giữ nguyên tín hiệu invalid cho `SeoContext`, còn bên payload đánh dấu `payload_status = 'rejected'` hoặc bỏ theo quy tắc dưới đây.
   - **Tồn kho (`stock`):** Chỉ đặt `stock` (`int >= 0`) khi `manage_stock === true` và `stock_quantity` là số nguyên không âm. Nếu `manage_stock === false` hoặc `stock_quantity === null`: **không gán `0`** — trong `ProviderPayloadBuilder` lược bỏ trường optional `stock`; trong `SeoContext` ghi nhận thiếu số lượng định lượng theo contract hiện hành của `LocalSeoAnalyzer` (không sửa `LocalSeoAnalyzer`).
   - **Tiền tệ & Availability (`offer`):** `currency` lấy từ `get_woocommerce_currency()`, phải khớp `^[A-Z]{3}$`. `stock_status` map `'instock' -> 'in_stock'`, `'outofstock' -> 'out_of_stock'`, `'onbackorder' -> 'on_backorder'`. Chỉ dựng object `offer = ['price' => ..., 'currency' => ..., 'availability' => ...]` khi **cả 3 trường đều hợp lệ**; thiếu 1 trong 3 thì không bịa `offer`.
   - **Variation & Parent:** Variation bắt buộc có `id > 0`, `parent_id > 0`, `id !== parent_id`, và reader phải xác nhận parent tồn tại qua `wc_get_product($parentId)` (nếu parent không tồn tại -> trả `status = 'invalid_source'`, `reason = 'parent_product_missing'`, không tự lấy bù giá/tồn kho của parent gán giả cho variation).
3. **Bảng chiếu hai kênh (`SeoContext` cho Analyzer vs `ProviderPayloadBuilder` cho AI Boundary):**
   *(Giải quyết triệt để khác biệt schema giữa `SeoContext` và `ProviderPayloadSchema`; cấm truyền thô `SeoContext::toArray()` vào `ProviderPayloadBuilder::build()` vì `SeoContext` chứa `'version'`, `'type'`, cho phép `null` và dùng `'title'` thay vì `'product_name'`):*

| Nguồn nền tảng | Resource | Khóa trong `SeoContext` (`type` truyền vào `fromArray`) | Khóa trong `ProviderPayloadBuilder::build($source, $type, $prov)` | Provenance & Precedence |
| --- | --- | --- | --- | --- |
| WP `post` | Bài viết | `fromArray(..., 'post')`: `id`, `title`, `content`, `excerpt`, `meta_description`, `url`, `canonical`, `images`, `categories`, `tags`, `internal_links`, `seo` | `$type = 'article'`. **Required:** `title` (non-empty), `content` (string). Optional: `id`, `excerpt`, `meta_description`, `url`, `canonical`, `language`, `categories`, `tags`, `images`, `headings`, `internal_links`, `seo`. | Mặc định `'wordpress'`. Nếu `RankMathReadAdapter` trả `state = 'value'`, đặt vào `seo.{title\|meta_description\|canonical}` với provenance `'rank_math'`. |
| WP `page` | Trang | `fromArray(..., 'page')`: cùng tập khóa như `post` | `$type = 'page'`. **Required:** `title` (non-empty), `content` (string). Optional như `article`. | Như `article`; canonical thắng theo thứ tự `seo.canonical` -> `canonical` -> `url` của `ProviderPayloadBuilder`. |
| WC `product` (simple) | Sản phẩm | `fromArray(..., 'product')`: `id`, `title` (từ `product_name`), `content` (từ `description`/`short_description`), `sku`, `price`, `stock`, `currency`, `availability`, `offer`, `url`, `canonical`, `images`, `categories`, `tags`, `attributes`, `seo` | `$type = 'product'`. **Required:** `product_name` (non-empty). Optional: `id`, `title`, `content`, `short_description`, `description`, `sku`, `price`, `stock`, `attributes` (`list<{name,value}>`), `offer` (`{price,currency,availability}`), `url`, `canonical`, `images`, `categories`, `tags`, `seo`. **Không truyền** `currency`/`availability` ở top-level và **loại bỏ mọi key có giá trị `null`**. | Mặc định `'woocommerce'`; field lấy từ `RankMathReadAdapter` (`state = 'value'`) gắn `'rank_math'`. |
| WC `variation` | Biến thể | `fromArray(..., 'variation')`: như `product` + `variation: {id, parent_id, sku, attributes}` | `$type = 'variation'`. **Required:** `product_name` (non-empty), `variation: {id, parent_id, optional sku, optional attributes}` (`id !== parent_id`, khớp top-level `id`/`sku` nếu có). Không có `null` hay key ngoài schema. | Mặc định `'woocommerce'`; SEO override hợp lệ gắn `'rank_math'`. |

---

### O3 — Tri-State Environment Probe, Rank Math Runtime Bridge & Safe Health Check Schema

1. **Injectable Tri-State `EnvironmentCheck`:**
   - Cho phép truyền một runtime environment probe/callable reader vào `EnvironmentCheck::__construct(...)` (mặc định đọc môi trường PHP/WordPress an toàn).
   - Mỗi mục trong `EnvironmentCheck::evaluate()` (`php`, `wordpress`, `woocommerce`, `mbstring`) trả các trường chuẩn hóa:
     - `name` (`string`), `installation` (`'installed' | 'absent' | 'unknown'`), `activation` (`'active' | 'inactive' | 'unknown'`), `version` (`?string`), `compatible` (`bool | 'unknown'` — giữ boolean keys `installed`, `active`, `compatible` tương thích ngược cho test cũ ở `php`/`wordpress`, hoặc cung cấp thêm `installation_state`/`activation_state`/`compatibility_state` để không làm gãy `FoundationTest` hiện hữu), và `message` (tiếng Việt).
     - Với `mbstring`: báo rõ `extension_loaded('mbstring')` là `true` hay đang chạy chế độ fallback regex UTF-8 (`compatible = true` ở chế độ fallback local nhưng ghi rõ trạng thái extension).
     - Với `woocommerce`: phân biệt `active` (`class_exists('WooCommerce')` / nằm trong `active_plugins`), `installed` nhưng `inactive` (khi file plugin/package metadata được probe xác nhận có mặt nhưng không active), `absent` (probe xác nhận không có) và `unknown` (ngoài WordPress runtime không đọc được danh sách plugin cài đặt).
2. **WordPress Rank Math Probe & Observer Mapping:**
   - `WordPressRankMathProbe implements RankMathEnvironmentProbe`:
     - Đọc qua bridge chỉ đọc (`get_option('active_plugins')`, `defined('RANK_MATH_VERSION')`, `WP_PLUGIN_DIR` / `get_plugins()` nếu hàm đã nạp sẵn — không `require_once` file admin nặng của WP).
     - Khi WordPress chưa nạp: trả `state = 'unknown'` / `'unavailable'` với `evidence_state = 'unobserved'`.
     - Với `RankMathCompatibilityMatrix::shippedDefault()` (`0 rule`), kể cả khi Rank Math active + version hợp lệ, `compatibility` phải trả `'unknown'` (`reason = 'matrix_unproven_for_range'`).
   - `WordPressRankMathSourceObserver implements RankMathSourceObserver`:
     - Chỉ đọc post meta qua `metadata_exists('post', $postId, $metaKey)` + `get_post_meta($postId, $metaKey, true)` (khi cả hai hàm tồn tại) để xác nhận chính xác `can_confirm_presence = true` và phân biệt `missing` vs `explicit_null`/empty.
     - Nếu thiếu `metadata_exists`: đặt `can_confirm_presence = false`, `presence = 'unreadable'` để fail-closed (không đoán `missing`).
     - Gắn `provenance` hợp lệ theo `RankMathSourceObserver::ALLOWED_PROVENANCE` (chỉ dùng `'runtime_verified'` khi đọc thật từ WordPress API đã xác nhận presence; trong test double dùng `'fixture'` hoặc `'synthetic'`). Do `RankMathMappingCatalog::shippedDefault()` có `0 rule`, runtime mặc định hiện trả `state = 'unsupported'` cho cả 3 field.
   - **Coexistence Snapshot cho `OwnershipRegistry`:** Không sinh `assertion = 'claim'` chỉ từ `active_plugins`; khi chưa có nguồn runtime chứng minh assertion ở mức `observed`, `OwnershipRegistry::resolve()` trả `owner = 'unknown'`, `state = 'unknown'`, `duplicate_risk = 'possible'`, `write_allowed = false` trên `title_meta`, `json_ld`, `sitemap` (còn `ai_draft` là internal `myseo_for_all` với `frontend_owned = false`, `publish_capability = false`, `write_allowed = false`).
3. **Health Check Schema Canonical & Redaction Tách Khỏi Raw `Configuration`:**
   - **Sửa đường rò dữ liệu trong `Plugin::health()`:** Thay vì trả thẳng `$configuration->all()` không kiểm soát khi cấu hình mở rộng về sau, chỉ trả **safe configuration summary theo allowlist cứng** (`version`, `site_language`, `ai_enabled`, `rank_math_mode`, `sitemap_owner` — chỉ kiểu scalar/bool đã qua whitelist; mọi key lạ hoặc chuỗi khớp pattern secret/token/path/email trong `OptionStore` đều bị loại bỏ hoàn toàn khỏi `Plugin::health()`).
   - `Plugin::health()` trả exact top-level keys: `plugin`, `configuration` (safe summary allowlist), `environment` (`php`, `wordpress`, `woocommerce`, `mbstring`), `modules` (danh sách 4 module health DTO theo thứ tự canonical: `admin`, `woocommerce`, `seo`, `rank_math`), `coexistence` (tóm tắt an toàn từ `OwnershipRegistry`: per-output `owner`, `state`, `duplicate_risk`, `reason`, `write_allowed = false`).
   - **`AdminModule::renderPage()`:**
     - Nhận callable/provider đọc snapshot chẩn đoán an toàn (từ `EnvironmentCheck` + module/coexistence health summary), bắt buộc kiểm tra `function_exists('current_user_can') && current_user_can('manage_options')` (nếu thiếu hàm hoặc trả `false` -> return ngay, không in bất kỳ ký tự nào).
     - **Escape bắt buộc:** Dùng helper `escapeHtml(string $text): string` gọi `esc_html($text)` khi có WordPress, và fallback sang `htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` khi chạy độc lập — **tuyệt đối không fallback về raw `(string)` cast như code cũ**.

---

### O4 — Acceptance Matrix, Negative Spies & Quy trình Snapshot

1. **Cập nhật có chủ đích 2 test cũ:**
   - `tests/Unit/FoundationTest.php`: cập nhật kiểm tra 4 modules canonical (`admin`, `woocommerce`, `seo`, `rank_math`) và idempotency trên cả cùng instance lẫn 2 instance dùng chung `HookDispatcher`.
   - `tests/Unit/OwnershipRegistryCoexistenceTest.php`: thay dòng phủ nhận module `rank_math` bằng kiểm tra `rank_math` module trong `Plugin::health()` giữ `write_allowed === false`, `compatibility === 'unknown'` dưới default matrix và không đăng ký frontend hook.
2. **Các bài kiểm thử bắt buộc mới (có Spy/Double quan sát trực tiếp):**
   - **Composition & Duplicate Guard:** resolve đủ toàn bộ service IDs từ `Container`; test đăng ký trùng service khác instance ném `\LogicException`; test gọi `boot()` 3 lần trên 1 `Plugin` và khởi tạo 2 `Plugin` trên cùng request-scoped `HookDispatcher` chỉ ghi nhận mỗi hook đúng 1 lần.
   - **Negative Side-Effect Spies:** dùng `SpyHookDispatcher` và `ReadOnlyPlatformSpy` để assert cứng:
     - **0 lần** đăng ký `wp_head`, `document_title_parts`, `wpseo_title`, `wpseo_canonical`, `rank_math/frontend/*`, `init` sitemap rewrite;
     - **0 lần** gọi bất kỳ hàm ghi nào (`update_option`, `update_post_meta`, `wp_update_post`, product `save()`) trong `__construct()`, `boot()`, `health()`, `renderPage()` và khi chạy Content/Product inspectors;
     - **0 lần** mở kết nối network/DNS;
     - Object/mảng đầu vào truyền cho readers/inspectors không bị mutate.
   - **Dual-Projection & Edge Matrix (4 Resources):** kiểm chứng `post` -> `'article'`, `page` -> `'page'`, `product` -> `'product'`, `variation` -> `'variation'` đều đi qua **cả** `LocalSeoAnalyzer::analyze()` lẫn `ProviderPayloadBuilder::validatePayload($payload)` thành công; test unmanaged stock (`null` không biến thành `0`), giá decimal giữ nguyên độ chính xác (`'199000.50'`), thiếu currency không sinh `offer`, variation thiếu parent trả `parent_product_missing`, bài viết chứa email/API key trong text vẫn trả local findings nhưng `provider_payload === null` (`payload_status = 'rejected'`) mà không crash.
   - **Unavailable-Dependency Matrix & Redaction/XSS:** kiểm thử các tổ hợp thiếu WordPress / thiếu WooCommerce / thiếu Rank Math / chế độ fallback `mbstring`; bơm sentinel secret (`sk-ant-secret123456`, `/etc/passwd`, payload `<script>alert(1)</script>`) vào raw option/probe để chứng minh **không xuất hiện** trong `json_encode($plugin->health())` và bị chặn/escape an toàn trong `AdminModule::renderPage()` (cả khi không có hàm `esc_html`).

---

## 4. Quy trình Snapshot Verification & Handoff

1. **Ranh giới file được phép sửa/thêm của Increment E:** chỉ nằm trong `src/Core/`, `src/Admin/`, `src/WooCommerce/`, `src/SEO/`, `src/Integrations/RankMath/`, `src/Infrastructure/WordPress/` và `tests/Unit/` (không sửa logic contract lõi đã khóa của `LocalSeoAnalyzer`, `ProviderPayloadSchema`, `ProviderPayloadRules`, `AiOutputValidator`, `RankMathDetector`, `RankMathReadAdapter`, `OwnershipRegistry` trừ khi chỉ bổ sung wiring/accessor không đổi hành vi). Không commit `.phpunit.result.cache`.
2. **Thứ tự khóa snapshot bắt buộc:**
   - Bước 1: Hoàn tất code + tests, commit toàn bộ thay đổi `src/` và `tests/` của Increment E thành một commit cố định `<E_SNAPSHOT_SHA>`.
   - Bước 2: Ngay trên `<E_SNAPSHOT_SHA>` (working tree của `src/` và `tests/` sạch 100%), chạy qua `run_check`:
     - Các lệnh focused cho từng file test mới/cập nhật của E (`FoundationTest`, các file test Runtime/Adapter/HealthCheck mới, `OwnershipRegistryCoexistenceTest`);
     - Full suite: `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`.
   - Bước 3: Viết báo cáo `docs/giai-doan-2-e-report.md` (commit riêng sau khi chạy `run_check`) ghi đầy đủ: `implementation_snapshot_sha`, `base_sha`, bảng mapping test -> O1..O4, `VERIFY-RUN-*` + command + exit code, đầy đủ SHA-256 của mọi file `src/` và `tests/` thuộc scope E, và mục giới hạn rõ ràng (chỉ chứng minh offline runtime/adapter contract trên snapshot; không chứng minh production WordPress/WooCommerce/Rank Math compatibility và không tự đóng gate C/D/E).
   - Nếu bất kỳ file `src/` hoặc `tests/` nào sửa sau khi chạy `run_check`, bắt buộc tạo commit snapshot mới và lấy lại toàn bộ receipt.

---

## 5. Evidence đối chiếu

- Phản biện đã tích hợp: `docs/giai-doan-2-Increment-E-feedback.md` (`sha256:6f18c2f192feeb0d5d881b24adec20819c3778e417b5e81bac86dcc6f8c706a8`).
- Bản hướng dẫn E trước cập nhật: `sha256:0a7ebfe0ebba3a04f585dd9f892824b35850a877f39a73ca689809ae147f30f7`.
- Schema & payload contracts đã đối chiếu trực tiếp:
  - `src/SEO/Context/ProviderPayloadBuilder.php` (`sha256:a45ce3fc88989c27114e810f50bce2abc10ff4a2f9cbcef8a499adae64adb093`)
  - `src/SEO/Context/ProviderPayloadSchema.php` (`sha256:7f5d5ff28bed0c930ed80b4f3d206126db1db06004e797d0f1422484862a57e7`)
  - `src/SEO/Context/ProviderPayloadRules.php` (`sha256:c7821c39d9c3baff90a8643b56a9b949da41a26536af73e0286e787bd59defdf`)
  - `src/SEO/Context/SeoContext.php` (`sha256:efaf6e7f9679bd785570667523a3f523203f7d13062c4cff6c56787faf36d573`)
  - `src/Core/Plugin.php` (`sha256:21a4981a3d6eadd71f4a3062a5a2be20bd5e2a84f5e5ca80950b3c614c860ff5`)
  - `src/Core/Container.php` (`sha256:d57823eb036b02c5efed4cd1aa621760d1f87c86c36e47e60ed9337b4cca7fa2`)
  - `src/Core/Contracts/Module.php` (`sha256:fc2c8ed8e856c3027dafca5e5e7dd99f5dd3359c38f348999e1e223de024d526`)
  - `src/Core/Environment/EnvironmentCheck.php` (`sha256:20deca38266d177a5ed11493141501092a9dc1f2468981d67945996429283aa2`)
  - `src/Admin/AdminModule.php` (`sha256:3511b2866dafd11fde50cfc073cb9cade1a0335e1d025d9421da8bccda4a08e2`)
  - `src/WooCommerce/WooCommerceModule.php` (`sha256:985e85034bb68bc45439e7d1e0eee776ac1d16bef12eb60235bc6b5c42bab05a`)
  - Snapshot D tham chiếu: commit `5aa3332133f203f41a8701e83b213c246cf491da`, `docs/giai-doan-2-d-r3-report.md` (`sha256:d9cc0b2236d31dbfc1fa0da7d5d91b9f426a2c9de9d0c58ff5612e6c6ad0eea2`).
