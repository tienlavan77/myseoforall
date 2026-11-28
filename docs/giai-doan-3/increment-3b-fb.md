# Phản biện kiến trúc — Increment 3B

Ngày: 26/11/2026  
Người nhận: Architecture Manager (AM)  
Phạm vi: chỉ rà soát các vấn đề đang đặt ra trong `docs/giai-doan-3/increment-3b.md`; không mở rộng sang 3C, bootstrap, lịch sử, cache hoặc transport AI.

Tài liệu được rà soát: `docs/giai-doan-3/increment-3b.md`, SHA-256 `sha256:1401a49c020a7a280a0227cd46dc4b59bfa145d2a1807d8a02621f60dbef0cf1`.

## 1. O1: thứ tự canonical source chưa được khóa

O1 yêu cầu Preview và Apply dùng cùng thứ tự đọc canonical, nhưng tài liệu mới nói “thứ tự đã định nghĩa rõ ràng” mà chưa nêu thứ tự cụ thể cho từng resource và field. Riêng product description còn để implementation “chọn đúng một nguồn canonical theo evidence reader hiện tại”. Cách diễn đạt này cho phép Preview và Apply chọn khác nguồn, hoặc cho phép adapter tự suy diễn thêm nguồn.

AM nên khóa một bảng precedence cho từng resource/field trước implementation. Bảng phải nêu nguồn đọc, key/field được phép, giá trị `null`/rỗng, và adapter ghi tương ứng. Product description cần một nguồn duy nhất; nếu chưa đủ evidence để chọn thì phải coi là chưa mở field đó, thay vì cho phép implementation tự chọn. Reader và writer phải cùng tham chiếu bảng này.

## 2. O2: fingerprint chưa ràng buộc proposal và field

Fingerprint hiện được mô tả là hash của snapshot hiện tại. Hash này bảo vệ tính mới của dữ liệu nguồn, nhưng tự nó không chứng minh proposal, task, field hoặc resource type mà người dùng gửi lúc Apply chính là proposal đã Preview. Một client có thể giữ nguyên `source_fingerprint` rồi thay `proposal.value` hoặc `field`.

AM nên chốt rằng Apply chỉ nhận một envelope do server phát hành, trong đó fingerprint được bind tối thiểu với object ID, resource type, task, target field và giá trị proposal đã normalize; hoặc dùng token/chữ ký server cho cùng dữ liệu. Nếu vẫn cho browser gửi proposal lại, server phải so khớp exact với proposal đã bind, không chỉ so khớp snapshot fingerprint. Acceptance O2/O3 và test matrix cần có ca sửa proposal nhưng giữ nguyên fingerprint, với zero write.

## 3. O2: canonical serialization và revision marker còn mơ hồ

Tài liệu yêu cầu serialization ổn định và `post_modified_gmt` hoặc revision marker, nhưng chưa khóa cách normalize chuỗi, `null`/rỗng, URL canonical, timezone và trường nào được đưa vào hash. Nếu reader và Apply normalize khác nhau sẽ tạo stale giả hoặc bỏ sót thay đổi. Nếu mọi thay đổi không liên quan đều làm đổi `post_modified_gmt`, người dùng có thể bị từ chối dù field mục tiêu không đổi.

AM nên khóa một hàm canonical serialization dùng chung, danh sách field bắt buộc theo task, quy tắc normalize và ý nghĩa revision marker. Acceptance cần chứng minh cùng snapshot cho ra cùng hash, thay đổi field liên quan làm stale, còn thay đổi ngoài snapshot contract không làm stale ngoài chủ ý.

## 4. O3: policy Rank Math đang có hai cách hiểu

Mục 3 nói mặc định không ghi Rank Math nhưng có thể ghi namespace My SEO khi UI cảnh báo và người dùng xác nhận. O1/O3 lại yêu cầu ownership policy cấm ghi hoặc đòi hỏi xác nhận. Chưa rõ khi Rank Math sở hữu `title_meta` thì Apply hợp lệ sẽ được ghi My SEO, bị chặn hoàn toàn, hay chỉ được phép cho từng field.

AM nên khóa ma trận `resource × field × ownership state × user confirmation` với kết quả duy nhất: `saved` hoặc `ownership_conflict`. Confirmation phải là một giá trị server-validated gắn với đúng field và snapshot, không phải boolean tùy ý từ browser. Test cần bao phủ Rank Math active/inactive và confirmation thiếu/sai.

## 5. Gate ordering và response status cần đóng thành bảng

Thứ tự 1–11 đã nêu đúng hướng, nhưng chưa khóa HTTP status cho mọi mã lỗi, đặc biệt `malformed_request`, `invalid_proposal`, `ownership_conflict`, `protected_field` và `storage_failed`. Body lỗi có ba khóa cố định, nhưng status là một phần observable contract và cần nhất quán với Preview.

AM nên bổ sung bảng `code → HTTP status → message tĩnh → downstream calls`. Bảng phải xác nhận các gate request/ID/capability/nonce/task/field/proposal không đọc snapshot và không ghi persistence; `stale_preview` và ownership không ghi; lỗi storage không trả thành công giả. Không cần thêm loại lỗi ngoài danh sách hiện có.

## 6. O4: failure safety/read-back mismatch chưa chỉ rõ trạng thái sau write

Tài liệu yêu cầu một field mỗi thao tác và không giả vờ thành công nếu read-back thất bại, nhưng chưa nói hệ thống phải làm gì khi write đã thành công rồi read-back thất bại. “Rollback/compensation nếu cần” là hướng dẫn mở, dễ dẫn tới rollback không khả thi với WordPress/WooCommerce hoặc chạm thêm field ngoài boundary.

AM nên khóa kết quả observable cho trường hợp này: nếu có thể compensation an toàn thì nêu adapter và điều kiện; nếu không, trả `storage_failed`, ghi rõ trạng thái không chắc chắn và không thực hiện write thứ hai ngoài field mục tiêu. Test phải phân biệt write failure, read-back mismatch và compensation failure, đồng thời xác nhận không auto-publish và không chạm field thương mại.

## 7. Bằng chứng nghiệm thu và dependency 3A

Kế hoạch 3B phụ thuộc nghiệm thu 3A trên WordPress thật, trong khi ma trận 3B chủ yếu là PHPUnit offline. Test offline có thể chứng minh gate, zero write ở nhánh lỗi, fingerprint và adapter seam; không chứng minh hook AJAX WordPress, WooCommerce CRUD thật, revision behavior hoặc ownership runtime.

AM nên giữ điều kiện mở 3B chỉ sau khi 3A được Owner nghiệm thu theo kế hoạch, và yêu cầu báo cáo 3B tách rõ: kết quả offline, kiểm thử WordPress/WooCommerce thật, checksum/revision, và giới hạn còn lại. Không dùng pass của PHPUnit để tuyên bố đã chứng minh production persistence hoặc an toàn dữ liệu thương mại.

## Kết luận

Các điểm cần AM chốt trước implementation là: precedence canonical cụ thể; binding proposal vào fingerprint/envelope; serialization và revision marker; ma trận Rank Math ownership; bảng status/gate đóng; và hành vi sau write/read-back failure. Đây đều nằm trong O1–O4 và AC1–AC4 hiện có, không yêu cầu mở thêm phạm vi.

## Cập nhật chốt sau khi AM cập nhật đặc tả

Đối chiếu bản hiện hành của `docs/giai-doan-3/increment-3b.md` (SHA-256 `sha256:1c506342e02185b7e8ec034e1dbaf5c150ebac7da22cd9d4226be4ccef153dc3`) cho thấy AM đã bổ sung đầy đủ:

- bảng canonical source/precedence và quy tắc `null`/rỗng;
- `apply_token` bind proposal, field, resource, ownership và fingerprint;
- canonical serialization field-scoped, deterministic;
- ma trận Rank Math ownership và confirmation server-validated;
- bảng HTTP status/downstream calls;
- trạng thái không chắc chắn sau write/read-back failure, không compensation write;
- điều kiện mở 3B và phân loại evidence offline/runtime/provenance.

Không còn bất đồng kiến trúc trong phạm vi các vấn đề đã nêu. Có thể bắt đầu code 3B **sau khi Owner xác nhận nghiệm thu 3A đúng revision/checksum**, theo điều kiện tại mục 10.1 của đặc tả. PHPUnit/offline acceptance của 3A một mình không thay thế xác nhận đó.

## 8. Ghi nhận lỗi runtime CanonicalSeoMetaRepository

Owner báo lỗi:
`Fatal error: Uncaught Error: Class "MySeoForAll\\SEO\\Runtime\\CanonicalSeoMetaRepository" not found` tại `src/SEO/Runtime/SeoModule.php:116`.

Đối chiếu source hiện hành cho thấy class đã tồn tại tại `src/SEO/Runtime/CanonicalSeoMetaRepository.php`, khai báo đúng namespace `MySeoForAll\\SEO\\Runtime`, và `SeoModule::register()` khởi tạo class ở dòng 116. Bootstrap `myseoforall.php` có PSR-4 fallback: lấy phần namespace sau `MySeoForAll\\`, đổi dấu phân cách thành `/`, rồi require file tương ứng; `composer.json` cũng khai báo `MySeoForAll\\` → `src/`. Vì vậy lỗi biểu hiện khi runtime không nạp được autoloader/fallback hiện hành hoặc file class không có trong artifact triển khai, thay vì do thiếu khai báo class trong source snapshot.

Cần kiểm tra trên máy lỗi: (1) file `src/SEO/Runtime/CanonicalSeoMetaRepository.php` có tồn tại đúng chữ hoa/thường; (2) plugin chạy đúng bản checkout có file này; (3) `vendor/autoload.php`/fallback được thực thi trước `new Plugin()`; (4) OPcache/deployment không giữ artifact cũ. Không nên thêm `require_once` rải trong `SeoModule`; hãy sửa nguyên nhân bootstrap hoặc artifact và xác nhận bằng một smoke test khởi tạo plugin trên bản triển khai thực tế.

[CRITIQUE: ALIGNED]
