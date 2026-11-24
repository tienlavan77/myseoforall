# Increment E r4 — Báo cáo triển khai

## Snapshot

- Tài liệu yêu cầu: `docs/giai-doan-2-Increment-E-r4.md`
- SHA-256 yêu cầu: `sha256:4c3b30f17faa2ddf60ee524de972296438c0780a66ce88f4a6a906818433bb31`
- Base HEAD khi bắt đầu: `2f5e32765b7d26ad357db0741fa4561f862524a7`
- Mốc kiến trúc D: `5aa3332133f203f41a8701e83b213c246cf491da`
- Implementation snapshot r4: chưa khóa; working tree còn thay đổi source/test/tài liệu. Không tạo commit vì snapshot vẫn chưa sạch và chưa đạt toàn bộ E4.

## Thay đổi đã thực hiện

### E4-1 — Reader runtime

- WooCommerce reader dùng kiểm tra ID nguyên dương chính xác cho cả array/object, chặn chuỗi bẩn như `9abc`.
- Parent variation ưu tiên `parentLoader`, rồi loader chính, rồi fallback guarded `wc_get_product`; kiểm tra parent ID khớp và loại `product|variable`, từ chối variation parent.
- Nhánh array chuẩn hóa nested variation, loại SKU rỗng/null và chuyển attributes về schema đóng.
- WordPress reader tiếp tục yêu cầu ID nguyên dương khớp trước khi phân tích.

### E4-2 — Health và readiness

- `EnvironmentCheck::isReady()` yêu cầu installation/activation của PHP và WordPress đều xác nhận cùng `compatible === true`.
- Ngoài WordPress runtime, trạng thái WordPress giữ tri-state `unknown`.
- Health projection khóa `write_allowed=false`, `frontend_owned=false`, giữ `dependency_ready` cho Rank Math.
- Coexistence dùng đúng enum `state: owned|unknown|conflict`, `duplicate_risk: none|possible|blocked`, allowlist owner/reason; version chỉ nhận pattern an toàn.
- Admin risk projection giữ trạng thái `blocked`.

### E4-3 — Hook seam

- `HookDispatcher` nhận forwarder tùy chọn, có `canDispatch()`, không ghi registration khi runtime không có bridge, và từ chối Closure vô danh.
- Admin/SEO/WooCommerce modules dùng dispatcher capability thay vì kiểm tra global hook trực tiếp.
- SEO đăng ký `add_meta_boxes` với `accepted_args=2`.

## Verification

| Run ID | Command | Status | Exit | Revision reported |
|---|---|---|---:|---|
| VERIFY-RUN-ec9c9941-0656-48cb-a278-27cedd47b03f | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeReaderSmokeTest.php` | passed | 0 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-fa1da5c6-d693-4ae1-bfe6-759d702d0076 | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementER2SafetyTest.php` | passed | 0 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-77f91b81-a6b5-448a-ab0f-3e3a11198a43 | `php -l src/Core/Plugin.php` | passed | 0 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-8542c5d6-81e4-4b8f-9bb6-637392dbb4b9 | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeReaderSmokeTest.php` | passed | 0 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-d5c035fc-037a-48ee-9273-0477f5585279 | `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeCompositionTest.php` | failed | 1 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |
| VERIFY-RUN-50a13ed9-9111-4831-8f4b-c97c211eec84 | `php -l src/Core/HookDispatcher.php` | passed | 0 | `2f5e32765b7d26ad357db0741fa4561f862524a7` |

Forge returned no assertion detail for the failed composition run and evaluated the stale HEAD rather than the current dirty working tree. These runs therefore do not constitute a clean snapshot verification.

## Current SHA-256

- `src/Admin/AdminModule.php`: `sha256:2d2ef40cfc9e3e3352ae11fa0f781a435d7d51c8ec5196d8a4b557343b3e43c7`
- `src/Core/Environment/EnvironmentCheck.php`: `sha256:4ecea023803428dd5dbca5d7d644f494ef914ea799d57792537462f3b8389fdc`
- `src/Core/HookDispatcher.php`: `sha256:8f5781da38ebd1769bfe1e3c2e3d8b1b836a94d75952ec15d27b49dde0253bae`
- `src/Core/Plugin.php`: `sha256:7e6307cedcf933dda6a0cbf67d2198ece06874da01894ceca0dc3ba6c9eb6674`
- `src/SEO/Runtime/SeoModule.php`: `sha256:fa46aec39017754e1ea99f45857dbaf51dd7e2d661a705205ef7385384b73e57`
- `src/SEO/Runtime/WooCommerceProductReader.php`: `sha256:044a7777d93b4ec065b37460b2da6419b9cee38a1305402a7ad05f06dbffb865`
- `src/SEO/Runtime/WordPressContentReader.php`: `sha256:59e33249cdb67d4245fc1ba38da435e6c98b005277d2586f4fe7f50f920c5d8a`
- `src/WooCommerce/WooCommerceModule.php`: `sha256:9467509e38aa444a7f66fce905e7a5a8312b5bedf95b5f3fabbc7a1e99da3722`
- `tests/Unit/RuntimeReaderSmokeTest.php`: `sha256:b6f26c94ff238c901cf87e76b43f81728ad63b5b01bc04b19db35a8c0cfd158f`

## Chưa hoàn tất và giới hạn

E4-4 Rank Math inventory bridge, observer capability/meta seam và reader adapter wiring chưa hoàn tất. E4-5 chưa có đầy đủ object-backed post/page/simple/variation, hook callback permission, health rendering sentinel và negative side-effect spy matrix. E4-6 chưa đạt vì chưa có commit snapshot source/test sạch; verification đang đánh giá commit cũ.

Kết quả offline không chứng minh tương thích production WordPress/WooCommerce/Rank Math. Default Rank Math vẫn zero-rule. Báo cáo không đóng gate C/D/E và không mở F–H; chuyển Reviewer/Owner đánh giá tiếp.
