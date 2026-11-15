# Architecture Details — Plugin `document`

Tài liệu chi tiết đi kèm [`architecture-overview.md`](architecture-overview.md).

## 1. Authentication và authorization

Hiện plugin dùng JWT HS256, secret runtime `SECRET_KEY_DOCUMENT`, token dài hạn và frontend lưu token trong `localStorage`. Middleware kiểm tra token, user, role và `user_information`; một số endpoint kiểm tra thêm WordPress capability.

### Rủi ro

- Token dài hạn tăng tác động khi bị lộ.
- `localStorage` nhạy cảm với XSS.
- JWT tự triển khai cần refresh, revoke, rotation và key validation.
- Role/capability có thể không đồng nhất giữa các route.
- Secret chưa được thể hiện như cấu hình bắt buộc có validation rõ ràng.

### Hướng xử lý

Đối với WordPress Admin, nên đánh giá:

```text
WordPress logged-in session + REST nonce + current_user_can()
```

Chỉ giữ JWT như public integration contract nếu có client độc lập thật sự cần. Nếu tiếp tục dùng JWT, cần access token ngắn hạn, refresh/revoke strategy, secret validation và audit mọi permission callback. Đây là thay đổi contract cần migration riêng, không nên gộp tùy tiện vào refactor folder.

## 2. Documents và PrintFiles

Model hiện có xu hướng cùng lúc xử lý business rule, database/meta, filesystem, upload/delete, path security, order assignment và response/error.

Nên tách dần thành:

```text
Domain entity/value objects
Application handlers:
  UploadDocument
  ListDocuments
  AssignDocument
  DetachDocument
  DeleteDocument
Infrastructure:
  DocumentRepository
  DocumentStorage
  OrderDocumentMetadata
Presentation:
  REST controller/serializer
```

### Storage compatibility

Plugin cần tiếp tục đọc legacy storage nhưng chỉ ghi vào storage mới trong giai đoạn migration:

```text
DocumentStorageInterface
  -> ConfiguredDocumentStorage
  -> LegacyDocumentStorage
  -> CompositeDocumentStorage
```

Cần kiểm soát path traversal, symlink escape, MIME/extension validation, file root và quyền xóa. Không chuyển storage hoặc đổi meta key nếu chưa có migration/rollback.

## 3. Frontend mục tiêu

```text
src/
  app/
    bootstrap.js
    router.js
    runtime-config.js
    module-loader.js
  platform/
    http/
    auth/
    storage/
    events/
    wordpress/
  modules/
    documents/
    quotation/
    delivery/
    invoice/
    settings/
    woo-orders/
    print-files/
  shared/
    ui/
    forms/
    validation/
    formatting/
  styles/
```

Mỗi module nên có public entry point, không import sâu vào implementation nội bộ module khác.

State browser nên có namespace và version:

```text
documentPlugin:quotation:v1
documentPlugin:delivery:v1
documentPlugin:invoice:v1
documentPlugin:settings:v1
```

Payload state nên gồm `version`, `updatedAt`, `data`; cần validation, migration, expiry/cleanup và không lưu secret/credential nhạy cảm.

## 4. Data ownership

| Dữ liệu | Nơi hiện tại | Module sở hữu đề xuất |
|---|---|---|
| Company setting | WP option | Settings |
| System folder config | WP option | Storage/Settings |
| User integration info | User meta | Authentication/Integration |
| Document assignment | Order meta/custom metadata | Documents |
| Print jobs/assets | Custom table/filesystem | PrintFiles |
| Uploaded documents | Filesystem | Documents/Storage |
| Template content | Plugin files | Templates |
| Draft quotation/delivery/invoice | Browser storage | Frontend document modules |

Mỗi field cần một owner duy nhất và một adapter persistence; module khác chỉ truy cập qua contract.

## 5. WooCommerce compatibility

Cần audit riêng và duy trì contract cho:

- HPOS;
- WooCommerce CRUD API;
- classic và new order screens;
- REST API version;
- product variation/customer/tax data;
- feature flags và capability.

Không nên phụ thuộc post meta trực tiếp cho mọi order logic khi WooCommerce CRUD API đã là abstraction chính. Nếu phải giữ legacy fallback, đặt nó trong adapter và có test tương ứng.

## 6. Build, package và release

Phân biệt source-controlled và generated artifact:

```text
Source: core/, src/, templates/, plugin.php, config, tests, docs
Generated: dist/, production/document.zip, production bundles
```

Pipeline đề xuất:

```text
lint/static analysis
  -> unit tests
  -> WordPress/WooCommerce integration tests
  -> contract tests
  -> frontend build
  -> composer install --no-dev
  -> package validation
  -> artifact checksum
```

Cần xác định chính thức việc commit `vendor/`, `dist/`, `node_modules/` và ZIP production; release không nên phụ thuộc trạng thái máy developer.

## 7. Kiểm thử mục tiêu

### Unit

- path normalization;
- document type mapping;
- validation và pagination;
- permission policy;
- template selection;
- state migration;
- JWT claims parsing nếu JWT còn được dùng.

### Integration

- plugin bootstrap;
- REST route/permission;
- settings persistence;
- document upload/assign/delete;
- database schema;
- folder setup;
- WooCommerce order/product/customer integration.

### Contract

- response envelope;
- error/status;
- auth failure/forbidden;
- invalid input;
- route parameter;
- pagination;
- backward compatibility.

### E2E

Login, mở quotation/delivery/invoice, chọn dữ liệu WooCommerce, lưu draft, upload/preview/assign PDF, thao tác settings, thiếu WooCommerce, thiếu capability và coexistence giữa legacy/new storage.

## 8. Lộ trình chuyển đổi an toàn

### Giai đoạn 0 — Đóng băng contract

Ghi lại REST routes, response/error, options/meta/table, template slug, localStorage keys, hooks và menus. Thêm smoke tests trước khi đổi cấu trúc.

### Giai đoạn 1 — Bootstrap và provider

Giữ behavior; làm rõ application bootstrap; gom registration; không thêm singleton mới.

### Giai đoạn 2 — Dependency injection

Chuyển dần HookManager, auth, storage, repositories, application services và REST controllers sang constructor injection.

### Giai đoạn 3 — Documents module

Tách storage/repository/use cases; giữ route và data compatibility; đọc legacy nhưng ghi new storage.

### Giai đoạn 4 — PrintFiles module

Tách sau khi abstraction database/storage và test nền tảng ổn định.

### Giai đoạn 5 — Frontend

Tách runtime config/router/API/auth/state; chuyển từng quotation, delivery, invoice và settings module.

### Giai đoạn 6 — Security và release

Chuẩn hóa capability, authentication policy, file security, static analysis, test và package pipeline.

## 9. Ưu tiên rủi ro

### P0 — An toàn và contract

- Permission callback của mọi route.
- JWT secret/token policy.
- File path/storage security.
- API/data contract.
- HPOS compatibility.
- Test upload, assign, delete và auth.

### P1 — Maintainability

- Một composition root.
- Giảm singleton và `new` trực tiếp.
- Provider registration.
- Repository/storage/use-case separation.

### P2 — Tổ chức

- Feature-first modules.
- Frontend router/runtime/state chuẩn hóa.
- API docs/OpenAPI.
- Static analysis và release automation.

### P3 — Tối ưu dài hạn

- Xóa legacy adapter sau migration.
- Profiling query/cache.
- Chỉ cân nhắc framework frontend khi complexity có bằng chứng rõ.

## 10. Không nên làm

- Big-bang rewrite toàn bộ plugin.
- Đổi REST response, meta key, template slug hoặc storage path không có migration.
- Xóa singleton khi vẫn còn consumer.
- Đổi authentication mà không kiểm kê client.
- Dùng `App` mới như một god class.
- Gộp Documents và PrintFiles chỉ vì cùng thao tác với file.
- Chuyển sang frontend framework lớn chỉ để giải quyết vấn đề thư mục.
- Coi test cấu trúc là bằng chứng đủ cho production behavior.

## 11. Evidence và giới hạn kết luận

Các nguồn chính đã kiểm tra:

- `plugin.php`
- `core/App.php`
- `core/Api/Api.php`
- `core/Support/PluginManager.php`
- `core/Support/ServiceContainer.php`
- `core/Controllers/`
- `core/Models/`
- `core/Middlewares/`
- `src/main.js`
- `src/assets/scripts/app.js`
- `src/assets/scripts/Controllers/`
- `src/assets/scripts/Models/`
- `src/assets/scripts/Views/`
- `composer.json`
- `package.json`
- `vite.config.js`
- `tests/`
- `docs/knowledge-base.md`
- `markdown/Part_1_Core_Refactoring_Specification.md`

Các điểm cần audit riêng trước khi trở thành quyết định triển khai:

- full REST permission matrix;
- mọi consumer của singleton và `get_instance()`;
- HPOS behavior trên phiên bản WooCommerce production;
- JWT lifecycle và client inventory;
- database schema/migration hiện hành;
- localStorage compatibility;
- build/release environment;
- production behavior của legacy file storage.

## 12. Kết luận

Plugin có nền tảng tốt để tiến hóa thành modular monolith bền vững. Hướng đúng là giữ WordPress/WooCommerce làm platform, biến `App`/provider thành composition root, tách feature module, đẩy business rule ra khỏi model hạ tầng, chuẩn hóa auth/API/storage contract và chuyển frontend sang module/state boundary rõ ràng.

Mọi thay đổi nên incremental, backward-compatible và có contract test trước. Mục tiêu không phải làm cây thư mục trông mới hơn, mà làm ownership, dependency, security boundary và data lifecycle rõ ràng, kiểm chứng được và có thể bảo trì lâu dài.
