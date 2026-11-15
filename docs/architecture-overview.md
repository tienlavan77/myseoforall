# Architecture Overview — Plugin `document`

> Tài liệu mô tả kiến trúc hiện tại, công nghệ và hướng tái cấu trúc tổng quát của plugin WordPress/WooCommerce `document`. Đây là tài liệu định hướng, không phải Sprint Plan hay manifest triển khai.
>
> **Mức độ bằng chứng:** đánh giá dựa trên cây repository, entry point và các module chính đã kiểm tra; không nên hiểu là mọi dòng của mọi file đã được audit thủ công.

## 1. Tóm tắt điều hành

Plugin là một **modular monolith chạy trên WordPress**, tích hợp WooCommerce, REST API, quản lý tài liệu và file in, cùng frontend JavaScript build bằng Vite.

Luồng hiện tại:

```text
WordPress -> plugin.php -> App/ServiceContainer
          -> API/Controllers/Models/Views
          -> WooCommerce/Database/Filesystem

Admin page -> PHP asset loader -> Vite bundle
           -> route resolver -> frontend Controller/Model/View
```

Các vấn đề kiến trúc chính:

1. Service container, singleton tĩnh và `new` trực tiếp cùng tồn tại.
2. Business logic bị dàn trải giữa API, controller, model và support class.
3. Một feature bị rải qua nhiều technical layer.
4. Authentication/authorization chưa được quy về policy thống nhất.
5. REST, storage và frontend state contract chưa có tài liệu trung tâm.
6. Test/release pipeline chưa đủ mạnh cho refactor lớn.

**Định hướng:** modular hóa từng bước thành feature-oriented modular monolith; giữ WordPress là platform, giữ compatibility và tránh big-bang rewrite.

## 2. Mục đích và phạm vi chức năng

Plugin hiện cung cấp:

- Tạo/in quotation, delivery document và VAT invoice.
- Đọc customer, product, order và tax từ WooCommerce.
- Upload, preview, list, assign, detach và delete PDF.
- Quản lý print assets/jobs và dữ liệu liên quan đến order.
- Đọc template HTML từ `templates/`.
- Quản lý company, system và user settings.
- Thêm menu/màn hình WordPress Admin và tích hợp màn hình WooCommerce.
- REST API namespace `tinsinhphat/v1`.

## 3. Công nghệ hiện tại

### Backend

| Hạng mục | Công nghệ/hiện trạng |
|---|---|
| Host | WordPress Plugin |
| E-commerce | WooCommerce |
| Ngôn ngữ | PHP |
| Autoload | Composer PSR-4 (`App\\` -> `core/`) |
| API | WordPress REST API |
| Authentication | JWT HS256 tự triển khai, `firebase/php-jwt` |
| Persistence | WP options, user meta, order/post meta, custom tables |
| Storage | `wp-content/uploads/`, thư mục cấu hình và legacy |
| Hook | WordPress actions/filters qua `HookManager` |
| DI | `ServiceContainer`, chưa dùng thống nhất |
| Server view | PHP wrapper; UI chính do JavaScript dựng |

### Frontend/build

| Hạng mục | Công nghệ/hiện trạng |
|---|---|
| Ngôn ngữ | JavaScript ES Modules |
| UI | JavaScript thuần, DOM API và helper nội bộ |
| Pattern | Controller -> Model -> View |
| Bundle | Vite |
| Style | Sass/SCSS |
| Loading | Dynamic import/module loader |
| HTTP | `fetch` qua `HttpClient` |
| State | `localStorage` |
| Events | `BroadcastChannel` |
| Package | Node script tạo production ZIP |

## 4. Cấu trúc repository hiện tại

```text
plugin.php                 Entry point WordPress
composer.json/lock         PHP dependency và autoload
package.json               JS dependency và build scripts
vite.config.js             Vite configuration
build-production.sh        Production helper
bundle.js                  Packaging/build helper

core/
  App.php                  Bootstrap/composition hiện tại
  Api/                     REST API và endpoint classes
  Controllers/             Admin, menu, document, WooCommerce handlers
  Middlewares/             JWT middleware
  Models/                  Settings, documents, print, WooCommerce data
  Support/                 Container, hooks, menu, auth, storage, database
  Views/                   PHP wrappers cho admin screens

src/
  main.js                  Frontend entry point
  style.scss               Style entry point
  assets/scripts/
    Controllers/            Điều phối page/module
    Models/                 HTTP và dữ liệu client
    Views/                  UI renderer
    Vendor/                 Helper/vendor nội bộ
  assets/scss/              SCSS theo màn hình

templates/                 HTML/PHP document templates
public/                     Public assets
tests/                     Refactoring, frontend/backend và fixtures
docs/                      Tài liệu bảo trì/kiến thức
markdown/                   Tài liệu refactoring
production/                 Generated package hiện có
```

Cấu trúc đang trộn ba tiêu chí:

- technical layer: `Api`, `Controllers`, `Models`, `Views`;
- feature: `Documents`, `PrintFiles`, `Setting`;
- integration: `Admin`, `Menus`, `Woocommerce`.

Đây là vấn đề truy vết và ownership, không phải lý do đổi toàn bộ tên file ngay lập tức.

## 5. Luồng khởi động hiện tại

### PHP

```text
plugin.php
  -> Tinsinhphat::get_instance()
  -> plugins_loaded
  -> Tinsinhphat::init()
  -> vendor/autoload.php
  -> App::get_instance()
  -> ServiceContainer/HookManager/PluginManager/MenuRegistry
  -> validate WooCommerce
  -> register menus, APIs, controllers, integrations
```

`App` đăng ký nhiều service nhưng một số API/controller vẫn có `get_instance()`, tự `new` dependency hoặc tạo `HookManager` fallback. Container chưa phải composition root duy nhất.

### Frontend

```text
src/main.js
  -> App
  -> DocumentPluginConfig hoặc URL/body fallback
  -> page/type
  -> dynamic import controller
  -> Controller -> Model -> View
```

## 6. Điểm mạnh cần giữ

- Đã có phân lớp API/controller/model/view.
- Có `ServiceContainer` làm nền tảng DI.
- Có kiểm tra dependency WooCommerce và admin notice.
- Template được tách khỏi phần lớn logic ứng dụng.
- Có fallback route phía frontend.
- File module đã quan tâm đến extension, path, capability và rollback ở một số luồng.
- Có cơ chế đọc legacy storage để bảo vệ dữ liệu cũ.

## 7. Kiến trúc backend mục tiêu

```text
plugin.php
  -> Application/bootstrap
  -> Providers (composition root)
  -> Feature modules
  -> Application use case
  -> Domain rule
  -> Infrastructure adapter
  -> WordPress/WooCommerce/DB/Filesystem
```

Nên tách registration khỏi `App.php` ngày càng lớn:

```text
core/Providers/
  CoreServiceProvider.php
  SecurityServiceProvider.php
  DocumentsServiceProvider.php
  PrintFilesServiceProvider.php
  WooCommerceServiceProvider.php
  AdminServiceProvider.php
```

Nguyên tắc:

- Chỉ composition root tạo object hạ tầng.
- Feature class nhận dependency qua constructor.
- Không thêm singleton tĩnh mới.
- Giữ `get_instance()` tạm thời nếu cần compatibility.
- Xóa singleton sau khi chuyển hết consumer và có test thay thế.

## 8. Feature-oriented module mục tiêu

```text
core/Modules/
  Documents/
    Domain/
    Application/
    Infrastructure/
    Presentation/
    DocumentsModule.php
  PrintFiles/
  Settings/
  Templates/
  Authentication/
  WooCommerce/
  Admin/
```

Mỗi module sở hữu use case, policy, persistence contract và route registration của mình. Không bắt buộc di chuyển tất cả ngay; nên áp dụng cho module mới và chuyển Documents trước PrintFiles.

## 9. REST API hiện tại và hướng chuẩn hóa

Namespace:

```text
/wp-json/tinsinhphat/v1
```

Nhóm endpoint:

```text
auth/       documents/       print-files/
settings/   templates/        woo/
```

Mỗi endpoint nên có:

```text
Request validation
  -> Authentication/permission policy
  -> Application handler/use case
  -> Response serializer
```

Nên duy trì response compatibility trong giai đoạn chuyển tiếp và ghi contract tại `docs/api/` hoặc OpenAPI. Cần chuẩn hóa error code/status, pagination, validation, route/body semantics, envelope và deprecation policy.

Đặc biệt cần thống nhất route xóa file: URL có `{slug}` nhưng handler còn đọc `path` từ body theo tài liệu audit. Client mới không nên phải biết hai nguồn định danh.

## 10. Tài liệu liên quan

- [`architecture-details.md`](architecture-details.md): boundary, security, data, frontend, testing, release và roadmap.
- [`knowledge-base.md`](knowledge-base.md): mô tả behavior hiện tại và các điểm cần kiểm tra thêm.
- [`../markdown/Part_1_Core_Refactoring_Specification.md`](../markdown/Part_1_Core_Refactoring_Specification.md): phạm vi refactoring core hiện có.
