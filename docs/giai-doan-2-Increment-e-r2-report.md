# Increment E r2 — Báo cáo triển khai

## Snapshot

- Tài liệu yêu cầu: docs/giai-doan-2-Increment-E-r2.md
- SHA-256 tài liệu: cce378ac3dc6777394e3d7a23b89b4928b7e8f0c586fba701de3ee034d18c0aa
- Base snapshot: 509bbe1634803bc075c78b1be9ee5e97f8aaba60
- Implementation snapshot: a31cc2bd7568702a8c05fc8d9017efd72a91a159
- Báo cáo được tạo sau khi khóa snapshot code/test; cache PHPUnit không thuộc commit.

## Findings đã xử lý

- E2-1: Environment probe và Plugin::health() giờ chiếu qua allowlist trường/kiểu/enum; loại caller message, unknown keys, raw registry/config values. Admin render chỉ dùng nhãn/trạng thái cố định và hiển thị risk theo output sau kiểm quyền.
- E2-2: EnvironmentCheck::isReady() chỉ true với compatible === true; WooCommerce mặc định không suy compatibility từ loaded signal; Rank Math health đọc đúng detector contract và tách service availability khỏi dependency readiness; plugin identity và malformed active-plugin option fail-closed.
- E2-3: WordPress reader phân biệt unavailable, not_found, invalid_source; projection payload có allowlist riêng và bỏ slug. WooCommerce ánh xạ simple → product, giữ decimal/empty price đúng nghĩa missing, đọc currency qua platform function khi có, kiểm ID/parent và chỉ lookup parent một lần.
- E2-4: SEO diagnostics listener gọi inspector khi có post ID; WooCommerce lifecycle listener ghi nhận readiness mà không quét/ghi dữ liệu.
- E2-5: Rank Math observer nhận mapping slot explicit; slot ngoài mapping bị chặn trước WordPress API, unreadable không gắn runtime_verified.
- Thêm tests/Unit/IncrementER2SafetyTest.php cho closed probe/health, explicit readiness và unmapped observer.

## Verification

| Run ID | Command | Exit |
|---|---|---:|
| VERIFY-RUN-72343c01-4f5a-4fca-b8b9-969dbad9b2d9 | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementER2SafetyTest.php | 0 |
| VERIFY-RUN-32f9531b-0edc-488c-923b-eb0fee5d28d7 | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/FoundationTest.php | 0 |
| VERIFY-RUN-92da480a-3f50-4032-bd76-e219f88e809f | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RuntimeReaderSmokeTest.php | 0 |
| VERIFY-RUN-d635373e-73f2-4adf-b3c3-5553645c234b | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/RankMathRuntimeSmokeTest.php | 0 |
| VERIFY-RUN-accc891f-bd4f-4211-a45e-0aa80b8db5db | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit (pre-snapshot) | 0 |
| VERIFY-RUN-9ee89023-9bd7-4dbb-a455-362c75546501 | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit (implementation snapshot) | 0 |

## SHA-256

### Source

- src/Admin/AdminModule.php: 305ed83f6d3f9de87f08233f535178eed64c8160b73f79e5b7aade7ca973891a
- src/Core/Environment/EnvironmentCheck.php: e0d6b5adfab55161676bd647428fc058b69deae06811d1a096da72685085987a
- src/Core/Plugin.php: 2b7b75f4e96a53d8bf65f7d0d4ec22859e20731df83f9c3fbb69e9e5cb2242a2
- src/Integrations/RankMath/RankMathModule.php: fe1060ee3131209117b45049c633f47c7d5d9c912bbdce9045f64f994cf2d330
- src/Integrations/RankMath/WordPressRankMathProbe.php: 5eb2e2ff09842ba0344c89b2b69ecc1d78a27065f919ba9437014d48d3ae496f
- src/Integrations/RankMath/WordPressRankMathSourceObserver.php: 2cfbf6b2b48e47c28da7217dd87fb13090caf615a1c5850d40c24bfd6d8104ac
- src/SEO/Runtime/SeoModule.php: 1ca16394263afabc7757ad4990341ac8c8f932eddc9e8b1ff2759044c3d613b9
- src/SEO/Runtime/WooCommerceProductReader.php: 634a4eec08f026d53b99e5486a8c67578bc5ae8e8e84bf9806243d03edb260ba
- src/SEO/Runtime/WordPressContentReader.php: 2b8627ba88e1996a518229520911a114dbe84e078632a75aba7218edf025774f
- src/WooCommerce/WooCommerceModule.php: 9e5bcc83936dc46265cc2c0f8c4811b3202bd4fdac047a6bc381c054c76c463c

### Test

- tests/Unit/IncrementER2SafetyTest.php: 71a195b38d0055ddd1b140d30216cd65f0c347837882b56c9d3a0cbab1f165b7

## Giới hạn

Đây là xác minh offline trên snapshot đã nêu. PHPUnit không chứng minh tương thích production WordPress/WooCommerce/Rank Math, không chứng minh callback forwarding trong một request WordPress thật, và không tự đóng gate C/D/E hoặc mở F–H. Default Rank Math catalog/matrix vẫn zero-rule; không thêm mapping production hay quyền ghi.
