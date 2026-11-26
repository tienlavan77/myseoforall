# Phản biện Increment 3A

Ngày: 25/11/2026  
Người nhận: Architecture Manager (AM)  
Phạm vi: chỉ rà soát các điểm cần chốt trong `docs/giai-doan-3/increment-3a.md`; không mở thêm hạng mục ngoài mục tiêu 3A.

## 1. O1: tách rõ đăng ký metabox và cổng render

O1 yêu cầu kiểm tra `postId`, post type và capability “trước khi đăng ký”, nhưng hook `add_meta_boxes` đăng ký theo post type và callback nhận đối tượng bài viết để render. Nếu dùng capability của bài hiện tại để quyết định toàn bộ `add_meta_box`, cần chốt hành vi khi callback chạy với post chưa có ID, khi người dùng không có quyền, và khi WordPress gọi hook theo post type thay vì theo từng đối tượng.

Đề nghị AM chốt hai cổng riêng: đăng ký metabox theo danh sách post type hợp lệ (product chỉ khi WooCommerce khả dụng), sau đó callback render phải kiểm tra lại ID nguyên dương, post type và `edit_post` trên đúng ID. Cả hai cổng đều phải fail-closed và không đọc inspector khi thất bại. Điều này giữ đúng mục tiêu không render trái quyền mà không biến capability của một đối tượng thành điều kiện đăng ký toàn cục.

## 2. O2: hợp đồng dữ liệu cho giá trị hiện tại và Ownership

O2 yêu cầu dựng bảng so sánh từ `current_value` trong `SeoContext`, đồng thời yêu cầu hiển thị title/meta description và trạng thái Ownership. Tài liệu chưa khóa rõ field nào trong snapshot là nguồn chuẩn cho từng resource type, đặc biệt với `product`, và chưa nói cách xử lý field vắng mặt hoặc `null`. Nếu controller tự suy đoán từ raw post/product hoặc đọc thêm nguồn khác, sẽ phá boundary snapshot và có thể gửi dữ liệu ngoài allowlist.

Cần chốt rằng controller chỉ dùng dữ liệu đã có trong inspection envelope/provider payload; field không tồn tại phải trả trạng thái an toàn (không có giá trị hiện tại), không tự đọc thêm meta hoặc dữ liệu thương mại. Ownership chỉ là trạng thái hiển thị, không được biến thành quyền ghi hay thay đổi payload.

## 3. O3: thống nhất lỗi đóng và response contract

Danh sách mapping ở O3 có tám category trong `AiProviderException`, nhưng phần mô tả gộp `malformed_request / unsupported_task` vào một dòng. Vì vậy chưa đủ bằng chứng rằng hai category này có mã lỗi và thông báo được kiểm tra độc lập. Ngoài ra, response thành công có `current` và `proposal`, còn response lỗi chưa khóa bộ khóa JSON đóng, có bao gồm `post_id`, `resource_type` và `task` hay không.

AM cần chốt một bảng độc lập cho đủ tám category: `malformed_request`, `unsupported_task`, `invalid_response`, `provider_disabled`, `authentication_failed`, `gateway_policy_rejected`, `rate_limited`, `transport_failed`. Mỗi dòng phải có HTTP status, mã lỗi ổn định và thông báo tĩnh. Response lỗi chỉ chứa các khóa đã duyệt; tuyệt đối không chuyển message, previous hoặc trace. Việc chốt này nằm trong AC2/AC4 và không yêu cầu thêm loại lỗi mới.

## 4. O3: thứ tự gate và snapshot cần bất biến

Tài liệu nêu thứ tự method, capability, nonce, task rồi mới đọc snapshot, nhưng chưa nói rõ mọi lỗi ở các gate đầu có được phép gọi reader hay inspector hay không. Để giữ `0` AI calls và tránh side effect ngoài ý muốn, cần xác nhận các gate đầu dừng ngay; chỉ sau khi capability và nonce hợp lệ mới đọc đối tượng, và chỉ sau khi payload được chấp nhận mới gọi provider. Với task không tương thích resource, phải dừng trước reader/provider phù hợp tiếp theo và trả đúng mã đã khóa.

## 5. O4/AC5: giới hạn của bằng chứng offline

AC5 yêu cầu toàn bộ `tests/Unit` pass và xác nhận không có thay đổi `myseoforall.php`. Các test offline với fake provider, loader và network tripwire chứng minh được orchestration, zero-write và redaction trong seam; chúng không chứng minh WordPress hook thực tế, cURL/DNS production hay retention của gateway bên ngoài. O4 nên ghi rõ đây là bằng chứng kỹ thuật offline cho 3A, còn nghiệm thu trên WordPress thật và gateway thật vẫn là bước Owner đã nêu trong kế hoạch.

Không nên dùng kết quả test offline để tuyên bố đã chứng minh production runtime hoặc chính sách lưu trữ upstream.

## Kết luận

Các điểm trên cần được AM chốt trong boundary O1–O4 và AC1–AC5 trước implementation. Chưa thấy lý do mở thêm Apply, persistence, lịch sử, streaming, queue hoặc router trong Increment 3A.

[CRITIQUE: DISCUSS]
