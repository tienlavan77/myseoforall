# Phản biện kiến trúc — Increment E

**Tài liệu được review:** `docs/giai-doan-2-Increment-E.md`  
**SHA-256:** `sha256:0a7ebfe0ebba3a04f585dd9f892824b35850a877f39a73ca689809ae147f30f7`  
**Phạm vi:** tính nhất quán prerequisite, khả năng triển khai contract, boundary read-only, Health Check và bằng chứng nghiệm thu.

## Kết luận

Tài liệu có boundary an toàn và đúng hướng: offline, read-only, fail-closed, không frontend emission, không Apply và không network. Tuy nhiên chưa nên dùng làm đặc tả triển khai cho đến khi xử lý các blocker/major dưới đây. Nếu triển khai ngay, các đội khác nhau có thể tạo public contract, trạng thái health và hành vi adapter không tương thích nhau.

## Blocker

### 1. Prerequisite D/C chưa có bằng chứng nghiệm thu canonical

Mục 1 ghi Owner đã chốt D tại snapshot `5aa3332133f203f41a8701e83b213c246cf491da`, đồng thời nói E kế thừa A–D đã nghiệm thu. Nhưng:

- `docs/giai-doan-2-d-r4.md` vẫn ghi cần Reviewer độc lập và Owner quyết định trước khi đóng gate.
- `docs/giai-doan-2-d-r3-report.md` ghi receipt pass không tự tạo Reviewer/Owner approval.
- Hồ sơ C cũng phân biệt rõ technical verification với acceptance.

Cần thay câu trạng thái đầu vào bằng một handoff canonical có: revision được review, source/test checksums, focused/full receipts, Reviewer decision, Owner decision, authority và scope. Nếu chưa có đủ, E chỉ ở trạng thái **prepared**, chưa được mở implementation.

## Major

### 2. O1 chưa khả thi với contract Module hiện tại

`Module` hiện chỉ có `register()`, `boot()`, `isAvailable()`, `health()`; `Plugin::boot()` gọi `register()` không truyền `Container`. Vì vậy yêu cầu mỗi module đăng ký `ProviderPayloadBuilder`, analyzer, Rank Math services và runtime builders vào Container chưa có seam xác định.

Tài liệu phải chọn một phương án và khóa test:

- truyền `Container` qua constructor;
- đổi contract thành `register(Container $container)`;
- hoặc giao toàn bộ composition cho một composition root riêng, module chỉ đăng ký hook.

Cần nêu rõ service ID/interface, thứ tự register/boot, duplicate registration policy và behavior khi dependency unavailable. Không nên để implementation tự chọn giữa các phương án.

### 3. “Idempotent” chưa định nghĩa phạm vi

Cờ `Plugin::$booted` chỉ ngăn boot lặp trên cùng instance. Hai instance `Plugin` trong cùng request vẫn có thể đăng ký hook trùng. Tài liệu cần xác định idempotency là:

- trên một instance;
- trên một composition root/request;
- hay toàn process.

Đồng thời cần một `HookDispatcher`/bridge có contract và spy để kiểm chứng hook đăng ký đúng một lần. Không thể kiểm thử đáng tin cậy bằng global WordPress functions tùy môi trường. Phải có negative assertions cho `wp_head`, `document_title_parts`, canonical, Open Graph, JSON-LD, sitemap và mọi writer.

### 4. Adapter WordPress/WooCommerce chưa có public contract

O2 nêu nhiều field nhưng chưa chốt:

- interface, method, input/output shape và error/unavailable result;
- hook nào gọi adapter, lúc nào gọi, có cache theo request hay không;
- mapping `post`/ `page` sang resource type;
- canonical precedence và provenance khi Rank Math/WordPress/editor cùng có giá trị;
- cách biểu diễn missing, explicit null, unreadable, invalid;
- behavior khi object bị thay đổi trong lúc đọc và yêu cầu không mutate.

Với product/variation, cần khóa API getter cụ thể, decimal scale/precision, currency thiếu hoặc sai, stock unmanaged/unknown, variation thiếu parent và parent không tồn tại. Câu “không đoán tồn kho = 0” chưa đủ để tạo assertion nhất quán.

### 5. Mapping SeoContext → ProviderPayloadBuilder đang có nguy cơ lệch contract

Tài liệu yêu cầu adapter chuyển dữ liệu qua `SeoContext` rồi `ProviderPayloadBuilder`, nhưng không chỉ rõ mapping trung gian hoặc resource schema. Hồ sơ source hiện tại đã có các allowlist/schema khác nhau giữa context và builder; các field product/variation như `product_name`, `parent_id`, `offer` cần được chứng minh là đi qua được cùng một contract.

Cần thêm bảng mapping cho bốn resource (article, page, product, variation), required/optional/type, và test payload cuối cùng. Không chấp nhận việc adapter tự cast hoặc copy nested array tùy ý.

### 6. Health Check chưa tách dữ liệu chẩn đoán khỏi configuration

`Plugin::health()` hiện trả `configuration->all()`. Khi configuration mở rộng, đây là đường rò secret/raw option trái với redaction invariant của E. Cần health DTO/allowlist riêng, test với sentinel secret/token/path và assertion không xuất hiện trong array lẫn HTML.

`AdminModule::renderPage()` hiện chỉ render `EnvironmentCheck`, trong khi E yêu cầu hiển thị module/coexistence/Rank Math diagnostics. Cần chốt nguồn dữ liệu, schema health canonical và hành vi khi `current_user_can`/các hàm escape không tồn tại. Fallback escape không được là cast raw string.

### 7. Environment và Rank Math probe chưa đủ trạng thái để đáp ứng acceptance

`EnvironmentCheck` hiện suy ra WooCommerce bằng `class_exists('WooCommerce') || defined('WC_VERSION')`, nên chưa phân biệt cài/chưa cài, active/inactive và version unknown. Tài liệu cần contract tri-state và probe injectable, tránh test phụ thuộc globals đã được load.

Rank Math bridge cũng cần mapping cụ thể từ WordPress signals sang `RankMathEnvironmentProbe` và `RankMathSourceObserver`: provenance bắt buộc, presence confirmation, `unsupported` do `shippedDefault()` zero-rule, `unknown` compatibility và fail-closed khi tín hiệu mâu thuẫn. Active plugin list không được tự tạo ownership assertion.

### 8. Acceptance chưa chứng minh đầy đủ read-only boundary

Bộ test cần có negative cases và spy cho:

- không gọi HTTP/DNS/AI gateway;
- không gọi update option/post meta/product CRUD;
- không đăng ký frontend output;
- không expose raw object, secret, PII hoặc payload;
- input object/array không bị mutate;
- dependency thiếu ở từng tổ hợp WordPress/WooCommerce/Rank Math/ext-mbstring.

Hiện tài liệu mới nêu mục tiêu, chưa quy định cách quan sát và assertion cho các side effect này.

## Major về quy trình snapshot

Mục 4 yêu cầu commit source/test, chạy focused và full-unit trực tiếp trên commit đó rồi tạo report commit riêng, nhưng chưa định nghĩa:

- danh sách file thuộc Increment E và boundary loại trừ;
- command focused chính xác;
- test nào chứng minh từng O1–O4;
- cách ghi base SHA, exit code, run ID và checksum;
- xử lý khi source/test thay đổi sau snapshot.

Cần giữ nguyên nguyên tắc của D: receipt không thay thế Reviewer/Owner approval; report không được gọi E là passed chỉ vì PHPUnit pass.

## Đề xuất chỉnh tài liệu trước khi triển khai

1. Bổ sung mục **Prerequisite gate** với handoff canonical của C/D và trạng thái rõ ràng.
2. Khóa một composition contract duy nhất (Container injection, service IDs, lifecycle và idempotency scope).
3. Thêm phụ lục contract cho WordPress/WooCommerce readers, hook dispatcher, Rank Math probes và health schema.
4. Thêm bảng mapping bốn resource tới SeoContext/ProviderPayloadBuilder và bảng trạng thái missing/null/unreadable/invalid.
5. Đưa negative side-effect tests, redaction tests và unavailable-dependency matrix vào acceptance bắt buộc.
6. Nêu rõ E chỉ chứng minh offline contract trên snapshot; không suy ra WordPress/WooCommerce/Rank Math production compatibility.

## Evidence đối chiếu

- `src/Core/Plugin.php` — `sha256:21a4981a3d6eadd71f4a3062a5a2be20bd5e2a84f5e5ca80950b3c614c860ff5`
- `src/Core/Container.php` — `sha256:d57823eb036b02c5efed4cd1aa621760d1f87c86c36e47e60ed9337b4cca7fa2`
- `src/Core/Contracts/Module.php` — `sha256:fc2c8ed8e856c3027dafca5e5e7dd99f5dd3359c38f348999e1e223de024d526`
- `src/Core/Environment/EnvironmentCheck.php` — `sha256:20deca38266d177a5ed11493141501092a9dc1f2468981d67945996429283aa2`
- `src/Admin/AdminModule.php` — `sha256:3511b2866dafd11fde50cfc073cb9cade1a0335e1d025d9421da8bccda4a08e2`
- `src/WooCommerce/WooCommerceModule.php` — `sha256:985e85034bb68bc45439e7d1e0eee776ac1d16bef12eb60235bc6b5c42bab05a`
- `tests/Unit/FoundationTest.php` — `sha256:55ddd46b35975663406e110ed8838184a64dea98fc5f0dadeda96aafda6e99f4`
- `docs/giai-doan-2-d-r4.md` và `docs/giai-doan-2-d-r3-report.md` — hồ sơ gate D còn yêu cầu Reviewer/Owner confirmation.
