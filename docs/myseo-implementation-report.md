# Báo cáo chuẩn bị và triển khai nền móng My SEO For All

Ngày: 2026-11-15

## Phạm vi

Báo cáo này đối chiếu ba tài liệu định hướng:

- `docs/gia-doan-1.md`
- `docs/myseo-product-direction.md`
- `docs/myseo-owner-decisions.md`

Giai đoạn 1 được giới hạn ở nền móng WordPress/WooCommerce, cấu hình, lifecycle, Settings/Health Check và các boundary cần thiết. Chưa triển khai gọi API AI, Rank Math hoặc logic SEO nghiệp vụ.

## Kết quả đã triển khai

- Bootstrap plugin và composition root được giữ mỏng trong `myseoforall.php`; `Plugin` dựng service và module theo hai bước register/boot, có guard để boot idempotent.
- `Configuration` có namespace option, schema version, giá trị mặc định tiếng Việt, chuẩn hóa locale/module enablement/migration status.
- `OptionStore` cô lập đọc/ghi option WordPress; activation tạo cấu hình namespaced và deactivation giữ nguyên dữ liệu người dùng.
- `AdminModule` cung cấp Settings và Health Check tối thiểu, dùng capability, nonce, sanitization và escaping.
- `WooCommerceModule` và `EnvironmentCheck` báo trạng thái dependency khi WooCommerce chưa sẵn sàng mà không gây fatal error.
- Health output trả về trạng thái plugin, configuration, environment và module; không đưa secret vào giao diện chẩn đoán.
- PHPUnit đã được thêm để bảo vệ default configuration, xử lý thiếu WooCommerce và tính idempotent của lifecycle.

## Quyết định Anthropic và cách diễn giải

Quyết định hiện hành ở `docs/myseo-owner-decisions.md` xác định Anthropic là model provider đầu tiên, được gọi **qua gateway bên thứ ba**, và plugin không gọi trực tiếp endpoint Anthropic. Provider phải đi qua contract riêng để có thể thay gateway hoặc nhà cung cấp.

Vì vậy, cụm “Anthropic API + SDK chính thức, gọi qua gateway” nên được thực hiện theo quy tắc sau:

1. Dùng contract/provider adapter của plugin làm boundary.
2. Nếu SDK chính thức được dùng, SDK chỉ là client/protocol implementation; endpoint, base URL và authentication phải trỏ tới gateway đã được Project Owner chọn.
3. Không hard-code endpoint Anthropic trực tiếp trong plugin và không gửi dữ liệu ngoài allowlist.
4. Chưa thêm SDK, credential hoặc network call ở Giai đoạn 1; mã hiện tại chỉ chuẩn bị boundary để tránh khóa kiến trúc sớm.

Điểm này không còn là mâu thuẫn về ownership, nhưng vẫn còn thiếu tên/version gateway, endpoint contract và cơ chế xác thực cụ thể. Các thông tin đó phải được chốt trước khi viết provider runtime.

## Kiểm tra đã thực hiện

Lệnh:

`php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/FoundationTest.php`

Kết quả: **OK — 3 tests, 7 assertions**.

Đây là bằng chứng cho behavior nền móng hiện có; nó chưa chứng minh migration production, provider Anthropic, gateway connectivity hay tương thích với mọi phiên bản WordPress/WooCommerce.

## Việc cần chốt trước hạng mục phụ thuộc

- Gateway Anthropic đầu tiên, version/API contract, base URL và authentication.
- Compatibility matrix cụ thể cho PHP, WordPress và WooCommerce của release mục tiêu.
- Chính sách retention lịch sử AI trước khi lưu dữ liệu production.
- Có cần database migration lock ngay từ bản đầu hay chỉ dùng option state/guard.
- Contract và registry của Rank Math trước khi triển khai ownership hoặc output integration.

## Bằng chứng nguồn

- `src/Core/Plugin.php` — sha256 `21a4981a3d6eadd71f4a3062a5a2be20bd5e2a84f5e5ca80950b3c614c860ff5`
- `src/Core/Configuration/Configuration.php` — sha256 `b73646e9f53d41a407d83dff5b0956983a13daf8ea7086edfa73ca66e89fc4a3`
- `src/Admin/AdminModule.php` — sha256 `3511b2866dafd11fde50cfc073cb9cade1a0335e1d025d9421da8bccda4a08e2`
- `tests/Unit/FoundationTest.php` — sha256 `262014fe9ee6c60752f1b9574817fab67877a1634a08141def4d840f1d563185`
- `docs/myseo-owner-decisions.md` — sha256 `baacf19b1a9fb367f0ff95f75d37b7fdb450e829b7c981ccbfe9c16c50b44f9a`
