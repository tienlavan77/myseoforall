# Giai Đoạn 3 — Increment 3B (Revision 2): Hướng dẫn chỉnh sửa sau kiểm toán `increment-3b-rp.md`

## 1. Kết luận nghiệm thu

- **Trạng thái:** **CHƯA ĐẠT (`REJECTED — REQUIRES R2 FIXES`)**.
- **Tài liệu đối chiếu:**
  - Đặc tả chuẩn: `docs/giai-doan-3/increment-3b.md` (`sha256:1c506342e02185b7e8ec034e1dbaf5c150ebac7da22cd9d4226be4ccef153dc3`).
  - Báo cáo SE: `docs/giai-doan-3/increment-3b-rp.md` (`sha256:3dde0b17fc0a80860d84c72d990b58a8135e9215db0f994b6e2a083f7740da2c`).
- **Điểm đạt cần giữ nguyên:**
  - `myseoforall.php` hoàn toàn không bị đụng chạm (`sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`).
  - Chỉ đăng ký `wp_ajax_myseo_ai_apply`, không có `nopriv`.
  - Không thêm lịch sử/cache AI và không ghi meta nội bộ của Rank Math.
- **Lý do bác bỏ:** Luồng Preview → Apply trên runtime hiện đang **hỏng end-to-end** do lỗi chữ ký token, lệch nguồn fingerprint giữa Preview/Apply, thiếu wiring trong `SeoModule`, thiếu UI Apply trong metabox, vi phạm gate ordering/status codes, thiếu kiểm tra URL/ownership/protected fields và chưa có bộ kiểm thử PHPUnit riêng cho Increment 3B (`0` test 3B).

---

## 2. Snapshot các file đã kiểm toán

- `src/SEO/Runtime/ApplyController.php` — `sha256:e4e1674d0bc0f2ffecc63a90d1a097912207e2d8d3602ccbbb474eee24e77195`
- `src/SEO/Runtime/ApplyToken.php` — `sha256:a4e85df1e5e07e55dec6558bf338c016f3a3b2d9436ef45067853930649539c6`
- `src/SEO/Runtime/ApplyFingerprint.php` — `sha256:a05fcaafd6f70071e866f864a093dd06fc878b5b51580f3ee1ff88cc61ea9d4f`
- `src/SEO/Runtime/CanonicalSeoMetaRepository.php` — `sha256:62c1d5ef69cb6eb702d7933062e3e2283befe0ce93937898dbe62fab72864108`
- `src/SEO/Runtime/WooCommerceDescriptionWriter.php` — `sha256:d62cf44c1d3ad2da9b620c8de7633098a7effad9dd1052277ce924555871869e`
- `src/SEO/Runtime/EditorPreviewController.php` — `sha256:94c075bd2d7ebf7b41eb3e389fea41f4b149dacfd4c012e0ea6f0b453f69b727`
- `src/SEO/Runtime/SeoModule.php` — `sha256:62effe70a9eab2df6b453e30f1f6ca3e4f410e2d527adb0da7ac2366fc6a6581`

---

## 3. Danh mục lỗi bắt buộc sửa trong R2 (`3B2-1` .. `3B2-9`)

### `3B2-1` [Blocker] `ApplyToken` ký sai dữ liệu khiến token tự hỏng chữ ký
- **Hiện trạng (`ApplyToken.php:20-31`):** `issue()` gọi `signature($payload)` trên chuỗi `base64_encode` chuẩn (còn `+`, `/`, `=`), nhưng trả về chuỗi đã chuyển sang base64url và `rtrim('=')`. Khi `verify()` tách `$payload`, nó gọi `signature($payload)` trên chuỗi base64url đã bỏ `=`, nên mọi payload có độ dài không chia hết cho 3 hoặc chứa `+/` đều **tự thất bại `hash_equals()`**. Ngoài ra `base64_decode(..., true)` không có padding chuẩn dễ lỗi tùy chuỗi.
- **Cách sửa:** Encode payload sang `base64url` không dấu `=` trước, rồi **ký và verify trên chính chuỗi `$encodedPayload` đó**. Khi decode phải bù padding `(4 - strlen($s) % 4) % 4` và kiểm tra ký tự hợp lệ `^[A-Za-z0-9_-]+$`. Cho phép truyền `$clock` (callable trả `int`, mặc định `time`) để test hết hạn (`exp`) tất định offline.

### `3B2-2` [Blocker] `SeoModule` chưa nối Apply vào `EditorPreviewController`, thiếu `not_found` và thiếu `ownershipResolver`
- **Hiện trạng (`SeoModule.php:118-161`):**
  1. `new EditorPreviewController(...)` chỉ truyền 9 tham số, bỏ trống `$nonceFactory`, `$applyToken`, `$applyFingerprint` → Preview trên runtime **không bao giờ phát hành `source_fingerprint` và `apply_token`**.
  2. `$resolver` của `ApplyController` luôn trả `['status' => 'ok', ...]` mà không kiểm tra đối tượng tồn tại hay khớp `post_type` (`post`/`page`/`product`), làm liệt hoàn toàn nhánh `404 not_found`.
  3. `ApplyController` được khởi tạo với `$ownershipResolver = null` → Rank Math đang active vẫn ghi thẳng vào DB, bỏ qua hoàn toàn ma trận ownership `409 ownership_conflict`.
- **Cách sửa:**
  - Truyền `ApplyToken` (khi có `AUTH_KEY` hợp lệ) và `ApplyFingerprint` vào `EditorPreviewController`.
  - Nối chung một snapshot/ownership resolver giữa Preview và Apply: kiểm tra object tồn tại và đúng kiểu (`post` ↔ `article`, `page` ↔ `page`, `product` ↔ `product` khi WooCommerce khả dụng; sai/mất trả `not_found` hoặc `unsupported_resource`), đọc trạng thái Rank Math (`RankMathDetector` + `OwnershipRegistry`) và cấp mã xác nhận server-validated khi cần.

### `3B2-3` [Blocker] Preview và Apply lệch nguồn đọc canonical & lệch `targetField`
- **Hiện trạng (`EditorPreviewController.php:94-112`, `SeoModule.php:118-122`):**
  1. Preview lấy `$targetField = 'title'` cứng cho `title_meta_suggestion`, trong khi `AiOutputValidator` cho phép `proposal.target_field` là `'title'` **hoặc** `'meta_description'`. Với `seo_analysis`, Preview cũng vẫn phát hành `apply_token` giả cho `'title'` dù task phân tích không có đề xuất Apply!
  2. Preview băm `current` lấy từ `provider_payload` (vốn lấy post title WordPress hoặc Rank Math override và cắt mô tả sản phẩm 2.000 ký tự), còn Apply băm `CanonicalSeoMetaRepository` (`_myseo_*`, ban đầu là `null`) hoặc `WooCommerceDescriptionWriter::read()` đầy đủ → **Fingerprint lúc Apply luôn lệch với Preview (`409 stale_preview`)**.
  3. `WordPressContentReader` / `WooCommerceProductReader` chưa hề đọc `_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`.
- **Cách sửa:**
  - Chỉ phát hành `source_fingerprint` + `apply_token` cho task có đề xuất ghi (`title_meta_suggestion` lấy đúng `$response['output']['target_field']`; `product_description_draft` lấy `'description'`; **không** phát hành cho `seo_analysis`).
  - Dùng **cùng một hàm dựng snapshot canonical** cho cả Preview và Apply theo Mục 3.1 & 4 của `increment-3b.md`: gồm `version`, `post_id`, `resource_type`, `task`, `field`, giá trị canonical hiện tại của field (`CanonicalSeoMetaRepository::read()` hoặc `WooCommerceDescriptionWriter::read()`), source field liên quan của đối tượng và `ownership_state`. Normalize chuỗi đề xuất (`trim`, chuẩn hóa xuống dòng) đồng nhất trước khi đưa vào `apply_token` và trước khi so khớp/lưu.
  - Cập nhật reader để đọc canonical `_myseo_*` theo đúng precedence đã khóa (ưu tiên canonical My SEO khi thuộc quyền My SEO, không đọc/ghi nhầm sang key nội bộ Rank Math khi Apply).

### `3B2-4` [Blocker] Vi phạm Gate Ordering 1–11, sai mã lỗi và gọi đọc snapshot quá sớm
- **Hiện trạng (`ApplyController.php:32-57`):**
  1. Dòng 46 gọi `snapshotResolver` **trước** khi kiểm tra format `source_fingerprint` và `apply_token` (vi phạm Mục 5 & 5.1: Gate 1–6 phải có **0 lần đọc snapshot / 0 persistence**).
  2. Dòng 50 đưa `$snapshot['fingerprint']` mới vào `$claims` của `tokens->verify()`. Khi dữ liệu nguồn đổi làm fingerprint đổi, `tokens->verify()` fail trước và trả `422 invalid_proposal` thay vì `409 stale_preview`!
  3. Chưa chặn các field thương mại bảo vệ của WooCommerce (`regular_price`, `sale_price`, `price`, `stock_quantity`, `stock_status`, `sku`, `reviews`, `orders`, hoặc key Rank Math `rank_math_*`) bằng mã `422 protected_field` / `409 ownership_conflict` theo Mục 4.1 & 5.
  4. Chưa kiểm tra tương thích `task` ↔ `field`/`resource_type` (ví dụ `seo_analysis`, hoặc gửi `task=product_description_draft` cho `title`).
- **Cách sửa theo đúng thứ tự cứng:**
  - **Gate 1–4:** POST array → `post_id` nguyên dương → `edit_post` → nonce `myseo_ai_apply` (0 đọc snapshot, 0 ghi).
  - **Gate 5:** Kiểm tra kiểu chuỗi & tính hợp lệ của `resource_type`, `task`, `field`:
    - Nếu `field` nằm trong danh mục thương mại bị cấm (`price`, `regular_price`, `sale_price`, `stock_quantity`, `stock_status`, `sku`, `reviews`, `orders`...) hoặc request đính kèm tham số thương mại → trả ngay `422 protected_field`.
    - Nếu `field` là meta nội bộ Rank Math (`rank_math_*`) → trả `409 ownership_conflict`.
    - Kiểm tra allowlist `resource_type` (`400 unsupported_resource`), allowlist `field` (`400 unsupported_field`), và khớp `task` ↔ `resource_type` ↔ `field` (`title_meta_suggestion` chỉ đi với `article|page` + `title|meta_description` — hoặc mở rõ nếu có task canonical hợp lệ; `product_description_draft` chỉ đi với `product` + `description`).
  - **Gate 6 (trước khi đọc snapshot):** Kiểm tra format `source_fingerprint` (`^sha256:[a-f0-9]{64}$`), normalize + validate `proposal` (plain text bounded theo từng field, không HTML tag/script/control chars, nếu `field === 'canonical'` bắt buộc URL `http/https` hợp lệ), và `ApplyToken::verify()` đối chiếu chữ ký + hạn dùng + `post_id`, `resource_type`, `task`, `field`, `value`, `request['source_fingerprint']` → sai bất kỳ điểm nào trả `422 invalid_proposal` với **0 lần gọi `snapshotResolver`**.
  - **Gate 7–8:** Đọc snapshot hiện tại (`not_found` → `404`), tính lại fingerprint bằng `ApplyFingerprint` và so khớp với `source_fingerprint` trong token/request → nếu lệch trả `409 stale_preview` (0 ghi).
  - **Gate 9:** Kiểm tra Rank Math ownership matrix (`409 ownership_conflict` nếu thiếu/sai confirmation token gắn với đúng object + field + fingerprint).
  - **Gate 10–11:** Ghi đúng 1 field + read-back exact; bọc `try/catch (\Throwable)` để không lộ raw exception và trả `502 storage_failed` nếu write hoặc read-back lỗi/lệch.

### `3B2-5` [Major] `ApplyFingerprint` chưa có canonical serialization ổn định
- **Hiện trạng (`ApplyFingerprint.php:14-17`):** Chỉ gọi `json_encode($snapshot)` thô, không sắp xếp khóa đệ quy, không cờ `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, không normalize `null` vs `""`, Unicode/newline hay URL.
- **Cách sửa:** Viết hàm canonicalizer đệ quy (sort keys associative ổn định, chuẩn hóa `\r\n`/`\r` → `\n`, trim chuỗi theo quy tắc chung, giữ phân biệt `null` và `""`, encode với `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`) dùng chung cho cả Preview và Apply.

### `3B2-6` [Major] Metabox UI chưa có luồng Apply có xác nhận
- **Hiện trạng (`EditorPreviewController.php:156-297`):** Chỉ render `data-nonce` cho `myseo_ai_preview` và nút `Sao chép`; không có `data-apply-nonce`, không hiển thị cảnh báo/xác nhận khi Rank Math sở hữu `title_meta`, và không có nút `Áp dụng` gọi `myseo_ai_apply`.
- **Cách sửa:** Khi `apply_token` và `source_fingerprint` có mặt trong response Preview của đề xuất hợp lệ, render nút xác nhận Apply riêng cho đúng field đó (dùng DOM API/`textContent`, tuyệt đối không `innerHTML`), gửi `action=myseo_ai_apply`, `_ajax_nonce` riêng của `myseo_ai_apply`, `post_id`, `resource_type`, `task`, `field`, `proposal`, `source_fingerprint`, `apply_token` và `confirmation` (khi người dùng tích xác nhận cảnh báo Rank Math). Hiển thị thông báo kết quả tĩnh an toàn; không auto-apply, không auto-publish.

### `3B2-7` [Major] Củng cố an toàn `CanonicalSeoMetaRepository` và `WooCommerceDescriptionWriter`
- **`CanonicalSeoMetaRepository`:** Lưu ý `update_post_meta()` của WordPress trả `false` cả khi giá trị mới **trùng hệt** giá trị đang lưu trong DB! Nếu người dùng Apply một đề xuất trùng với `_myseo_title` hiện có, `(bool) update_post_meta(...)` trả `false` sẽ gây báo lỗi giả `502 storage_failed`. Cần xử lý trường hợp giá trị hiện tại đã bằng đúng `$value` (hoặc kiểm tra kết quả kết hợp read-back exact) mà không nuốt lỗi ghi thật; đồng thời đồng bộ quy tắc đọc chuỗi rỗng vs `null` với Mục 3.1.
- **`WooCommerceDescriptionWriter`:** Không được để exception từ CRUD tràn ra ngoài; kiểm tra đối tượng hợp lệ và đảm bảo chỉ gọi `get_description()`, `set_description()`, `save()`, không chạm bất kỳ field giá/kho/SKU/order/status nào.

### `3B2-8` [Blocker] Bổ sung đầy đủ bộ kiểm thử `tests/Unit/Increment3BTest.php`
- Báo cáo hiện chưa có bất kỳ file test nào cho 3B. Bắt buộc tạo `tests/Unit/Increment3BTest.php` bao phủ toàn bộ Mục 9 của `docs/giai-doan-3/increment-3b.md`:
  1. Apply thành công cho `post`/`page` (`title`, `meta_description`, `canonical` khi có luồng hợp lệ) và `product` (`description`), xác nhận read-back exact, `post_status` không đổi (không auto-publish) và các field không chọn giữ nguyên.
  2. Ma trận Gate 1–6 (sai method, `post_id`, capability, nonce, `resource_type`, `task`, `field`, thiếu/hết hạn/sai chữ ký `apply_token`, sửa `proposal` nhưng giữ nguyên `source_fingerprint`, HTML/script/`javascript:`, quá dài, canonical URL xấu) → đúng HTTP status + body 3 khóa + **0 lần gọi snapshot reader + 0 lần gọi writer**.
  3. `ApplyToken` test với nhiều độ dài payload (chia hết cho 3, dư 1, dư 2, ký tự Unicode tiếng Việt) xác nhận `issue()` → `verify()` thành công 100% và hết hạn/sửa 1 ký tự đều fail.
  4. Determinism của `ApplyFingerprint`: cùng snapshot ra cùng hash; đổi field liên quan ra hash mới → trả `409 stale_preview` với **0 lần ghi**; đổi dữ liệu ngoài snapshot contract không gây stale giả.
  5. Ma trận Rank Math ownership: không active → `saved`; active thiếu confirmation hoặc sai confirmation → `409 ownership_conflict` (0 ghi); active kèm confirmation server-validated hợp lệ → ghi đúng `_myseo_*` và **0 ghi sang `rank_math_*`**.
  6. Hàng rào WooCommerce: gửi `price`, `regular_price`, `sale_price`, `stock_quantity`, `stock_status`, `sku`, `reviews`, `orders` bị chặn `422 protected_field` (0 ghi); product spy chứng minh `write()` không chạm bất kỳ setter thương mại nào.
  7. Failure safety: writer trả `false`, writer ném exception, hoặc read-back lệch giá trị đều trả `502 storage_failed` (body 3 khóa, không lộ trace/secret) và **không phát sinh lần ghi thứ hai (0 compensation write)**.
  8. Tương thích ngược với `Increment3ATest.php` và toàn bộ suite `tests/Unit`.

### `3B2-9` [Major] Chuẩn hóa báo cáo `docs/giai-doan-3/increment-3b-r2-rp.md`
- Chạy và đính kèm kết quả thực tế của cả hai lệnh:
  - `php vendor/bin/phpunit --do-not-cache-result tests/Unit/Increment3BTest.php`
  - `php vendor/bin/phpunit --do-not-cache-result tests/Unit`
- Ghi đầy đủ SHA-256 của **mọi** file tạo mới/chỉnh sửa (bao gồm `EditorPreviewController.php`, `SeoModule.php`, `Increment3BTest.php` và xác nhận SHA-256 `myseoforall.php` không đổi), exact test count, assertion count, và tách bạch rõ bằng chứng Offline PHPUnit với giới hạn chưa chạy WordPress/WooCommerce runtime thật theo Mục 10.2.
