# Định Hướng Kiến Trúc Giai Đoạn 3 — Editor Metabox, AI Preview & Controlled Apply (Theo Hướng 2)

> Tài liệu đặc tả kiến trúc chia theo từng Increment của Giai đoạn 3 (Hướng 2) để Project Owner rà soát và mở lần lượt từng chặng triển khai. Không phải Sprint Plan, danh sách ticket hay manifest sửa file.

---

## 1. Mục tiêu & Hiện trạng nền tảng kế thừa từ Giai đoạn 2

### 1.1. Những gì đã sẵn sàng từ Giai đoạn 2 (`Increment A -> G` + nâng cấp Admin AI Settings)
- **Đọc & Phân tích tại chỗ:** `WordPressContentReader`, `WooCommerceProductReader`, `ContentSeoInspector`, `ProductSeoInspector` và `LocalSeoAnalyzer` đã tạo được snapshot `SeoContext`, danh sách `findings` (`blocker`, `warning`, `info`) và `provider_payload` (`myseo.ai.payload.v2` qua `ProviderPayloadBuilder`).
- **Tích hợp & Coexistence thụ động:** `RankMathDetector`, `RankMathReadAdapter`, `RankMathMappingCatalog` và `OwnershipRegistry` đã đánh giá quyền sở hữu 4 mặt phẳng (`title_meta`, `json_ld`, `sitemap`, `ai_draft`) ở chế độ chỉ đọc (`write_allowed = false`).
- **Cấu hình & Gateway an toàn:** `AiSettingsRepository` (`myseo_ai_settings` AEAD non-autoload), `GatewayEndpointValidator`, `CurlGatewayTransport` (ghim IP, cấm redirect/proxy), `NativeGatewayResolver` (UDP DNS có deadline) và `GatewayAiProvider` + `AiOutputValidator` đã hỗ trợ 3 tác vụ:
  1. `seo_analysis`
  2. `title_meta_suggestion`
  3. `product_description_draft`

### 1.2. Khoảng trống cần giải quyết ở Giai đoạn 3
- `SeoModule::registerDiagnosticsSurface()` hiện mới chạy ngầm hàm `inspect()` trên hook `add_meta_boxes`, chưa render giao diện Metabox trên màn hình sửa Bài viết / Trang / Sản phẩm.
- Người biên tập chưa xem được kết quả `LocalSeoAnalyzer`, chưa bấm gọi AI để xem **Preview** trực tiếp trên Editor, và chưa có cơ chế **Apply** có xác nhận kèm chống ghi đè phiên bản cũ (optimistic concurrency) hay hiển thị SEO title/meta hợp lệ khi `My SEO For All` được phép sở hữu.

### 1.3. Chiến lược triển khai theo **Hướng 2** (Chia 3 Increment độc lập, kiểm soát rủi ro từng bước)
1. **Increment 3A — Editor Metabox: Local SEO Diagnostics & Read-Only AI Preview (`0` DB Writes)**
2. **Increment 3B — Controlled Apply, Optimistic Concurrency & Canonical SEO Meta Storage**
3. **Increment 3C — Safe Frontend Title/Meta Emission (Ownership-Gated) & WooCommerce Variation/HPOS Hardening**

---

## 2. Increment 3A — Editor Metabox: Local SEO & Read-Only AI Preview

### 2.1. Mục tiêu của 3A
Đưa toàn bộ sức mạnh chẩn đoán Local SEO và trợ lý AI lên ngay màn hình biên tập WordPress (`post`, `page`) và WooCommerce (`product`) ở chế độ **100% chỉ đọc (Read-Only)** để Owner trải nghiệm giao diện thực tế và kiểm chứng chất lượng gợi ý từ gateway thật trước khi mở quyền ghi dữ liệu.

### 2.2. Các Outcomes kiến trúc của 3A
- **`3A-O1` — Hiển thị Metabox tiếng Việt cho `post`, `page` và `product`:**
  - Đăng ký metabox `My SEO For All — Trợ lý SEO & AI` qua `add_meta_box` cho `post`, `page`, và cho `product` khi WooCommerce đang kích hoạt (nếu thiếu WooCommerce vẫn hoạt động bình thường trên Post/Page, không gây lỗi).
  - Kiểm tra chặt quyền `current_user_can('edit_post', $postId)` và ID nguyên dương hợp lệ trước khi đọc dữ liệu hoặc render.
  - **Tuyệt đối `0` kết nối mạng / `0` truy vấn DNS** khi mở màn hình chỉnh sửa bài viết hoặc sản phẩm.
- **`3A-O2` — Bảng chẩn đoán Local SEO & Trạng thái Ownership trực quan:**
  - Render ngay danh sách `findings` từ `ContentSeoInspector` / `ProductSeoInspector` phân nhóm rõ theo mức độ ưu tiên: **Blocker (Đỏ)**, **Warning (Vàng)**, **Info (Xanh)** kèm `message` và `suggested_fix` tiếng Việt.
  - Hiển thị tóm tắt trạng thái **Ownership / Coexistence** hiện tại cho `title_meta` (ví dụ: Rank Math đang hoạt động hay chưa có plugin SEO khác) để người biên tập nắm rõ nguồn dữ liệu đang đọc.
  - Khi AI đang tắt (`ai_enabled = false`) hoặc chưa cấu hình xong, phần Local SEO vẫn hoạt động đầy đủ 100%, đồng thời khu vực AI hiển thị thông báo gọn kèm liên kết sang `Settings -> My SEO For All`.
- **`3A-O3` — Endpoint AJAX Gọi AI theo yêu cầu & Màn hình Preview So sánh:**
  - Đăng ký endpoint `wp_ajax_myseo_ai_preview` chỉ dành cho người dùng đã đăng nhập có quyền `edit_post` trên đúng `$postId` và hợp lệ WordPress AJAX nonce riêng (`myseo_ai_preview`).
  - Hiển thị bảng kê minh bạch (Data Disclosure) các nhóm trường allowlist được phép gửi sang gateway (tiêu đề, nội dung/mô tả tóm tắt, danh mục, thuộc tính công khai — cam kết không gửi dữ liệu khách hàng, đơn hàng, PII hay secret).
  - Cung cấp các nút thao tác chủ động không làm reload trình soạn thảo:
    - `seo_analysis`: Phân tích SEO chuyên sâu bằng AI (`post`, `page`, `product`).
    - `title_meta_suggestion`: Gợi ý SEO Title hoặc Meta Description (`post`, `page`, `product`).
    - `product_description_draft`: Soạn nháp mô tả sản phẩm tiếng Việt (`product` duy nhất; chặn `unsupported_task` nếu gọi cho `post`/`page`).
  - Dựng request chuẩn `myseo.ai.request.v2` từ `provider_payload` của Inspector $\rightarrow$ gọi `AiProvider::generate()` (`GatewayAiProvider`) $\rightarrow$ hậu kiểm qua `AiOutputValidator` $\rightarrow$ trả JSON an toàn về khung **Preview so sánh trực quan** (Giá trị hiện tại vs Đề xuất của AI + nút *Sao chép vào clipboard* để dùng ngay ở 3A).
  - Mọi lỗi từ `AiProviderException` (hết hạn mức `rate_limited`, lỗi xác thực, lỗi kết nối, sai schema...) được ánh xạ sang thông báo tiếng Việt tĩnh, an toàn, không lộ stack trace, URL nội bộ hay khóa bí mật.
- **`3A-O4` — Khóa cứng Zero-Write & Zero-Retention trong suốt 3A:**
  - Không gọi `wp_update_post`, `update_post_meta`, WooCommerce CRUD `save()`, hay `OptionStore::update/delete` trong cả luồng mở Metabox lẫn luồng AJAX Preview.
  - Không lưu payload gửi đi hoặc bản nháp AI trả về vào database, transient, cache hay log.

### 2.3. Điều kiện nghiệm thu để đóng 3A sang 3B
- Mở màn hình sửa Post / Page / Product thấy rõ Metabox Local SEO + Ownership + khu vực AI Preview; `0` network/DNS call khi chưa bấm nút AI.
- Bấm nút AI nhận được khung Preview sạch sẽ (hoặc thông báo lỗi tiếng Việt an toàn khi gateway từ chối); `0` thao tác ghi DB trên mọi nhánh thành công và thất bại; toàn bộ `tests/Unit` pass.

---

## 3. Increment 3B — Controlled Apply, Optimistic Concurrency & Canonical SEO Storage

*(Chỉ mở lập kế hoạch chi tiết sau khi Project Owner nghiệm thu xong Increment 3A trên WordPress thật)*

### 3.1. Mục tiêu của 3B
Cho phép người biên tập bấm nút **Áp dụng (Apply)** từng đề xuất đã xem trước trong khung Preview (Title, Meta Description, hoặc Mô tả sản phẩm) xuống cơ sở dữ liệu một cách có kiểm soát, chống ghi đè mù quáng và bảo toàn tuyệt đối dữ liệu thương mại.

### 3.2. Các Outcomes kiến trúc dự kiến của 3B
- **`3B-O1` — Canonical SEO Post/Product Meta Boundary của My SEO For All:**
  - Định nghĩa kho lưu trữ meta chuẩn có tiền tố riêng của plugin cho từng đối tượng (`_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`) tách biệt hoàn toàn với dữ liệu gốc và không ghi trực tiếp vào meta key nội bộ của Rank Math từ core.
  - Cập nhật `WordPressContentReader` và `WooCommerceProductReader` đọc theo thứ tự nguồn minh bạch đã khóa.
- **`3B-O2` — Snapshot Hash & Optimistic Concurrency cho Preview -> Apply:**
  - Khi trả kết quả Preview ở bước xem trước, đính kèm mã băm phiên bản dữ liệu gốc (`source_fingerprint` tính trên nội dung/meta hiện tại của bài viết hoặc sản phẩm + `post_modified_gmt`).
  - Khi người dùng bấm **Áp dụng**, endpoint `wp_ajax_myseo_ai_apply` kiểm tra:
    1. Capability `edit_post` trên đúng `$postId` + Nonce riêng cho thao tác Apply (`myseo_ai_apply`).
    2. Validate lại giá trị đề xuất/chỉnh sửa theo đúng giới hạn độ dài, ngôn ngữ, văn bản an toàn (không chứa script/HTML nguy hiểm).
    3. Đọc lại đối tượng hiện tại và so khớp `source_fingerprint`: nếu bài viết/sản phẩm đã bị sửa ở tab khác hoặc bởi người khác sau thời điểm Preview $\rightarrow$ **từ chối ghi (`stale_preview`)** và yêu cầu Preview lại để không làm mất dữ liệu mới.
- **`3B-O3` — Áp dụng chọn lọc từng trường & Hàng rào bảo vệ WooCommerce:**
  - Người dùng xác nhận áp dụng trường nào thì chỉ ghi đúng trường đó (`title`, `meta_description`, hoặc phần mô tả sản phẩm được phép qua WooCommerce CRUD adapter).
  - **Không auto-publish:** giữ nguyên `post_status` hiện tại (nháp vẫn là nháp; bài đã đăng chỉ cập nhật trường được xác nhận khi người dùng chủ động bấm Áp dụng).
  - **Hàng rào bất khả xâm phạm cho WooCommerce:** cấm tuyệt đối việc đọc nhầm hoặc ghi chạm vào `regular_price`, `sale_price`, `price`, `stock_quantity`, `stock_status`, `sku`, `reviews` hoặc dữ liệu đơn hàng.
  - Nếu Rank Math đang active và giữ quyền sở hữu `title_meta`, giao diện hiển thị cảnh báo rõ ràng khi lưu vào meta của `My SEO For All` (hoặc chặn ghi đè sang Rank Math nếu chưa mở adapter ghi riêng được duyệt).

---

## 4. Increment 3C — Safe Frontend SEO Output (Ownership-Gated) & WooCommerce Hardening

*(Chặng khép lại Giai đoạn 3 sau khi luồng Preview & Apply ở 3B đã vận hành ổn định)*

### 4.1. Mục tiêu của 3C
Xuất dữ liệu SEO Title / Meta Description / Canonical đã lưu ra ngoài giao diện người đọc (Frontend `<head>`) **chỉ khi không xung đột với plugin SEO khác**, đồng thời hoàn thiện kiểm chứng sản phẩm biến thể (Variations) và HPOS của WooCommerce.

### 4.2. Các Outcomes kiến trúc dự kiến của 3C
- **`3C-O1` — Frontend Title & Meta Emitter có cổng kiểm soát Ownership (`Duplicate-Zero`):**
  - Trước khi xuất `<title>`, `<meta name="description">` hoặc `<link rel="canonical">` ngoài frontend, hệ thống kiểm tra quyết định từ `OwnershipRegistry` và `Configuration` (`rank_math_mode`):
    - Khi không có plugin SEO khác tranh chấp và `My SEO For All` là owner hợp lệ: xuất thẻ sạch, đã escape chuẩn xác.
    - Khi Rank Math (hoặc plugin SEO khác trong danh mục phát hiện) đang active / sở hữu `title_meta`, hoặc trạng thái ownership là `conflict` / `unknown`: **tự động nhường quyền (`0` thẻ trùng lặp ngoài frontend)**.
- **`3C-O2` — Hoàn thiện tương thích WooCommerce Product / Variation & HPOS:**
  - Đảm bảo đọc và kiểm tra dữ liệu nhất quán cho cả sản phẩm đơn (`simple`) lẫn sản phẩm có biến thể (`variable` / `variation`) thông qua WooCommerce CRUD API chuẩn (tương thích High-Performance Order Storage — HPOS, không truy vấn bảng SQL cũ trực tiếp).
  - Xác nhận ma trận tương thích thực tế giữa PHP 8.x, WordPress, WooCommerce và chế độ chung sống với Rank Math.

---

## 5. Ranh giới bất biến xuyên suốt toàn bộ Giai đoạn 3 (Guardrails cấp Owner)

1. **Không sửa `myseoforall.php`** (giữ nguyên phần bootstrap/autoloader mà Project Owner đã cài đặt thủ công).
2. **Bảo mật khóa bí mật & SSRF giữ nguyên chuẩn Giai đoạn 2:** mọi lời gọi AI bắt buộc đi qua `GatewayAiProvider` $\rightarrow$ `AiSettingsRepository::executeGatewayRequest()` $\rightarrow$ `CurlGatewayTransport` / `NativeGatewayResolver`; không mở đường tắt gọi HTTP trực tiếp từ Admin/AJAX controller.
3. **Không gửi PII / Dữ liệu khách hàng / Đơn hàng:** mọi request gửi AI bắt buộc đi qua `ProviderPayloadBuilder` (`myseo.ai.payload.v2`).
4. **Zero-Retention cho dữ liệu hội thoại AI:** không lưu lịch sử prompt/response thô vào database, log hay cache nếu chưa có quyết định mở tính năng lịch sử riêng của Owner.
5. **Con người quyết định cuối cùng:** không bao giờ tự động gọi AI khi mở trang, không auto-apply kết quả AI và không auto-publish bài viết/sản phẩm.

---

## 6. Thứ tự thực thi tiếp theo

1. **Bước 1 (Ngay bây giờ):** Mở kế hoạch thực thi chính thức cho **Increment 3A** (*Editor Metabox: Local SEO & Read-Only AI Preview*) và giao System Engineer / Sprint Leader triển khai.
2. **Bước 2:** Kiểm thử thực tế 3A ngay trên màn hình sửa Post / Page / Product của WordPress với gateway đã cấu hình.
3. **Bước 3:** Mở tiếp **Increment 3B** (*Controlled Apply & Canonical SEO Meta Storage*), rồi khép Giai đoạn 3 bằng **Increment 3C** (*Ownership-Gated Frontend Output & WooCommerce Hardening*).
