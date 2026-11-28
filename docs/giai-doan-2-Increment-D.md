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

Hồ sơ C hiện có hai revision cần phân biệt: `docs/giai-doan-2-increment-c-r4-report.md` và `docs/giai-doan-2-increment-c-r4.md` ghi snapshot verification `9c0e2ed40833f784ee9350fce96f122728d3c9a7`, trong khi HEAD hiện tại có commit `9c76c628853dd63024c1e02fb44a70581b022db7` chứa source/test C mới hơn. Đây là chênh lệch hồ sơ, không phải bằng chứng revision nào đã được Owner nghiệm thu.

D chỉ chuyển từ **prepared** sang **executable** khi workflow lưu một handoff C canonical xác định duy nhất: revision được review, checksum source/test, focused và full-unit receipt, Reviewer decision, Owner decision, authority, scope và identity. Không dùng report/r4 hoặc receipt của `9c0e2ed...` để chứng minh acceptance cho `9c76...`; cũng không dùng test pass của `9c76...` để tự suy approval. Receipt C chỉ là prerequisite/evidence context, không phải verification evidence của D.

## 2. Phạm vi và loại trừ

### In scope

1. Detector Rank Math với các kết quả độc lập cho installation, activation, version, compatibility, reason, signal source và evidence state.
2. Compatibility matrix có version/range, capability, rule, nguồn bằng chứng và identity/revision.
3. Pure read adapter Rank Math, injectable source-observation contract và offline fixtures; allowlist title, `meta_description`, canonical; typed validation, normalization, precedence và fallback.
4. Ownership registry cho `title_meta` (bao gồm title, meta description và canonical), `json_ld`, `sitemap` và `ai_draft`, với decision riêng theo output.
5. Producer capability, ownership assertion, source identifier, evidence state và duplicate-output policy cho coexistence.
6. Contract/coexistence tests offline, immutable-input tests và snapshot verification evidence.

### Out of scope

- Concrete WordPress reader wiring, runtime hooks, composition root, renderer, Health Check integration đầy đủ hoặc phát sinh title/meta/JSON-LD/sitemap output.
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
- compatibility: **chỉ** `compatible`, `incompatible` hoặc `unknown`.

`unsupported` không phải compatibility state. Nếu capability hoặc matrix chưa được hỗ trợ, thể hiện bằng `reason`/`evidence_state` ổn định, thường dẫn đến compatibility `unknown`; không tự mở thêm enum.

Compatibility phải có `scope`: capability nào được đánh giá (ví dụ `read_title`, `read_meta_description`, `read_canonical`) và aggregate chỉ được gọi `compatible` khi toàn bộ capability bắt buộc của scope đã khớp matrix có bằng chứng. `compatible` không có nghĩa toàn bộ Rank Math tương thích.

Contract phải trả reason ổn định, nguồn tín hiệu và evidence identity/state đủ để truy nguyên quyết định. Tối thiểu phải có decision table cho Rank Math absent; installed nhưng inactive; active nhưng thiếu installation metadata; active với version unavailable; version invalid nhưng capability probe báo compatible; version hợp lệ nhưng thiếu capability; và các probe mâu thuẫn/chỉ quan sát một phần.

Quy tắc bắt buộc:

- `active` không đồng nghĩa `installed` và không đồng nghĩa `compatible`;
- version invalid không tự chuyển thành `incompatible`;
- chỉ trả `compatible` khi version/capability khớp matrix trong đúng scope;
- thiếu hoặc mâu thuẫn tín hiệu trả `unknown` cùng reason/evidence state, không đoán theo nhãn `latest`;
- detector không được yêu cầu Rank Math class/hook để trả trạng thái an toàn và không được gây fatal khi dependency thiếu.

Compatibility matrix phải có version/range, capability, rule, evidence source, identity/revision và ngày/cách cập nhật. Nếu repository chưa có bằng chứng đủ cho một range, giữ `unknown`; không tự đặt range tương thích.

### O2 — Rank Math read adapter

Adapter là boundary độc lập; không rải meta key/API knowledge của Rank Math vào SEO Core, analyzer hoặc registry. Adapter chỉ expose ba field: `title`, `meta_description`, `canonical`.

Mỗi field phải có closed result gồm tối thiểu `field`, `state`, `source`, `reason` và `evidence_identity`; chỉ có `value` khi dữ liệu hợp lệ. Field state được đóng đúng tập:

- `value` — đúng kiểu, qua validation và normalization;
- `missing` — source xác nhận field không tồn tại;
- `explicit_null` — source xác nhận field tồn tại với null;
- `invalid` — có dữ liệu nhưng sai kiểu/format/policy;
- `unsupported` — source/version/mapping không được hỗ trợ;
- `unreadable` — read operation không xác nhận được dữ liệu.

Không có state `unknown` cho field. Mapping ambiguity phải trả `unsupported` với reason ổn định `mapping_ambiguous`; không tự mở enum hoặc chọn mapping tùy ý.

#### Source-observation contract

Source injectable không được chỉ trả `?string`, vì như vậy không phân biệt được missing và explicit null. Mỗi read observation phải giữ được:

- presence: `present`, `missing` hoặc `unreadable`;
- `value`: arbitrary input chỉ ở boundary nội bộ để typed validation, có thể là null khi presence là `present`;
- source identifier và evidence identity;
- read error/reason an toàn nếu presence là `unreadable`.

Adapter map `present + null` thành `explicit_null`, `missing` thành `missing`, và `unreadable` thành `unreadable`. Source không đủ khả năng xác nhận presence không được giả làm `missing` và không được mở fallback. Fixture evidence phải gắn nhãn fixture/test; không được trình bày như runtime Rank Math observation.

Mapping phải ghi version/range, kiểu dữ liệu, normalization, precedence, fallback rule, source và mapping revision. Fallback **chỉ** được phép khi nguồn ưu tiên xác nhận `missing`; không fallback để che `explicit_null`, `invalid`, `unsupported` hoặc `unreadable`. Canonical phải có policy cú pháp rõ ràng (HTTP(S), host, userinfo, fragment/port nếu liên quan); không fetch, DNS lookup hay khẳng định crawlability.

Template thô, malformed input, sai kiểu, extra field và dữ liệu ngoài allowlist không được coi là frontend value hợp lệ. Tối thiểu phải chốt empty/whitespace-only, UTF-8 lỗi, control characters, giới hạn kích thước và template markers. `state=value` chỉ có nghĩa hợp lệ trong read boundary; không đồng nghĩa đã render hoặc an toàn để chèn trực tiếp vào HTML. Adapter không mutate input, không đọc arbitrary metadata, không trả raw payload không cần thiết và không đưa secret/credential vào result.

### O3 — Ownership và duplicate-output policy

Registry trả `owner`, `state`, `reason`, `duplicate_risk`, `write_allowed` riêng cho `title_meta`, `json_ld`, `sitemap`, `ai_draft`. `state` chỉ nhận `owned`, `unknown`, `conflict`; không có trường `decision_state`. `write_allowed=false` trong mọi hàng dưới đây.

Assertion là `claim`, `deny` hoặc `absent`. Chỉ tính claim cho các field được nêu rõ khi `can_emit=true` và `evidence_state=observed`: nguồn có định danh đã xác nhận **assertion**, không phải đã quan sát frontend phát output. `declared` và `missing` không đủ để chọn owner; `invalid` không làm bằng chứng dương hoặc âm; `conflict` chặn chọn owner cho output liên quan. Capability và danh sách plugin active không thay thế assertion.

Chỉ gộp bản ghi lặp khi cả identity của observation lẫn mọi dữ kiện dùng để quyết định đều giống nhau (producer, site/snapshot, output, tập field, assertion, capability, nguồn và trạng thái bằng chứng). Cùng identity nhưng khác dữ kiện là collision; không lấy bản đầu hoặc cuối. Đánh giá từng output độc lập, theo thứ tự ưu tiên từ trên xuống:

| Điều kiện | `owner` / `state` | `duplicate_risk` | `reason` |
| --- | --- | --- | --- |
| Collision identity liên quan output | `unknown` / `conflict` | `blocked` | `identity_collision` |
| Assertion claim đã quan sát trái với capability được xác nhận là tắt | `unknown` / `conflict` | `blocked` | `assertion_capability_conflict` |
| Evidence báo conflict, hoặc cùng producer vừa claim vừa deny trên cùng field ở cùng snapshot | `unknown` / `conflict` | `blocked` | `conflicting_evidence` |
| Hai producer có claim hợp lệ chồng lấn trên ít nhất một field | `unknown` / `conflict` | `blocked` | `overlapping_claims` |
| Các producer claim những phần không chồng lấn của `title_meta` | `unknown` / `unknown` | `possible` | `split_ownership` |
| Chỉ một producer có claim nhưng chưa phủ đủ title, description và canonical trong `title_meta` | `unknown` / `unknown` | `possible` | `partial_ownership` |
| Không có claim hợp lệ | `unknown` / `unknown` | `possible` | `no_valid_claim` |
| Một producer có claim phủ toàn output, đủ bằng chứng loại trừ các producer khác như dưới đây | producer / `owned` | `none` | `other_emitters_excluded` |
| Một producer có claim phủ toàn output, chưa loại trừ được tất cả producer khác | producer / `owned` | `possible` | `other_emitters_not_excluded` |

`none` chỉ áp dụng trong phạm vi **site, output và snapshot** mà một nguồn xác định (có identity và revision) xác nhận đã liệt kê đầy đủ producer. Với từng producer khác, phải có observation cùng phạm vi xác nhận nó không thể phát output đó, ví dụ capability phát đã tắt cho tất cả field liên quan. Danh sách rỗng, cờ `inventory_complete` đơn lẻ hay lời `deny` không chứng minh điều này. Nếu A claim toàn nhóm và B deny nhưng `can_emit=true`, kết quả là A / `owned` / `possible`. `blocked` chỉ chặn quyết định chọn owner; không chặn output frontend.

`ai_draft` là bản nháp nội bộ: `frontend_owned=false`, `publish_capability=false`, `write_allowed=false`; owner nội bộ không phải frontend ownership. Registry không đăng ký hook, không renderer, không thay đổi output và không chuyển ownership.

### Canonical và phạm vi output

Canonical được đọc bởi O2 và thuộc output `title_meta` trong registry D. D không claim ownership cho Open Graph, robots hoặc các SEO output chưa liệt kê; các output đó phải ở trạng thái ngoài scope, không suy luận từ `title_meta`.

## 4. Public contract, migration và implementation cho SE

1. Khảo sát consumer trước khi đổi public shape. Contract cũ hiện trả boolean/suy ownership (`RankMathDetector::status()` và `OwnershipRegistry::resolve()`); không được để registry mới âm thầm coi string state là `false`.
2. Dùng result contract có version/identity rõ cho detector và registry. Chỉ tạo legacy adapter khi discovery chứng minh có consumer thật; legacy mapping phải bảo thủ, không biến boolean/plugin name thành ownership assertion.
3. Nếu giữ compatibility shim, test cả cũ → mới và detector → registry với contract mới; nếu không có consumer, cập nhật test cũ để loại bỏ giả định `active ⇒ ownership` thay vì chỉ đổi expected value.
4. Tạo probe/interface injectable cho installation, activation, version và capability; tránh phụ thuộc global constant duy nhất trong test hoặc runtime read path.
5. Lập compatibility matrix, read mapping và source-observation contract có identity/revision trước hoặc cùng implementation. Không điền version/range theo suy đoán.
6. Cô lập mọi meta key/API mapping trong adapter. Không tái sử dụng ProviderPayload boundary để thay thế Rank Math adapter.
7. Dùng closed states và reason ổn định; không cast sai kiểu thành string, không nuốt lỗi thành `missing` nếu contract yêu cầu `invalid`/`unreadable`.
8. Chuẩn hóa/sắp xếp input theo policy để cùng evidence cho cùng output luôn cho cùng kết quả; chỉ gộp bản sao đúng quy tắc O3. Test cùng cặp observation trùng identity nhưng trái `can_emit` theo thứ tự A–B, B–A và A–B–A: cả ba lần phải giống `owner`, `state`, `duplicate_risk`, `reason=identity_collision`; output không liên quan giữ nguyên. Test riêng claim/deny khi producer deny vẫn có thể phát, claim một phần, chia field không chồng lấn và claim chồng lấn.
9. Giữ mọi read path offline/read-only. Không thêm concrete WordPress wiring, hook, writer, persistence, Apply, publish hoặc output renderer để “chứng minh” adapter.
10. Khi phát hiện public contract, security/data semantics hoặc compatibility policy hiện tại không đủ để tiếp tục, dừng tại boundary và báo Architecture; không âm thầm mở scope.

## 5. Acceptance và verification

### Contract tests bắt buộc

- Detector: mọi installation/activation/version/compatibility state; scope capability; matrix match/no-match/ambiguous; missing capability, probe conflict, malformed input và dependency unavailable.
- Matrix (O1): scope capability rỗng hoặc không hợp lệ luôn trả `unknown`, kể cả khi version khớp. Test match, no match, ambiguous match, invalid version và evidence identity/revision. Dùng matrix tổng hợp chỉ trong test để chứng minh nhánh `compatible` với scope hợp lệ. Không sao chép range giả sang matrix bàn giao: với cùng version/capability, khi không có bằng chứng thực, kết quả bàn giao phải là `unknown`.
- Adapter: valid field, missing, explicit null, invalid type, unsupported version, ambiguous mapping, unreadable source, normalization, empty/whitespace/control/UTF-8/template handling, canonical boundary, precedence, fallback, malformed/extra input và input non-mutation.
- Source boundary: source phân biệt presence; source không phân biệt presence không mở fallback; fixture provenance không bị coi là runtime observation.
- Registry: từng output độc lập, capability/assertion separation, decision table đầy đủ, unknown, conflict, duplicate `none/possible/blocked`, complete-vs-incomplete inventory, deny/claim, assertion-capability conflict, contradictory same-producer evidence, duplicate source identity, permutation/repetition, malformed input, AI draft flags và input non-mutation.
- Migration: old/new contract nếu có shim; detector → registry integration; không còn giả định `active ⇒ ownership`.
- Negative boundary: không network, persistence, writer/Apply, publish, runtime output mới; test spies/double và diff review phải corroborate, không coi unit test đơn lẻ là chứng minh tuyệt đối.

### Snapshot verification

Sau khi implementation, matrix, mapping, fixtures và tests đã chốt:

1. khóa một revision D duy nhất;
2. chạy focused contract/coexistence tests trên revision đó;
3. chạy toàn bộ `tests/Unit` trên đúng revision đó;
4. ghi receipt gồm run identity, command, scope, status, exit code, revision/base SHA và checksum của source, tests, matrix/mapping/fixtures;
5. nếu bất kỳ source, test, matrix, mapping hoặc fixture nào đổi sau run, tạo revision và receipt mới;
6. bàn giao diff và receipt cho Reviewer độc lập.

Reviewer phải xác nhận behavior, diff, checksum, read-only boundary, compatibility scope, deterministic ownership, policy semantics và các giới hạn chưa được chứng minh. Test pass không chứng minh production readiness, WordPress/Rank Math runtime compatibility, commercial truthfulness, quyền Apply hoặc approval.

### Không nghiệm thu giả vì “mọi thứ unsupported”

D có thể nghiệm thu **contract implementation** khi state/reason/fixtures và verification đầy đủ, nhưng report phải tách riêng: (1) contract behavior đã kiểm thử; (2) mapping/range đã có evidence; (3) capability/runtime chưa được xác minh. Nếu chưa có mapping/range thực được chứng minh, không gọi chung là “Rank Math compatible/supported”.

## 6. Risks và cách xử lý

- **Hồ sơ prerequisite C lệch revision:** dừng executable gate cho tới khi có canonical handoff duy nhất; không nối revision bằng suy luận từ chat/lịch sử.
- **Thiếu compatibility evidence:** giữ `unknown`, ghi rõ scope/matrix gap; không suy từ active/latest.
- **Meta key/API thay đổi theo Rank Math version:** cô lập mapping, version-aware result; trả invalid/unsupported khi không xác nhận được.
- **Nhiều plugin cùng phát output:** decision theo từng output và duplicate risk; `blocked` chỉ là registry policy, không phải frontend suppression.
- **Fixture offline không phản ánh runtime WordPress:** ghi limitation; không tuyên bố production compatibility.
- **Source đổi sau verification:** khóa revision/checksum và chạy lại receipt; không dùng receipt cũ cho snapshot mới.
- **Scope creep sang runtime/Apply:** dừng và quay lại Architecture/Owner decision gate.

## 7. Handoff và không làm

SL được tự quyết số ticket, dependency DAG, mutable-file manifest và delivery order trong các outcome trên. SE tự chọn file thực tế sau discovery, miễn không vượt boundary, không mở budget tùy ý và không giảm acceptance.

Increment D chỉ bàn giao để Reviewer đánh giá sau khi có implementation snapshot và receipt riêng. Không tự đóng exit gate, không tự mở Increment E, không chuyển ownership và không xem status của detector/registry là quyền thay đổi dữ liệu.

## 8. Evidence tham chiếu

- `docs/giai-doan-2-r3.md` — roadmap đặt detector, adapter và ownership trước runtime composition.
- `docs/giai-doan-2-increment-c-r4-report.md` và `docs/giai-doan-2-increment-c-r4.md` — hồ sơ C hiện ghi revision `9c0e2ed...` và trạng thái acceptance cần được đối chiếu; không dùng thay cho canonical handoff revision mới.
- `docs/myseo-owner-decisions.md` — Rank Math adapter, explicit ownership transfer, coexistence và không tự tắt plugin.
- `docs/myseo-architecture-principles.md` — adapter boundary, single output owner và contract-first verification.
- `docs/report-giai-doan-2-r1.md` — gap hiện trạng của detector/registry, adapter, compatibility matrix và coexistence tests.
- `src/Integrations/RankMath/RankMathDetector.php` — hiện trạng detector tối thiểu, checksum tại thời điểm khảo sát: `sha256:1f59d4d0234328957a19280d4746ceeb4f9d6dfebddc12eec4e26223b4aee9aa`.
- `src/Integrations/RankMath/OwnershipRegistry.php` — hiện trạng registry tối thiểu, checksum tại thời điểm khảo sát: `sha256:e7db1b4bf6ec779f447d4a49f6750c96c7c0d905b5d06f0ba404622d736e2393`.
- `tests/Unit/SeoCoreTest.php` — test hiện trạng và checksum tại thời điểm khảo sát: `sha256:de1aaf2587705fa439c4089627fef70a162bc7974f20a12539b5f8161875218a`.

Tài liệu này không cấp quyền triển khai network, secret, persistence, Apply, publish hoặc ownership transfer. Mọi thay đổi chạm các boundary đó phải quay lại Architecture/Owner decision gate.