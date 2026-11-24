# Báo cáo Giai đoạn 2 — R2 đối chiếu implementation

- Ngày: 2026-11-16
- Phạm vi: đối chiếu toàn bộ yêu cầu trong `docs/giai-doan-2-r2.md` với source và unit tests hiện có trong working tree.
- Kết luận: **SEO local foundation + offline AI provider + một phần provider boundary/ownership registry — partial**.
- R2 chưa được nghiệm thu đầy đủ; gateway production, network call và auto-apply vẫn bị khóa.

## 1. Phần đã có bằng chứng

### Increment A — Provider payload boundary: đã triển khai một phần đáng kể

`ProviderPayloadBuilder` hiện đã có builder-owned resource type, resource shape checks cho article/page/product/variation, top-level và nested allowlist, provenance deterministic, cùng giới hạn depth, byte size, text length và số phần tử list; kiểu dữ liệu ngoài policy bị từ chối. `FakeAiProvider` gọi boundary này trước khi xử lý request.

Bằng chứng:

- `src/SEO/Context/ProviderPayloadBuilder.php` — SHA-256 `sha256:3fd6a6710f8fe462fc737e8112e2f7ee92c26354b8e9b332380122ad9189388b`
- `src/SEO/Context/SeoContext.php` — SHA-256 `sha256:1a0b7e58bd6c6f7e8b4e0ab89bc4e6239cac0c8ce171f05dad34ef9b112d7058`
- `tests/Unit/SeoCoreTest.php` — SHA-256 `sha256:db3cc3f82b0cf386cd97520f3a8d08c0c0fe4d9588a8826abc3072ac80d6095a`

Còn thiếu: required/optional contract sâu hơn theo từng resource, canonical precedence đầy đủ và chứng minh PII/secret bị chặn ở mọi nested schema hợp lệ; provenance deterministic và resource-type guard đã có ở mức foundation.

### Increment B — AI task/output schema offline: đã có foundation

Có ba task offline (`seo_analysis`, `title_meta_suggestion`, `product_description_draft`), response typed theo task, validator output, response deterministic và test shape cho từng task. Không có network.

Bằng chứng:

- `src/AI/Contracts/AiProvider.php` — SHA-256 `sha256:6eeea1936e4b1057a05041512c9ab2f1c05667963410c906f129c8f07522b8d5`
- `src/AI/Fake/FakeAiProvider.php` — SHA-256 `sha256:0698da86d623628be21483e3f0e15d0d46bab0538d1bc8904320020e56382ec6`
- `tests/Unit/AiProviderTest.php` — SHA-256 `sha256:50fbbc49ca229ebdff42a9aaffd650b1631d75073d58fd6579fa99ac277ef88e`

Còn thiếu: response validator dùng chung ngoài fake provider, fixture malformed riêng cho từng task, kiểm tra ngôn ngữ/độ dài/field đích sâu hơn, và boundary ngăn output đi vào Apply.

### Increment C — Local SEO analyzer: đã bổ sung một phần contract

Analyzer hiện có `suggested_fix` cho finding, rule title/meta/content, heading count, canonical thiếu, image alt, internal links, product fields, sort severity ổn định và fallback đếm chuỗi không cần `ext-mbstring`.

Bằng chứng:

- `src/SEO/Analysis/LocalSeoAnalyzer.php` — SHA-256 `sha256:d80e3782ce83286913eeaea4ea566541f817845bd4a9a84cce0bb489f8eaadaa`
- `tests/Unit/SeoCoreTest.php` — SHA-256 như trên

Còn thiếu: canonical hợp lệ, heading hierarchy, internal-link semantics, offer/schema consistency, parent/variation rules và boundary tests tương ứng. Fallback hiện có chưa phải compatibility policy đầy đủ cho mọi môi trường.

### Increment D — Rank Math ownership: đã có registry tối thiểu, chưa có adapter

Ngoài `RankMathDetector`, working tree đã có `OwnershipRegistry` với trạng thái `unknown`/`conflict`/`rank_math`, ownership theo output và `write_allowed=false`. Test chứng minh inactive không tự claim ownership và conflict không cho ghi.

Bằng chứng:

- `src/Integrations/RankMath/RankMathDetector.php` — SHA-256 `sha256:1f59d4d0234328957a19280d4746ceeb4f9d6dfebddc12eec4e26223b4aee9aa`
- `src/Integrations/RankMath/OwnershipRegistry.php` — SHA-256 `sha256:e7db1b4bf6ec779f447d4a49f6750c96c7c0d905b5d06f0ba404622d736e2393`
- `tests/Unit/SeoCoreTest.php` — SHA-256 như trên

Còn thiếu: adapter đọc dữ liệu Rank Math, compatibility matrix/version rules thực tế, phát hiện nhiều plugin đầy đủ, runtime Health Check và coexistence tests chứng minh không tạo duplicate output.

### Increment E — Runtime composition: chỉ có foundation chung

`Plugin` đã compose `AdminModule` và `WooCommerceModule`, boot idempotent và health output cơ bản. SEO analyzer, Rank Math detector và ownership registry chưa được đăng ký vào composition root; chưa có hook bài viết/trang hoặc product/variation context.

Bằng chứng:

- `src/Core/Plugin.php` — SHA-256 `sha256:21a4981a3d6eadd71f4a3062a5a2be20bd5e2a84f5e5ca80950b3c614c860ff5`
- `tests/Unit/FoundationTest.php` — SHA-256 `sha256:55ddd46b35975663406e110ed8838184a64dea98fc5f0dadeda96aafda6e99f4`

## 2. Phần chưa triển khai

### Increment F — AI Settings và secret boundary

Chưa có Settings riêng cho provider/gateway/model, secret storage server-side, masked display, rotation/retention, key retention khi ô nhập trống, redaction end-to-end hoặc permission tests. `AdminModule` hiện chỉ quản lý foundation configuration và Health Check.

### Increment G — Gateway/network client

Chưa có gateway registry, authentication, timeout/retry/redirect policy, SSRF controls, response validation hay network client. Không có bằng chứng decision gate cho phép gọi gateway thật.

### Increment H — Preview/Apply

Chưa có preview snapshot/hash, stale-preview rejection, optimistic concurrency, explicit confirmation, adapter-owned write hoặc partial-write recovery.

## 3. Kiểm tra đã chạy

Lệnh:

```text
php vendor/bin/phpunit --configuration phpunit.xml tests/Unit
```

Kết quả kiểm tra tập trung sau Increment A:

```text
OK (9 tests, 66 assertions)
```

Đây là test file `tests/Unit/SeoCoreTest.php`; full unit result trước increment được báo cáo là 15 tests, 80 assertions.

Đây là bằng chứng unit test hiện có. Nó không chứng minh runtime WordPress/WooCommerce đầy đủ, Rank Math coexistence thực tế, compatibility matrix, secret handling production, gateway/network security hoặc migration.

## 4. Bảng nghiệm thu R2

| Hạng mục | Trạng thái |
|---|---|
| Context/payload boundary | **Partial+** — resource type/shape, provenance và limits đã có; canonical precedence/PII contract sâu còn thiếu |
| Local analyzer | **Partial** — contract/fallback/sort đã bổ sung; thiếu rule coverage |
| AI provider/fake | **Partial** — ba typed offline task; thiếu validator/fixtures đầy đủ |
| Rank Math | **Partial** — detector + registry tối thiểu; thiếu adapter/matrix/runtime |
| Runtime composition | **Chưa đạt** — chưa compose SEO/Rank Math |
| AI Settings/secret storage | **Chưa có** |
| Gateway/network | **Chưa có; production bị khóa** |
| Preview/Apply | **Chưa có** |
| Compatibility/security hardening | **Chưa đạt** |
| Test evidence | **15 tests, 80 assertions**; chưa có integration/CI artifact riêng |

## 5. Handoff

Có thể tiếp tục delivery offline theo Increment A–E, ưu tiên hoàn thiện boundary dùng chung, rule tests, adapter/ownership và runtime composition. Increment F–H chỉ được mở sau các decision gate về secret, gateway, security, retention, compatibility và output ownership. Báo cáo này chỉ ghi nhận hiện trạng; không coi Giai đoạn 2 là hoàn tất và không tự tạo commit.
