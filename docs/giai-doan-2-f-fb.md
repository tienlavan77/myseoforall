# Phản biện — Increment F r2

## Kết luận

Bản r2 đã cải thiện rõ rệt: đã tách core/AI, định nghĩa envelope AEAD, khóa thứ tự nguồn secret, thống nhất kết quả URL, tách admin handler và ghi rõ phần runtime SSRF thuộc Increment G.

Tuy nhiên, tài liệu vẫn chưa nên được xem là contract hoàn toàn executable. Các điểm dưới đây cần chốt trước khi implementation.

## Blocker

### 1. Persistence hai option chưa nguyên tử

O4 yêu cầu xử lý credential trước rồi mới ghi `myseo_ai_settings`. WordPress option update không tạo transaction chung cho hai option. Nếu secret ghi thành công nhưng settings thất bại, hệ thống có thể giữ credential mới với cấu hình cũ; nếu clear thành công nhưng settings thất bại, credential có thể bị mất.

Cần chọn một trong các phương án:

- cơ chế transaction/compensation rõ ràng;
- envelope trạng thái pending và commit marker;
- hoặc quy định thứ tự, rollback và hành vi retry khi một thao tác thất bại.

Cụm “nguyên tử ở mức một option key” chỉ đúng cho riêng `myseo_ai_credentials`, chưa đúng cho toàn bộ thao tác lưu Settings.

### 2. Migration `ai_enabled` chưa có semantics khả dụng

O1 nói chỉ kế thừa `ai_enabled` khi “đi kèm cấu hình hợp lệ”, nhưng đồng thời cấm migrate plaintext `base_url` và `api_key`, và nêu rằng thiếu base URL/key thì luôn `effective_enabled = false`. Như vậy điều kiện để migration xảy ra chưa rõ và có thể không bao giờ đạt.

Cần quy định cụ thể:

- những trường nào được phép migrate;
- migration có tạo `myseo_ai_settings` khi chỉ có cờ `ai_enabled` hay không;
- khi nào core option được purge;
- migration chạy lúc đọc, lúc save hay qua hook nâng cấp;
- nếu purge thất bại thì trạng thái và lần retry tiếp theo là gì.

### 3. Core allowlist còn mâu thuẫn

O1 nói core chỉ có bốn khóa, nhưng lại cho phép `rank_math_mode` và `sitemap_owner` nếu được thêm vào `ALLOWED_KEYS`. Trong source hiện tại, `Plugin::safeConfiguration()` đã đọc hai khóa này. Cần chốt chúng là core fields chính thức hay phải loại bỏ, rồi cập nhật migration và regression tests tương ứng.

Evidence hiện tại:

- `src/Core/Configuration/Configuration.php` — `sha256:b73646e9f53d41a407d83dff5b0956983a13daf8ea7086edfa73ca66e89fc4a3`
- `src/Core/Plugin.php` — `sha256:82f72c873119f188e2e67512c641d5aff9f94499304725f51cab30efe126258c`

## Major

### 4. Allowlist hostname có hai nguồn authority

Chữ ký validator nhận `$allowedInternalHosts`, trong khi policy nói allowlist chỉ được lấy từ hằng số hệ thống `MYSEO_AI_ALLOWED_GATEWAY_HOSTS`. Nếu caller có thể truyền mảng tùy ý, một admin hoặc adapter sai có thể mở hostname nội bộ.

Cần quy định mảng tham số chỉ là dependency injection cho test, hoặc bỏ tham số này khỏi public API và để validator tự đọc immutable system policy. Phải có test chứng minh allowlist không thể đến từ form Settings.

### 5. Percent-encoding mới chỉ decode một lần

O3 cấm `%2e`, `%2f`, `%5c` sau một lần decode, nhưng chưa nói rõ với double encoding như `%252e%252e`. Cần khóa invariant rằng downstream transport không decode thêm, hoặc từ chối mọi chuỗi có thể trở thành traversal sau các bước decode hợp lệ.

### 6. AEAD fallback và lỗi crypto chưa hoàn toàn đóng

Envelope đã rõ hơn, nhưng còn thiếu:

- hành vi khi `hash_hkdf()`, `random_bytes()` hoặc `openssl_encrypt()` trả lỗi;
- độ dài tag AES-GCM bắt buộc và cách xác thực AAD;
- cách bảo đảm payload plaintext có đúng một format duy nhất trước khi mã hóa;
- chiến lược re-encrypt khi key material thay đổi.

Mọi lỗi này phải trả trạng thái đóng và tuyệt đối không gọi `OptionStore::update()`.

### 7. Semantics của clear khi credential là read-only còn mơ hồ

O2 ghi “bỏ qua xóa DB hoặc chỉ dọn phần DB mồ côi”. Đây là hai hành vi khác nhau và có ảnh hưởng vận hành khác nhau. Cần chọn một hành vi duy nhất, gồm status trả về, thông báo UI và việc có cho phép xóa ciphertext cũ hay không.

### 8. Composition vẫn còn lựa chọn thay thế

O4 nói truyền `$aiSettingsController` “hoặc” truyền repository + secret store vào `AdminModule`, dù phần đầu yêu cầu một phương án composition duy nhất. Cần chọn đúng dependency graph và tên service cụ thể; nếu không, hai implementation khác nhau vẫn đều phù hợp tài liệu.

### 9. Runtime SSRF contract phụ thuộc curl cụ thể

O3 yêu cầu `CURLOPT_RESOLVE`, nhưng Increment G có thể dùng WordPress HTTP API hoặc transport không dựa trên cURL. Nên khóa contract ở mức abstract transport: IP đã kiểm định phải được pin tới request, redirect bị cấm, TLS verification bắt buộc; chỉ nêu `CURLOPT_RESOLVE` như một adapter cụ thể.

### 10. Acceptance còn placeholder và thiếu trạng thái kiểm chứng

AC5 vẫn dùng `<F_SNAPSHOT_SHA>`. Đây là placeholder hợp lệ trong kế hoạch, nhưng cần ghi rõ chỉ được thay bằng SHA thật trong report sau implementation và không được coi là evidence hiện tại.

AC2 cũng liệt kê `encryption_unavailable` như read state dù trạng thái này chủ yếu phát sinh khi write. Cần định nghĩa state machine đọc/ghi riêng để test không phải tự suy diễn.

## Khuyến nghị chốt trước implementation

1. Khóa transaction/rollback cho thao tác ghi hai option.
2. Chốt migration một chiều và purge failure semantics.
3. Chốt core allowlist chính thức.
4. Loại bỏ hoặc giới hạn public hostname allowlist parameter.
5. Chốt double-encoding và abstract transport contract.
6. Tách rõ CredentialStatus đọc với SaveResult ghi.
7. Thay mọi lựa chọn “hoặc” trong composition bằng một dependency graph duy nhất.

## Evidence

- Tài liệu được phản biện: `docs/giai-doan-2-f.md`, SHA-256 `694d7f315dac5aa8a346f808c0a757fcf2ba65e102150c28daaadf423e85eb16`.
- Bản r1 được tài liệu tham chiếu: SHA-256 `0660ec19833e06e191e2575ba6ec92faa90b8802cc93ac75f96b4b70565d8b71`.
- `src/Infrastructure/WordPress/OptionStore.php`, SHA-256 `0c574397ec58c8f235e620fbdca7b02f6c3c3fd93fb40a4f0c3cd525735c9f73`.
- `src/Admin/AdminModule.php`, SHA-256 `7ea9772836096cc431745950bb95e7a609f2ead1fd8ab1515dbe5649c075b089`.

## Phản biện bổ sung cho r3

### 11. Quy tắc multi-pass decoding có thể từ chối mọi path hợp lệ

O3 yêu cầu ở mỗi vòng decode phải từ chối khi xuất hiện dấu `/`. Nhưng các URL hợp lệ như `https://gateway.example.com/v1/messages` vốn đã chứa `/` trong path. Implementation phải phân biệt slash có sẵn trong path gốc với slash/backslash/control char được tạo ra bởi percent-decoding và bị cấm, cũng như segment `.` hoặc `..` xuất hiện sau decode.

Nếu không tách được ba trường hợp này, acceptance mẫu `/v1` sẽ tự mâu thuẫn với rule chống encoded slash.

### 12. Allowlist hostname và private IP sau resolve chưa hoàn toàn rõ

Tài liệu nói hostname nội bộ có thể được allowlist, đồng thời nói private/loopback/link-local/reserved IP bị chặn. Phần ngoại lệ chỉ nêu rõ loopback và metadata luôn bị chặn; chưa nói dứt khoát liệu hostname được allowlist có được resolve tới RFC1918/CGNAT hay không.

Cần ghi rõ một trong hai policy: mọi private/reserved resolved IP luôn bị chặn, kể cả hostname trong system allowlist; hoặc cho phép một tập private CIDR cụ thể, có owner và mục đích rõ ràng. AC3 nên có test cho hostname được allowlist nhưng resolve tới `10/8`, `172.16/12`, `192.168/16`, `fc00::/7` và `100.64/10`.

### 13. Rollback snapshot chưa bao phủ mọi trạng thái “absent”

Credential read state coi `null`, `false` và `[]` đều là absent. Nhưng protocol rollback chỉ gọi `delete()` khi snapshot là `null` hoặc `false`; snapshot `[]` sẽ được khôi phục bằng `update([])`, tạo hành vi khác với state machine đọc và có thể làm acceptance rollback không nhất quán.

Cần chuẩn hóa một predicate `isAbsentCredentialOption()` dùng chung cho đọc, clear và rollback, rồi test riêng ba giá trị `null`, `false`, `[]`.

## Phản biện bổ sung sau khi đọc tiếp bản hiện tại

### 14. `validateResolvedIps()` cần ràng buộc với static validation

Public method nhận trực tiếp một chuỗi `$normalizedUrl`. Nếu caller gọi thẳng method này với URL chưa qua `validateStaticUrl()`, có nguy cơ bỏ qua scheme/host/port/path policy và chỉ chạy kiểm tra IP.

Cần chọn một contract duy nhất:

- `validateResolvedIps()` tự gọi lại static validation trước khi resolver chạy; hoặc
- method nhận một value object/result token chỉ được tạo bởi `validateStaticUrl()`, không nhận string tùy ý.

AC3 nên có test gọi trực tiếp với URL sai scheme, direct-provider host và private IP để chứng minh fail-closed.

### 15. Snapshot rollback chưa xử lý ghi đồng thời

Snapshot-and-compensating-rollback chỉ đúng khi không có hai admin request chạy xen kẽ. Hai request có thể cùng đọc snapshot cũ, một request ghi thành công rồi request kia rollback theo snapshot đã lỗi thời, làm mất thay đổi hợp lệ.

Cần chốt lock/version/CAS cho `myseo_ai_settings` và `myseo_ai_credentials`, hoặc quy định rõ mutex ở application layer và test hai lần lưu đồng thời. Rollback không nên dùng snapshot cũ nếu option đã thay đổi bởi request khác.

### 16. Encoded query/fragment chưa được khóa

O3 cấm query/fragment trong URL thô, nhưng multi-pass path decoding hiện tập trung vào slash, backslash, control char và traversal. Các segment như `%3fquery`, `%23fragment` hoặc dạng encode lồng có thể trở thành `?`/ `#` nếu transport tiếp tục decode.

Cần hoặc từ chối `?` và `#` xuất hiện sau mọi vòng decode, hoặc ghi invariant rằng mọi downstream transport tuyệt đối không decode thêm và test invariant đó.

### 17. `readPlaintext()` cần boundary bảo mật rõ hơn

O2 đặt tên API `AiSecretStore::readPlaintext()`, nhưng chưa quy định caller nào được phép gọi, plaintext tồn tại trong bộ nhớ bao lâu và cấm truyền nó sang health/UI/log/exception. Nên giới hạn plaintext vào một interface nội bộ phục vụ provider ở Increment G, hoặc đổi contract để F chỉ trả `CredentialStatus` và một capability dùng một lần.

### 18. Write-state enum mâu thuẫn với hành vi read-only

O2 tuyên bố save()/clear() chỉ có đúng **5 trạng thái ghi**, nhưng quy tắc clear_api_key khi Constant/Env override lại yêu cầu trả status = 'rejected_read_only'. Trạng thái này không nằm trong danh sách 5 trạng thái và cũng khác với 'read_only_environment'. Cần chọn một enum duy nhất, ghi nó vào state machine và AC2; nếu rejected_read_only chỉ là status của controller thì phải nói rõ AiSecretStore::clear() trả giá trị nào.

### 19. Input API key mới chưa có contract validation độc lập

Regex độ dài 16–512 chỉ được nêu cho Constant/Env. save(string $apiKey) không quy định rõ có trim hay không, có chấp nhận chuỗi rỗng/whitespace/control byte hay không, và trả lỗi nào khi đầu vào sai. Việc “trim trước khi mã hóa” ở plaintext format không thay thế validation đầu vào: hai API key khác nhau có thể bị biến thành cùng một giá trị. Cần thêm trạng thái lỗi input hoặc quy định validation/normalization duy nhất trước bước mã hóa và test boundary 15, 16, 512, 513 byte.

### 20. Bảo đảm autoload = false chưa gắn với thao tác lưu

Contract gọi myseo_ai_settings và myseo_ai_credentials là autoload = false, nhưng OptionStore::update() không tự thay đổi cờ autoload của option đã tồn tại, còn chữ ký OptionStoreContract chỉ có get/update/delete. Chưa có quy định option mới được tạo bằng add_option(..., false) hay cách kiểm chứng cờ autoload sau lần lưu đầu tiên. Cần chốt write primitive và acceptance cho cả option chưa tồn tại lẫn option đã tồn tại.

### 21. load() chưa định nghĩa fail-closed cho dữ liệu AI hỏng

O1 chỉ nêu defaults khi option chưa có. Chưa quy định hành vi khi myseo_ai_settings là scalar, thiếu khóa, sai kiểu, sai enum, có revision âm, hoặc chứa khóa lạ; cũng chưa nói có được tự sửa DB trong đường đọc hay chỉ trả defaults kèm warning. Nếu mỗi implementation tự chọn fallback, isReady(), health và optimistic revision sẽ có semantics khác nhau. Cần một hàm normalize/validation đọc duy nhất, kết quả đóng và test cho từng dạng corruption.

### 22. Dependency graph chưa đăng ký dependency của write lock

O4 yêu cầu handlePost() nhận AiSettingsWriteLockContract và dùng lock trước snapshot, nhưng graph “4 service identifiers mới chính xác” chỉ đăng ký validator, secret store, repository và controller; constructor controller cũng không nhận lock. Chưa có nơi tạo/inject lock, nên graph hiện tại không thể thực thi đúng O4. Cần thêm lock vào graph và constructor hoặc quy định rõ controller tự tạo implementation nào (kèm lý do không dùng global/static lock).

### 23. TTL lock có thể hết hạn khi request hợp lệ vẫn đang chạy

Lock 15 giây không có lease renewal, cũng không có quy tắc request phải fail trước khi hết TTL. Một request chậm có thể bị coi là stale, request khác xóa/reclaim lock rồi ghi; request đầu tiên vẫn tiếp tục và cuối cùng release theo token cũ. “Chỉ thu hồi stale lock khi hết hạn” chưa giải quyết race giữa kiểm tra hết hạn và reclaim. Cần chốt cơ chế lease/renewal hoặc giới hạn thời gian xử lý và test request vượt TTL.

### 24. “Immutable system policy” vẫn cho phép authority từ callable

Contract cấm caller truyền allowlist từ Settings/form nhưng đồng thời cho phép systemHostPolicyReader tùy chọn qua constructor. Chưa định nghĩa callable được gọi một lần rồi đóng băng hay có thể trả policy khác theo request, cũng chưa có schema cho giá trị constant (list/string, wildcard, duplicate, IDN, case folding). Cần xác định injection chỉ là fixture test và production luôn đọc policy cố định; khóa normalization và giới hạn policy trước khi so khớp.

### 25. Static URL canonicalization và capability của IDN chưa đủ executable

Contract yêu cầu normalized URL và UTS-46 idn_to_ascii, nhưng chưa chốt canonical output (host lowercase, path slash cuối, percent-encoding giữ hay chuẩn hóa) hoặc hành vi khi extension intl/idn_to_ascii không có. Hai URL tương đương có thể tạo hai base_url, làm sai revision/conflict và cache. Cần fail-closed khi không thể chuẩn hóa, định nghĩa canonical form duy nhất và test URL tương đương.

### 26. Purge trong AdminModule::sanitizeSettings() trộn hàm sanitize với side effect

sanitizeSettings() thường là callback lọc dữ liệu form, trong khi O1 yêu cầu nó đọc rồi ghi lại myseo_core_settings. Contract chưa nói purge chạy trước hay sau khi sanitize, có chạy khi validation thất bại không, và kết quả purge được trả ở đâu. Nếu callback bị gọi nhiều lần, side effect có thể xảy ra ngoài một lần submit. Cần tách purge thành operation write rõ ràng hoặc khóa điều kiện/lifecycle và bổ sung acceptance cho lỗi purge, retry và không ghi khi form invalid.

### 27. AC5 chưa định nghĩa tripwire có thể quan sát toàn bộ network/DNS

Yêu cầu networkCalls === 0 và dnsCalls === 0 trên “toàn bộ lifecycle” nhưng chưa chỉ ra seam/instrumentation nào đếm các lời gọi từ PHP, WordPress hooks, gethostbyname, cURL và stream transport. Một test chỉ spy resolver sẽ không chứng minh không có DNS call ngoài resolver. Cần inject các adapter cấm network hoặc dùng tripwire runtime cụ thể, nêu phạm vi đo và fixture chứng minh counter bắt được cả lời gọi gián tiếp.

### 28. Fencing metadata phá vỡ closed schema

O1 khóa AiSettings ở đúng 10 khóa canonical, còn envelope credential được mô tả là 7 khóa. O4 lại yêu cầu ghi thêm last_write_fence vào cả myseo_ai_settings và envelope. Như vậy dữ liệu lưu thực tế có 11/8 khóa và AC1 sẽ đánh dấu mọi bản ghi sau khi ghi là corrupted. Cần đưa fencing metadata ra option riêng hoặc đưa nó vào schema canonical và cập nhật toàn bộ count, hash/validation và rollback.

### 29. Reclaim lock chưa có primitive CAS bắt buộc

Contract nói ưu tiên wpdb conditional update nhưng cho phép verify-after-write qua OptionStore. Verify sau khi ghi không ngăn Request C ghi đè giữa thao tác ghi và lần đọc xác nhận, nên hai request có thể cùng nhận lock với fence khác nhau. Cần một primitive compare-and-swap nguyên tử bắt buộc cho production; verify-after-write chỉ nên là fixture không đại diện cho WordPress runtime.

### 30. Lease hết hạn sau Step 4a có thể để lại partial write

Nếu credential đã ghi ở Step 4a nhưng lease mất trước Step 4b, controller bị cấm rollback vì không còn lease hợp lệ. Khi đó credential mới tồn tại nhưng Settings cũ vẫn còn, trong khi contract không có pending marker hoặc recovery job. Cần định nghĩa trạng thái partial write và cơ chế khôi phục an toàn; chỉ trả write_lease_expired là chưa đủ để bảo toàn tính nhất quán hai option.

### 31. Success state của AiSettingsLoadResult chưa được định nghĩa

O1 chỉ mô tả state absent và corrupted, nhưng Plugin::health() và AC4 yêu cầu ai_settings_state = 'ok'. Chưa có điều kiện chính xác để trả ok (đủ 10 khóa, không khóa lạ, normalized URL, revision hợp lệ) và chưa nói load result có giữ corruption_reason ở trạng thái ok hay không. Cần đóng enum và thêm test cho bản ghi canonical hợp lệ.

### 32. Chữ ký save() loại trừ trạng thái invalid_key_type

Validation contract nhận mixed và yêu cầu invalid_key_type, nhưng public API vẫn là save(string $apiKey). Input không phải string sẽ gây TypeError trước khi validateApiKeyFormat() chạy, nên trạng thái này không thể quan sát qua AiSecretStore. Cần đổi boundary thành mixed hoặc xác định type check ở controller trước khi gọi và bỏ trạng thái khỏi secret store.

### 33. Scoped callback vẫn chưa phải capability được enforce

Mô tả nói withAuthorizedHeader() là seam nội bộ, nhưng chưa chỉ rõ visibility/interface hoặc cách ngăn caller lưu callback, ném exception chứa header, hay giữ lại mảng header sau khi callback kết thúc. sodium_memzero không bảo đảm xóa mọi bản sao PHP zval và cũng không áp dụng khi chỉ có OpenSSL. Cần quy định capability private/internal, callback error redaction và giới hạn lifetime có thể kiểm thử.

### 34. Contract kết quả DNS resolver còn mở

validateResolvedIps() chỉ nhận callable và nói truyền host cho resolver, nhưng không định nghĩa resolver phải trả list IP canonical, xử lý lỗi/timeout, thứ tự và duplicate, IPv4-mapped IPv6, hay trường hợp trả scalar/malformed IP. Không có contract này, cùng một hostname có thể cho kết quả khác nhau và bypass kiểm tra qua giá trị không được chuẩn hóa. Cần DTO/result schema và fail-closed cho mọi kết quả không hợp lệ.

### 35. Multi-pass percent decoding còn khe hở với percent chưa hoàn chỉnh

Quy tắc chỉ nói còn %XX sau ba lượt thì lỗi. Chuỗi như %2525 có thể hội tụ thành ký tự % trần; nếu ký tự này được đưa vào normalized_url thì output không còn là URI canonical và downstream có thể diễn giải khác. Cần cấm mọi percent trần sau vòng cuối hoặc canonicalize lại thành %25 trước khi trả kết quả, rồi thêm fixture cho %25, %2525 và malformed residual percent.

### 36. Tripwire stream wrapper chưa bao phủ mọi đường network

Stream wrapper và WordPress filter không bắt cURL trực tiếp, socket extension, DNS functions ngoài resolver hoặc HTTP client khác. Việc unregister global wrappers trong setUp() còn có thể làm hỏng test song song và code cleanup khi test fail. AC5 cần nêu rõ cURL/socket tripwire hoặc cấm/khóa các capability đó ở process test, đồng thời bảo đảm restore trong finally và có positive controls cho từng đường gọi.

### 37. Lease timestamp không thể dùng monotonic clock xuyên request

Lock record lưu acquired_at/expires_at trong WordPress option, nhưng contract đồng thời yêu cầu injectable monotonic/unix clock. Monotonic clock thường chỉ có ý nghĩa trong một process và không thể so sánh an toàn giữa PHP workers; dùng nó cho timestamp persisted có thể làm lock hợp lệ bị coi là stale hoặc ngược lại. Cần dùng wall-clock UTC cho lease persisted, monotonic clock riêng cho budget trong request, và test clock jump giữa hai workers.

### 38. Corrupted revision có hai nguồn sự thật

load() bảo toàn preservedRevision từ bản ghi hỏng, nhưng Bước 4.1 lại đọc currentRevision trực tiếp từ raw option. Khi raw scalar, revision sai kiểu hoặc có revision hợp lệ nhưng các khóa khác hỏng, UI có thể kiểm tra một revision còn repository trả fallback revision khác. Cần một nguồn revision canonical dùng chung cho load, expected_revision, ghi mới và force reset.

### 39. Purge core vẫn có lost-update race

purgeLegacyCoreKeys() đọc myseo_core_settings rồi dùng update() với bản sanitized, nhưng không có revision/CAS. Một request khác có thể thêm thay đổi core hợp lệ giữa hai thao tác và bị purge ghi đè. Việc chạy purge ở activate() cũng không dùng write lock của AI. Cần khóa phạm vi purge, CAS hoặc merge theo allowlist để không xóa cập nhật hợp lệ.

### 40. Chuyển autoload bằng delete rồi add không có recovery contract

putNonAutoload() được phép delete_option() rồi add_option() khi option cũ autoload=yes. Nếu add thất bại sau delete, secret/settings có thể mất dù caller chưa thực hiện bước ghi nghiệp vụ; contract chỉ định nghĩa storage_write_failed nhưng không nêu snapshot/restore cho chính thao tác đổi autoload. Cần primitive atomic phù hợp hoặc trạng thái recovery rõ ràng trước khi coi autoload migration là thành công.

[CRITIQUE: DISCUSS]
