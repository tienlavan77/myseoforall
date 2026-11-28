# Increment 3B R4 — Báo cáo triển khai

## Kết quả

Đã triển khai projection xem trước AI trong `WordPressContentReader` theo boundary R4:

- Tách projection text-only khỏi dữ liệu dùng cho Local SEO Analyzer.
- Loại Gutenberg comments, shortcode và HTML bằng pipeline deterministic.
- Chuẩn hóa whitespace/entity, giới hạn nội dung AI ở 2.000 code points.
- Bỏ URL/canonical không hợp lệ (ví dụ permalink local/draft) khỏi provider payload.
- Trả reason đóng `missing_title`, `missing_content` và `content_truncated_for_preview`.
- Metabox ánh xạ reason sang thông báo tĩnh, không phản chiếu raw exception hoặc payload.

## Kiểm thử

Focused test và full suite đã được yêu cầu bằng Forge Verification Service nhưng hiện trả lỗi trình chạy PHPUnit chỉ với dòng nhận diện:

```text
PHPUnit 9.6.39 by Sebastian Bergmann and contributors.
exit_code=1
```

Các lệnh đã thử:

- `php vendor/bin/phpunit tests/Unit/RuntimeCompositionTest.php`
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeCompositionTest.php`
- `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit`

Vì Verification Service không trả failure detail/test report, chưa thể xác nhận PASS và không tuyên bố nghiệm thu.

Lint focused đã PASS:

- `php -l src/SEO/Runtime/WordPressContentReader.php`
- `php -l src/SEO/Runtime/EditorPreviewController.php`
- `php -l tests/Unit/RuntimeCompositionTest.php`

## Giới hạn

Chưa có kiểm thử WordPress/WooCommerce runtime thật. Chưa commit hoặc push thay đổi. Không sửa persistence schema, privacy gate, auto-Apply, Publish hoặc frontend emission 3C.
