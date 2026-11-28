# Giai đoạn 3 — Increment C: Hoàn thiện Local SEO Analyzer

> Hướng dẫn Architecture cho System Engineer. Đây là boundary và acceptance ở cấp increment; không phải Sprint Plan, ticket list hay mutable-file manifest.

## 1. Trạng thái, bối cảnh và phạm vi

- Increment B đã được Owner chốt nghiệm thu trong phạm vi **shared AI output boundary offline** tại snapshot `c7793d992da7f27b08c18efe9c76fbd3eb176b7e`; receipt kỹ thuật là bằng chứng verification, không phải production readiness.
- Increment C là phần tiếp theo của **SEO Core (Giai đoạn 2)**. Tên file “Giai đoạn 3” chỉ là numbering delivery; không được hiểu là đã mở toàn bộ Giai đoạn 3 WooCommerce SEO.
- Mục tiêu là hoàn thiện analyzer local, deterministic, read-only, không network và không ghi dữ liệu.
- Không mở AI gateway, secret storage, Preview/Apply, Rank Math write/ownership transfer, runtime hooks hoặc thay đổi giá/tồn kho.

## 2. Evidence hiện tại và gap

`src/SEO/Analysis/LocalSeoAnalyzer.php` hiện có rule nền tảng cho title, meta description, content, H1/heading, canonical thiếu, image alt, internal links và một số field product/variation. `tests/Unit/SeoCoreTest.php` đã có test finding ổn định, severity order và `suggested_fix`.

Các gap trước khi code:

- input schema của analyzer chưa đóng theo field/type/missing/null/invalid;
- `SeoContext::fromArray()` chỉ allowlist/copy, không tự bảo đảm typed contract cho analyzer; `ProviderPayloadSchema` cũng không tự áp dụng cho `SeoContext`;
- equal-severity tie-breaker chưa khóa;
- canonical mới phát hiện thiếu, chưa kiểm tra URL hợp lệ;
- heading mới đếm H1/heading, chưa kiểm tra hierarchy;
- internal link mới kiểm tra danh sách rỗng, chưa phân loại semantics/URL;
- offer local, price consistency và parent/variation semantics chưa hoàn tất;
- `finding()` còn nhận `mixed`, evidence hiện có thể phản ánh input nhạy cảm;
- Unicode/UTF-8 và fallback khi thiếu `mbstring` chưa thành compatibility policy.

## 3. Input contract phải chốt trước implementation

SE phải lập fixture/schema matrix cho đúng các field mà analyzer thực sự đọc. Với mỗi field cần ghi: resource (`post`/`article`/`page`/`product`/`variation`), kiểu chính xác, ví dụ hợp lệ, missing, explicit `null`, sai kiểu và nguồn thắng nếu có nhiều nguồn. Không cast trước khi kiểm tra kiểu; dữ liệu sai không được biến thành finding “thiếu” một cách âm thầm.

Tối thiểu phải chốt:

- `type`, `title`, `meta_description`, `content`, `url`, `canonical`;
- `headings`, `images`, `internal_links`;
- `offer`, `price`, `currency`, `availability` nếu các field này được mở rộng vào input contract;
- `id`, `variation.id`, `variation.parent_id`, `sku`, `stock` cho product/variation.

`SeoContext::get()` không phân biệt thiếu key với key có giá trị `null`; nếu rule cần phân biệt hai trạng thái, phải dùng contract/accessor phù hợp, không suy luận từ `get()` hiện tại. Giữ fixture `post` hiện có và chốt rõ `post`/`article` là alias tương thích hay resource khác. Nếu mở rộng schema công khai, phải ghi compatibility decision trước khi code.

### Nguồn thắng và ownership input

Nếu vừa có field cấp đầu vừa có `seo.*`, C phải ghi rõ source precedence. Nếu C chỉ đọc field cấp đầu như implementation hiện tại, adapter/context builder sau này chịu trách nhiệm canonical/title/meta precedence; analyzer không tự bịa source. Không coi label nguồn là bằng chứng dữ liệu đã được đọc từ WordPress, WooCommerce hay Rank Math.

## 4. Outcomes và rule contract

### O1 — Finding contract và evidence

Mọi finding có exact shape và kiểu:

- `code`: code trong registry;
- `severity`: chỉ `blocker`, `warning`, `info`;
- `message`: safe, deterministic, bounded;
- `field`: field/context tương ứng;
- `evidence`: reason/count/field hoặc giá trị đã lọc theo registry, bounded;
- `suggested_fix`: hướng xử lý deterministic, không phải command ghi dữ liệu.

Registry phải chốt `code → severity, field, evidence shape`. Không đưa URL/query, anchor, markup, object nền tảng hoặc giá trị commerce thô vào evidence mới; đặc biệt không sao chép URL có thể chứa dữ liệu nhạy cảm. Giữ code hiện có; code mới hoặc đổi nghĩa cần compatibility note và test.

### O2 — Deterministic ordering

Thứ tự bắt buộc: `blocker` → `warning` → `info`. Trong cùng severity, dùng rank cố định của code registry, sau đó `field`, rồi khóa ngữ nghĩa ổn định từ evidence đã lọc. Chỉ dùng thứ tự phát hiện nếu hai finding vẫn hoàn toàn giống nhau.

Test phải đảo input/traversal khi không có thứ tự ngữ nghĩa, kiểm tra nhiều code cùng severity và hai finding cùng code/field nhưng evidence khác nhau. Không phụ thuộc locale, hash iteration, database order hay network.

### O3 — Canonical và URL validity

- `canonical_missing` chỉ áp dụng khi policy đã chốt rằng public URL có giá trị hợp lệ và canonical absent/blank.
- Canonical present-but-invalid là finding riêng, không gộp với missing.
- Canonical hợp lệ khi là URL HTTP(S) tuyệt đối, có host hợp lệ và không có userinfo. Trim whitespace/control theo policy; không fetch, DNS lookup hay kiểm tra crawlability.
- Chốt fragment, port, IDN và canonical khác host là policy cú pháp/ownership riêng; khác host không tự đồng nghĩa URL sai.
- Relative URL chỉ hợp lệ khi contract riêng cho phép; không kế thừa ngầm policy của internal link.

### O4 — Heading hierarchy

- Giữ rule đúng một H1, nhưng ghi rõ analyzer chỉ thấy markup đầu vào; H1 do theme render ngoài input không được suy diễn.
- Cho phép đi lên nhiều cấp, ví dụ `h4 → h2`; chỉ báo bỏ cấp khi đi sâu, ví dụ `h2 → h4`, theo policy đã chốt.
- Tách không có heading khỏi hierarchy sai bằng finding code riêng.
- Heading giả trong comment/script, markup thiếu thẻ đóng, nội dung trắng và malformed markup phải có behavior deterministic, không fatal. Không render browser, fetch tài nguyên hay tự sửa content.
- Nếu dùng parser/extension, ghi rõ behavior khi extension thiếu; không mặc định môi trường có sẵn.

### O5 — Internal-link semantics

Chốt trước khi tạo finding code:

- `internal_links` là danh sách đã lọc hay danh sách ứng viên hỗn hợp;
- “nội bộ” là cùng host hay cùng origin;
- relative URL, fragment, port và khác scheme xử lý thế nào;
- base/public URL thiếu, sai, danh sách rỗng, chỉ external, entry sai kiểu và anchor trắng phân biệt ra sao.

Không phát hiện “thiếu internal link” khi thiếu base khiến phân loại không xác định. Anchor trắng chỉ đánh giá anchor được cung cấp; không tự suy diễn accessible label từ ảnh. Không fetch URL và không thêm/sửa link.

### O6 — Offer local và consistency

Chỉ kiểm tra field có trong input contract; thiếu offer/schema không tạo mismatch giả. Kiểm tra kiểu nội tại của `offer`, `currency`, `availability` và đối chiếu giá chỉ khi giá sản phẩm và giá offer cùng có mặt, cùng nghĩa và cùng policy.

Giá dùng decimal string/canonical decimal, không dùng số thực làm chuẩn; `0` và `0.00` là giá có mặt. Tách missing, invalid và mismatch. `stock = 0` không tự xác định availability nếu chưa biết policy backorder. Đối chiếu JSON-LD/schema chỉ mở khi có input shape/version được duyệt. Analyzer chỉ đọc commerce data.

### O7 — Product/variation semantics

- Phân biệt `product` và `variation`; fixture riêng cho product đơn và variation.
- Variation relationship tối thiểu: `variation.id` và `variation.parent_id` là số nguyên dương, khác nhau; nếu top-level `id` có mặt thì khớp variation ID.
- Tách missing relationship, sai shape và xung đột ID. Không xác minh parent tồn tại bằng database, không lấy field từ parent và không bịa giá/tồn kho/SKU.
- Chốt riêng `product_fields_missing` áp dụng cho product/variation và severity `blocker` hiện tại; không đổi severity âm thầm.

### O8 — Unicode và compatibility

Chọn và ghi một policy có bằng chứng: yêu cầu `ext-mbstring`/health check hoặc abstraction/fallback tương đương. Đơn vị đo là Unicode code points; không trộn byte của `strlen()` với character limit. Chốt UTF-8 invalid, trim và normalization/combining characters; chuỗi dựng sẵn và chuỗi tổ hợp phải có test.

Fallback không được âm thầm cho kết quả khác policy; nếu không bảo đảm tương đương, trả trạng thái unavailable thay vì false pass. Plumbing vào WordPress Health Check để Increment E, nhưng C phải chứng minh behavior cục bộ.

## 5. Guardrails

- Analyzer chỉ nhận normalized context theo input contract; không nhận `WP_Post`, `WC_Product` hay gọi platform API trực tiếp trong rule.
- Không network, AI call, Rank Math write, persistence, Apply hoặc side effect.
- Không thay đổi giá, tồn kho, SKU hoặc dữ liệu gốc; không thay đổi output ownership, sitemap hay JSON-LD ownership.
- Message/evidence/suggested_fix bounded, không lộ secret/payload đầy đủ.
- Không coi finding là command, truth source, semantic PII proof hay production compatibility.
- Breaking public contract/field semantics phải quay lại Architecture/Owner decision.

## 6. Acceptance criteria

1. Input schema matrix được chốt cho mọi field rule đọc, gồm missing/null/invalid và `post`/`article` policy.
2. Finding có đủ sáu field đúng kiểu; registry khóa code/severity/field/evidence shape.
3. Ordering deterministic theo severity + rank code + field/evidence tie-breaker; test input đảo thứ tự và cùng code/field.
4. Canonical absent/blank/invalid được phân biệt; URL boundary có test, không fetch.
5. Heading hierarchy đúng chiều, malformed/comment/script/closing-tag cases và đúng một H1 có test.
6. Internal-link empty/invalid/external/internal/base/anchor semantics có test theo host/origin policy.
7. Offer local missing/invalid/mismatch, decimal `0`, currency/availability và product/variation relationship có fixture; không mutate commercial data.
8. Unicode boundary, UTF-8 invalid, combining characters và `mbstring` policy có evidence/test.
9. Input không bị mutate; không network, writer, persistence hoặc side effect.
10. Focused analyzer suite và toàn bộ `tests/Unit` pass qua `run_check` trên revision/checksum cố định.
11. Report ghi command, run ID, status, exit code, base SHA, scope và checksum; Reviewer review độc lập và Owner xác nhận exit gate.

## 7. Hướng dẫn delivery cho SE

1. Lập fixture/input matrix và registry/evidence policy trước hoặc cùng implementation; không chốt file manifest trong tài liệu.
2. Giữ finding code hiện có; nếu thêm code cho canonical/heading/link/offer/variation, ghi compatibility test.
3. Kiểm tra type trước khi cast; không biến null/missing/invalid thành cùng một trạng thái nếu contract phân biệt.
4. Chốt URL/heading/link policy trước khi viết rule; dùng fixture behavior, không snapshot mơ hồ.
5. Chốt Unicode policy trước khi thay `textLength()`; không đổi ngưỡng title/meta nếu chưa có quyết định sản phẩm.
6. Chạy focused analyzer test rồi toàn bộ `tests/Unit` qua `run_check` trên revision cố định; đối chiếu checksum sau run.
7. Báo cáo phần implemented, verification, limitations và rule chưa chứng minh do thiếu runtime WordPress/WooCommerce.
8. Không tự đóng exit gate; gửi Reviewer/Owner xác nhận.

## 8. Không làm trong Increment C

- Không triển khai Rank Math adapter/ownership transfer (D).
- Không wire runtime hooks hoặc Health Check production integration (E); chỉ có policy/evidence cục bộ cần cho C.
- Không mở AI Settings, secret, gateway/network hoặc Preview/Apply (F–H).
- Không triển khai WooCommerce CRUD adapter hay thay đổi commercial data.

## 9. Evidence

- Feedback: `docs/giai-doan-3-increment-c-feedbeck.md` — `sha256:19ed04df35fb8a9623829e587b43895d5c80d1b0e0847fdeb0e21d859f4086bb`.
- Kế hoạch trước cập nhật: `sha256:8538824e79a05280a0a833eacf58744e091449c880db026134ca797be11eae41`.
- `src/SEO/Analysis/LocalSeoAnalyzer.php` — hiện trạng analyzer: `sha256:d80e3782ce83286913eeaea4ee566541f817845bd4a9a84cce0bb489f8eaadaa`.
- `tests/Unit/SeoCoreTest.php` — test hiện trạng: `sha256:0b21f3eb98ff3d7fab87ca8d2fc3c103ca307bc62846870bc106ccece0cf42f9`.
- `docs/giai-doan-2-increment-B-r3-report.md` — evidence B: `sha256:0ced83b46653087e4b509e9e4459f1ae6b0a9f1920ba91afcc65ec3aebd44e90`.

Tài liệu này cập nhật theo feedback C và chỉ cấp hướng dẫn trong boundary Local SEO analyzer offline. Mọi thay đổi chạm public contract, commercial write, network hoặc ownership phải quay lại Architecture/Owner decision gate.