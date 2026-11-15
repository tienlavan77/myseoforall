# My SEO For All — Quyết định của Project Owner

> Bản ghi các quyết định sản phẩm đã được Project Owner chốt trong giai đoạn định hướng. Đây là nguồn tham chiếu cho kiến trúc và delivery; không phải Sprint Plan hay manifest triển khai.

## 1. Môi trường mục tiêu

- PHP: PHP 8.
- WordPress: phiên bản mới hiện tại tại thời điểm hỗ trợ/phát hành.
- WooCommerce: phiên bản mới hiện tại tại thời điểm hỗ trợ/phát hành.
- Mô hình triển khai ban đầu: site đơn; chưa cam kết multisite.
- Công cụ phát triển: Composer + PHPUnit ngay từ đầu.
- Giai đoạn 1 có Settings và Health Check tối thiểu.
- Frontend framework lớn chưa được đưa vào nền móng.

> Ghi chú: “phiên bản mới hiện tại” cần được chuyển thành ma trận phiên bản cụ thể trước mỗi release để có thể kiểm thử và ghi rõ compatibility. Không nên xem đây là một số phiên bản bất biến.

## 2. WooCommerce

WooCommerce là dependency sản phẩm bắt buộc của MVP, nhưng plugin phải xử lý an toàn khi WooCommerce chưa được cài hoặc chưa kích hoạt:

- Không gây fatal error.
- Không gọi class/hook WooCommerce khi dependency chưa sẵn sàng.
- Hiển thị trạng thái và hướng dẫn rõ ràng.
- Không giả lập dữ liệu sản phẩm.

Dữ liệu variation được hỗ trợ ở mức nền tảng ngay từ đầu. Tối thiểu phải bảo đảm không làm sai:

- Giá.
- Tồn kho.
- Offer.
- Quan hệ sản phẩm cha/variation.

Chưa bắt buộc có toàn bộ giao diện nâng cao cho variation trong MVP.

## 3. Tích hợp SEO

- Plugin SEO bên thứ ba đầu tiên: **Rank Math**.
- Khi Rank Math đang hoạt động, cần phát hiện và hiển thị ownership/output status rõ ràng.
- My SEO For All có thể đọc dữ liệu Rank Math thông qua adapter, không rải meta key Rank Math vào core.
- My SEO For All trở thành plugin SEO chính chỉ sau khi người dùng xác nhận rõ ràng.
- Chuyển ownership phải có preview, cảnh báo xung đột, lưu dấu phiên bản trước và đường lui phù hợp.
- Không tự tắt Rank Math hoặc âm thầm ghi đè dữ liệu.

## 4. AI Assistant

- Nhà cung cấp đầu tiên: API bên thứ ba thông qua **Anthropic API và SDK chính thức**.
- AI Assistant giai đoạn đầu hỗ trợ:
  - Phân tích SEO.
  - Đề xuất title/meta.
  - Tạo mô tả sản phẩm WooCommerce.
- Kết quả luôn là đề xuất hoặc bản nháp; người dùng phải preview và phê duyệt.
- Không tự xuất bản.
- Không tự thay đổi giá, tồn kho, review hoặc dữ liệu thương mại gốc.
- Thiết kế provider abstraction để có thể thêm provider khác về sau.

### Phạm vi dữ liệu AI được phép đọc

AI chỉ được đọc:

- Nội dung bài viết hoặc sản phẩm đang mở và được người dùng yêu cầu.
- Dữ liệu liên quan cần cho ngữ cảnh, gồm danh mục, thuộc tính và sản phẩm liên quan.

AI bị cấm tuyệt đối đọc/gửi:

- Dữ liệu khách hàng.
- Dữ liệu đơn hàng.
- Thông tin cá nhân.
- API key, token hoặc secret.

Payload phải được xây dựng theo allowlist dữ liệu được phép, không lấy toàn bộ object/database rồi cố gắng loại trừ về sau.

## 5. Sitemap và output

- Ban đầu dùng sitemap của WordPress hoặc plugin SEO hiện có.
- My SEO For All không tự giành quyền sitemap trong MVP.
- Các output khác cũng phải tuân thủ ownership policy để tránh duplicate.
- Việc chuyển ownership output cần là thao tác có chủ đích của người dùng.

## 6. Các quyết định còn để sau

- License và mô hình thương mại.
- Nhà cung cấp AI thứ hai.
- Plugin SEO thứ hai ngoài Rank Math.
- Cam kết multisite.
- Mở rộng AI sang bài viết dài, bulk generation hoặc tự động xuất bản.
- Chính sách retention cụ thể của lịch sử AI, cần được chốt trước khi lưu dữ liệu production.

## 7. Ảnh hưởng tới Giai đoạn 1

Giai đoạn 1 phải chuẩn bị các boundary sau nhưng chưa cần triển khai toàn bộ tính năng tương lai:

1. `WooCommerce` dependency checker và module availability.
2. `RankMath` integration contract/registry, không hard-code logic integration vào Core.
3. `AiAssistant` provider contract và privacy/allowlist boundary; chưa cần gọi API trong nền móng nếu chưa có use case triển khai.
4. Output ownership state, để sau này chuyển quyền có kiểm soát.
5. Site-scope configuration, không tạo network-wide assumptions.
6. Health Check hiển thị dependency, integration và migration status.

## 8. Guardrails bất biến

- Không gửi dữ liệu bị cấm ra ngoài, kể cả khi provider yêu cầu thêm context.
- Không để API key xuất hiện ở browser, log hoặc export cấu hình.
- Không tạo dữ liệu schema/offer/review không có nguồn thật.
- Không tự xuất bản nội dung AI.
- Không tự chuyển ownership từ Rank Math sang My SEO For All.
- Không coi “latest” là bằng chứng tương thích; mọi release cần compatibility matrix và test.
