# Increment G — Gateway AI có kiểm soát, increment cuối của Giai đoạn 2

G nối payload đã lọc ở A, provider/schema ở B và AI Settings ở F để trả về đề xuất đã kiểm định cho người gọi. Đây là đặc tả kỹ thuật, không phải quyết định bật gateway hay xác nhận đã hoàn tất Giai đoạn 2.

**Phạm vi:** một provider `gateway` thực thi `AiProvider`, một contract transport nhỏ, một adapter production có khả năng ghim IP, resolver/transport giả cho test. Dùng custom base URL và option `myseo_ai_settings` hiện hữu. Không thêm option, migration, lịch sử, Preview/Apply UI, ghi Post/Product/Rank Math, auto-publish, streaming, tool-use, queue hay router. SEO local tiếp tục hoạt động khi AI tắt.

## O1 — Ai được gửi, gửi tới đâu và dùng credential nào (FB-1, FB-2)

- Chỉ gửi theo tác vụ người dùng chủ động yêu cầu cho đối tượng được phép đọc, sau khi thông báo dữ liệu sẽ gửi. Điểm vào WordPress phải kiểm quyền theo đối tượng và nonce. Khởi tạo, boot, health và lưu Settings không phải yêu cầu gửi.
- Bật AI không thay thế điều kiện vận hành: cần hồ sơ gateway đã xác minh cho môi trường đích (origin/base path, Messages API/version, model, auth, rotation, TLS/pinning, timeout và retention). Đổi đích không tự chuyển quyền dùng credential cũ sang đích mới. Khi thiếu điều kiện: không DNS/HTTP.
- Thứ tự quyết định: (1) kiểm quyền/gate và request O3; (2) lấy một snapshot settings nhất quán: trạng thái `ok`, `ai_enabled=true`, URL canonical, model mặc định hợp lệ và thuộc danh sách nếu danh sách không rỗng; (3) mới resolve nguồn credential và xác minh. Không đòi `credentialStatus()` giải mã trước rồi lại giải mã để ký. Settings không đạt dừng trước credential; credential không đạt dừng trước DNS/HTTP.
- Nguồn: constant `MYSEO_AI_GATEWAY_API_KEY` → biến môi trường cùng tên → envelope DB. **Chỉ chuyển nguồn khi vắng mặt**. Constant/env có mặt nhưng rỗng hoặc sai định dạng: dừng `authentication_failed`, không dùng DB. Không có nguồn nào: `provider_disabled`. Envelope sai cấu trúc làm settings `corrupted`: `provider_disabled`; envelope có cấu trúc hợp lệ nhưng không xác thực/giải mã được: `authentication_failed`. Giữ tương thích dữ liệu Sodium và AES-256-GCM của F.
- **Không cung cấp** `readPlaintext()` hoặc `withAuthorizedHeaders(callable): mixed`. Provider yêu cầu thực hiện một POST cho đích đã kiểm định; chỉ adapter gửi tin cậy được cấp header, không cho caller chọn callback, đích khác hoặc header thay thế. Dùng đúng một trong `x-api-key` hoặc `Authorization: Bearer` theo `auth_scheme`, cùng `anthropic-version: 2023-06-01` theo profile Messages. Header chỉ tồn tại cho attempt; chỉ output qua O3 hoặc lỗi đóng được rời provider. Không inject quyền ký vào UI, hook công khai hoặc health.
- Dọn tham chiếu tạm trong `finally`; không hứa xóa mọi bản sao chuỗi trong PHP. Trên runtime được hỗ trợ, phải ngăn trace thu arguments nhạy cảm xuyên suốt đường gọi (ví dụ yêu cầu `zend.exception_ignore_args=1` trước khi nhận payload/credential; annotation bổ trợ nếu phiên bản hỗ trợ). Không đáp ứng thì không bật đường mạng. Bắt lỗi ở biên tin cậy, không chuyển raw exception, `previous`, debug dump hay thông báo upstream ra ngoài.

## O2 — Một đường kết nối trực tiếp cho từng attempt (FB-3, FB-4, FB-6)

- Chỉ HTTPS:443, POST JSON tới endpoint canonical của snapshot O1. Trước **mỗi** attempt, kể cả retry, kiểm tra URL và toàn bộ IP bằng policy `GatewayEndpointValidator::validateResolvedIps()`. Nếu có bất kỳ IP không public, kết quả rỗng/sai hoặc lỗi phân giải, không mở kết nối HTTP; allowlist tên nội bộ không miễn kiểm tra IP. Chặn `anthropic.com` và mọi subdomain. Literal IP public không cần DNS.
- Chọn một IP vừa duyệt và dùng nó cho chính kết nối gửi dữ liệu. Giữ hostname gốc trong URL để TLS xác minh đúng danh tính server. Với cURL, bản ghi resolve cho hostname có dạng `host:443:IPv4` hoặc `host:443:[IPv6]` theo phiên bản hỗ trợ. Với literal đã duyệt, dùng trực tiếp URL canonical (IPv6 trong ngoặc vuông), xác minh chứng chỉ cho literal; không dựng bản ghi resolve giả.
- Tắt proxy kể cả proxy môi trường (ví dụ `CURLOPT_PROXY=''`), tắt redirect, chỉ cho HTTPS, verify peer và hostname. Dùng handle/kết nối mới cho attempt, không chia sẻ cache DNS/kết nối cũ. Không chứng minh được đường trực tiếp, ghim IP hoặc TLS thì dừng; không fallback sang client khác không có bảo đảm tương đương.
- `timeout_seconds` (5–60 giây) là **ngân sách tổng của generate**, tính theo đồng hồ monotonic; DNS, kết nối, đọc và chờ retry cùng tiêu ngân sách. Resolver phải có khả năng giới hạn thời gian thực sự; kiểm tra đồng hồ sau một lời gọi DNS blocking không đủ. Connect timeout tối đa `min(10s, thời gian còn lại)`. Hết ngân sách: không khởi tạo attempt tiếp.
- Giới hạn body upstream **65.536 byte** trên đường nhận, trước khi parse. Write callback cộng kích thước từng chunk, hủy ngay trước khi thêm phần vượt trần, không dùng phần body dở dang. Không bật verbose/debug capture.

## O3 — Messages API và kết quả trả cho caller (FB-5)

- Giữ `AiProvider::generate(array): array`. Kiểm đúng envelope `myseo.ai.request.v2` gồm `schema_version`, `task`, `context`; dùng `ProviderPayloadBuilder::validatePayload()` với `provider-payload-2`. Chỉ ba task: `seo_analysis`, `title_meta_suggestion`, `product_description_draft`. Không gửi object WP/WC, khách hàng, đơn hàng, PII hoặc secret trong nội dung. Dữ liệu không xác minh được quyền gửi phải bị từ chối.
- Từ base URL đã chuẩn hóa: endpoint kết thúc bằng segment `messages` thì giữ nguyên; kết thúc bằng `v1` thì thêm `/messages`; còn lại thêm `/v1/messages`. Kiểm lại endpoint hoàn chỉnh theo O2; không nhận path, URL hoặc model override từ prompt/request. Body dùng model của snapshot, `max_tokens` hữu hạn ≤ 2048, temperature cố định, system prompt theo task và một user message gồm task/context đã kiểm định. Kết quả chỉ là đề xuất, không phải nguồn sự thật về giá/tồn kho/review.
- Chỉ nhận HTTP 200, JSON Messages hoàn chỉnh: message của assistant, `stop_reason=end_turn`, `content` là danh sách đúng **một** block `type=text`. Text phải parse toàn bộ thành đúng một JSON object output của task. Không lấy riêng block đầu, không nhặt JSON từ Markdown, không nhận tool block, streaming, dữ liệu bị cắt hoặc thiếu stop reason. Không hỗ trợ fallback envelope nội bộ trực tiếp: G chỉ có một giao thức upstream.
- Adapter tự dựng `myseo.ai.response.v1` với `provider=gateway`, task ban đầu, `status=ok`, output đã parse rồi gọi `AiOutputValidator::validate($envelope, $task, 'gateway')`. Không trả text thô nếu validator từ chối. Trần body transport không thay thế giới hạn schema, field, depth, kích thước và privacy của validator.

## O4 — Lỗi, retry, retention và composition (FB-1, FB-4, FB-6, FB-7)

Giữ ba category cũ của `AiProviderException`, thêm đúng năm category ở bảng dưới. Phân loại nguyên nhân nội bộ trước khi quyết định retry; **không retry chỉ vì category là `transport_failed`**.

| Nguyên nhân | Category cho caller | Retry |
|---|---|---|
| Gate chưa mở, settings chưa sẵn sàng hoặc key vắng | `provider_disabled` | Không |
| Envelope/context sai; task không hỗ trợ | `malformed_request`; `unsupported_task` tương ứng | Không |
| Nguồn key có mặt nhưng sai, giải mã lỗi; HTTP 401/403 | `authentication_failed` | Không |
| URL/IP bị chặn, phân giải lỗi/không xác minh được | `gateway_policy_rejected` | Không |
| HTTP 429 | `rate_limited` | Có điều kiện |
| HTTP 502/503/504; lỗi kết nối tạm thời trước khi gửi | `transport_failed` | Có điều kiện |
| TLS lỗi, không bảo đảm transport, hết deadline; lỗi mạng không xác định đã gửi hay chưa; HTTP 4xx/5xx còn lại | `transport_failed` | Không |
| 1xx, 2xx khác 200, 3xx, status không hợp lệ; body quá lớn, Messages hoặc schema sai | `invalid_response` | Không |

`max_retries=0..2` là trần, không phải số lần buộc phải gửi. Chờ 100 ms rồi 200 ms trước hai retry; nếu không còn đủ ngân sách thì dừng. POST có thể phát sinh chi phí: khi request đã ra khỏi client, chỉ thử lại các status được phép nếu profile gateway bảo đảm replay an toàn (ví dụ idempotency được gateway xác nhận, cùng khóa trong một generate); không suy từ tên status rằng gateway chưa xử lý. Timeout sau khi gửi không tự retry. Mỗi retry lặp O2; không tự chuyển nhà cung cấp hay model.

Zero-retention áp dụng cho **plugin** trên cả đường thành công và lỗi: không lưu body, auth headers, prompt hay output vào option/DB, transient/cache, file/log, queue, telemetry/APM, exception hoặc thuộc tính service sau request. Chỉ dùng category và thông báo tĩnh để chẩn đoán. Chính sách lưu trữ của gateway là điều kiện vận hành riêng, không suy ra từ unit test.

Đăng ký provider/transport trong composition root; giữ nguyên bốn module `admin`, `woocommerce`, `seo`, `rank_math` và bốn trường health `ai_enabled`, `ai_configured`, `ai_settings_state`, `ai_credential_state`. `ai_configured` mô tả credential configured/environment, không xác nhận URL/model, TLS hoặc kết nối. Readiness thật nằm ở `generate`; không probe trong health.

## Điều kiện nghiệm thu offline

| ID | Bằng chứng cần có trên cùng snapshot |
|---|---|
| AC1 | Spy resolver/sender: constructor, boot, health, AI tắt, settings hỏng, gate chưa mở, credential thiếu/sai và input không hợp lệ đều có 0 DNS/HTTP. Kiểm thứ tự khi settings và key cùng lỗi; invalid override không fallback xuống nguồn thấp hơn. Local analyzer vẫn hoạt động. |
| AC2 | Fixture không dùng secret thật: kiểm cả hai auth scheme, precedence và hai cipher của F. Lỗi phát sinh khi ký, gửi hoặc validate không làm lộ giá trị đánh dấu qua kết quả, message, trace/args, previous hay trạng thái service. Không có callback do caller cung cấp nhận header. |
| AC3 | Ngoài fake provider, chạy logic adapter production với executor/resolver giả: quan sát IP được chuyển tới thao tác kết nối, hostname/TLS, proxy thực sự bị tắt kể cả khi môi trường có proxy, IPv4/IPv6 và literal. Mỗi retry dùng IP mới được duyệt; tập IP hỗn hợp bị từ chối trước connect. Redirect không tạo kết nối thứ hai. Test offline này không chứng minh peer/TLS của production. |
| AC4 | Fixture thành công cho ba task và các dạng base path; sai schema/field/provider/task ở biên nội bộ bị từ chối. Với upstream: nhiều block, tool, thiếu text, truncated, JSON thừa/sai, direct envelope và output sai contract đều không trả output/không retry. Gọi chính write callback với nhiều chunk: đúng 65.536 byte không bị cắt; thêm một byte thì hủy và không parse. |
| AC5 | Clock/sleeper giả: kiểm từng hàng bảng lỗi, `max_retries=0/1/2`, thành công sau lỗi tạm, timeout trước/sau gửi, budget cạn khi resolve/chờ, TLS/capability không retry. Khi đủ điều kiện replay và ngân sách, tối đa 1/2/3 attempt tương ứng; nếu không đủ điều kiện thì không replay POST. |
| AC6 | Trên thành công, lỗi transport và lỗi validator: đối chiếu code path với các cổng side effect thực tế, dùng spy ở các cổng đó, kiểm trạng thái service và dữ liệu exception. `OptionStore writes=0` chỉ là một bằng chứng. Không thêm hệ thống audit mới. Chạy focused G và full `tests/Unit` offline trên cùng commit sạch `src/` + `tests/`; báo cáo commit SHA, checksums, run IDs, exit codes, kết quả và giới hạn. Reviewer độc lập kiểm đúng snapshot trước khi trình Owner. |

## Baseline và quyết định phản biện

- Mốc lịch sử: source/test F-r5 `e8aed50edf60bb67ed75c8d4c8e673347b7d73c1`; `578b559d0226c38cde60f659a56864df38c9d303` là mốc báo cáo F, không phải chứng cứ cho working tree G.
- G kế thừa sửa bootstrap trong [addendum F-r5](giai-doan-2-f-r5-rp.md): `src/Core/Plugin.php` có SHA-256 `12cdaddbeaf3a635d2926a9c06e2622af2b7aadc100ed078f7a11c927f84688c`, thay cho checksum lịch sử `3af2…94df`. SE ghi commit sạch chứa thay đổi này trước khi dùng làm baseline thực thi; không gán kết quả chạy trên working tree cho commit chưa chứa sửa đổi.
- Điểm nối tham chiếu: `src/AI/AiSettingsRepository.php`, `src/AI/GatewayEndpointValidator.php`, `src/AI/Contracts/{AiProvider,AiProviderException,AiOutputValidator}.php`, `src/AI/Fake/FakeAiProvider.php`, `src/SEO/Context/ProviderPayloadBuilder.php` và `src/Core/Plugin.php`.
- Đã xét [G-FB-1…7](giai-doan-2-g-fb.md), SHA-256 `b45d273b61eb128e25e8f126bd251ebbb7ea2e5d06dd5578b77f7f99829253a5`. Nhận các vấn đề có ảnh hưởng đến bí mật, đích kết nối, kết quả caller và khả năng kiểm chứng. Bác bỏ callback tùy ý, fallback giao thức, thêm category chỉ để retry và audit toàn WordPress: chúng tăng bề mặt xử lý mà không cần cho contract này. Không mở lại persistence của F.
- Đạt AC1–AC6 là bằng chứng kỹ thuật offline. Kết nối gateway thật cần môi trường thử được phép, phiên bản/compatibility cụ thể và bằng chứng chính sách upstream. Việc đóng Giai đoạn 2 do Owner quyết định sau review; tài liệu này không cấp quyền gửi dữ liệu production.
