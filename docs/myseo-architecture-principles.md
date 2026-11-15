# My SEO For All — Nguyên tắc kiến trúc

> Tài liệu chuyển hóa các điểm mạnh và điểm yếu được ghi nhận trong `docs/architecture-overview.md` và `docs/architecture-details.md` của plugin mẫu thành nguyên tắc thiết kế cho `myseoforall`.
>
> Đây là tài liệu định hướng kiến trúc, không phải Sprint Plan, danh sách ticket hoặc manifest file triển khai.

## 1. Kết luận chính

My SEO For All sẽ được xây dựng mới theo hướng **modular monolith hướng feature**, chạy trên WordPress và WooCommerce. Không thực hiện big-bang rewrite vì dự án chưa có mã nguồn ứng dụng cần di trú; tuy vậy, các quy tắc migration và compatibility của plugin mẫu vẫn được giữ như guardrail cho tương lai.

Mục tiêu không phải tạo một cây thư mục đẹp hơn, mà là làm rõ:

- module nào sở hữu dữ liệu và business rule;
- dependency nào được phép đi qua boundary;
- output SEO nào là nguồn duy nhất;
- quyền nào được kiểm tra ở đâu;
- dữ liệu AI đi ra ngoài khi nào và theo quyền nào;
- mỗi thay đổi được kiểm chứng ra sao.

## 2. Điểm mạnh của plugin mẫu cần kế thừa

### 2.1 WordPress/WooCommerce là platform

- Dùng WordPress hooks, capability, REST API và persistence abstraction thay vì tự xây lại platform.
- Dùng WooCommerce CRUD API và các abstraction chính thức khi xử lý sản phẩm, biến thể, giá, tồn kho và đơn vị tiền tệ.
- Kiểm tra HPOS và các chế độ WooCommerce liên quan; không giả định mọi dữ liệu đều nằm trực tiếp trong post meta.
- Có thể hoạt động an toàn khi WooCommerce chưa khả dụng: phần sản phẩm ở trạng thái disabled/unavailable rõ ràng, không fatal error.

### 2.2 Modular monolith theo feature

Mỗi module sở hữu use case, policy, persistence contract và presentation boundary của mình. Cấu trúc mục tiêu có thể bắt đầu như sau:

```text
myseoforall.php
src/
  Core/                  bootstrap, config, capability, contracts
  Seo/
    Domain/
    Application/
    Infrastructure/
    Presentation/
  Content/
  WooCommerce/
  StructuredData/
  Integrations/
  AiAssistant/
  Admin/
  Privacy/
  Infrastructure/
```

Đây là hướng tổ chức, không khóa tên file hay yêu cầu di chuyển trước khi có evidence từ implementation.

### 2.3 Composition root rõ ràng

- Chỉ bootstrap/composition root khởi tạo service hạ tầng.
- Dependency được truyền qua constructor.
- Không thêm singleton tĩnh mới.
- Không để một `App` trở thành god class.
- Provider/module registration được tách khỏi business logic.
- Compatibility shim chỉ tồn tại khi có consumer thực tế và phải có kế hoạch loại bỏ.

### 2.4 Business rule tách khỏi WordPress adapter

Luồng xử lý nên có dạng:

```text
WordPress hook/REST/Admin request
  -> request validation + capability policy
  -> application use case
  -> domain rule
  -> repository/provider/adapter
  -> serializer/output
```

Ví dụ:

- `GenerateProductStructuredData` không tự đọc trực tiếp toàn bộ `$_POST`.
- `AnalyzeContent` không phụ thuộc trực tiếp vào một HTTP client cụ thể.
- `SaveSeoFields` không biết chi tiết option/meta key của từng plugin SEO.

### 2.5 Contract-first và kiểm thử nhiều lớp

Trước khi mở rộng tính năng cần xác định contract cho:

- SEO field và nguồn dữ liệu;
- output meta/JSON-LD/sitemap;
- adapter plugin SEO;
- WooCommerce product/variation;
- AI provider request/response;
- quyền riêng tư và capability;
- REST response/error;
- migration/version của option và meta.

Các lớp kiểm thử cần có:

- Unit: rule SEO, schema mapping, validation, redaction, provider selection.
- Integration: bootstrap, hooks, WordPress options/meta, WooCommerce product/variation, REST permission.
- Contract: adapter, output JSON-LD, AI provider, error/status.
- E2E: chỉnh sửa bài viết/sản phẩm, preview, apply, rollback, plugin conflict và WooCommerce unavailable.

## 3. Điểm yếu của plugin mẫu cần tránh

### 3.1 Không trộn technical layer với feature ownership

Không để business logic bị rải tùy tiện giữa `Api`, `Controllers`, `Models`, `Views`, rồi thêm integration vào một nơi khác. Technical layer chỉ là cách triển khai bên trong module; module feature mới là boundary sở hữu.

### 3.2 Không để model làm mọi việc

Model/repository không đồng thời xử lý:

- business rule;
- authorization;
- database/meta;
- filesystem hoặc HTTP;
- serialize response;
- xử lý lỗi giao diện.

Các trách nhiệm này phải được tách thành use case, policy, repository/adapter và serializer phù hợp.

### 3.3 Không có nhiều nguồn xuất SEO cùng lúc

Tại mỗi thời điểm chỉ có một nguồn phát hành cho từng loại output:

- title/meta;
- canonical;
- Open Graph;
- JSON-LD;
- sitemap;
- robots directives.

Khi phát hiện Yoast, Rank Math, AIOSEO hoặc plugin tương tự, phải có detection và ownership policy rõ ràng. Không âm thầm in output trùng lặp.

### 3.4 Không khóa dữ liệu vào một plugin hoặc nhà cung cấp AI

Dùng interface/adapter cho:

- plugin SEO bên thứ ba;
- WooCommerce;
- AI provider;
- storage/cache nếu về sau cần thay thế.

Adapter không được làm rò rỉ meta key hoặc response format riêng ra domain/application layer.

### 3.5 Không tự xây authentication thay cho WordPress Admin

Đối với thao tác trong Admin, ưu tiên:

- WordPress logged-in session;
- REST nonce;
- `current_user_can()`;
- capability riêng khi cần.

Không thêm JWT chỉ vì muốn có một lớp auth “độc lập”. Nếu tương lai cần public integration/API client, đó phải là quyết định contract riêng, có token lifecycle, rotation, revoke, audit và inventory consumer.

### 3.6 Không lưu thông tin nhạy cảm ở frontend

- Không lưu API key trong `localStorage` hoặc HTML.
- Không expose secret vào JavaScript bundle.
- Không gửi toàn bộ nội dung website tới AI mặc định.
- Chỉ gửi payload tối thiểu sau khi người dùng bật provider và được thông báo.
- Lưu cấu hình nhạy cảm qua cơ chế WordPress phù hợp, có capability và bảo vệ dữ liệu.

### 3.7 Không đồng nhất cấu trúc với bằng chứng production

Folder mới hoặc test cấu trúc không chứng minh behavior đúng. Mọi thay đổi cần kiểm tra bằng hành vi thực tế: permission, output, dữ liệu WooCommerce, conflict và failure path.

### 3.8 Không chuyển framework frontend lớn khi chưa có nhu cầu

MVP nên dùng giao diện quản trị nhỏ, module hóa và có state boundary rõ. Chỉ đưa framework nặng vào khi complexity, accessibility hoặc maintainability có bằng chứng cụ thể.

## 4. Boundary cốt lõi của My SEO For All

### SEO Core

Sở hữu canonical SEO model, field validation, source precedence và output ownership. Không biết chi tiết API của AI hay plugin SEO bên ngoài.

### Content

Đọc context bài viết/trang và chuẩn hóa dữ liệu nội dung cho use case. Không tự quyết định quyền xuất bản.

### WooCommerce

Đọc product, variation, price, stock, SKU, taxonomy, image và review qua adapter chính thức. Không để AI thay đổi dữ liệu thương mại gốc.

### Structured Data

Sinh JSON-LD từ dữ liệu đã chuẩn hóa. Schema phải phản ánh dữ liệu hiển thị thật; thiếu dữ liệu thì bỏ field hoặc báo cảnh báo, không bịa.

### Integrations

Phát hiện và giao tiếp với plugin SEO khác thông qua adapter. Chịu trách nhiệm ownership/coexistence, không đưa meta key của bên thứ ba vào core.

### AI Assistant

Phân tích, đề xuất và tạo draft. Kết quả phải qua preview/approval; không tự publish trong MVP. Provider phải có timeout, lỗi có thể hiểu được, giới hạn dữ liệu và redaction.

### Admin/Presentation

Hiển thị tiếng Việt trước, quản lý form, nonce, capability, preview và apply/revert. Không đặt domain rule trong view/controller UI.

### Privacy

Sở hữu consent/enablement, audit tối thiểu, policy dữ liệu gửi ra ngoài và cơ chế tắt AI. Không để provider tự quyết định privacy policy.

## 5. Nguyên tắc dữ liệu và migration

- Mỗi field có một owner và một nguồn sự thật.
- Option/meta key có version và namespace riêng, ví dụ `myseo_`.
- Thay đổi schema/option/meta phải có migration và rollback strategy trước khi release.
- Đọc dữ liệu cũ có thể hỗ trợ qua adapter; không ghi đè dữ liệu bên thứ ba ngoài lựa chọn của người dùng.
- Không xóa field/plugin integration chỉ vì không còn dùng trong code mới nếu chưa kiểm kê consumer.
- Output SEO cần snapshot/contract test để phát hiện thay đổi ngoài ý muốn.

## 6. Quy trình phát triển đề xuất

1. Khóa outcome và boundary.
2. Viết contract và acceptance behavior.
3. Tạo module nhỏ nhất có composition root rõ ràng.
4. Thêm unit/integration/contract test tương ứng.
5. Kiểm tra coexistence với WooCommerce và plugin SEO.
6. Kiểm tra security, privacy, capability và failure path.
7. Chỉ sau đó mới tối ưu cache, query hoặc UI.

Không xây tất cả abstraction trước khi có use case; abstraction phải xuất phát từ contract và consumer thật.

## 7. Các guardrail không thương lượng

- Không bịa dữ liệu schema hoặc dữ liệu sản phẩm.
- Không tự xuất bản nội dung AI trong MVP.
- Không gửi dữ liệu ra AI nếu chưa được bật rõ ràng.
- Không tạo output SEO trùng với plugin đang sở hữu output đó.
- Không bỏ qua `current_user_can()` và nonce cho thao tác thay đổi dữ liệu.
- Không dùng trực tiếp dữ liệu post meta cho mọi logic WooCommerce khi CRUD API phù hợp.
- Không đổi public contract, option/meta key hoặc output format mà không có migration/versioning.
- Không biến bootstrap thành god class.
- Không coi test cấu trúc là bằng chứng đủ cho production.

## 8. Giới hạn và việc cần xác minh sau này

Tài liệu mẫu mô tả một plugin khác, vì vậy các kết luận về lỗi implementation cụ thể không được sao chép máy móc. Khi My SEO For All bắt đầu có mã nguồn, cần xác minh lại bằng:

- ma trận capability và REST permission;
- ownership output khi nhiều plugin SEO cùng tồn tại;
- HPOS và product/variation behavior;
- lifecycle của API key và dữ liệu AI;
- migration option/meta;
- build, package và release;
- hiệu năng sitemap/schema trên website lớn;
- accessibility của giao diện Admin.
