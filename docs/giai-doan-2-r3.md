# Giai đoạn 2 — R3: Nhận xét báo cáo và hướng dẫn bước tiếp theo

> Đối chiếu `docs/report-giai-doan-2-r1.md` bản cập nhật ngày 2026-11-16 với source/test hiện có. Đây là handoff kiến trúc và hướng dẫn delivery, không phải Sprint Plan, manifest file hay bằng chứng nghiệm thu production.

## 1. Kết luận hiện tại

Báo cáo cập nhật phản ánh đúng hướng và có bằng chứng mới cho:

- Provider payload boundary ở mức generic allowlist/resource limits.
- Ba AI task offline với fake response.
- Local SEO analyzer có finding contract mở rộng và sort severity.
- Rank Math detector cùng ownership registry tối thiểu.
- Unit result được báo cáo là `15 tests, 80 assertions`.

Mức nghiệm thu phù hợp:

> **SEO local foundation + offline AI provider + một phần provider boundary/ownership registry — partial.**

Chưa được mở:

- AI Settings/secret storage.
- Gateway/network call.
- Preview/Apply hoặc bất kỳ ghi dữ liệu nào.
- Ownership transfer/auto-publish.

Không gọi network thật chỉ vì unit test offline đang pass.

## 2. Nhận xét và điều chỉnh wording bắt buộc

### 2.1 Provider payload boundary chưa phải typed per-content schema

`ProviderPayloadBuilder` hiện chứng minh generic allowlist và các giới hạn tài nguyên, chưa chứng minh schema riêng đầy đủ cho `article/page` và `product/variation`. Cần giữ rõ các gap:

- Required/optional field và type riêng cho từng resource chưa hoàn tất.
- Nested allowlist trong `SeoContext` không đồng nhất với builder; không được coi `SeoContext` tự nó là privacy boundary gateway.
- `categories`/`tags` và các list item cần kiểm tra length/type thống nhất.
- Input `type` không được phép ghi đè resource type do builder quyết định.
- Provenance và canonical precedence chưa có.
- PII/secret rejection phải được chứng minh ở mọi nested schema hợp lệ.

Báo cáo nên dùng wording: **“generic allowlist và resource limits; typed article/page/product schemas và complete nested-PII enforcement còn thiếu.”**

### 2.2 AI output mới là task-shaped với validation một phần

Fake provider đã có ba task và response shape, nhưng validator còn gắn trong fake provider; chưa có shared response validator. Cần tránh gọi là fully typed output schema.

Còn thiếu:

- Exact key/type/list-shape validation cho từng finding.
- Success và malformed fixture riêng cho từng task.
- Language, length và target-field validation.
- Error contract tách `malformed_request`, `unsupported_task`, `invalid_response`.
- Ranh giới rõ rằng response chưa được Apply.

### 2.3 Local analyzer mới có partial contract

Đã có `suggested_fix` và severity sorting, nhưng:

- Equal-severity tie-breaker chưa được định nghĩa/test.
- Canonical mới kiểm tra thiếu, chưa kiểm tra URL hợp lệ.
- Heading hierarchy, internal-link semantics, offer/schema consistency và parent/variation chưa hoàn tất.
- `mbstring` fallback chưa thay thế compatibility policy đầy đủ.

### 2.4 Rank Math vẫn là detector/registry sơ bộ

`RankMathDetector` chưa phân biệt đầy đủ installed/inactive/active/version/compatible; `OwnershipRegistry` chưa đọc dữ liệu, chưa write và chưa thay thế adapter.

Cần giữ các trạng thái `unknown`/`conflict` và không tự claim ownership. Cần compatibility matrix/version rule thật, multiple-plugin detection, runtime health và coexistence duplicate-output tests.

### 2.5 Diễn đạt phạm vi absence thận trọng

Trong các path đã audit không thấy AI Settings, gateway, Apply hoặc adapter production. Khi chưa có inventory toàn repository tương ứng, dùng wording **“chưa quan sát thấy implementation/evidence trong các path đã audit”** thay vì khẳng định tuyệt đối project-wide absence.

## 3. Safe next increment — đóng Increment A

Chỉ giao một increment tiếp theo:

> **Hoàn thiện shared typed provider boundary + privacy/limit contract tests, hoàn toàn offline và không ghi dữ liệu.**

### Phạm vi code

1. Tách hoặc hoàn thiện `ContextBuilder`/`ProviderPayloadBuilder` làm boundary dùng chung cho mọi provider.
2. Định nghĩa schema riêng cho `article/page` và `product/variation` với required/optional fields, exact types và parent/variation semantics.
3. Không copy arbitrary nested arrays; nested allowlist phải rõ theo field và list item.
4. Reject type sai; không cast tùy ý thành string.
5. Áp dụng depth, byte-size, text-length và list-count limits thống nhất.
6. Chặn secret/token/API key, customer/order/PII ở mọi nested level theo policy `reject` hoặc `redact` đã chốt.
7. Bảo toàn resource type do builder quyết định; input không được override.
8. Thêm provenance và canonical precedence cho field lấy từ nhiều nguồn.
9. Provider chỉ nhận payload đã build; không nhận `WP_Post`, `WC_Product` hoặc object nền tảng.
10. Không mutate input và không lưu request/response lâu dài.

### Acceptance của Increment A

- Có fixture thành công cho article, page, product và variation.
- Forbidden field trong `images`, `seo`, `variation` và từng list item bị xử lý đúng policy.
- Payload vượt depth/byte/text/list limits bị từ chối an toàn.
- Sai type, unknown field và resource-type override bị từ chối.
- Provenance/canonical precedence có test deterministic.
- Mọi provider test dùng cùng boundary; không chỉ fake provider tự lọc riêng.
- Không có network call, secret storage hoặc Apply.

**Exit gate:** contract test độc lập pass và có evidence checksum/revision; khi chưa đạt không chuyển sang Increment F–H.

## 4. Hướng dẫn các increment sau A

### Increment B — Shared AI output schema

- Đưa validator ra ngoài `FakeAiProvider`.
- Dùng schema/version/status/error contract chung.
- Tạo success/malformed fixtures riêng cho `seo_analysis`, `title_meta_suggestion`, `product_description_draft`.
- Validate field allowlist, exact types, language, length, target field, provenance và commercial-fact guard.
- Response sai schema không được trở thành output có thể Apply.

### Increment C — Local analyzer

- Hoàn thiện sáu field finding: `code`, `severity`, `message`, `field`, `evidence`, `suggested_fix`.
- Định nghĩa deterministic tie-breaker cho equal severity.
- Bổ sung boundary tests title/meta, heading hierarchy, canonical validity, internal-link classification, offer/schema consistency và parent/variation.
- Chốt `mbstring` dependency/health check hoặc fallback abstraction có test trên môi trường thiếu extension.

### Increment D — Rank Math adapter/ownership

- Tách detector, read adapter và ownership registry.
- Phân biệt installed/inactive/active/version/compatible bằng matrix cụ thể.
- Xử lý unknown/conflict/multiple SEO plugin.
- Ownership theo title/meta/JSON-LD/sitemap/AI draft.
- Chỉ đọc; không ghi Rank Math, không transfer ownership.
- Test coexistence và duplicate-output prevention; status vào Health Check không lộ secret.

### Increment E — Runtime composition

- Đăng ký builder, analyzer, detector/registry qua composition root.
- Thêm adapter/hook cho post/page và WooCommerce product/variation.
- Boot idempotent, không fatal khi dependency thiếu, không network trong boot.
- Test runtime invocation, available/unavailable dependency và health output an toàn.

## 5. Blocker và decision gate cho F–H

Chưa mở các increment Settings/gateway/Apply vì còn thiếu:

1. Secret storage, rotation, masking, retention và key-retention policy được chốt.
2. Gateway test environment, API/version/model/auth contract.
3. Timeout/retry/redirect/SSRF/response-validation policy.
4. Ownership matrix đủ chắc để cho phép Apply.
5. Snapshot/hash, stale-preview rejection, optimistic concurrency và partial-write recovery.
6. Compatibility matrix cho PHP, WordPress, WooCommerce và Rank Math.

Do đó:

- Không triển khai gateway/network client.
- Không thêm custom URL hoặc credential vào core configuration.
- Không tạo Preview/Apply ghi dữ liệu.
- Không chuyển ownership hoặc auto-publish.

## 6. Test và bằng chứng

Mỗi increment phải có unit/contract/integration test phù hợp:

- Payload nested privacy, type, provenance, limits và immutable input.
- AI task success/malformed/invalid response.
- Analyzer rule boundaries và deterministic ordering.
- Rank Math status/compatibility/ownership/conflict/coexistence.
- Runtime composition/hook idempotency.
- Sau decision gate: Settings permission, key retention/redaction, gateway SSRF/auth/timeout/retry, Preview non-persistence và stale Apply rejection.

Kết quả `15 tests, 80 assertions` được xem là evidence unit hiện tại theo báo cáo; cần gắn output/CI artifact với revision để tái lập. Không xem unit result là bằng chứng runtime WordPress/WooCommerce hoặc production compatibility.

## 7. Trạng thái handoff

SL có thể phân rã **Increment A** thành ticket trong boundary trên và chọn file thực tế sau discovery. Không mở rộng sang network, secret, ownership transfer, commercial data mutation hoặc auto-publish.

Sau khi A đạt exit gate, tiếp tục B–E theo dependency. F–H cần decision gate và Architecture/Owner review riêng; không suy diễn approval từ unit tests.

## 8. Bằng chứng đối chiếu

- `docs/report-giai-doan-2-r1.md` — `sha256:f770bca0a86d5cc225ac9a6dfc2db22cdee17f1898854265af48ee94df4cafbf`
- `docs/giai-doan-2-r2.md` — `sha256:52070de261c2a5e473610751963947f9d4ec70ba3490ea2980eb9c3706c91fd4`
- `src/SEO/Context/ProviderPayloadBuilder.php` — `sha256:3fd6a6710f8fe462fc737e8112e2f7ee92c26354b8e9b332380122ad9189388b`
- `src/SEO/Context/SeoContext.php` — `sha256:1a0b7e58bd6c6f7e8b4e0ab89bc4e6239cac0c8ce171f05dad34ef9b112d7058`
- `src/AI/Contracts/AiProvider.php` — `sha256:6eeea1936e4b1057a05041512c9ab2f1c05667963410c906f129c8f07522b8d5`
- `src/AI/Fake/FakeAiProvider.php` — `sha256:0698da86d623628be21483e3f0e15d0d46bab0538d1bc8904320020e56382ec6`
- `src/Integrations/RankMath/OwnershipRegistry.php` — `sha256:e7db1b4bf6ec779f447d4a49f6750c96c7c0d905b5d06f0ba404622d736e2393`
- `tests/Unit/AiProviderTest.php` — `sha256:50fbbc49ca229ebdff42a9aaffd650b1631d75073d58fd6579fa99ac277ef88e`
- `tests/Unit/SeoCoreTest.php` — `sha256:db3cc3f82b0cf386cd97520f3a8d08c0c0fe4d9588a8826abc3072ac80d6095a`
