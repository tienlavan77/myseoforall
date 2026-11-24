<!-- Phản biện kiến trúc và đề xuất tiêu chí nghiệm thu để Owner chốt phạm vi Local SEO Analyzer trước khi triển khai. -->
# Góp ý và phản biện — Increment C: Local SEO Analyzer

Đối chiếu [kế hoạch Increment C](giai-doan-3-increment-c.md). **Đồng ý với hướng analyzer offline, deterministic và chỉ đọc. Đề nghị chốt lại contract đầu vào, sửa ví dụ heading và xác nhận bằng chứng đóng gate B trước khi triển khai.** Các mục dưới đây là đề xuất sửa kế hoạch; chưa phải nghiệm thu C.

## 1. Cần chốt trước khi triển khai

### C01 — Bằng chứng nghiệm thu B chưa khớp với tuyên bố mở C

§1 ghi B đã nghiệm thu tại `c7793d992da7f27b08c18efe9c76fbd3eb176b7e`. [Báo cáo B r3](giai-doan-2-increment-B-r3-report.md#4-trạng-thái-bàn-giao) ghi test pass trên commit đó và vẫn chờ review độc lập, Owner xác nhận; báo cáo cũng ghi C chưa mở. Nếu đã có quyết định sau báo cáo, dẫn nguồn, ngày và SHA được duyệt. Nếu chưa, ghi “B đã qua kiểm thử kỹ thuật; C đang ở bước phản biện kế hoạch”. Giữ tên file theo numbering bàn giao, nhưng dùng “SEO Core (Giai đoạn 2) — Increment C” nhất quán khi ghi trạng thái phụ thuộc.

### C02 — Chốt schema đầu vào riêng cho analyzer; O5–O7 chưa thể nghiệm thu như đang viết

[`SeoContext::fromArray()`](../src/SEO/Context/SeoContext.php#L20) lọc theo danh sách khóa cấp đầu và sao chép cấu trúc lồng, nhưng chưa xác thực kiểu theo field. Nó giữ `offer`, `variation`, `internal_links`; bỏ `schema`, `currency`, `availability` ở cấp đầu. `get()` dùng `??`, nên không phân biệt khóa thiếu và khóa hiện diện với `null` nếu chỉ dùng accessor này. [`ProviderPayloadSchema::forType()`](../src/SEO/Context/ProviderPayloadSchema.php#L15) có shape riêng cho offer và variation; analyzer không tự nhận các bảo đảm kiểm tra của provider schema.

Đề nghị chốt bảng `field / resource / kiểu / ví dụ / missing / null / sai kiểu` cho các giá trị rule thực sự đọc. Kiểm tra kiểu trước khi cast để tránh biến dữ liệu hỏng thành “thiếu” hoặc gây cảnh báo PHP. Ghi rõ nguồn thắng cho canonical/title/meta khi vừa có khóa cấp đầu vừa có `seo.*`; nếu C tiếp tục chỉ đọc khóa cấp đầu như hiện tại, ghi rõ adapter sau này chịu trách nhiệm chọn giá trị. Giữ fixture `post` hiện có và quyết định hợp đồng `post`/ `article` nếu hai nhãn cùng được dùng. Nếu cần mở rộng schema công khai, tách thành quyết định compatibility rõ ràng thay vì để rule tự đoán.

- **Offer/schema (O6):** Gọi phần có thể giao ngay là “kiểm tra offer local”. Kiểm tra kiểu/field trong offer và đối chiếu giá khi cả giá sản phẩm lẫn giá offer có mặt, cùng nghĩa (ví dụ cùng là giá hiện hành) và cùng biểu diễn thập phân theo policy. Chốt chuẩn giá và cách so sánh, ví dụ `0`, `0.00` là giá có mặt; không dùng số thực làm chuẩn so tiền. Currency/availability có thể được kiểm tra nội tại; chỉ báo mâu thuẫn giữa nguồn khi có currency hoặc trạng thái bán độc lập cùng nghĩa. `stock` bằng 0 tự nó không xác định availability nếu backorder chưa biết. Dời đối chiếu JSON-LD/schema sang khi có input được duyệt, hoặc khai báo rõ shape/version được bổ sung cho C.
- **Variation (O7):** Có thể lấy shape `variation.id`/`variation.parent_id` làm điểm xuất phát; chốt kiểu ID dương, parent khác chính variation, và khớp với `id` cấp đầu khi cả hai cùng có mặt. Thiếu relationship, sai shape và xung đột ID là ba tình huống phân biệt. Đây là kiểm tra cấu trúc; không xác minh parent tồn tại, không lấy giá/tồn kho/SKU từ parent. Chốt riêng khi nào `product_fields_missing` áp dụng cho product/variation và liệu mức `blocker` hiện tại có đổi theo chính sách tương thích.

### C03 — Sửa policy heading để tránh báo sai hàng loạt

O4 nêu `h4 → h2` là ví dụ sai, trong khi đây có thể là kết thúc mục con và lên mục cha. Đề nghị: báo khi đi sâu và bỏ qua cấp, ví dụ `h2 → h4`; cho phép quay lên một hoặc nhiều cấp, ví dụ `h4 → h2` trong chuỗi hợp lệ. Chốt cả heading đầu tiên và khi nào rule đúng một H1 được áp dụng: nếu chỉ nhận HTML phần body, H1 có thể do theme render ngoài dữ liệu analyzer nhìn thấy. Nêu rõ trong message/acceptance đây là kiểm tra trên markup đầu vào; ưu tiên mức khuyến nghị cho trường hợp không đủ ngữ cảnh trang.

Hiện [`appendHeadingFinding()`](../src/SEO/Analysis/LocalSeoAnalyzer.php#L101) đếm opening tag bằng regex. Test cần khóa hành vi với heading giả trong comment/script, markup thiếu thẻ đóng và nội dung trắng; chọn parser/policy hồi phục có giới hạn, không render hoặc tải tài nguyên. Nếu chọn thêm extension parser, ghi rõ hỗ trợ khi thiếu extension; không mặc định môi trường có sẵn.

## 2. Làm rõ các rule để có thể kiểm thử

### C04 — Canonical và link: chốt phân loại trước khi chốt finding code

O3 liệt kê điều kiện dễ đọc nhầm; viết tường minh “canonical hợp lệ khi là URL HTTP(S) tuyệt đối, có host hợp lệ và không có userinfo”. Tách absent/blank khỏi present-but-invalid, xác định chuẩn trim cho whitespace/control, và quy định `canonical_missing` có cần `url` hợp lệ hay chỉ cần có giá trị. Chốt fragment, port, IDN và canonical khác host ở mức policy; khác host không tự đồng nghĩa với URL sai. Đây là kiểm tra cú pháp, không xác minh URL tồn tại hoặc có thể crawl.

Với O5, nếu `internal_links` là danh sách đã lọc thì nên kiểm tra các entry có còn khớp base; nếu là danh sách ứng viên hỗn hợp, phải ghi rõ contract và khi nào “không có link nội bộ” được suy ra. Chốt “nội bộ” theo cùng host hay cùng origin; ví dụ hai URL cùng host khác scheme/port có thể cho kết quả khác theo lựa chọn. Chốt relative URL/fragment riêng cho link, không kế thừa mặc định của canonical một cách ngầm định. Phân biệt không có dữ liệu link, danh sách rỗng, chỉ có external, entry sai và thiếu base; không phát finding khẳng định “không có internal link” nếu thiếu base. Anchor trắng với link hình ảnh cũng cần mặc định rõ: C chỉ đánh giá anchor được cung cấp, hoặc bổ sung nguồn accessible label có chủ đích.

### C05 — Khóa evidence và thứ tự, không chỉ tên sáu khóa

Hiện [`finding()`](../src/SEO/Analysis/LocalSeoAnalyzer.php#L228) nhận `mixed`; [`appendCanonicalFinding()`](../src/SEO/Analysis/LocalSeoAnalyzer.php#L132) đưa URL đầu vào vào evidence. Điều này xung đột với yêu cầu không lộ dữ liệu: URL có thể mang giá trị nhạy cảm trong query/path. Đề nghị registry cố định `code → severity, field, shape evidence`; ưu tiên reason enum, count hoặc tên field cho evidence. Chốt giới hạn cho input markup, list, số finding và kích thước evidence; vượt giới hạn phải có kết quả rõ, không coi phần bị bỏ qua là pass. Nếu thay evidence đang dùng từ URL sang reason, ghi thay đổi tương thích và cập nhật test; không chép URL, anchor, markup hoặc giá trị commerce thô vào finding mới.

[`analyze()`](../src/SEO/Analysis/LocalSeoAnalyzer.php#L29) chỉ so severity. Trên PHP 8, comparator trả 0 giữ thứ tự phát hiện; vấn đề là thứ tự phát hiện chưa phải contract và có thể đổi khi đổi traversal. Đề nghị sắp theo severity → rank cố định của code → field → khóa ngữ nghĩa ổn định từ evidence đã lọc, hoặc gộp nhiều occurrence thành một finding có count. Hai finding cùng code/field nhưng evidence khác nhau vẫn cần quy tắc. Test thứ tự trên nhiều code đồng severity và trên list không có thứ tự ngữ nghĩa; không đảo heading vì thứ tự là dữ liệu của rule.

### C06 — Unicode và health: giải quyết trong core, triển khai plumbing ở E

[`textLength()`](../src/SEO/Analysis/LocalSeoAnalyzer.php#L247) dùng `mb_strlen()` theo encoding mặc định hoặc `strlen()` đếm byte. Đề nghị dùng số Unicode code point trên UTF-8 hợp lệ một cách nhất quán, chốt hành vi UTF-8 lỗi, quy tắc trim và chính sách với dạng tổ hợp (ví dụ ký tự tiếng Việt dựng sẵn so với chữ + dấu). Nếu không chuẩn hóa thì hai chuỗi đó có thể có số code point khác nhau dù nhìn giống nhau. Chọn fallback cho kết quả tương đương hoặc báo rõ rule unavailable khi thiếu hỗ trợ. C có thể cung cấp trạng thái cục bộ đã test; chỉ tích hợp vào WordPress Health Check ở E theo §7. Giữ ngưỡng title/meta hiện tại trừ khi có quyết định sản phẩm riêng.

## 3. Đề nghị bổ sung vào acceptance

Hai test analyzer đang có trong [`SeoCoreTest.php`](../tests/Unit/SeoCoreTest.php#L47) kiểm tra một số code, severity đầu tiên, presence của evidence và suggested fix. Chúng chưa khóa các kết quả dương/âm và hợp đồng sau đây:

| Nhóm | Dữ liệu tiêu biểu và kết quả cần khẳng định |
| --- | --- |
| Contract/order | Đúng sáu khóa, đúng kiểu/evidence theo code; thứ tự chính xác khi cùng severity, cùng code/field; không đổi dữ liệu context; hai lần chạy cho cùng kết quả. |
| URL & evidence | URL hợp lệ nhưng canonical thiếu; canonical sai nhưng hiện diện; URL có userinfo, control hoặc query có dữ liệu thử nhạy cảm; finding chỉ trả reason/count được phép. |
| Heading | Chuỗi hợp lệ `h1 → h2 → h3 → h4 → h2`; `h2 → h4` bị báo theo policy; 0/2 H1, markup hỏng và heading giả được xử lý riêng. |
| Link | Thiếu/rỗng/sai kiểu; internal, external, thiếu base; anchor trắng; relative URL và khác port theo policy đã chọn. |
| Commerce | Product và variation hợp lệ; offer vắng mặt không tự tạo mismatch; offer có field sai kiểu, có mâu thuẫn trên dữ liệu so sánh được, hoặc không đủ dữ liệu; giá 0; parent thiếu, sai kiểu hoặc trỏ chính mình. |
| Unicode & giới hạn | Title và meta ở min−1/min/max/max+1; tiếng Việt dựng sẵn và tổ hợp, emoji, UTF-8 lỗi; cùng behavior trên hai đường đo hoặc trạng thái unavailable; list/markup vượt giới hạn. |

Nên đóng contract đầu vào, registry/evidence và Unicode trước; sau đó chốt các rule HTML/URL/link, rồi commerce trên những field thực sự có trong schema đã chọn. Khi triển khai, chạy focused analyzer suite và `tests/Unit` như §5–6, ghi revision/checksum và run ID thực tế. Review diff về network, platform API, ghi dữ liệu và giới hạn tài nguyên; unit test offline chỉ chứng minh hành vi trên fixtures đã chạy. Giữ Rank Math, runtime hooks, gateway, Preview/Apply và ghi dữ liệu ngoài C.

## 4. Phạm vi kiểm chứng của phản biện

Đã đối chiếu kế hoạch C (SHA-256 `8538824e79a05280a0a833eacf58744e091449c880db026134ca797be11eae41`), mã analyzer và test hiện có với checksum khớp §8, và báo cáo B r3. Metadata tươi của `SeoContext` được tái sử dụng từ worker `payload_review`; metadata `ProviderPayloadSchema` từ bằng chứng phiên SE trước. Các nhận xét về hai contract dựa trên source symbol đã đọc trực tiếp. Đây là thay đổi tài liệu; không chạy PHPUnit và không xác nhận hành vi runtime WordPress/WooCommerce.
