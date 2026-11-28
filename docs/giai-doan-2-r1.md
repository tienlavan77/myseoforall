# Giai đoạn 2 — R1: Phần còn thiếu sau kiểm tra

> Tài liệu này ghi nhận khoảng cách giữa mục tiêu Giai đoạn 2 và phần code hiện có sau lần rà soát SE. Đây là gap report/định hướng nghiệm thu, không phải bằng chứng hoàn thành và không thay thế Sprint Plan.
>
> **Kết luận hiện tại:** Giai đoạn 2 chưa đạt nghiệm thu đầy đủ và chưa được phép gọi gateway production.

## 1. Trạng thái đã có

Repository hiện có một phần nền tảng standalone:

- `SeoContext` — value object/context sơ bộ, có lọc top-level.
- `LocalSeoAnalyzer` — một số rule phân tích SEO cục bộ.
- `RankMathDetector` — phát hiện Rank Math ở mức presence flag.
- `tests/Unit/SeoCoreTest.php` — unit test cơ bản cho ba phần trên.

Bằng chứng:

- `src/SEO/Context/SeoContext.php` — SHA-256 `1a0b7e58bd6c6f7e8b4e0ab89bc4e6239cac0c8ce171f05dad34ef9b112d7058`.
- `src/SEO/Analysis/LocalSeoAnalyzer.php` — SHA-256 `bbd8be709f98df3aedf21158b6744b770486d2421d4f9e633a5cedca34b49a30`.
- `src/Integrations/RankMath/RankMathDetector.php` — SHA-256 `d816408abee2c4ee258e71c4d72d6d871412a1cdbc2deae97d26d0c941f83c08`.
- `tests/Unit/SeoCoreTest.php` — SHA-256 `96ecd7661bede6c723f912d9bb5ed372a7730bc42920245b6667abd68617b958`.

Các file Phase 2 nêu trên đang được ghi nhận là untracked trong lần kiểm tra; cần đưa vào trạng thái repository chính thức trước khi coi là delivery đã commit.

## 2. Cập nhật kiểm tra increment AI provider

SE đã tạo skeleton nhưng chưa hoàn tất increment “AI provider contract + fake client”:

- `src/AI/Contracts/AiProvider.php` — interface hiện đang rỗng, chưa có `generate()` hoặc request/response contract.
- `src/AI/Fake/FakeAiProvider.php` — class hiện mới `implements AiProvider`, chưa có fake response deterministic và chưa có allowlist enforcement.
- Chưa có test contract độc lập cho provider/fake client.
- Chưa có network call; đây là đúng guardrail, nhưng chưa phải bằng chứng provider đã hoàn tất.

Evidence hiện tại:

- `src/AI/Contracts/AiProvider.php` — SHA-256 `aa0dba8b0bda31344e325882ad9023132d97bdd6103e48a8ba27602887db0120`.
- `src/AI/Fake/FakeAiProvider.php` — SHA-256 `f45fd66ad66d8123152a38833ea1702d31416f70bc6543f53bc27abe3088ac91`.
- Git status đang ghi nhận `src/AI/` và `tests/Unit/SeoCoreTest.php` là untracked.

Acceptance còn thiếu:

- Interface định nghĩa tác vụ, request schema/version, response schema/version và lỗi có thể xử lý.
- Fake provider trả response deterministic, không HTTP/SDK/network, không mutate input và không lưu request/response lâu dài.
- Provider boundary chỉ nhận payload typed/allowlist; từ chối field ngoài schema và dữ liệu cấm ở nested level.
- Test chứng minh output ổn định, không network, allowlist/privacy, unsupported task và malformed input.

Chưa chuyển sang secret storage hoặc gateway client cho đến khi increment này có method, schema và test độc lập.

## 3. Blocker phải xử lý trước gateway hoặc Apply

### 2.1 Provider và gateway

Chưa có:

- Provider contract production và fake client.
- Gateway registry cố định.
- HTTP client và authentication boundary.
- Timeout, retry, redirect và error mapping.
- Response schema validator.
- SSRF controls.
- Decision-gate evidence cho gateway, API version, model và authentication.

**Acceptance:** khi decision gate chưa đạt, chỉ được chạy local analyzer hoặc fake client; không có network call production.

### 2.2 AI Settings và secret storage

Chưa có:

- Trang Settings AI riêng.
- Secret boundary tách khỏi core configuration.
- Server-side storage được phê duyệt.
- Rotation/replace policy.
- Masked display và giữ key cũ khi input mới trống.
- Capability, nonce, sanitization và validation riêng.
- Bảo đảm secret không xuất hiện trong health, log, export, browser storage hoặc exception.

`Plugin::health()` hiện trả configuration; không được thêm credential vào core configuration khi chưa có secret boundary.

### 2.3 Preview và Apply

Chưa có:

- Preview flow hoặc endpoint/UI.
- Field allowlist cho Apply.
- Snapshot/hash phiên preview.
- Optimistic concurrency và stale-preview rejection.
- Adapter sở hữu dữ liệu.
- Confirmation, error boundary và partial-write recovery.
- Bảo đảm không sửa giá, tồn kho, review hoặc dữ liệu thương mại.

**Acceptance:** Apply chỉ ghi field đã xác nhận, sau khi đọc lại dữ liệu hiện tại; preview stale phải bị từ chối và yêu cầu tạo lại.

## 3. Context contract và privacy boundary còn thiếu

`SeoContext` hiện lọc key top-level nhưng cho phép cấu trúc lồng nhau tùy ý. Vì vậy một key nhạy cảm lồng trong field được phép vẫn có nguy cơ đi qua context. Chưa có:

- Nested schema có kiểu dữ liệu rõ ràng.
- Field-level allowlist/redaction.
- Giới hạn depth, kích thước và độ dài payload.
- Source provenance và canonical precedence.
- Provider-specific payload builder.
- Schema riêng cho bài viết/trang và sản phẩm/variation.
- Quy tắc parent/variation, offer, giá và tồn kho.

**Acceptance:** test chứng minh payload được tạo từ nested typed allowlist; dữ liệu khách hàng, đơn hàng, PII, API key, token và secret bị loại bỏ ở mọi cấp; payload vượt giới hạn bị từ chối.

## 4. Local SEO Analyzer còn thiếu

Finding hiện chưa đủ contract đã ghi trong `docs/giai-doan-2.md`: thiếu `suggested_fix`.

Các rule còn cần hoàn thiện:

- Ngưỡng title/meta và boundary tests.
- Heading hierarchy, không chỉ đếm H1/H2–H6.
- Canonical thiếu và canonical không hợp lệ.
- Xác định internal link thay vì chỉ đếm input.
- Offer và schema/display consistency.
- Quan hệ parent/variation và điều kiện variation.
- Mã rule ổn định, severity và evidence có cấu trúc.
- Thứ tự severity thực tế; hiện mô tả có sắp xếp nhưng chưa chứng minh implementation đã sort.

**Acceptance:** mỗi finding có `code`, `severity`, `message`, `field`, `evidence`, `suggested_fix`; test định lượng các ngưỡng và thứ tự kết quả.

Ngoài ra, analyzer gọi `mb_strlen()` trực tiếp trong khi `composer.json` chưa yêu cầu hoặc kiểm tra `ext-mbstring`. Cần chọn rõ: thêm dependency/health check hoặc abstraction fallback và test môi trường thiếu extension.

## 5. Rank Math integration và ownership còn thiếu

Hiện mới có detector; chưa có:

- Adapter đọc dữ liệu Rank Math.
- Version compatibility rule/matrix.
- Installed-but-inactive distinction.
- Registry ownership theo từng output.
- Conflict/unknown/multiple-plugin state.
- Coexistence và duplicate-output prevention.
- Runtime composition, hook và Health Check integration.

Không được mặc định `Rank Math inactive => My SEO For All owns`. Ownership tối thiểu phải phân biệt `rank_math`, `wordpress`, `myseo_for_all`, `unknown`, `conflict` và trạng thái chưa xác nhận.

Ownership cần được chốt theo output:

| Output | Khi Rank Math active | Giai đoạn 2 |
|---|---|---|
| SEO title/meta | Rank Math sở hữu; My SEO For All đọc/phân tích | Không ghi nếu chưa có adapter contract và xác nhận rõ |
| JSON-LD | Rank Math sở hữu | Không xuất |
| Sitemap | Rank Math hoặc WordPress sở hữu | Không xuất |
| AI suggestion/draft | Preview của My SEO For All, không phải public output | Có thể Apply theo policy riêng |

## 6. Runtime integration còn thiếu

`Plugin` hiện chỉ compose nền móng `AdminModule` và `WooCommerceModule`; các class SEO/Rank Math chưa được đăng ký hoặc gọi trong runtime. `WooCommerceModule` chưa tạo product context hoặc invoke analyzer.

Cần bổ sung và kiểm thử:

- Composition root/module registration.
- WordPress post/page hooks.
- WooCommerce product/variation adapter và lifecycle an toàn khi WooCommerce thiếu.
- Admin/analysis surface.
- Rank Math status trong health/integration registry.
- Không network call trong bootstrap.
- Idempotent hook registration.

## 7. Output contract và AI task schema còn thiếu

Mỗi tác vụ phải có schema version và validator, tối thiểu:

- SEO analysis: findings có code/severity/field/evidence/suggested fix.
- Title/meta suggestion: field allowlist, giới hạn độ dài, ngôn ngữ và nguồn context.
- Product description draft: field đích, ngôn ngữ và cờ không suy diễn dữ liệu thương mại.

Response sai kiểu, field ngoài allowlist, vượt giới hạn hoặc không đúng schema phải bị từ chối an toàn; UI không được biến text tự do thành dữ liệu có thể ghi.

## 8. Không lưu request/response và privacy vận hành

Cần chứng minh request/response gateway không bị lưu tại:

- Database.
- Log/debug output/exception.
- Queue/cache/session ngoài policy.
- Telemetry/APM hoặc HTTP client retry.

Preview phải redaction secret và giới hạn độ dài. Dữ liệu tạm cần có lifecycle rõ ràng. Việc lưu draft/snapshot để hoàn tác chỉ được bật sau khi Owner chốt retention, quyền truy cập và phương án xóa.

## 9. Test và bằng chứng nghiệm thu còn thiếu

Chưa có bằng chứng test suite đã chạy trong lần rà soát. Cần chạy và lưu kết quả cho:

- Context nested privacy, type/depth/size limits.
- Analyzer thresholds, product/variation, finding schema và severity ordering.
- Rank Math detection thực tế, installed/inactive, compatibility và ownership conflict.
- Runtime composition/hook/admin/Health Check.
- Settings permission, nonce, sanitization, key retention và redaction.
- Gateway auth, timeout, retry, redirect, SSRF và response validation.
- Preview non-persistence.
- Apply confirmation, snapshot/version, stale rejection, field allowlist và partial failure.
- AI disabled vẫn chạy local SEO.
- Không lưu request/response ngoài policy.

Trước release phải có compatibility matrix cụ thể cho PHP, WordPress, WooCommerce và Rank Math; “latest” không phải bằng chứng tương thích.

## 10. Trạng thái nghiệm thu

| Hạng mục | Trạng thái |
|---|---|
| Context contract | Một phần, chưa đủ privacy boundary |
| Local analyzer | Một phần, thiếu contract/rule coverage |
| Rank Math detection | Một phần, chưa phải integration |
| Runtime composition | Chưa có |
| Provider/fake client | Chỉ có skeleton; chưa đạt contract |
| AI Settings/secret storage | Chưa có |
| Gateway/network client | Chưa có; không được bật production |
| Preview/Apply | Chưa có |
| Compatibility/security hardening | Chưa đạt |
| Test execution evidence | Chưa có trong lần rà soát |

**Quyết định:** chỉ có thể ghi nhận milestone “SEO local foundation — partial”. Chưa được ghi nhận là Giai đoạn 2 hoàn tất, Rank Math integration hoàn tất hoặc AI/gateway production-ready.

## 11. Thứ tự xử lý đề xuất

1. Đưa các file Phase 2 vào repository chính thức.
2. Sửa ownership status và định nghĩa ownership registry.
3. Hoàn thiện nested typed context và payload builder.
4. Hoàn thiện finding contract/rule tests và xử lý `mbstring`.
5. Compose runtime SEO/Rank Math và thêm integration tests.
6. Tạo provider contract/schema/fake client, chưa network.
7. Chốt secret storage, Settings và decision gate.
8. Triển khai gateway registry/network client với security tests.
9. Triển khai preview/Apply với optimistic concurrency.
10. Chạy full test suite, compatibility matrix và security/release hardening.
