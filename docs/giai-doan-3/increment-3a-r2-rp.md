# Increment 3A r2 — Báo cáo triển khai

Ngày: 25/11/2026  
Tài liệu chỉ đạo: `docs/giai-doan-3/increment-3a-r2.md`  
SHA-256 tài liệu chỉ đạo: `sha256:45fe132f7fefb73e37d50f8e7fc265c6c03bfcf0d5c9c6b301dbf312358413d0`

## Đã triển khai

- `EditorPreviewController` đã tách Gate 1–7: malformed request, ID, capability, nonce, task, resource/task compatibility, inspection/payload.
- `product_description_draft` bị chặn trước inspector khi resource không phải product.
- Response thành công dùng đúng 7 khóa; response lỗi dùng body đúng 3 khóa, HTTP status giữ ở envelope nội bộ cho WordPress wrapper.
- `current` lấy từ provider payload, trả `null` cho field thiếu/rỗng và có 3 khóa riêng cho product.
- Metabox có ownership message an toàn, bộ đếm severity, disclosure provenance, settings link, nonce, AJAX URL và `fetch()` dùng DOM API/`textContent`, không dùng `.innerHTML`.
- `SeoModule` đăng ký metabox theo post type trước khi có post ID/capability; fallback offline vẫn giữ `product_variation`.
- Tạo `tests/Unit/Increment3ATest.php` với gate, response contract và XSS coverage.

## SHA-256 sau triển khai

| File | SHA-256 |
|---|---|
| `src/SEO/Runtime/EditorPreviewController.php` | `sha256:215fc53a7cf76ea1f335be398b18b304af1340e667b95e9149761fc22adb4ad6` |
| `src/SEO/Runtime/SeoModule.php` | `sha256:a8ef85f959ba4296009ac35acf00263c62093914640af20fede7296fa222ba09` |
| `tests/Unit/Increment3ATest.php` | `sha256:7724a907dbb1cdf93f38c90e07e153bea4cb208448019e64433a36222f00dfd1` |
| `myseoforall.php` | `sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef` |

## Kiểm tra

- `php -l src/SEO/Runtime/EditorPreviewController.php`: pass.
- `php -l src/SEO/Runtime/SeoModule.php`: pass.
- `php -l tests/Unit/Increment3ATest.php`: pass.
- `php vendor/bin/phpunit tests/Unit/RuntimeCompositionTest.php`: pass.
- `php vendor/bin/phpunit tests/Unit/Increment3ATest.php`: failed, exit code 2; Forge output chỉ trả banner PHPUnit, không có diagnostic test cụ thể. Không coi test này là đạt.

## Trạng thái và giới hạn

R2 đã được triển khai về mặt source và các regression/lint check liên quan đã pass, nhưng chưa đủ điều kiện nghiệm thu vì test mới `Increment3ATest.php` chưa chạy thành công và full `tests/Unit` chưa chạy. `myseoforall.php` không bị sửa. Các test offline cũng không chứng minh WordPress runtime, gateway thật, DNS/TLS production hoặc retention upstream.

Trạng thái: `IMPLEMENTED — NOT ACCEPTED`.
