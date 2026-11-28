# Increment 3B R5 — Báo cáo verification và bằng chứng R4

## Kết luận

**R4 implementation đã được sửa theo failure evidence và focused/full PHPUnit hiện PASS.** Full suite còn 1 test skipped do môi trường PHP không cho phép mutation environment; không có failure.

R5 xử lý đúng lỗi được runner làm rõ:

- Projection content dài trước đó làm test cũ kỳ vọng `payload_rejected` không còn phù hợp với contract R4; test đã được cập nhật để xác nhận payload accepted sau projection.
- Permalink `http://localhost/draft` được coi là URL local không hợp lệ và bị loại khỏi provider payload.
- PHPUnit được chạy bằng Forge `run_command` với `--verbose` để thu stdout/stderr đầy đủ.

## Thay đổi implementation

### WordPressContentReader

- Giữ source dùng cho Local SEO Analyzer nguyên vẹn.
- Thêm projection text-only cho provider payload.
- Loại Gutenberg comments, shortcode và HTML; decode entity và chuẩn hóa whitespace.
- Giới hạn content ở 2.000 code points UTF-8.
- Trả reason đóng `missing_title`, `missing_content`, `content_truncated_for_preview`.
- Loại URL/canonical local, private hoặc có credential khỏi projection.
- Không gọi network/DNS và không ghi persistence.

### EditorPreviewController

- Ánh xạ reason qua allowlist thông báo tĩnh trong metabox.
- Không phản chiếu raw payload, exception hoặc reason tùy ý.

### Tests

- Bổ sung matrix projection HTML/Gutenberg dài, content rỗng và permalink local.
- Cập nhật fixture R3A để phản ánh contract R4: content dài sau projection vẫn preview được.

## Verification

Focused runtime composition:

```text
php vendor/bin/phpunit --verbose tests/Unit/RuntimeCompositionTest.php
OK (7 tests, 33 assertions)
exit_code=0
```

Full suite:

```text
php vendor/bin/phpunit --verbose --configuration phpunit.xml tests/Unit
Tests: 110, Assertions: 1616, Skipped: 1
exit_code=0
```

Skipped test:

- `IncrementGTest::testEmptyEnvironmentCredentialDoesNotFallbackToDatabase`
- Lý do: Environment mutation unavailable trong PHP runtime hiện tại.
- Đây không phải failure implementation.

PHP lint PASS:

- `src/SEO/Runtime/WordPressContentReader.php`
- `src/SEO/Runtime/EditorPreviewController.php`
- `tests/Unit/Increment3ATest.php`
- `tests/Unit/RuntimeCompositionTest.php`

PHP/runtime evidence:

- PHPUnit 9.6.39
- PHP 8.5.9
- Configuration: `phpunit.xml`

## Checksums hiện hành

- `src/SEO/Runtime/WordPressContentReader.php`: `sha256:1a9e4062eb6718cd39fbc8fb7f3ba579112be340ea5d9fe7a212fdacdecc3eab`
- `src/SEO/Runtime/EditorPreviewController.php`: `sha256:a438a32ec6155e600b9e7801b9c785c5397f99595abc8df3403f438a51023649`
- `tests/Unit/Increment3ATest.php`: `sha256:5c5191946fceaa54ec90872329979d0b493e5a9f9732af70920ad3b77ec35b7f`
- `tests/Unit/RuntimeCompositionTest.php`: `sha256:6df5b0ff42decee80d82ebd617303660108b721591e6845f9caa55fd91020ccc`
- `phpunit.xml`: `sha256:60bf845d5988a6d4233a92facfd6b83ed212c27a49eed9d7a8d40236a7d420a5`

## Scope and limits

Không sửa `myseoforall.php`, persistence schema, Rank Math ownership, frontend emission 3C hoặc production data. Không bàn giao `.phpunit.result.cache`; file này là artifact sinh bởi PHPUnit và đang được giữ ngoài báo cáo bàn giao.

Evidence là offline/unit verification. Chưa có kiểm thử WordPress/WooCommerce runtime thật, gateway production hoặc OPcache/deployment artifact thực tế; do đó báo cáo không tuyên bố production acceptance.
