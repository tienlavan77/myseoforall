# Giai đoạn 1 — Nền móng plugin My SEO For All

> Bản ghi các ý tưởng, phạm vi và guardrail đã trao đổi cho Giai đoạn 1. Tài liệu này là định hướng kiến trúc/delivery, chưa phải Sprint Plan hay manifest file.

## 1. Mục tiêu

Xây dựng nền móng WordPress/WooCommerce đủ ổn định để các giai đoạn SEO Core, Structured Data, Rank Math integration và AI Assistant có thể phát triển mà không phải viết lại bootstrap.

Giai đoạn này chưa tập trung vào việc viết tính năng SEO hay gọi API AI. Trọng tâm là:

- Plugin được WordPress nhận diện và kích hoạt đúng.
- Không fatal error khi WooCommerce chưa hoạt động.
- Composition root rõ ràng, không có god class.
- Cấu hình có namespace và version.
- Có lifecycle activation/deactivation/upgrade an toàn.
- Có capability, nonce, sanitization và escaping cơ bản.
- Có Settings và Health Check tối thiểu bằng tiếng Việt.
- Có test bảo vệ behavior nền tảng.

## 2. Môi trường và phạm vi mục tiêu

- PHP 8.
- WordPress phiên bản mới hiện tại.
- WooCommerce phiên bản mới hiện tại.
- Site đơn trong phiên bản đầu; chưa cam kết multisite.
- Composer + PHPUnit ngay từ đầu.
- Không đưa frontend framework lớn vào nền móng.
- License và mô hình thương mại để quyết định sau.

“Phiên bản mới hiện tại” phải được chuyển thành compatibility matrix cụ thể trước mỗi release; không xem đó là bằng chứng tương thích tự động.

## 3. Cấu trúc ban đầu đề xuất

Chỉ tạo các module có behavior thật, không tạo hàng loạt thư mục trống:

```text
myseoforall.php
composer.json
phpunit.xml
README.md
CHANGELOG.md

src/
  Core/
    Plugin.php
    Container.php
    Contracts/
    Configuration/
    Support/
  Admin/
    AdminModule.php
    Notices/
  WooCommerce/
    WooCommerceModule.php
  Infrastructure/
    WordPress/
    Persistence/

tests/
  Unit/
  Integration/

languages/
docs/
```

Các module SEO, StructuredData, Integrations, AiAssistant và Privacy chỉ thêm khi có use case/contract cụ thể.

## 4. Bootstrap

File `myseoforall.php` chỉ nên:

- Khai báo metadata plugin và version.
- Chặn truy cập trực tiếp.
- Kiểm tra PHP/WordPress tối thiểu.
- Nạp Composer autoloader hoặc bootstrap loader.
- Gọi composition root.
- Đăng ký activation/deactivation hook.

Không đặt SEO logic, truy vấn database, HTML, request handling, AI call hoặc WooCommerce business rule vào entry point.

Luồng mục tiêu:

```text
myseoforall.php
  -> Plugin::boot()
  -> EnvironmentCheck
  -> Container/Providers
  -> Core module
  -> Admin module
  -> WooCommerce module nếu khả dụng
```

Bootstrap phải idempotent, không đăng ký hook trùng khi được gọi lại.

## 5. Dependency và module availability

### Bắt buộc

PHP 8 và WordPress phiên bản nằm trong compatibility matrix. Nếu thiếu dependency bắt buộc, plugin không khởi động đầy đủ và hiển thị thông báo rõ ràng.

### WooCommerce

WooCommerce là dependency sản phẩm của MVP nhưng plugin phải an toàn khi chưa cài/chưa kích hoạt:

- Không gọi class/hook khi chưa sẵn sàng.
- Không fatal error.
- Hiển thị trạng thái “WooCommerce chưa được kích hoạt”.
- Không giả lập dữ liệu sản phẩm.
- Có thể mở phần cấu hình nền tảng nếu phù hợp.

### Tùy chọn

Rank Math, Anthropic provider và các integration tương lai không được làm hỏng plugin chính khi unavailable.

Nên có trạng thái dependency thống nhất:

```text
DependencyStatus
  - name
  - installed
  - active
  - compatible
  - version
  - message
```

## 6. Composition root và module lifecycle

Chỉ một composition root chịu trách nhiệm lắp ráp service:

```text
Plugin
  -> register Core services
  -> register Admin services
  -> register WooCommerce services
  -> register module hooks
```

Nguyên tắc:

- Service được tạo ở composition root/provider.
- Dependency truyền qua constructor.
- Không thêm singleton tĩnh mới.
- Không `new` rải rác trong controller/model.
- Container không trở thành global service locator cho mọi class.
- Plugin/Container không chứa business rule.

Module lifecycle định hướng:

```text
register()  -> đăng ký service/dependency
boot()      -> đăng ký hook/route/admin/integration
isAvailable() -> kiểm tra điều kiện
health()    -> báo trạng thái/cảnh báo
```

## 7. Cấu hình và persistence

Prefix ban đầu:

```text
Option: myseo_
Meta:   _myseo_
```

Không dùng option/meta key chung chung. Mỗi option có module sở hữu và version riêng khi cần.

Cấu hình nền tảng cần có:

- Version schema.
- Module enablement.
- Locale mặc định `vi_VN`.
- Trạng thái migration.
- Site-scope rõ ràng.

API key không được đưa xuống JavaScript, HTML, localStorage hoặc log.

Không tạo một option khổng lồ cho mọi tính năng tương lai nếu chưa có consumer. Có thể tách `myseo_core_settings`, `myseo_seo_settings`, `myseo_ai_settings` theo ownership.

## 8. Lifecycle và migration

### Activation

- Tạo cấu hình mặc định nếu chưa có.
- Ghi schema version.
- Kiểm tra môi trường.
- Chỉ đặt cờ cần flush rewrite nếu thật sự cần.
- Không gọi API ngoài, không tạo hàng loạt SEO data, không migration nặng trong activation request.

### Deactivation

- Dừng behavior plugin.
- Không xóa dữ liệu người dùng.

### Uninstall

Tách khỏi deactivation. Chỉ xóa dữ liệu khi có lựa chọn/chỉ thị rõ ràng của người dùng.

### Migration runner

```text
stored_version < current_version
  -> chạy migration tuần tự
  -> ghi trạng thái
  -> dừng an toàn nếu lỗi
```

Migration phải idempotent hoặc có guard; chạy lại không được làm hỏng dữ liệu. Cần trạng thái để tránh ghi version mới khi migration chưa hoàn tất và cần cân nhắc lock/chống chạy đồng thời.

## 9. Bảo mật và quyền

Chuẩn hóa từ Giai đoạn 1:

- `current_user_can()` theo từng hành vi.
- WordPress nonce cho form/AJAX/REST.
- Sanitize input.
- Escape output đúng ngữ cảnh.
- Không tin `$_POST`, `$_GET` hoặc dữ liệu JavaScript.
- Không hiển thị secret trong admin/response.
- Không dùng JWT cho WordPress Admin.

Giai đoạn đầu có thể dùng capability WordPress hiện có; cấu hình API key và privacy policy về sau phải có quyền chặt hơn thao tác preview.

## 10. Settings và Health Check tối thiểu

### Settings

- Trang cài đặt tiếng Việt.
- Site scope, không network-wide.
- Lưu/đọc option đúng namespace.
- Nonce và capability.
- Sanitization/validation.
- Thông báo thành công/thất bại rõ ràng.

### Health Check

Hiển thị tối thiểu:

- Phiên bản plugin.
- PHP/WordPress/WooCommerce.
- Trạng thái module.
- Trạng thái migration.
- Rank Math có được phát hiện không.
- AI provider đã cấu hình chưa, nhưng không hiển thị API key.

Thông báo nên phân biệt blocker, warning và info; không hiển thị stack trace cho người dùng.

## 11. Logging và môi trường hosting

Logging tối thiểu có:

```text
event, module, severity, redacted_context, timestamp
```

Không log API key, token, secret, dữ liệu cá nhân hoặc toàn bộ nội dung riêng tư.

Cần kiểm tra/hiển thị trạng thái phù hợp cho:

- PHP extensions cần thiết.
- Memory limit và execution time khi liên quan.
- HTTPS.
- REST API.
- WP-Cron nếu tác vụ tương lai cần.
- Object/persistent cache nếu phát hiện.
- Khả năng ghi dữ liệu.

Không giả định hosting giống môi trường phát triển.

## 12. Tiếng Việt và accessibility

- Text domain riêng, ví dụ `myseo-for-all`.
- Dùng WordPress translation functions.
- Không hard-code toàn bộ chuỗi UI.
- Label/error liên kết đúng với input.
- Hoạt động bằng bàn phím và hỗ trợ screen reader.
- Không dùng màu là tín hiệu duy nhất.
- Có trạng thái loading/success/failure/retry.
- Không làm mất input khi request thất bại.

## 13. Compatibility và ownership chuẩn bị trước

Dù chưa triển khai SEO Core, Giai đoạn 1 phải để chỗ cho:

### Rank Math

- Integration registry/contract riêng.
- Không rải meta key Rank Math vào Core.
- Phát hiện trạng thái nhưng chưa tự chuyển ownership.

### Output SEO

- Lưu trạng thái owner theo loại output.
- Không tự giành sitemap; dùng WordPress hoặc plugin SEO hiện có.
- My SEO For All chỉ trở thành plugin SEO chính sau xác nhận rõ, preview, lưu dấu trạng thái trước và có đường lui.

### WooCommerce variation

- Boundary giữ quan hệ product cha/variation.
- Không làm sai giá, tồn kho và offer.
- Không cần UI variation nâng cao trong Giai đoạn 1.

## 14. Test tối thiểu

### Unit

- Dependency checker.
- Configuration defaults/validation.
- Module availability.
- Version comparison.
- Migration ordering.
- Capability policy.

### Integration

- Bootstrap.
- Activation không tạo dữ liệu trùng.
- Deactivation không xóa dữ liệu.
- WooCommerce thiếu không fatal.
- WooCommerce có mặt đăng ký module đúng.
- Nonce/capability failure.
- Settings đúng namespace.

### Smoke thủ công

- WordPress sạch.
- WooCommerce chưa bật rồi bật sau.
- WooCommerce bị tắt giữa chừng.
- Deactivate/activate lại.
- Upgrade cấu hình cũ.
- Không có PHP warning nghiêm trọng.

## 15. Chưa làm trong Giai đoạn 1

- AI viết bài hoặc gọi Anthropic API.
- Schema Product hoàn chỉnh.
- Sitemap riêng.
- Bulk SEO audit.
- Tích hợp đầy đủ Rank Math.
- Dashboard phân tích lớn.
- Queue/background worker.
- Tự động xuất bản.
- License/thương mại.
- Frontend framework lớn.

## 16. Kết quả bàn giao

```text
myseoforall.php
composer.json
phpunit.xml
README.md
CHANGELOG.md
src/Core/
src/Admin/
src/WooCommerce/
src/Infrastructure/
tests/
languages/
docs/
```

Cần trả lời được:

- Plugin khởi động từ đâu?
- Ai tạo service?
- Module nào hoạt động?
- WooCommerce thiếu thì điều gì xảy ra?
- Config nằm ở đâu và version nào?
- Ai có quyền thay đổi?
- Migration chạy thế nào?
- Lỗi báo cho người dùng ra sao?
- Test nào chứng minh behavior?

## 17. Các điểm cần chốt trước implementation

Các quyết định sản phẩm chính đã được chốt trong `docs/myseo-owner-decisions.md`. Trước khi implementation vẫn cần chuyển “WordPress/WooCommerce mới hiện tại” thành compatibility matrix cụ thể theo release mục tiêu, đồng thời xác nhận:

- Tên/version cụ thể của Anthropic SDK sẽ dùng.
- PHP 8.x minor version tối thiểu trong môi trường phát hành.
- Chính sách retention lịch sử AI trước khi lưu dữ liệu production.
- Có cần lock migration ở mức database ngay trong phiên bản đầu hay chỉ dùng guard/option state.
