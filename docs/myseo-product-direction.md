# My SEO For All — Định hướng sản phẩm

> Tài liệu ghi lại các quyết định và trao đổi ban đầu với Project Owner cho plugin `myseoforall`. Đây là tài liệu định hướng sản phẩm, không phải Sprint Plan, danh sách ticket hay manifest file triển khai.

## 1. Bối cảnh hiện tại

- Dự án bắt đầu gần như từ số 0; chưa có mã nguồn plugin ứng dụng cần bảo toàn.
- Repository hiện chưa phải Git repository.
- Thư mục `.forge/` là hạ tầng runtime/workflow và không thuộc phạm vi sửa sản phẩm.
- Thư mục `docs/` đã có tài liệu cũ mô tả một plugin khác; tài liệu này được tạo riêng để tránh ghi đè tài liệu hiện có.

## 2. Tầm nhìn sản phẩm

`My SEO For All` là một plugin WordPress tập trung vào SEO cho bài viết và sản phẩm WooCommerce, đồng thời cung cấp AI Assistant tiếng Việt để phân tích, đề xuất và hỗ trợ tạo bản nháp nội dung.

Định vị đề xuất:

> AI Assistant SEO tiếng Việt cho WordPress và WooCommerce, có thể phân tích dữ liệu SEO hiện có, tạo nội dung nháp dựa trên dữ liệu thật và tối ưu cách trình bày nội dung cho công cụ tìm kiếm cùng các hệ thống AI — luôn để chủ website duyệt trước khi áp dụng.

Plugin không nên chỉ cạnh tranh bằng việc tạo thêm title, schema hoặc sitemap. Giá trị chính là kết hợp:

1. Dữ liệu SEO kỹ thuật.
2. Ngữ cảnh bài viết và sản phẩm WooCommerce.
3. Trợ lý AI có kiểm soát.
4. Khả năng tích hợp với plugin SEO đang dùng.

## 3. Quyết định sản phẩm đã chốt

### 3.1 WooCommerce

WooCommerce là yêu cầu bắt buộc của phiên bản đầu tiên.

Phạm vi sản phẩm cần quan tâm gồm tên, mô tả, giá, giá khuyến mãi, SKU, tồn kho, thương hiệu, danh mục, thuộc tính, biến thể, ảnh và đánh giá khi dữ liệu đó thực sự tồn tại.

Nếu WooCommerce chưa được cài hoặc kích hoạt, phần chức năng sản phẩm phải hiển thị trạng thái rõ ràng và không được giả lập dữ liệu sản phẩm.

### 3.2 Ngôn ngữ

Phiên bản đầu tiên ưu tiên tiếng Việt:

- Giao diện quản trị bằng tiếng Việt.
- Hướng dẫn và gợi ý AI bằng tiếng Việt.
- Nội dung sinh ra mặc định bằng tiếng Việt, nhưng nên thiết kế để mở rộng đa ngôn ngữ sau này.

### 3.3 AI Assistant

AI Assistant không tự động xuất bản bài viết hoặc sản phẩm trong phiên bản đầu tiên.

AI có thể:

- Phân tích nội dung và dữ liệu SEO hiện tại.
- Đề xuất SEO title và meta description.
- Gợi ý chủ đề, heading, dàn ý và FAQ.
- Tạo bản nháp bài viết hoặc mô tả sản phẩm.
- Viết lại đoạn nội dung được người dùng chọn.
- Phát hiện trường dữ liệu sản phẩm còn thiếu.
- Cảnh báo schema không khớp với nội dung thật.
- Gợi ý liên kết nội bộ.

Mọi đề xuất phải có bước xem trước. Người dùng có thể **Áp dụng**, **Sửa**, **Bỏ qua** hoặc **Hoàn tác** khi phù hợp.

## 4. Nội dung AI và giới hạn an toàn

AI được xem là trợ lý, không phải nguồn sự thật cuối cùng.

Đối với bài viết:

- Có thể tạo dàn ý, đoạn mở đầu, FAQ và bản nháp.
- Không tự động xuất bản.
- Không tự động nhồi từ khóa hoặc làm sai giọng thương hiệu.

Đối với sản phẩm WooCommerce:

- Chỉ dựa trên dữ liệu đã có trong sản phẩm và dữ liệu người dùng cung cấp.
- Không bịa thông số, công dụng, chứng nhận, giá, tồn kho hoặc cam kết bán hàng.
- Không tự tạo đánh giá hoặc xếp hạng.
- Không thay đổi giá, tồn kho hay dữ liệu thương mại gốc.

## 5. Tích hợp với plugin SEO hiện có

Plugin nên hỗ trợ hai chế độ:

### 5.1 Chế độ tích hợp

Khi website đã có plugin SEO, `My SEO For All` ưu tiên đóng vai trò AI Assistant và lớp phân tích:

- Đọc dữ liệu SEO và nội dung hiện có.
- Phân tích dữ liệu bài viết/sản phẩm.
- Ghi đề xuất vào đúng trường khi adapter tương ứng được hỗ trợ.
- Không xuất trùng meta, JSON-LD hoặc sitemap nếu plugin SEO chính đã đảm nhiệm.
- Cho phép người dùng chọn plugin SEO chính khi phát hiện nhiều plugin.

Không bắt đầu bằng việc hỗ trợ mọi plugin. Nên thiết kế adapter độc lập, ví dụ:

- `YoastAdapter`
- `RankMathAdapter`
- `AioseoAdapter`

Tên adapter chỉ là định hướng kỹ thuật, không phải cam kết phạm vi MVP.

### 5.2 Chế độ độc lập

Khi không có plugin SEO tương thích, plugin có thể tự quản lý:

- SEO title và meta description.
- Canonical URL.
- Open Graph và Twitter Cards.
- Dữ liệu có cấu trúc JSON-LD.
- Sitemap và robots directives.
- Breadcrumbs.

Tại mỗi thời điểm chỉ được có một nguồn xuất dữ liệu SEO tương ứng để tránh trùng lặp.

## 6. SEO và dữ liệu cho công cụ tìm kiếm/AI

Mục tiêu thực tế là làm cho nội dung dễ được máy tìm kiếm và hệ thống AI hiểu, không hứa hẹn một thẻ đặc biệt bảo đảm thứ hạng hoặc việc được một nền tảng AI đề xuất.

Nền tảng cần hỗ trợ:

- Nội dung có cấu trúc heading rõ ràng.
- Tác giả, ngày xuất bản và ngày cập nhật khi có dữ liệu.
- Liên kết nội bộ hợp lý.
- Thông tin doanh nghiệp và nguồn nội dung.
- Schema.org phản ánh đúng nội dung hiển thị.
- Schema cho `Article`, `Product`, `Offer`, `BreadcrumbList`, `Organization` và các loại phù hợp khác.
- Dữ liệu sản phẩm rõ ràng: giá, tình trạng, SKU, thương hiệu và ảnh khi có.

Không tạo dữ liệu có cấu trúc giả, không che giấu nội dung quan trọng và không khẳng định các hệ thống AI đều hỗ trợ một định dạng bổ trợ nào đó nếu chưa có căn cứ.

## 7. Phạm vi MVP được đề xuất

1. Plugin WordPress có bootstrap và trang cài đặt cơ bản.
2. Giao diện quản trị tiếng Việt.
3. WooCommerce là dependency bắt buộc cho tính năng sản phẩm.
4. Metabox SEO cho bài viết/trang và sản phẩm.
5. Title, meta description, canonical và Open Graph.
6. Schema cơ bản cho bài viết, sản phẩm, offer và breadcrumb.
7. Đọc dữ liệu WooCommerce qua abstraction phù hợp, bao gồm sản phẩm và biến thể khi cần.
8. Cơ chế phát hiện plugin SEO hiện có và tránh xung đột output.
9. Phân tích SEO cục bộ không cần gửi dữ liệu ra dịch vụ bên ngoài.
10. Khung AI Assistant tùy chọn, có xem trước và phê duyệt thủ công.
11. Cấu hình quyền riêng tư và thông báo rõ dữ liệu nào sẽ được gửi tới nhà cung cấp AI.

MVP không bao gồm tự động xuất bản hàng loạt, tự động thay đổi giá/tồn kho, hoặc cam kết hỗ trợ mọi plugin SEO ngay từ đầu.

## 8. Nguyên tắc quyền riêng tư và vận hành

- Tính năng gọi API AI phải được bật chủ động.
- Người dùng tự cung cấp và quản lý API key của nhà cung cấp được hỗ trợ.
- Không đưa API key vào HTML công khai.
- Hiển thị dữ liệu dự kiến gửi trước khi gọi API ở mức phù hợp.
- Không gửi nội dung website ra ngoài khi người dùng chưa bật tính năng.
- Có thể tắt hoàn toàn AI và vẫn dùng được phần SEO nền tảng.
- Không lưu nội dung hoặc phản hồi AI lâu hơn nhu cầu đã công bố.
- Mọi dữ liệu đầu vào/đầu ra phải được kiểm tra, escape và phân quyền theo chuẩn WordPress.

## 9. Các quyết định còn mở

Các điểm sau chưa được Project Owner chốt:

1. Gateway bên thứ ba đầu tiên dùng để gọi Anthropic và contract/provider configuration tương ứng.
2. Plugin SEO bên thứ ba đầu tiên cần tích hợp.
3. Phạm vi sitemap trong MVP.
4. Cơ chế cấp phép/thương mại của plugin.
5. Có cần hỗ trợ đa ngôn ngữ ngoài tiếng Việt ngay sau MVP hay không.
6. Mức độ lưu lịch sử đề xuất và khả năng hoàn tác.

Những điểm này cần được chốt trước khi khóa kiến trúc chi tiết hoặc bắt đầu các hạng mục phụ thuộc vào chúng.

## 10. Nguyên tắc phát triển

- Ưu tiên tương thích WordPress và WooCommerce chính thống.
- Một nguồn dữ liệu SEO và một nguồn xuất output tại mỗi thời điểm.
- Dữ liệu hiển thị phải là nguồn sự thật của schema.
- AI chỉ đề xuất; chủ website quyết định nội dung xuất bản.
- Thiết kế module/adaptor để không khóa vào một plugin SEO hay nhà cung cấp AI.
- Thay đổi nhỏ, có kiểm thử và có khả năng tắt từng tích hợp.
