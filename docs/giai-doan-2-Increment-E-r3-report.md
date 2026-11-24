# Increment E r3 — Báo cáo triển khai

## Snapshot và phạm vi

- Tài liệu yêu cầu: `docs/giai-doan-2-Increment-E-r3.md`
- SHA-256 tài liệu yêu cầu: `sha256:d6cc963935b1f66261dd49ad939fb473fe49bb3b939323d985a93a5c64703606`
- Base kiến trúc D: `5aa3332133f203f41a8701e83b213c246cf491da`
- HEAD khi bắt đầu: `2f5e32765b7d26ad357db0741fa4561f862524a7`
- Implementation snapshot r3: chưa khóa; working tree còn thay đổi source/test và tài liệu chưa commit.

## Findings đã xử lý trong lượt này

### E3-4 — Reader identity, parent seam và nested variation

- `WordPressContentReader::readPost()` yêu cầu source có ID nguyên dương khớp chính xác ID yêu cầu; source thiếu ID hoặc chuỗi bẩn bị trả `invalid_source`.
- `WooCommerceProductReader` bổ sung cổng currency và parent loader tùy chọn. Variation parent được đọc qua cùng loader một lần, kiểm tra ID khớp và loại parent `product|variable`, loại variation parent.
- Object product ánh xạ `simple` thành `product`; nested variation bỏ SKU rỗng/null và chuẩn hóa attribute object có `get_name()/get_options()` thành danh sách đóng.
- Bổ sung kiểm thử reader cho parent lookup một lần và malformed product identifier.

## Verification

| Run ID | Command | Status | Exit | Revision |
|---|---|---|---:|---|
| VERIFY-RUN-6ebb2ae5-aafc-40cf-aec2-b575aac26f7b | `php -l src/SEO/Runtime/WooCommerceProductReader.php` | passed | 0 | Forge reported commit `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-e0bba349-aa32-4bd7-a7d9-38c5c3e9f27d | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeReaderSmokeTest.php` | failed / unusable | 1 | Forge evaluated stale commit `2f5e32765b7d26ad357db0741fa4561f862524a7`; no assertion details returned |

## SHA-256 hiện tại

- `src/SEO/Runtime/WooCommerceProductReader.php`: `sha256:9c06157324df02043f045453e35e0e0912748b4c6c0091be0fca026153e474f1`
- `src/SEO/Runtime/WordPressContentReader.php`: `sha256:59e33249cdb67d4245fc1ba38da435e6c98b005277d2586f4fe7f50f920c5d8a`
- `tests/Unit/RuntimeReaderSmokeTest.php`: `sha256:b6f26c94ff238c901cf87e76b43f81728ad63b5b01bc04b19db35a8c0cfd158f`

## Chưa hoàn tất / giới hạn

E3-1 chưa đạt: chưa có commit snapshot source/test sạch và verification trên đúng revision. E3-2, E3-3 và E3-5 chưa triển khai đầy đủ: health enum/capability gate, WordPress hook callback seam, Rank Math platform bridge và observer permission gate vẫn cần xử lý. E3-6 chưa đạt vì focused PHPUnit chưa chạy được trên snapshot hiện tại và chưa có ma trận O1–O4 đầy đủ.

Lint pass không chứng minh tương thích production WordPress/WooCommerce/Rank Math. Báo cáo này không đóng gate C/D/E và không mở F–H; chuyển Reviewer/Owner đánh giá tiếp.
