# Giai Đoạn 3 — Increment 3B: Controlled Apply, Optimistic Concurrency & Canonical SEO Storage

## 1. Mục tiêu và phạm vi

Increment 3B mở quyền **Apply có xác nhận** từ Preview của Increment 3A. Người biên tập có thể áp dụng từng đề xuất AI đã xem trước vào dữ liệu SEO chuẩn của My SEO For All, nhưng không auto-save, không auto-publish và không ghi đè mù dữ liệu mới.

### In scope

- Endpoint AJAX authenticated `wp_ajax_myseo_ai_apply`.
- Canonical SEO meta namespace của My SEO For All cho post/page/product.
- Snapshot fingerprint từ Preview và kiểm tra optimistic concurrency khi Apply.
- Apply chọn lọc từng trường: `title`, `meta_description`, `canonical`, hoặc `description` sản phẩm theo contract.
- Ownership-aware behavior và hàng rào WooCommerce.
- PHPUnit offline coverage cho authorization, nonce, fingerprint, validation, zero unintended writes và rollback/failure behavior.

### Out of scope

- Frontend `<title>`, `<meta description>`, canonical emission (Increment 3C).
- Ghi vào meta key nội bộ của Rank Math hoặc adapter ghi Rank Math.
- Lưu lịch sử prompt/response AI, cache payload hoặc retention mới.
- Sửa `myseoforall.php`.

## 2. Guardrails bất biến

1. Không sửa `myseoforall.php`.
2. Không gọi HTTP/DNS trong Apply; Apply chỉ dùng dữ liệu Preview đã ký bởi fingerprint và dữ liệu hiện tại đọc từ WordPress/WooCommerce.
3. Mọi request phải qua `edit_post` đúng ID, nonce riêng `myseo_ai_apply`, kiểm tra task/field/resource và validation server-side.
4. Không tin `proposal`, `post_id`, `resource_type`, `source_fingerprint` hoặc field từ browser nếu không khớp envelope/allowlist.
5. Không lộ API key, gateway URL nội bộ, raw exception, trace hoặc raw payload.
6. Không ghi nếu validation, authorization, ownership policy hoặc fingerprint thất bại.
7. Không auto-publish; giữ nguyên `post_status`, tác giả, ngày sửa và các field không được chọn.
8. WooCommerce: tuyệt đối không ghi/chạm `regular_price`, `sale_price`, `price`, `stock_quantity`, `stock_status`, `sku`, reviews, orders hoặc metadata thương mại ngoài field description được cho phép.
9. Không ghi trực tiếp meta key nội bộ của Rank Math.
10. Không dùng `update_post_meta()` trước khi hoàn tất toàn bộ validation và fingerprint check; mọi lỗi phải fail-closed.

## 3. Canonical storage contract

Dùng namespace riêng, ổn định và không xung đột:

```text
_myseo_title
_myseo_meta_description
_myseo_canonical
```

### 3.1 Bảng canonical source/precedence bắt buộc

Preview và Apply phải dùng cùng một abstraction đọc snapshot; không được để hai luồng tự chọn nguồn khác nhau.

| Resource/field | Nguồn đọc và ghi duy nhất | `null`/rỗng | Ghi chú |
|---|---|---|---|
| `post.title` | `_myseo_title` | `null` nếu key chưa tồn tại; chuỗi rỗng là giá trị sau normalize | Không đọc/ghi meta nội bộ của Rank Math |
| `post.meta_description` | `_myseo_meta_description` | `null` nếu key chưa tồn tại; chuỗi rỗng là giá trị sau normalize | Không fallback sang nguồn khác |
| `post.canonical` | `_myseo_canonical` | `null` nếu key chưa tồn tại; chuỗi rỗng chỉ sau URL normalization hợp lệ | Không fallback sang Rank Math |
| `page.title`, `page.meta_description`, `page.canonical` | Ba key My SEO tương ứng ở trên | Theo cùng quy tắc post | Cùng reader/writer contract |
| `product.description` | WooCommerce CRUD description adapter duy nhất | `null` nếu product/description không tồn tại; chuỗi rỗng là giá trị hợp lệ | Không dùng post content/excerpt/meta song song |

Field không có nguồn canonical đã khóa thì **chưa được Apply**. Giá trị lưu phải là plain text đã normalize/validate; không lưu HTML/JavaScript nguy hiểm, exception, provenance thô, API output envelope hoặc secret.

Khi Rank Math đang sở hữu `title_meta`, Apply mặc định không ghi sang Rank Math; chỉ có thể ghi namespace My SEO theo ma trận ownership ở mục 4. Không tự suy diễn quyền ghi từ trạng thái read-only của 3A.

## 4. Preview envelope, fingerprint và ownership

Mở rộng response thành công của Preview bằng các trường cần cho Apply nhưng vẫn giữ backward-compatible shape đã có; không đưa secret vào envelope:

```json
{
  "status": "ok",
  "post_id": 123,
  "resource_type": "article",
  "task": "title_meta_suggestion",
  "current": {"title": "...", "meta_description": null},
  "proposal": {"target_field": "title", "value": "...", "language": "vi", "provenance": "editor"},
  "source_fingerprint": "sha256:...",
  "apply_token": "signed-server-token",
  "read_only": true
}
```

`source_fingerprint` bảo vệ tính mới của snapshot; `apply_token` bảo vệ tính toàn vẹn của proposal. Token do server phát hành, có thể stateless và có thời hạn, không lưu prompt/response/payload AI mới vào database. Token phải bind tối thiểu với object ID, resource type, task, target field, normalized proposal, `source_fingerprint` và ownership/confirmation state.

Apply phải xác minh token và đối chiếu exact các trường request với token. Proposal bị sửa nhưng giữ nguyên fingerprint phải trả `422 invalid_proposal`, không đọc persistence và không ghi.

Fingerprint được tính server-side từ snapshot field-scoped gồm:

- object ID và resource type;
- source field liên quan đến task;
- canonical value của field mục tiêu;
- ownership state;
- với product: description snapshot và identity cần thiết.

Dùng một hàm canonical serialization chung cho Preview/Apply: version snapshot, thứ tự key cố định, quy tắc cố định cho `null`/rỗng, Unicode/whitespace, URL đã normalize và JSON encoding. Hash bằng SHA-256. Không đưa timestamp hiện tại, credential, gateway URL hoặc dữ liệu ngoài snapshot contract vào hash. Chỉ dùng `post_modified_gmt` nếu adapter xác nhận đó là revision marker liên quan; không dùng toàn bộ revision bài viết làm stale mặc định.

### 4.1 Ma trận Rank Math ownership

| Trạng thái | Confirmation | Kết quả |
|---|---|---|
| Rank Math không active/không sở hữu field | Không cần | Ghi namespace My SEO |
| Rank Math sở hữu field | Thiếu | `409 ownership_conflict` |
| Rank Math sở hữu field | Có confirmation server-validated, đúng field và snapshot | Chỉ ghi namespace My SEO |
| Confirmation sai field, cũ hoặc tự tạo từ browser | Không hợp lệ | `409 ownership_conflict` |
| Yêu cầu ghi meta key Rank Math | Không bao giờ | `409 ownership_conflict` |
| Field thương mại WooCommerce | Không áp dụng | `422 protected_field` |

Confirmation không phải boolean tự do; phải bind với `apply_token`, field, object và fingerprint.

## 5. Apply endpoint và gate ordering

Đăng ký **chỉ** `wp_ajax_myseo_ai_apply`; không đăng ký `nopriv`.

`handleApplyRequest()` phải dừng ngay theo thứ tự:

1. Request là POST và array hợp lệ → `400 malformed_request`.
2. Parse exact positive `post_id` → `400 invalid_post_id`.
3. Capability `edit_post` đúng ID → `403 forbidden`.
4. Verify nonce `myseo_ai_apply` → `403 invalid_nonce`.
5. Validate `resource_type`, `field` và task/resource compatibility → `400 unsupported_resource` hoặc `unsupported_field`.
6. Validate `apply_token`, `source_fingerprint` và proposal exact; proposal là string bounded, không HTML/script nguy hiểm → `422 invalid_proposal`.
7. Đọc snapshot hiện tại qua reader/repository chuẩn; nếu object không tồn tại → `404 not_found`.
8. Tính lại fingerprint server-side và so khớp exact với Preview → `409 stale_preview` nếu lệch.
9. Kiểm tra ownership policy và WooCommerce boundary → `409 ownership_conflict` hoặc `422 protected_field`.
10. Ghi đúng một trường đã xác nhận, qua adapter/storage boundary phù hợp.
11. Đọc lại giá trị đã ghi và trả response đóng; nếu persist/read-back thất bại phải trả `502 storage_failed` và không giả vờ thành công.

Mọi lỗi trả body đúng ba khóa:

```json
{"status":"error","code":"stale_preview","message":"Dữ liệu đã thay đổi; hãy tạo bản xem trước mới rồi thử lại."}
```

### 5.1 Bảng status và downstream calls

| Code | HTTP | Đọc snapshot | Persistence |
|---|---:|---:|---:|
| `malformed_request`, `invalid_post_id`, `forbidden`, `invalid_nonce`, `unsupported_resource`, `unsupported_field`, `invalid_proposal` | 400/403/422 | Không | Không |
| `not_found` | 404 | Tối thiểu để xác nhận object | Không |
| `stale_preview` | 409 | Có | Không |
| `ownership_conflict` | 409 | Có | Không |
| `protected_field` | 422 | Không quá mức cần thiết | Không |
| `storage_failed` | 502 | Có thể đã đọc/ghi attempt | Có thể đã xảy ra |

Lỗi gate không được gọi persistence. `storage_failed` không được diễn giải là zero-write; trạng thái sau write/read-back failure là không chắc chắn.

## 6. Apply chọn lọc

UI phải cho người dùng xác nhận từng đề xuất; request Apply phải nêu đúng field mục tiêu. Không cho phép một proposal ghi nhiều field ngoài field đã xác nhận.

- `title`: chỉ ghi `_myseo_title`.
- `meta_description`: chỉ ghi `_myseo_meta_description`.
- `canonical`: chỉ ghi `_myseo_canonical` sau URL validation và ownership check.
- product `description`: chỉ ghi description qua adapter được duyệt; không nhận giá, SKU, stock hoặc order data từ client.

Sau Apply, UI hiển thị kết quả tĩnh thành công/lỗi. Không tự gọi lại AI và không tự chuyển sang publish.

## 7. Persistence và failure safety

Tách boundary persistence để test được:

- `CanonicalSeoMetaRepository` cho post/page canonical keys;
- `WooCommerceDescriptionWriter` hoặc adapter tương đương cho product description;
- operation result đóng: `saved`, `stale_preview`, `validation_failed`, `ownership_conflict`, `storage_failed`.

Mỗi Apply chỉ có tối đa một thao tác ghi vào field mục tiêu. Increment 3B không tạo transaction giả cho nhiều field và không thực hiện rollback/compensation write. Nếu write trả lỗi, trả `storage_failed`; nếu write báo thành công nhưng read-back lỗi hoặc không khớp giá trị normalized, cũng trả `storage_failed` và coi trạng thái là không chắc chắn. Không trả `saved` khi chưa read-back xác nhận; không ghi field khác để sửa trạng thái; không auto-publish và không thay đổi post status. UI chỉ hiển thị hướng dẫn an toàn như tải lại dữ liệu hoặc tạo Preview mới.

## 8. Acceptance criteria

### 3B-O1 — Canonical boundary

- Post/page apply ghi đúng namespace My SEO, không ghi key Rank Math.
- Reader đọc lại đúng giá trị canonical theo precedence đã khóa.
- Product description không làm thay đổi giá, tồn kho, SKU, review hoặc order data.

### 3B-O2 — Optimistic concurrency

- Preview trả fingerprint server-side.
- Apply với fingerprint đúng ghi được field đã xác nhận.
- Sau khi source thay đổi, Apply bị từ chối `409 stale_preview`, zero write.
- Fingerprint không chứa secret và không phụ thuộc dữ liệu không ổn định ngoài snapshot contract.

### 3B-O3 — Security and validation

- Sai method/ID/capability/nonce/task/resource/field/proposal đều dừng trước persistence.
- Không raw exception/secret trong response.
- Không Apply nếu Rank Math ownership policy cấm hoặc chưa có xác nhận cần thiết.

### 3B-O4 — Regression and operations

- Không auto-publish; chỉ field đã chọn bị thay đổi.
- Read-back xác nhận giá trị lưu.
- PHPUnit offline chứng minh zero write ở mọi nhánh fail và protected Woo fields không bị chạm.
- Ghi rõ giới hạn offline; nghiệm thu WordPress/WooCommerce thật trước production.

## 9. Test matrix bắt buộc

Bổ sung test focused cho:

- valid Apply post/page cho từng field;
- invalid method, ID, capability, nonce, resource, task và field;
- malformed proposal, HTML/script, quá dài, sai fingerprint;
- stale fingerprint với zero write;
- ownership conflict và Rank Math active;
- product description success;
- attempts gửi price/stock/SKU/review/order field bị từ chối;
- persistence failure/read-back mismatch; không có compensation write;
- proposal bị sửa nhưng giữ nguyên fingerprint phải bị từ chối;
- cùng snapshot tạo cùng fingerprint, thay đổi field liên quan tạo fingerprint mới;
- ownership matrix với Rank Math: thiếu/sai confirmation và confirmation hợp lệ;
- no auto-publish và post status unchanged;
- response schema lỗi ba khóa và không leak marker nhạy cảm.

Chạy:

```text
php vendor/bin/phpunit --do-not-cache-result tests/Unit/<focused-3b-test>.php
php vendor/bin/phpunit --do-not-cache-result tests/Unit
```

Báo cáo exact test count, assertions, changed-file checksums và giới hạn offline.

## 10. Dependency, evidence và handoff cho SE/SL

### 10.1 Điều kiện mở Increment 3B

Chỉ bắt đầu implementation 3B sau khi Increment 3A đã được Owner nghiệm thu đúng revision/checksum và đã chứng minh các guardrail Preview/read-only của 3A. PHPUnit pass riêng lẻ không thay thế Owner acceptance hoặc runtime evidence của 3A.

### 10.2 Phân loại bằng chứng bắt buộc

Báo cáo 3B phải tách riêng:

- **Offline PHPUnit:** gate ordering, apply token, fingerprint determinism/staleness, zero persistence ở các nhánh reject, ownership matrix, protected WooCommerce fields và write/read-back failure.
- **WordPress runtime:** hook AJAX thật, nonce/capability, post/page persistence, canonical read-back và post status unchanged.
- **WooCommerce runtime:** product description qua CRUD adapter và bằng chứng giá, tồn kho, SKU, review, order data không bị thay đổi.
- **Hồ sơ provenance:** exact test count, assertion count, changed-file checksums, source revision và giới hạn của kiểm thử offline.

Không dùng PHPUnit offline để tuyên bố đã chứng minh persistence production, revision behavior thực tế hoặc an toàn tuyệt đối của dữ liệu thương mại WooCommerce.

SE triển khai trong boundary trên; SL tự phân rã ticket, dependency và verification. Không bắt đầu 3C cho tới khi 3B có evidence đúng revision, review gate và nghiệm thu hành vi Apply/stale/ownership/WooCommerce. Không mở rộng 3B thành frontend emission hoặc tự sửa bootstrap.
