# Báo cáo xử lý — Giai đoạn 2 Increment C r2

Cập nhật: 19/11/2026 (UTC). Phạm vi: xử lý docs/giai-doan-2-increment-c-r2.md trong boundary Local SEO analyzer offline, deterministic, read-only.

## 1. Kết quả

Đã xử lý các blocker C2-R2 đến C2-R7 ở mức implementation và test:

- phân biệt missing/null và sai kiểu cho title, meta description, content;
- thêm currency và availability vào allowlist normalized context để offer policy có dữ liệu đầu vào;
- chuẩn hóa giá bằng decimal string không dùng float; 10.00 và 10 được coi là cùng giá, 0 là giá hợp lệ, giá âm/sai pattern là invalid;
- chỉ phát hiện price mismatch khi cả hai giá đều canonicalize được;
- giữ parent/variation structural checks, không suy diễn parent tồn tại hoặc dữ liệu thương mại từ parent;
- giữ heading policy chỉ báo skip khi đi sâu; comment/script được loại khỏi heuristic;
- giữ canonical absolute HTTP(S), không userinfo, không fetch/DNS;
- internal links dùng same-host classification, không đoán internal khi thiếu base URL;
- finding evidence bounded bằng reason/count và ordering theo severity → code rank → field → evidence;
- Unicode length dùng code points với mbstring hoặc fallback regex UTF-8 deterministic.

Không thêm network, platform API, persistence, writer, Apply, runtime Health Check hoặc commercial mutation.

## 2. Files and checksums

| File | SHA-256 | Vai trò |
| --- | --- | --- |
| src/SEO/Analysis/LocalSeoAnalyzer.php | sha256:7d019998c052c896d90e0a5ddde0ad70cefa50fffb71a3a0b02550b72e602131 | Analyzer rules, decimal policy, type checks |
| src/SEO/Context/SeoContext.php | sha256:efaf6e7f9679bd785570667523a3f523203f7d13062c4cff6c56787faf36d573 | Add normalized currency/availability fields |
| tests/Unit/SeoCoreTest.php | sha256:8af4924f18faab19d81dba22067432af8dcb75867d849a1375bb67635f6e427a | Input contract and decimal policy tests |
| docs/giai-doan-2-increment-c-r2-report.md | pending | This report |

## 3. Verification

Forge checks passed on the current overlay. Node reported the checked execution context as commit c6de1b3a063b1ede91dd24d247fb7ca6fd9b0777, base c7793d992da7f27b08c18efe9c76fbd3eb176b7e; the C r2 source/test changes remain working-tree edits, so these are overlay receipts, not commit-bound receipts.

| Scope | Command | Run ID | Evaluated at | Status |
| --- | --- | --- | --- | --- |
| Focused analyzer | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/SeoCoreTest.php | VERIFY-RUN-849f2aa9-0a63-45c2-b8b9-b39b5eccbbb1 | 2026-11-19T08:44:03.656Z | passed, exit 0 |
| Unit scope | php vendor/bin/phpunit --configuration phpunit.xml tests/Unit | VERIFY-RUN-96917081-158b-4c3c-b310-d9330054639b | 2026-11-19T08:44:25.402Z | passed, exit 0 |

## 4. Policy decisions recorded by implementation

- Input types: scalar text is accepted; arrays/objects for text fields create bounded invalid findings. Missing fields create missing findings. Explicit null follows the existing normalized accessor behavior and is treated as missing.
- Decimal: only non-negative integer/decimal strings are accepted; leading zeros and trailing fractional zeros canonicalize; floats, signs and exponent notation are invalid. No float arithmetic is used.
- Offer: shape requires scalar price, currency, and availability; invalid shape/type/price are distinct from price mismatch. Currency/availability are retained as context fields but no external truth is inferred.
- Heading: bounded regex heuristic, not HTML parser; comments/scripts ignored; only downward skipped levels are reported.
- Canonical/link: HTTP(S) absolute URLs with host and no userinfo; no network or DNS; links classify by same hostname only when a valid base host exists; relative URLs remain unsupported.
- Unicode: code-point count is the policy; fallback uses UTF-8 regex and byte length only for invalid UTF-8 fallback, which is recorded as a remaining compatibility limitation.

## 5. Remaining limitations and gate status

- SeoContext::get() still cannot distinguish absent from explicit null after normalization; explicit null is intentionally treated as missing for this increment.
- Offer currency equivalence and availability semantics are shape-only; the report does not claim commercial truthfulness or full schema consistency.
- Regex heading inspection does not prove HTML parser correctness for all malformed markup.
- Relative URLs, origin/port policy and IDN normalization remain outside the current input policy.
- Health Check/runtime integration belongs to Increment E.
- C r2 source/test are not committed in this handoff. Reviewer review and Owner confirmation remain required; no “Increment C passed” claim is made.
- Existing unrelated untracked planning/report files and PHPUnit cache remain outside logic evidence.

## 6. Evidence

- Plan: docs/giai-doan-2-increment-c-r2.md — sha256:2376be560e339e4fbcf5164e19d9ef82a2cb4f5f9883de269e9eb8ebd4644e87.
- Prior C report: docs/giai-doan-2-increment-c-report.md — sha256:dce1fc450f1b533621d658aff9ccb588b25166a4051ec7d1e62598dbfd539e9a.
- Verification scope and checksums above were read after the implementation edits.

C r2 is technically implemented and test-verified on the overlay, but the immutable snapshot and approval exit gate remain open per the plan.