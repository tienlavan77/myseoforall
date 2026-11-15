# My SEO For All — Kế hoạch phát triển

> Kế hoạch phát triển tổng thể cho plugin WordPress/WooCommerce `My SEO For All`. Tài liệu này trình bày thứ tự xây dựng, kết quả mong muốn, guardrail và cách kiểm chứng; không phải Sprint Plan, danh sách ticket hoặc quyền triển khai tự động.

## 1. Mục tiêu sản phẩm

Xây dựng một plugin SEO chuyên nghiệp cho WordPress và WooCommerce, ưu tiên tiếng Việt, có AI Assistant tùy chọn. Plugin phải giúp chủ website:

- Quản lý dữ liệu SEO cho bài viết, trang và sản phẩm.
- Xuất metadata và structured data đúng, không trùng lặp.
- Hiểu dữ liệu sản phẩm WooCommerce và biến thể.
- Phân tích, đề xuất và tạo bản nháp nội dung bằng AI có kiểm soát.
- Hoạt động an toàn khi coexist với plugin SEO khác.
- Bảo vệ dữ liệu, API key, quyền người dùng và khả năng nâng cấp.

## 2. Nguyên tắc kiến trúc

- Modular monolith hướng feature, không big-bang rewrite.
- Composition root duy nhất; dependency qua constructor.
- Business rule tách khỏi WordPress/WooCommerce adapter.
- Mỗi dữ liệu có một owner và một nguồn sự thật.
- Mỗi output SEO có một owner phát hành tại một thời điểm.
- AI chỉ đề xuất/tạo draft; người dùng phải preview và phê duyệt.
- Không bịa schema, giá, tồn kho, review hoặc thông số sản phẩm.
- Dùng WordPress session, nonce và capability cho Admin; không tự xây JWT nếu chưa có nhu cầu contract.
- Mọi thay đổi option/meta/output có versioning và migration khi cần.
- Tối ưu sau khi behavior và contract đã được kiểm thử.

Tham chiếu chi tiết: [`myseo-architecture-principles.md`](myseo-architecture-principles.md).

## 3. Kiến trúc module định hướng

```text
myseoforall.php
src/
  Core/                  bootstrap, config, contracts, capability
  Seo/                   canonical SEO model và output ownership
  Content/               chuẩn hóa context bài viết/trang
  WooCommerce/           product/variation adapter
  StructuredData/        JSON-LD builders và validator
  Integrations/          plugin SEO/provider adapters
  AiAssistant/           use cases, providers, draft workflow
  Privacy/               consent, redaction, retention
  Admin/                 settings, metabox, preview, notices
  Infrastructure/        WordPress, HTTP, persistence, cache

tests/
  Unit/
  Integration/
  Contract/
  E2E/
docs/
```

Tên thư mục là định hướng; implementation phải xác nhận boundary bằng use case thực tế, không tạo abstraction hàng loạt trước khi có consumer.

## 4. Lộ trình theo giai đoạn

### Giai đoạn 1 — Nền móng plugin

**Xây dựng:**

- Bootstrap plugin WordPress chuẩn.
- Kiểm tra WordPress/WooCommerce dependency và phiên bản.
- Namespace/prefix `MySeo`/`myseo_`.
- Composition root, provider registration và module lifecycle.
- Settings có version, activation/deactivation và migration runner cơ bản.
- Capability, nonce, sanitization, escaping và error boundary.
- Khung kiểm thử và quy tắc lint/static analysis.

**Kết quả cần đạt:**

- Plugin bật/tắt an toàn.
- WooCommerce thiếu không gây fatal error.
- Có trang trạng thái dependency rõ ràng.
- Có test bootstrap và permission cơ bản.

**Không làm:**

- Không xây toàn bộ SEO/AI trong bootstrap.
- Không đưa business rule vào file entry point.

### Giai đoạn 2 — SEO Core

**Xây dựng:**

- Canonical SEO model cho post, page và product.
- Field title, meta description, canonical, robots, social metadata.
- Validation, default template, source precedence và reset.
- Metabox/editor panel tiếng Việt.
- Search/social preview.
- Output ownership và cơ chế tránh duplicate output.

**Kết quả cần đạt:**

- Một nguồn sự thật cho từng trường.
- Có thể lưu, đọc, preview và reset dữ liệu.
- Output đúng khi không có plugin SEO khác.
- Có thể tắt output của My SEO For All khi plugin khác là owner.

### Giai đoạn 3 — WooCommerce SEO

**Xây dựng:**

- Product và variation adapter dùng WooCommerce CRUD API.
- Chuẩn hóa tên, mô tả, giá, tiền tệ, tồn kho, SKU, thương hiệu, danh mục, thuộc tính, ảnh và review khi có.
- Metabox sản phẩm.
- Kiểm tra dữ liệu sản phẩm thiếu hoặc mâu thuẫn.
- Chính sách product/variation schema.
- Kiểm tra HPOS và các phiên bản WooCommerce mục tiêu.

**Kết quả cần đạt:**

- Sản phẩm đơn và biến thể không bị đọc sai.
- Schema không bịa dữ liệu.
- Không thay đổi giá, tồn kho hoặc dữ liệu thương mại gốc.
- WooCommerce không hoạt động thì module báo trạng thái thay vì lỗi.

### Giai đoạn 4 — Structured Data và technical SEO

**Xây dựng:**

- Engine JSON-LD tập trung.
- `Article`, `BlogPosting`, `Product`, `Offer`, `BreadcrumbList`, `Organization`, `WebSite` và loại phù hợp.
- Validator nội bộ, field omission khi thiếu dữ liệu.
- Breadcrumb, canonical và robots integration.
- Sitemap bài viết/sản phẩm theo ownership policy.
- Conflict detection với WordPress core và plugin SEO khác.

**Kết quả cần đạt:**

- JSON-LD phản ánh nội dung hiển thị.
- Không xuất trùng schema/sitemap.
- Có contract/snapshot test cho output.
- Tác vụ sitemap không làm chậm request frontend.

### Giai đoạn 5 — Tích hợp plugin SEO

**Xây dựng:**

- Detection nhiều plugin SEO.
- Ownership/coexistence policy.
- Adapter đầu tiên có phạm vi rõ ràng.
- Đọc/import dữ liệu hiện có.
- Preview thay đổi trước khi ghi.
- Khả năng rollback hoặc giữ bản sao dữ liệu khi migration.

**Kết quả cần đạt:**

- Người dùng không phải nhập lại dữ liệu một cách mù quáng.
- Không ghi trực tiếp meta key bên thứ ba ngoài adapter.
- Không tự tắt plugin khác.
- Nhiều plugin cùng tồn tại có cảnh báo rõ ràng.

### Giai đoạn 6 — AI Assistant tiếng Việt

**Xây dựng:**

- Provider abstraction, cấu hình provider và API key phía server.
- Bật/tắt AI và privacy consent.
- Payload tối thiểu, redaction, timeout, retry giới hạn và lỗi dễ hiểu.
- Phân tích SEO cục bộ và phân tích qua AI tùy chọn.
- Đề xuất title/meta/heading/dàn ý/FAQ.
- Tạo draft bài viết và mô tả sản phẩm.
- Preview, apply từng đề xuất, reject, lịch sử và revert trong phạm vi phù hợp.
- Usage/limit cơ bản.

**Kết quả cần đạt:**

- Không gọi API khi chưa được bật.
- Không lưu API key ở frontend.
- AI không tự xuất bản, đổi giá hoặc đổi tồn kho.
- Người dùng biết dữ liệu nào được gửi và có quyền phê duyệt.
- Dữ liệu sản phẩm không bị bịa.

### Giai đoạn 7 — Hoàn thiện chuyên nghiệp

**Xây dựng:**

- Dashboard tổng quan SEO.
- Audit và cảnh báo theo mức độ.
- Cache/invalidation theo hook.
- Bulk analysis có giới hạn và background processing khi có bằng chứng cần thiết.
- Accessibility cho Admin.
- Internationalization sau khi tiếng Việt ổn định.
- Documentation, changelog, upgrade path và package/release validation.
- Security/privacy review và compatibility matrix.

**Kết quả cần đạt:**

- Có thể vận hành trên website thật với quy mô phù hợp.
- Có thông báo lỗi và hướng xử lý rõ.
- Nâng cấp không làm mất dữ liệu.
- Có quy trình phát hành lặp lại được.

## 5. Thứ tự ưu tiên bắt buộc

```text
Nền móng
  -> SEO Core
  -> WooCommerce adapter
  -> Structured Data
  -> Output ownership
  -> Integration
  -> AI Assistant
  -> Dashboard/scale/release
```

Không bắt đầu bằng AI viết bài trước khi canonical data model, product context và output ownership ổn định. AI dựa trên dữ liệu không đáng tin sẽ tạo nội dung có thể hay về câu chữ nhưng sai về SEO hoặc thông tin thương mại.

## 6. Ma trận chất lượng tối thiểu

| Khu vực | Kiểm chứng bắt buộc |
|---|---|
| Bootstrap | Activate/deactivate, dependency, upgrade, no fatal |
| Security | Nonce, capability, sanitization, escaping, secret handling |
| SEO Core | Field validation, precedence, persistence, preview, reset |
| WooCommerce | Product/variation/CRUD, price/stock truth, HPOS compatibility |
| Structured Data | JSON-LD contract, omission, no fake data, no duplicate output |
| Integration | Detection, adapter contract, import preview, coexistence |
| AI | Consent, payload minimization, provider errors, preview/apply/revert |
| Performance | Cache invalidation, sitemap batching, bounded requests |
| Release | Build, package, migration, checksum, rollback evidence |
| UX | Vietnamese copy, accessibility, actionable errors |

## 7. Các quyết định chưa khóa

Những điểm sau cần được chốt trước khi lập kế hoạch triển khai chi tiết cho các phần phụ thuộc:

1. Plugin SEO bên thứ ba đầu tiên cần tích hợp.
2. Nhà cung cấp AI đầu tiên.
3. Phiên bản WordPress/WooCommerce tối thiểu cần hỗ trợ.
4. Mức độ sitemap trong MVP.
5. Cơ chế cấp phép/thương mại.
6. Chính sách lưu lịch sử AI và thời gian retention.
7. Phạm vi hỗ trợ sản phẩm biến thể ở phiên bản đầu tiên.

## 8. Rủi ro chính

- Nhiều plugin SEO cùng xuất output gây duplicate hoặc canonical conflict.
- Schema Product/Offer không khớp với giá, tồn kho hoặc nội dung hiển thị.
- AI bịa dữ liệu nếu payload và guardrail không chặt.
- API provider lỗi, chậm hoặc thay đổi response contract.
- Migration meta/options làm mất dữ liệu nếu thiếu backup/rollback.
- Sitemap và audit gây tải lớn trên website nhiều sản phẩm.
- Tích hợp WooCommerce phụ thuộc phiên bản hoặc HPOS không được kiểm thử đủ.
- Giao diện AI khiến người dùng áp dụng nội dung chưa kiểm tra.

Mỗi rủi ro phải có verification và owner xử lý trước khi tính năng tương ứng được xem là hoàn tất.

## 9. Tiêu chí thành công cấp sản phẩm

- Người dùng quản lý được SEO cho bài viết, trang và sản phẩm WooCommerce từ giao diện tiếng Việt.
- Output SEO và structured data đúng, có thể kiểm chứng và không trùng ownership.
- Website không bị fatal error khi dependency tùy chọn không có.
- AI chỉ hoạt động khi được bật, không làm lộ secret và không tự xuất bản.
- Nội dung sản phẩm không chứa dữ liệu bịa.
- Tích hợp plugin SEO có preview, cảnh báo và đường lui.
- Mỗi giai đoạn có test behavior/contract tương ứng và tài liệu nâng cấp.
