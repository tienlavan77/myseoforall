# Increment 3B R6 — Báo cáo triển khai

## Kết quả

Đã xử lý 5 mục R6 trong phạm vi Increment 3B:

- **3B6-1:** Chuẩn hóa preview theo thứ tự an toàn, decode entity rồi strip tags lần hai; kiểm tra privacy trên toàn bộ text trước khi cắt; giới hạn content, excerpt, meta_description ở 2.000 code points.
- **3B6-2:** Metabox hiển thị cảnh báo tĩnh content_truncated_for_preview trong nhánh accepted và vẫn render disclosure cùng các nút Preview.
- **3B6-3:** Apply secret chọn AUTH_KEY, sau đó wp_salt('auth'); không có secret thì không đăng ký Apply services.
- **3B6-4/5:** Bổ sung test privacy nằm sau giới hạn projection và missing title cho cả array/object; giữ nguyên coverage long-content accepted và các gate hiện có.

Không sửa myseoforall.php, schema persistence _myseo_*, hoặc Rank Math ownership.

## Verification

| Scope | Command | Run ID | Status | Exit |
|---|---|---|---|---:|
| Runtime composition | php vendor/bin/phpunit tests/Unit/RuntimeCompositionTest.php | VERIFY-RUN-7dc45bca-f572-4bde-9b3c-96dd1a9b3e39 | passed | 0 |
| Increment 3A focused | php vendor/bin/phpunit tests/Unit/Increment3ATest.php | VERIFY-RUN-5a32fff9-030f-4a35-9e00-53443f9cf7c2 | passed | 0 |
| Full Unit | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit | VERIFY-RUN-3def3be0-15a1-477c-b1e2-5da2360701f6 | passed | 0 |
| PHP lint | php -l src/SEO/Runtime/WordPressContentReader.php | VERIFY-RUN-2082b09d-8045-49e4-8a4f-c026a2ddc12e | passed | 0 |
| PHP lint | php -l src/SEO/Runtime/EditorPreviewController.php | VERIFY-RUN-d639777f-a06e-46a8-abd3-8f9ad2c17752 | passed | 0 |
| PHP lint | php -l src/SEO/Runtime/SeoModule.php | VERIFY-RUN-36d5072b-e182-40d0-beba-58ca8450263e | passed | 0 |
| PHP lint | php -l tests/Unit/Increment3ATest.php | VERIFY-RUN-f0558898-0836-456d-a512-887bbc922b3f | passed | 0 |
| PHP lint | php -l tests/Unit/RuntimeCompositionTest.php | VERIFY-RUN-9ed65e28-b878-44e2-8e1a-13fbe435e5de | passed | 0 |

Verbose focused runs via Forge command runner: RuntimeCompositionTest 8 tests / 37 assertions; Increment3ATest 7 tests / 341 assertions; Increment3BTest 6 tests / 29 assertions. Full Unit: 111 tests / 1625 assertions / 1 skipped, exit 0. The skipped test is IncrementGTest::testEmptyEnvironmentCredentialDoesNotFallbackToDatabase because environment mutation is unavailable in this PHP runtime. run_check receipts independently returned pass/exit 0 for the focused and full scopes listed above.

## Checksums

- src/SEO/Runtime/WordPressContentReader.php: sha256:c966c595bcf1a6349e463d93d44a236afdf211907a402df567145edc3b4f592e
- src/SEO/Runtime/EditorPreviewController.php: sha256:feafcbfe528982209dfbd46441089cac2c4163fe8e0f048936be7afa9efeedde
- src/SEO/Runtime/SeoModule.php: sha256:757d8efb7924431394d133e0bc6fd7f53c4f557f43c856c4a55abfd8f767a506
- tests/Unit/RuntimeCompositionTest.php: sha256:4a78f13eda5ab2d87ea12fa63f8fe98bb35d940d37a607e2f2ff9c640d734cf4
- tests/Unit/Increment3ATest.php: sha256:57a185fc8ffde225b934c3fd8124a5f5bc6c0c414fe0cd9f9bd8970b6ac41c2b

## Giới hạn

Kiểm thử là offline PHPUnit với seams/fixtures. Chưa có bằng chứng runtime WordPress, WooCommerce hoặc Rank Math production; không dùng kết quả này để tuyên bố production acceptance.
