# Giai Đoạn 3 — Increment 3A (Vòng r5): Sửa Provenance Báo Cáo & Chốt Nghiệm Thu

> Tài liệu phản hồi của Architecture Manager sau khi đọc `docs/giai-doan-3/increment-3a-r4-rp.md` (`sha256:01f9e1e4f120faca431c9789fc979e9bcb1244ebba55ac3c96a7577690bd0803`) và đối chiếu evidence hiện hành của `tests/Unit/Increment3ATest.php`, runtime 3A và bootstrap.

## 1. Kết luận vòng r4: **ĐẠT VỀ KỸ THUẬT; CHƯA CHỐT ARTIFACT DO SAI CHECKSUM TRONG BÁO CÁO**

Bản r4 đã hoàn thành đúng ma trận `AC1–AC5` theo báo cáo triển khai:

- 7 test, 330 assertions cho `Increment3ATest.php`.
- Toàn bộ suite `tests/Unit`: 103 test, 1.572 assertions, 1 skipped.
- AC1: Cổng đăng ký/render, WooCommerce và fallback `product_variation`.
- AC2: Gate 1–7, HTTP status, schema lỗi 3 khóa và zero downstream calls.
- AC3: Các task/resource, response 7 khóa và chuẩn hóa `current`.
- AC4: Đủ 8 category `AiProviderException`, redaction và message tĩnh.
- AC5: Spy `OptionStore` xác nhận zero write/delete/CAS.

Đối chiếu runtime cũng không phát hiện blocker mới trong `EditorPreviewController`, `SeoModule`, `AiProviderException` hoặc `AiOutputValidator`. Không yêu cầu sửa thêm `src/` và không yêu cầu sửa `myseoforall.php`.

## 2. Lỗi cần sửa bắt buộc trong báo cáo r4

Dòng SHA-256 của `myseoforall.php` trong `increment-3a-r4-rp.md` đang ghi sai:

```text
06900bbed1e16f49258a9287bab512b6d75c21aaf2bb48635c0783df1b6de22aef
```

Checksum đúng theo receipt nguồn hiện hành là:

```text
sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef
```

Đây là lỗi **provenance/documentation**, không phải lỗi implementation. Tuy nhiên không được để báo cáo nghiệm thu chứa hash sai vì sẽ làm mất khả năng đối chiếu revision và chứng minh bootstrap không bị sửa.

## 3. Yêu cầu r5 tối thiểu

1. Cập nhật lại báo cáo nghiệm thu với đúng checksum của `myseoforall.php` nêu trên.
2. Giữ nguyên các kết quả test r4 nếu không có thay đổi source; không tự tuyên bố kết quả chạy mới nếu chưa thực sự chạy.
3. Ghi lại chính xác các checksum đã dùng trong báo cáo:
   - `tests/Unit/Increment3ATest.php`: `sha256:491986e0b24da0c0d181c148d91ce1ea0d36567b52309734c94c6cb6b522b7db`
   - `src/SEO/Runtime/EditorPreviewController.php`: `sha256:0cc0e5304b57a27d0f4f8fbc87ec7c34a3672ccc43df628b06d17574ef6abe62`
   - `src/SEO/Runtime/SeoModule.php`: `sha256:cb1388ae5b58689c2ab2da52133dd21f19b3d555cd9d27c5d8f553d0842af29e`
   - `myseoforall.php`: `sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`
4. Không đổi public contract, không bổ sung phạm vi Increment 3B, không sửa `myseoforall.php`, không bỏ ghi chú rằng test hiện tại là offline fixture/seam và không thay thế nghiệm thu WordPress/WooCommerce/gateway production.
5. Xuất báo cáo `docs/giai-doan-3/increment-3a-r5-rp.md` với kết luận `ACCEPTED` sau khi checksum đã được sửa và các kết quả r4 vẫn còn đúng revision.

## 4. Tiêu chí chốt sau r5

Chỉ chốt Increment 3A khi báo cáo r5 đồng thời có:

- checksum bootstrap đúng như trên;
- kết quả test r4 được gắn với đúng file test và revision đã kiểm tra;
- xác nhận `myseoforall.php` không bị sửa;
- xác nhận không có database write/delete/CAS trong render/preview;
- ghi rõ giới hạn kiểm thử offline;
- không còn discrepancy giữa report và source receipt.

Phân loại: **Minor — documentation/provenance only**, không phải blocker runtime. Sau khi r5 sửa đúng checksum và không phát sinh thay đổi source, Increment 3A đủ điều kiện nghiệm thu.
