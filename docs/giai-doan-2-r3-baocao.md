# Báo cáo triển khai giai đoạn 2 — R3

Cập nhật: 19/11/2026 (UTC). Triển khai và kiểm thử: 18/11/2026 (UTC). Người nhận: chủ dự án MySeoForAll.

## 1. Kết quả và phạm vi

Đã triển khai phần code và contract tests của **Increment A** theo mục 3 trong `docs/giai-doan-2-r3.md`: shared typed provider boundary, hoàn toàn offline. PHPUnit receipt hợp lệ từ `run_check` đã đạt; Increment A vẫn cần được đánh giá cùng các giới hạn privacy và production readiness tại mục 6.

R3 chỉ giao Increment A ở bước này. Các hướng dẫn B–E là công việc tiếp theo; F–H tiếp tục giữ gate. Thay đổi lần này không thêm network client, lưu secret, lưu request/response, Preview/Apply hoặc ghi dữ liệu WordPress/WooCommerce.

Code Increment A và tests đã nằm trong commit `358b745769ec99bf2a557de547a147546d14514e`. HEAD được đối chiếu trước lần cập nhật báo cáo này là `4c9914231f200f784f42f0b55e1dab49a2fddf48` trên nhánh `main`, đã chứa code/tests và bản ghi receipt trước đó. `git_diff` xác nhận source, tests và cấu hình dependency/PHPUnit không đổi giữa hai revision. Receipt mới tại mục 4 gắn trực tiếp với HEAD này. Hai blocker kỹ thuật về receipt PHPUnit và code chưa nằm trong HEAD đã được xử lý; chưa push.

## 2. Thay đổi đã làm

| File | Thay đổi |
| --- | --- |
| `src/SEO/Context/ProviderPayloadSchema.php` | Thêm schema đóng theo resource, required/optional fields và exact types cho từng nested object/list item. |
| `src/SEO/Context/ProviderPayloadRules.php` | Thêm kiểm tra depth, text/list/byte limits, UTF-8, exact types, URL và mẫu private text dùng chung. |
| `src/SEO/Context/ProviderPayloadBuilder.php` | Build payload v2, kiểm tra quan hệ variation, provenance, canonical precedence và revalidate payload tại provider boundary. |
| `src/AI/Contracts/AiProvider.php` | Nâng request contract lên `myseo.ai.request.v2`, yêu cầu context theo payload v2. |
| `src/AI/Fake/FakeAiProvider.php` | Dùng `validatePayload()` của boundary chung; bỏ bộ lọc context/list riêng của fake provider. |
| `tests/Unit/ProviderPayloadContractTest.php` | Thêm contract tests độc lập với các fixture đầy đủ và negative cases. |
| `tests/Unit/AiProviderTest.php` | Chuyển các request thành công sang builder chung. |
| `tests/Unit/SeoCoreTest.php` | Cập nhật fixture và assertion theo contract v2. |

Trong `src` và `tests` đã khảo sát, provider triển khai `AiProvider` được tìm thấy là `FakeAiProvider`; provider này đi qua boundary chung. Đây là phạm vi bằng chứng hiện tại, không phải chứng nhận cho provider production tương lai.

### Schema và relationship

- `article`, `page`: bắt buộc `title` là string không rỗng và `content` là string; content rỗng vẫn hợp lệ.
- `product`: bắt buộc `product_name` là string không rỗng.
- `variation`: bắt buộc `product_name` và `variation` chứa `id`, `parent_id` là số nguyên dương, khác nhau. Nếu có top-level `id`, giá trị phải bằng variation ID; hai SKU phải khớp khi cùng được cung cấp.
- Các trường tùy chọn chung gồm ID, nội dung, excerpt/meta, URL/canonical, language, taxonomy, images, headings, internal links và SEO overrides.
- Commerce có thêm description, short_description, SKU, price, stock, attributes và offer. Article/page từ chối các trường commerce; product từ chối relationship dành cho variation.
- `price` là decimal string không âm; `stock` là integer không âm. Không tự cast float/string/bool/null sang kiểu khác.
- `images[]`: chỉ `src`, `alt`; `headings[]`: chỉ integer `level` từ 1–6 và string `text`; `internal_links[]`: chỉ `url`, `anchor`; `attributes[]`: chỉ `name`, `value`.
- `seo`: chỉ `title`, `meta_description`, `canonical`; `offer`: bắt buộc `price`, `currency`, `availability`; variation chỉ thêm SKU và attributes ngoài hai ID bắt buộc.
- Unknown fields, arbitrary nested arrays, object nền tảng và input tự đưa `type`, `schema_version`, `provenance` đều bị từ chối. Type do tham số builder quyết định.

Các relationship trên là kiểm tra tính nhất quán của payload, không truy vấn database để chứng minh parent thực sự tồn tại hoặc là đúng loại WooCommerce product.

### Giới hạn và privacy policy

Policy là **reject**, không tự cắt nội dung hoặc redact âm thầm.

| Giới hạn | Giá trị |
| --- | --- |
| Depth | Tối đa 4, root tính là 0; hỗ trợ `variation.attributes[].value`. |
| Số phần tử | Tối đa 50 cho mỗi array, kể cả taxonomy, nested lists và provenance. |
| Text | Tối đa 2.000 Unicode code points mỗi string; UTF-8 phải hợp lệ. |
| Byte size | Tối đa 12.000 bytes JSON dùng UTF-8/slash không escape; payload cuối bao gồm metadata/provenance. Source cũng được kiểm tra byte budget. |

Duyệt giới hạn trước khi copy theo schema để từ chối cả cấu trúc quá sâu và array tự tham chiếu. Tất cả string values được kiểm tra cùng một bộ quy tắc, bao gồm chuỗi trong list item và provenance. Closed schema từ chối customer/order/secret keys tại mọi object đã định nghĩa.

Bộ nhận dạng private text chặn email, contact phone theo mẫu, credential trong HTTP URL, nhãn API key/token/password/secret/customer/order, Bearer credential và một số dạng key/private-key header. Kiểm tra thêm HTML entity decoding và tối đa ba lượt URL decoding. Lỗi không đưa giá trị đầu vào vào thông báo.

URL/canonical/internal-link URL không rỗng phải là HTTP(S) tuyệt đối, không có user/password. Image `src` vẫn cho phép đường dẫn tương đối. Không URL nào được fetch.

### Provenance và canonical

Thứ tự ưu tiên canonical không rỗng: **`seo.canonical` → `canonical` → `url`**. Mọi candidate được cung cấp đều phải hợp lệ, kể cả candidate không thắng.

Provenance chỉ tham chiếu field đang có trong payload hoặc SEO subfield đang có. Source labels được giới hạn chính xác ở `wordpress`, `woocommerce`, `rank_math`, `editor`; mặc định là `wordpress` cho editorial và `woocommerce` cho commerce. Canonical mang label của candidate thắng. Các field và provenance được sắp thứ tự ổn định.

Label biểu thị nguồn do caller khai báo; builder không xác thực nguồn bằng cách đọc WordPress hoặc Rank Math. Không được hiểu label là bằng chứng dữ liệu đã được lấy từ hệ thống đó.

## 3. Thay đổi tương thích

Request schema mới: `myseo.ai.request.v2`; payload schema mới: `provider-payload-2`. Response schema vẫn là `myseo.ai.response.v1`.

Caller cần build context trước rồi đưa kết quả vào request:

```php
$context = (new ProviderPayloadBuilder())->build(
    ['title' => 'Tiêu đề', 'content' => 'Nội dung'],
    'article'
);
$request = [
    'schema_version' => AiProvider::REQUEST_SCHEMA,
    'task' => 'seo_analysis',
    'context' => $context,
];
```

Raw context, request v1, generic resource và payload v1 không còn được chấp nhận. `validatePayload()` kiểm tra lại schema, limits, provenance và canonical form; metadata không phải đường bỏ qua validation. Đây là kiểm tra contract, không phải chữ ký chứng minh caller đã gọi builder.

`SeoContext` của local analyzer không thay đổi và không được coi là provider privacy boundary. Không truyền nguyên `SeoContext::toArray()` hoặc `WP_Post`/`WC_Product` vào provider.

## 4. Kiểm thử và receipt mới nhất

Đã thực thi qua Forge `run_check`, `type: test`, đúng lệnh:

```text
php vendor/bin/phpunit --configuration phpunit.xml tests/Unit
```

| Thuộc tính | Giá trị do Forge trả về |
| --- | --- |
| Status | **passed** |
| Exit code | **0** |
| Run ID | `VERIFY-RUN-115e21b5-19ce-4aa7-b24b-ec13f7e53b14` |
| Evaluated at | `2026-11-18T23:59:29.541Z` |
| Commit SHA | `4c9914231f200f784f42f0b55e1dab49a2fddf48` |
| Base SHA | `358b745769ec99bf2a557de547a147546d14514e` |
| Scope | `tests/Unit`; không chạy toàn bộ project suite. |
| Ready for review | `true` |

Receipt không trả stdout PHPUnit hoặc số tests/assertions. Vì vậy không gán các số liệu lịch sử `20 tests/378 assertions` hay `23 tests/385 assertions` cho run này. `ready_for_review: true` là trạng thái do Forge trả về, không thay thế review độc lập hoặc nghiệm thu của chủ dự án.

Trước lệnh thành công trên, một lần thử file contract test riêng với `--do-not-cache-result` bị admission từ chối bằng `INPUT_INVALID`: “Check command must use an approved Node, pnpm, npm, or quoted PHP project check.” Lần thử đó không thực thi PHPUnit và không tạo receipt. Chưa xác định nguyên nhân validator; lệnh `tests/Unit` ở trên đã được chấp nhận và chạy đạt.

Working tree trước khi sửa báo cáo có thay đổi `.phpunit.result.cache`; không có source/test thay đổi hoặc conflict. Cache không phải bằng chứng nghiệm thu. Không chạy lại verification qua `run_command` trong lượt cập nhật này.

## 5. Đối chiếu acceptance của Increment A

| Acceptance R3 | Bằng chứng hiện tại |
| --- | --- |
| Fixture article/page/product/variation | `testSuccessFixturesUseSharedBoundaryForEveryTask`: bốn resource × ba task; kiểm tra không mutate input. |
| Forbidden fields/nested private text | `testPrivacyIsEnforcedAcrossAllNestedSchemas`: chèn forbidden keys và email vào nested objects/list items/scalar leaves; thêm credential/encoded contact cases. Giới hạn semantic PII ở mục 6 vẫn còn. |
| Depth/byte/text/list limits | `testResourceLimitsCoverTaxonomyNestedItemsAndCycles`: biên 2.000 ký tự, 50 items, 12.000 bytes; overflow, invalid UTF-8, nested overflow và cycle. |
| Exact types/unknown/type override/relationship | `testInvalidTypesRequiredFieldsAndRelationshipsAreRejected`: thiếu required field, sai scalar/list/object, cross-resource fields, ID/SKU không nhất quán. |
| Provenance/canonical deterministic | `testCanonicalAndProvenanceAreDeterministic`: thứ tự ưu tiên, winner source, đảo thứ tự source input, nhãn/field sai. |
| Provider dùng boundary chung | Fake provider gọi `validatePayload`; positive provider fixtures đi qua builder; negative cases cố ý gửi raw/tampered payload để kiểm tra reject. |
| Không thêm network/storage/Apply | Các source thay đổi chỉ build/validate/copy và tạo output offline; không thêm runtime hook hoặc writer. |
| Contract tests và verification có revision | Có contract-test class, checksum tại mục 7 và receipt mới `VERIFY-RUN-115e21b5-19ce-4aa7-b24b-ec13f7e53b14`; passed, exit code 0, commit `4c9914231f200f784f42f0b55e1dab49a2fddf48`. Review độc lập và nghiệm thu của chủ dự án chưa được chứng minh bằng receipt này; privacy/production gate tại mục 6 vẫn giữ nguyên. |

## 6. Giới hạn và phần còn lại

- Pattern matching không chứng minh loại bỏ mọi PII trong văn bản tự do: tên người, địa chỉ, mã khách hàng không nhãn hoặc secret opaque không có dạng nhận biết vẫn có thể không bị nhận diện. Chưa tuyên bố “complete nested-PII enforcement” theo nghĩa ngữ nghĩa tuyệt đối.
- Chưa có kiểm thử WordPress/WooCommerce/Rank Math production, HTTP provider hoặc dữ liệu thật. Fixture offline không chứng minh compatibility runtime, migration production hoặc hiệu quả bảo vệ mọi dạng nội dung.
- Chưa wire provider boundary vào runtime/network; dù đã có verification receipt hợp lệ, vẫn cần giải quyết privacy policy cho văn bản tự do và các điều kiện production trước khi xét mở network gate.
- Increment B: shared AI output schema/validator và error/status contract; C: local analyzer semantics; D: Rank Math read adapter/compatibility/coexistence; E: runtime wiring/health evidence — vẫn là công việc tiếp theo theo R3.
- Increment F–H: settings/secrets, gateway/network và Preview/Apply tiếp tục giữ gate. Không triển khai ownership transfer hoặc auto-publish trong đợt này.
- Code/tests đã có trong HEAD và PHPUnit qua `run_check` đã đạt. Báo cáo đang ghi lại kết quả này; không có thay đổi source/test trong lượt cập nhật. Cache PHPUnit đã được đưa vào các commit trước và còn thay đổi tại thời điểm đối chiếu; không dùng cache để nghiệm thu. Chưa push.

## 7. Checksum bàn giao

Tài liệu kế hoạch: `docs/giai-doan-2-r3.md`, SHA-256 `ddf972458b4b7db24120df919088f04718bf7d4def8c6e421a721acca4311c77`.

Các checksum sau xác định source/test Increment A đã được Forge xác nhận và đưa vào commit `358b745769ec99bf2a557de547a147546d14514e`:

| File | SHA-256 |
| --- | --- |
| `src/SEO/Context/ProviderPayloadBuilder.php` | `a45ce3fc88989c27114e810f50bce2abc10ff4a2f9cbcef8a499adae64adb093` |
| `src/SEO/Context/ProviderPayloadSchema.php` | `7f5d5ff28bed0c930ed80b4f3d206126db1db06004e797d0f1422484862a57e7` |
| `src/SEO/Context/ProviderPayloadRules.php` | `c7821c39d9c3baff90a8643b56a9b949da41a26536af73e0286e787bd59defdf` |
| `src/AI/Contracts/AiProvider.php` | `b5a87d137ecf0cf1400eb0e1077cd8c59ce3974069cdd4e99ade129c8aef5bad` |
| `src/AI/Fake/FakeAiProvider.php` | `4b7398147069315e48e649ebff14fe39a0461c3b4a7c6b80d482c45673de67ee` |
| `tests/Unit/ProviderPayloadContractTest.php` | `785f2b0443606d47bde27acbf76b82e73951f8f750081e991df384972ba1926e` |
| `tests/Unit/AiProviderTest.php` | `ac923147c78ef9869d9b980b3ccb29f72199979340fe7d70f845a6b835b11946` |
| `tests/Unit/SeoCoreTest.php` | `0b21f3eb98ff3d7fab87ca8d2fc3c103ca307bc62846870bc106ccece0cf42f9` |

Evidence đọc lại từ parent và kho dùng chung được đối chiếu với source hiện tại. Hai kết quả explorer về payload/report phản ánh snapshot trước thay đổi, không phải review độc lập của bản mới. Explorer provider-usage trả `fallback_to_parent`; SE đã tự đọc provider/tests và tìm usage trong `src`, `tests` trước khi tổng hợp báo cáo.

## 8. Lịch sử bằng chứng và trạng thái bàn giao

Các lần thử trước được rút gọn dưới đây; trạng thái mới nhất là mục 1 và receipt tại mục 4.

- Trước khi admission PHPUnit được chấp nhận, `run_check` nhiều lần trả `INPUT_INVALID`; các lần đó không thực thi PHPUnit và không tạo contract-test receipt.
- SE đã chạy sai kênh verification bằng `run_command`: focused suite báo `20 tests, 378 assertions`; một lần chạy `tests/Unit` báo `23 tests, 385 assertions`. Đây chỉ là kết quả thực thi lịch sử, không thay thế receipt `run_check`.
- Syntax receipt cũ `VERIFY-RUN-5a79f6b8-d160-425d-903d-a80eee642e50` chỉ kiểm tra builder tại HEAD cũ `4cbd16da17bee2e7b464dca48d01d1b438c71ac2`; không phải contract-test receipt cho Increment A.
- Bản báo cáo trước ghi receipt PHPUnit hậu commit `VERIFY-RUN-acaa5a4b-680d-4ddb-a2f7-7d3bfd7cedca`, evaluated at `2026-11-18T23:50:52.128Z`, gắn với commit triển khai `358b745769ec99bf2a557de547a147546d14514e`. Receipt mới ở mục 4 được lấy trực tiếp từ `run_check` trong lượt cập nhật này.
- Đã kiểm tra `git_status`, `git_log`, `git_show HEAD` và `git_diff` giữa commit triển khai với HEAD: code A đã nằm trong lịch sử HEAD; source/tests/configuration không đổi giữa hai revision. Trước khi sửa tài liệu, chỉ cache PHPUnit thay đổi.
- Lượt này chỉ cập nhật `docs/giai-doan-2-r3-baocao.md`; không sửa source/test, không triển khai Increment B hoặc mở network/Apply.

Người nhận: chủ dự án MySeoForAll. Hai blocker kỹ thuật được nêu trước đó đã có bằng chứng xử lý. Bước còn lại là review/nghiệm thu Increment A với các giới hạn ở mục 6 trước khi quyết định chuyển sang Increment B theo R3.
