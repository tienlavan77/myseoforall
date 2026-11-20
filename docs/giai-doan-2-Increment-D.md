# Giai đoạn 2 — Increment D: Rank Math read adapter và ownership coexistence

> Hướng dẫn Architecture cho System Engineer (SE). Đây là boundary, contract và acceptance ở cấp increment; không phải Sprint Plan, danh sách ticket hay mutable-file manifest. SL chịu trách nhiệm phân rã delivery trong boundary này.

## 1. Trạng thái và mục tiêu

Increment D tiếp tục thuộc **Giai đoạn 2 — SEO Core/integration boundary**. Mục tiêu là xây dựng boundary Rank Math **chỉ đọc, offline, deterministic và fail-closed** để:

- phát hiện độc lập trạng thái cài đặt, kích hoạt, phiên bản và tương thích;
- đọc có kiểm soát title, meta description và canonical qua adapter riêng;
- xác định ownership và nguy cơ duplicate theo từng loại output trong môi trường coexistence;
- bảo toàn `unknown`, `unsupported`, `invalid` và `conflict` khi thiếu hoặc mâu thuẫn bằng chứng.

Kết quả của D chỉ là status/configuration evidence an toàn cho các bước sau. D không tạo output SEO mới và không cấp quyền ghi, publish hoặc chuyển ownership.

### Prerequisite và trạng thái mở gate

Báo cáo C r4 ghi nhận snapshot C đã verification kỹ thuật nhưng exit acceptance cần Reviewer độc lập và Owner xác nhận trong phạm vi offline. D chỉ chuyển từ **prepared** sang **executable** khi workflow xác nhận đúng decision identity, authority, scope và snapshot C đã được chấp thuận. Receipt của C là prerequisite/evidence context, không phải verification evidence của D.

Snapshot C được tham chiếu trong hồ sơ hiện tại là commit `9c76c628853dd63024c1e02fb44a70581b022db7`; SE không được dùng riêng test pass hoặc tự suy diễn approval từ giá trị này.

## 2. Phạm vi và loại trừ

### In scope

1. Detector Rank Math với các kết quả độc lập cho installation, activation, version, compatibility, reason, signal source và evidence state.
2. Compatibility matrix có version/range, capability, rule, nguồn bằng chứng và identity/revision.
3. Read adapter Rank Math với allowlist title, `meta_description`, canonical; typed validation, normalization, precedence, fallback và field-state contract.
4. Ownership registry cho `title_meta`, `json_ld`, `sitemap` và `ai_draft`, với decision riêng theo output.
5. Producer capability, ownership assertion, source identifier, evidence state và duplicate-output policy cho coexistence.
6. Contract/coexistence tests offline, immutable-input tests và snapshot verification evidence.

### Out of scope

- Runtime hooks, composition root, renderer, Health Check integration đầy đủ hoặc phát sinh title/meta/JSON-LD/sitemap output.
- Network, HTTP, DNS, credential, secret storage, persistence, writer, Apply, publish hoặc import dữ liệu.
- Tự kích hoạt/vô hiệu hóa plugin, tự thay đổi option/post meta, tự chuyển ownership hoặc thay đổi output bên thứ ba.
- Diễn giải template/configuration thô thành giá trị đã render, commercial truthfulness, production readiness hoặc quyền phát hành frontend.
- Mở Increment E hoặc các nhóm F–H chỉ vì D có test pass.

## 3. Outcome và contract bắt buộc

### O1 — Detector và compatibility

Detector phải tách các chiều sau, không dùng một boolean chung:

- installation: `absent`, `installed` hoặc `unknown`;
- activation: `inactive`, `active` hoặc `unknown`;
- version: `known`, `unavailable` hoặc `invalid`;
- compatibility: `compatible`, `incompatible` hoặc `unknown`.

Contract phải trả reason ổn định, nguồn tín hiệu và evidence identity/state đủ để truy nguyên quyết định. Tối thiểu phải có decision table cho:

- Rank Math absent;
- installed nhưng inactive;
- active nhưng thiếu installation metadata;
- active với version unavailable;
- version invalid nhưng capability probe báo compatible;
- version hợp lệ nhưng thiếu capability cần thiết;
- các probe mâu thuẫn hoặc chỉ quan sát được một phần.

Quy tắc bắt buộc:

- `active` không đồng nghĩa `installed` và không đồng nghĩa `compatible`;
- version invalid không tự chuyển thành `incompatible`;
- chỉ trả `compatible` khi version/capability khớp matrix có bằng chứng;
- thiếu hoặc mâu thuẫn tín hiệu phải trả `unknown`/`unsupported` cùng reason, không đoán theo nhãn `latest`;
- detector không được yêu cầu Rank Math class/hook để trả trạng thái an toàn và không được gây fatal khi dependency thiếu.

Compatibility matrix phải có version hoặc range, capability, rule, evidence source, identity/revision và ngày/cách cập nhật. Nếu repository chưa có bằng chứng đủ cho một range, giữ `unknown`; không tự đặt range tương thích.

### O2 — Rank Math read adapter

Adapter là boundary độc lập; không rải meta key/API knowledge của Rank Math vào SEO Core, analyzer hoặc registry. Adapter chỉ được expose ba field:

- `title`;
- `meta_description`;
- `canonical`.

Mỗi field phải có closed result gồm tối thiểu `field`, `state`, `source`, `reason` và `evidence_identity`; chỉ có `value` khi dữ liệu hợp lệ. State phải phân biệt:

- `value` — đúng kiểu và qua validation;
- `missing` — nguồn không có field;
- `explicit_null` — nguồn có field với null;
- `invalid` — có dữ liệu nhưng sai kiểu/format;
- `unsupported` — source/version không được hỗ trợ;
- `unreadable` — có tín hiệu nhưng read operation không thể xác nhận.

Mapping phải ghi version/range, kiểu dữ liệu, normalization, precedence, fallback rule, source và mapping revision. Khi nhiều mapping khớp, precedence phải deterministic; không giải quyết được thì trả `unsupported` hoặc `unknown` thay vì chọn tùy ý.

Fallback chỉ được phép khi nguồn ưu tiên xác nhận `missing`. Không fallback để che `explicit_null`, `invalid`, `unsupported` hoặc `unreadable`. Canonical phải có policy cú pháp rõ ràng (HTTP(S), host, userinfo, fragment/port nếu liên quan); không fetch, DNS lookup hay khẳng định crawlability.

Template thô, malformed input, sai kiểu, extra field và dữ liệu ngoài allowlist không được coi là frontend value hợp lệ. Adapter không mutate input, không đọc arbitrary metadata, không trả raw payload không cần thiết và không đưa secret/credential vào result. Configuration/template không phải bằng chứng giá trị đã render hoặc commercial truthfulness.

### O3 — Ownership và duplicate-output policy

Registry phải trả **decision object riêng** cho từng output:

- `title_meta`;
- `json_ld`;
- `sitemap`;
- `ai_draft`.

Mỗi decision tối thiểu gồm `owner`, `state`, `reason`, `duplicate_risk` và `write_allowed`. `write_allowed` luôn là `false` trong Increment D.

Input theo từng output phải tách ít nhất `producer`, `can_emit`, ownership assertion, source identifier và evidence state. Active plugin list chung chỉ là tín hiệu phụ; không đủ để claim ownership hoặc conflict. Capability chứng minh khả năng phát, không tự chứng minh producer đang sở hữu output.

Policy tối thiểu:

- thiếu ownership assertion hoặc thiếu evidence: `unknown`/`possible`, không claim My SEO;
- hai producer có assertion khác nhau trên cùng output: `conflict` ở output đó;
- duplicate risk biểu diễn rõ `none`, `possible` hoặc `blocked`; thiếu tín hiệu không được fallback thành `none`;
- conflict/duplicate risk của một output không lan sang output khác;
- kết quả không phụ thuộc thứ tự input và malformed capability không gây claim ownership;
- `ai_draft` là bản nháp nội bộ: `frontend_owned=false`, `publish_capability=false`, `write_allowed=false`; owner nội bộ không phải frontend ownership;
- không có đường tắt để chuyển ownership, tắt plugin khác hoặc cho phép ghi.

## 4. Hướng dẫn implementation cho SE

1. Khảo sát consumer và contract hiện tại trước khi đổi public shape. Giữ compatibility decision rõ nếu thay đổi shape của `RankMathDetector` hoặc `OwnershipRegistry`.
2. Tạo probe/interface injectable cho installation, activation, version và capability; tránh phụ thuộc global constant duy nhất trong test hoặc runtime read path.
3. Lập compatibility matrix và read mapping có identity/revision trước hoặc cùng implementation. Không điền version/range theo suy đoán.
4. Cô lập mọi meta key/API mapping trong adapter. Không tái sử dụng ProviderPayload boundary để thay thế Rank Math adapter.
5. Dùng closed states và reason ổn định; không cast sai kiểu thành string, không nuốt lỗi thành `missing` nếu contract yêu cầu `invalid`/`unreadable`.
6. Tách per-output evidence trong registry. Không suy ownership từ `active`, `compatible` hoặc tên plugin nếu thiếu assertion theo output.
7. Chuẩn hóa/sắp xếp input theo policy để cùng evidence cho cùng output luôn cho cùng kết quả; không mutate input.
8. Giữ mọi read path offline/read-only. Không thêm hook, writer, persistence, Apply, publish hoặc output renderer để “chứng minh” adapter.
9. Khi phát hiện public contract, security/data semantics hoặc compatibility policy hiện tại không đủ để tiếp tục, dừng tại boundary và báo Architecture; không âm thầm mở scope.

## 5. Acceptance và verification

### Contract tests bắt buộc

- Detector: absent, installed-inactive, active, unknown; version known/unavailable/invalid; compatible/incompatible/unknown; missing capability, probe conflict, malformed input và dependency unavailable.
- Matrix: version/range match, no match, ambiguous match, invalid version và evidence identity/revision.
- Adapter: valid field, missing, explicit null, invalid type, unsupported version, unreadable source, normalization, canonical boundary, precedence, fallback, template, malformed/extra input và input non-mutation.
- Registry: từng output độc lập, capability/assertion separation, unknown, conflict, duplicate `none/possible/blocked`, plugin coexistence, deterministic ordering, malformed input, AI draft flags và input non-mutation.
- Negative boundary: không network, không persistence, không writer/Apply, không publish, không output mới và `write_allowed=false` trong mọi trạng thái.

### Snapshot verification

Sau khi implementation, matrix, mapping, fixtures và tests đã chốt:

1. khóa một revision D duy nhất;
2. chạy focused contract/coexistence tests trên revision đó;
3. chạy toàn bộ `tests/Unit` trên đúng revision đó;
4. ghi receipt gồm run identity, command, scope, status, exit code, revision/base SHA và checksum của source, tests, matrix/mapping/fixtures;
5. nếu bất kỳ source, test, matrix, mapping hoặc fixture nào đổi sau run, tạo revision và receipt mới;
6. bàn giao diff và receipt cho Reviewer độc lập.

Reviewer phải xác nhận behavior, diff, checksum, read-only boundary, compatibility handling, deterministic ownership và các giới hạn chưa được chứng minh. Test pass không chứng minh production readiness, WordPress/Rank Math runtime compatibility, commercial truthfulness, quyền Apply hoặc approval.

## 6. Risks và cách xử lý

- **Thiếu compatibility evidence:** giữ `unknown`/`unsupported`, ghi rõ matrix gap; không suy từ active/latest.
- **Meta key/API thay đổi theo Rank Math version:** cô lập mapping, version-aware result; trả invalid/unsupported khi không xác nhận được.
- **Nhiều plugin cùng phát output:** decision theo từng output và duplicate risk `possible`/`blocked`; không tự tắt hoặc ghi đè plugin khác.
- **Fixture offline không phản ánh runtime WordPress:** ghi limitation; không tuyên bố production compatibility.
- **Source đổi sau verification:** khóa revision/checksum và chạy lại receipt; không dùng receipt cũ cho snapshot mới.
- **Scope creep sang runtime/Apply:** dừng và quay lại Architecture/Owner decision gate.

## 7. Handoff và không làm

SL được tự quyết số ticket, dependency DAG, mutable-file manifest và delivery order trong các outcome trên. SE tự chọn file thực tế sau discovery, miễn không vượt boundary, không mở budget tùy ý và không giảm acceptance.

Increment D chỉ bàn giao để Reviewer đánh giá sau khi có implementation snapshot và receipt riêng. Không tự đóng exit gate, không tự mở Increment E, không chuyển ownership và không xem status của detector/registry là quyền thay đổi dữ liệu.

## 8. Evidence tham chiếu

- `docs/giai-doan-2-r3.md` — roadmap đặt detector, adapter và ownership trước runtime composition.
- `docs/giai-doan-2-increment-c-r4-report.md` — trạng thái C: technical verification và acceptance gate tách biệt.
- `docs/myseo-owner-decisions.md` — Rank Math adapter, explicit ownership transfer, coexistence và không tự tắt plugin.
- `docs/myseo-architecture-principles.md` — adapter boundary, single output owner và contract-first verification.
- `docs/report-giai-doan-2-r1.md` — gap hiện trạng của detector/registry, adapter, compatibility matrix và coexistence tests.
- `src/Integrations/RankMath/RankMathDetector.php` — hiện trạng detector tối thiểu, checksum tại thời điểm khảo sát: `sha256:1f59d4d0234328957a19280d4746ceeb4f9d6dfebddc12eec4e26223b4aee9aa`.
- `src/Integrations/RankMath/OwnershipRegistry.php` — hiện trạng registry tối thiểu, checksum tại thời điểm khảo sát: `sha256:e7db1b4bf6ec779f447d4a49f6750c96c7c0d905b5d06f0ba404622d736e2393`.
- `tests/Unit/SeoCoreTest.php` — test hiện trạng và checksum tại thời điểm khảo sát: `sha256:de1aaf2587705fa439c4089627fef70a162bc7974f20a12539b5f8161875218a`.

Tài liệu này không cấp quyền triển khai network, secret, persistence, Apply, publish hoặc ownership transfer. Mọi thay đổi chạm các boundary đó phải quay lại Architecture/Owner decision gate.