# Giai đoạn 2 — Increment C r2: Hướng dẫn xử lý sau báo cáo

> Handoff Architecture sau `docs/giai-doan-2-increment-c-report.md`. Đây là hướng dẫn xử lý blocker và điều kiện bàn giao; không phải Sprint Plan, ticket list hay mutable-file manifest.

## 1. Kết luận nghiệm thu

Increment C **chưa được đóng exit gate**.

Báo cáo đã cho thấy implementation và focused verification có phạm vi đáng kể, nhưng chưa đủ để nghiệm thu vì:

1. Source/test C mới ở working tree, chưa có commit snapshot bất biến.
2. Hai receipt hiện gắn với revision `c6de1b3a063b1ede91dd24d247fb7ca6fd9b0777`, nhưng báo cáo đồng thời nói source/test đang chưa commit; cần làm rõ snapshot overlay/commit và đối chiếu checksum sau cùng.
3. Chưa có Reviewer review độc lập và Owner confirmation.
4. Một số policy mà kế hoạch C yêu cầu vẫn còn mở: decimal/currency semantics, input contract typed, heading parser/markup boundary và relative/internal-link policy.

Không mở Increment D/E hoặc F–H chỉ dựa trên focused/unit test pass.

## 2. Mục tiêu r2

Tạo một snapshot source/test có thể tái lập, chốt các policy còn mơ hồ trong phạm vi Local SEO analyzer, chạy verification trên đúng snapshot, rồi gửi Reviewer/Owner quyết định. Không mở network, persistence, writer hay runtime integration.

## 3. Việc bắt buộc cho SE

### C2-R1 — Commit-bound snapshot và evidence

- Đối chiếu `git status`, `git show` và checksum của tối thiểu:
  - `src/SEO/Analysis/LocalSeoAnalyzer.php`;
  - `tests/Unit/SeoCoreTest.php`;
  - mọi source/test/schema thực sự được C sử dụng.
- Tạo commit chứa source/test C theo quy trình Git được Owner/SL cho phép; không tự push nếu chưa được cấp quyền.
- Không đưa `.phpunit.result.cache` vào evidence logic.
- Phân biệt rõ tài liệu báo cáo/kế hoạch với implementation/test; report không được gán receipt của overlay cho commit khác.
- Nếu chỉ có overlay, ghi rõ overlay và giữ exit gate mở.

### C2-R2 — Chốt input contract của analyzer

Trước khi thêm rule mới, ghi vào test/contract nội bộ các trạng thái `missing`, `null`, sai kiểu và hợp lệ cho từng field analyzer đọc:

- `type`, `title`, `meta_description`, `content`, `url`, `canonical`;
- `headings`, `images`, `internal_links`;
- `offer`, `price`, `currency`, `availability`;
- `id`, `variation.id`, `variation.parent_id`, `sku`, `stock`.

Không cast trước khi kiểm tra type. `SeoContext::get()` không phân biệt missing và explicit null; nếu C cần phân biệt, phải dùng boundary/accessor phù hợp. Chốt rõ `post` và `article` là alias hay hai resource. Không coi `SeoContext` hoặc `ProviderPayloadSchema` là bằng chứng analyzer đã được typed tự động.

### C2-R3 — Chốt commerce/decimal policy

- Chuẩn hóa giá bằng decimal string/canonical decimal, không dùng float.
- Chốt quy tắc `0`/`0.00` là giá có mặt.
- Chỉ so sánh giá offer và product khi hai field cùng nghĩa và cùng currency semantics; currency thiếu hoặc khác phải là trạng thái riêng, không suy ra mismatch giá.
- Tách `missing`, `invalid` và `mismatch`; `stock = 0` không tự suy ra availability nếu chưa có backorder policy.
- Không đọc parent để bịa giá/tồn kho/SKU và không mutate commercial input.
- Nếu chưa chốt được canonical decimal/currency semantics, C chỉ được nghiệm thu phần offer-shape local, không claim full offer consistency.

### C2-R4 — Chốt heading và parser boundary

- `h4 → h2` được phép đi lên; chỉ báo bỏ cấp khi đi sâu, ví dụ `h2 → h4`.
- Chốt behavior cho comment/script, heading giả, markup thiếu thẻ đóng, content trắng và malformed markup.
- Nếu vẫn dùng bounded regex, ghi rõ đây là heuristic markup inspection, không phải HTML parser/rendering; test không được claim parser correctness.
- Không render browser, tải tài nguyên hoặc tự sửa nội dung.
- Nếu dùng parser/extension, phải có policy khi extension thiếu và test tương ứng.

### C2-R5 — Chốt canonical và internal-link policy

- Canonical `missing/blank` phải khác `present-invalid`; URL HTTP(S) absolute cần host hợp lệ và không userinfo.
- Không fetch, DNS lookup hoặc kiểm tra crawlability.
- Chốt fragment, port, IDN và khác host là policy riêng; khác host không tự động là invalid.
- Internal link phải ghi rõ cùng host hay cùng origin; relative URL, fragment, khác scheme/port và thiếu base xử lý thế nào.
- Không phát hiện “thiếu internal link” khi base thiếu làm phân loại không xác định.
- Anchor trắng chỉ đánh giá anchor được cung cấp; không tự suy diễn accessible label.

### C2-R6 — Finding evidence và ordering

- Registry phải khóa `code → severity, field, evidence shape`.
- Evidence chỉ dùng reason/count/field hoặc giá trị đã lọc, bounded; không chép URL/query, anchor, markup hoặc raw commerce value.
- Ordering phải deterministic: severity → code rank → field → khóa evidence đã lọc; test input/traversal đảo thứ tự và nhiều finding cùng code/field.
- Giữ đủ sáu field: `code`, `severity`, `message`, `field`, `evidence`, `suggested_fix`.
- Không đổi code/severity/meaning hiện có âm thầm; thay đổi contract cần Architecture/Owner review.

### C2-R7 — Unicode/UTF-8 compatibility

- Chốt đơn vị Unicode code points, trim, UTF-8 invalid và combining characters.
- `mbstring` và fallback phải cho cùng policy; nếu không bảo đảm tương đương, trả trạng thái unavailable thay vì false pass.
- Test min−1/min/max/max+1 với tiếng Việt dựng sẵn, dạng tổ hợp, emoji và UTF-8 lỗi.
- Không mở Health Check production trong C; chỉ cung cấp evidence cục bộ. Health Check plumbing thuộc E.

## 4. Acceptance criteria để mở exit gate

C chỉ được đưa Reviewer/Owner xem xét khi:

1. Có commit/revision chứa toàn bộ source/test C và checksum sau commit.
2. Focused analyzer test và toàn bộ `tests/Unit` chạy qua `run_check` trên đúng revision; receipt có command, run ID, scope, status, exit code, base SHA.
3. Input contract matrix được test cho missing/null/invalid/type.
4. Finding contract/evidence/order deterministic được test.
5. Canonical, heading, internal-link, offer/decimal và variation rules có test theo policy đã chốt.
6. Không network, platform API, persistence, writer, Apply hoặc commercial mutation.
7. Report phân biệt receipt commit-bound với overlay/history và ghi rõ giới hạn còn lại.
8. Reviewer review độc lập và Owner xác nhận exit gate.

`passed` của Forge chỉ là verification evidence; không tự tạo approval.

## 5. Guardrails không thay đổi

- C vẫn là Local SEO analyzer offline trong SEO Core (Giai đoạn 2); không phải mở toàn bộ Giai đoạn 3 WooCommerce SEO.
- Không mở Rank Math adapter/ownership transfer, runtime hooks/Health Check production integration, AI gateway, secret, Preview/Apply hoặc WooCommerce CRUD.
- Không coi unit fixtures là bằng chứng runtime WordPress/WooCommerce, semantic PII completeness, HTML/XSS safety, commercial truthfulness hoặc production compatibility.

## 6. Báo cáo tiếp theo phải ghi

- commit/revision, branch, base SHA và danh sách file implementation/test;
- checksum sau verification;
- policy input/decimal/URL/heading/link/Unicode đã chốt;
- focused và unit commands nguyên văn, run ID, evaluated time, scope, status và exit code;
- phần đạt/chưa đạt, overlay receipt lịch sử và receipt commit-bound;
- Reviewer/Owner status; không tự ghi “Increment C passed”.

## 7. Evidence

- Báo cáo C: `docs/giai-doan-2-increment-c-report.md` — `sha256:dce1fc450f1b533621d658aff9ccb588b25166a4051ec7d1e62598dbfd539e9a`.
- Kế hoạch C: `docs/giai-doan-3-increment-c.md` — `sha256:2e2fff7b0f7c8b769a49fb141cc71c3f21a99b2b1ccbade37a01ac457ef30488`.
- Analyzer report nêu: revision `c6de1b3a063b1ede91dd24d247fb7ca6fd9b0777`, base `c7793d992da7f27b08c18efe9c76fbd3eb176b7e`.
- Focused receipt lịch sử: `VERIFY-RUN-32ada6d6-d30e-4c11-8954-1008c959d4d0`.
- Unit receipt lịch sử: `VERIFY-RUN-369854ce-4b2b-45cb-8169-abf48c95e0a7`.

Các evidence trên dùng để xử lý blocker; chỉ receipt mới gắn đúng revision bàn giao mới có thể đóng verification gate.