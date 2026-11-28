# Giai Đoạn 3 — Increment 3B (Revision 3): Hướng dẫn chỉnh sửa sau kiểm toán `increment-3b-r2-rp.md`

## 1. Kết luận nghiệm thu

- **Trạng thái:** **CHƯA ĐẠT (`REJECTED — REQUIRES R3 FIXES`)**.
- **Tài liệu đối chiếu:**
  - Đặc tả chuẩn: `docs/giai-doan-3/increment-3b.md` (`sha256:1c506342e02185b7e8ec034e1dbaf5c150ebac7da22cd9d4226be4ccef153dc3`).
  - Hướng dẫn R2: `docs/giai-doan-3/increment-3b-r2.md` (`sha256:c07cba6d37b200a8fe70e0945e31036e6df2bbcfaf746602893c163d0d6fab5c`).
  - Báo cáo SE R2: `docs/giai-doan-3/increment-3b-r2-rp.md` (`sha256:0854b8937cc4108e8ac9b0c5df8c4becfbf591dc45e29161401b2889047d50d6`).
- **Điểm đạt cần giữ nguyên:**
  - `myseoforall.php` hoàn toàn không bị chỉnh sửa (`sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`).
  - `ApplyToken` đã ký/verify đúng chuỗi base64url, kiểm tra padding chặt và hỗ trợ `$clock` injectable.
  - Chỉ đăng ký `wp_ajax_myseo_ai_apply` (không có `nopriv`).
- **Lý do bác bỏ:** Báo cáo R2 xác nhận `Increment3BTest.php` đang **FAILED (exit 1)**; kiểm toán mã nguồn R2 chỉ ra trực tiếp nguyên nhân làm fail PHPUnit, kèm lỗi crash `SeoModule::register()` khi vắng `AUTH_KEY`, lỗi chặn nhầm newline `\n` trong mô tả sản phẩm, thiếu phát hành confirmation token cho Rank Math, thiếu đọc canonical `_myseo_*` trong `$seoOverrideResolver`, và bỏ sót hoàn toàn UI Apply trong metabox (`3B2-6`).

---

## 2. Snapshot các file R2 đã kiểm toán

- `src/SEO/Runtime/ApplyController.php` — `sha256:0173d5dae7f1d773ff9b09162e1ac9be9784be944a64d37b24352fa26c2feb77`
- `src/SEO/Runtime/ApplyFingerprint.php` — `sha256:77c4b31efc384732f3a8e060f69ffe176f5d27357323a3a7418a82e01e10192d`
- `src/SEO/Runtime/ApplyToken.php` — `sha256:5d77056fa090ae9f86cd43e055adc45b8ecf75f0bf1ce6b307fff55fc8aac43e`
- `src/SEO/Runtime/CanonicalSeoMetaRepository.php` — `sha256:c5be004672381927e3f5cf96b2e5d53e25894e50c5b33443922dd45447634f77`
- `src/SEO/Runtime/WooCommerceDescriptionWriter.php` — `sha256:01ed61b2b66901e4cb5a321ce627f9077d7408026704d70ed3c43dbf342bc84d`
- `src/SEO/Runtime/EditorPreviewController.php` — `sha256:c44acb01ff6ba0cdd2f611862260199f56b9b7d8b1fff06380a46b21e2f8fd7c`
- `src/SEO/Runtime/SeoModule.php` — `sha256:712c0c4df5bd4e8377fd4564743d7fc610f10562ff89764c6d20060e7e3b978e`
- `src/SEO/Runtime/WordPressContentReader.php` — `sha256:6825e87e24b2a065b9f951d55663e8cc1a3c2c7b7de0a4f66096b7502127141c`
- `src/SEO/Runtime/WooCommerceProductReader.php` — `sha256:b097c1678f1d1dc0cf613e3792ebde9750d7f59070a3d458c633ba67134c7416`
- `src/SEO/Context/ProviderPayloadBuilder.php` — `sha256:51502841f2e5ee78d1f87ae54ff25013ba4a07119d4d05363bc0a0ed3a6d2901`
- `tests/Unit/Increment3BTest.php` — `sha256:23fe5a6f7cd040045ec66df9edc3563ab81ec3bf4b215847475a77e8783bc073`

---

## 3. Danh mục lỗi bắt buộc sửa dứt điểm trong R3 (`3B3-1` .. `3B3-8`)

### `3B3-1` [Blocker — Nguyên nhân trực tiếp làm `Increment3BTest` exit 1] Lỗi double-escape `\\r\\n` trong 3 file runtime
- **Hiện trạng:**
  - `ApplyFingerprint.php:24`: `str_replace(["\\r\\n", "\\r"], "\\n", $value)` đang tìm chuỗi ký tự backslash + `r` thay vì ký tự điều khiển CR/LF thật! Do đó trong `Increment3BTest::testFingerprintIsDeterministicAndScoped()` (dòng 44–46), `"  Hello\r\nworld  "` giữ nguyên `\r\n` và không bao giờ khớp `"Hello\nworld"` → **fail test ngay lập tức**.
  - Cùng lỗi double-escape này lặp lại tại `EditorPreviewController.php:125` và `SeoModule.php:158`, trong khi `ApplyController.php:51` lại dùng đúng `["\r\n", "\r"]` → dẫn tới `apply_token` do Preview phát hành sẽ lệch `value` với `ApplyController` bất cứ khi nào đề xuất có xuống dòng!
- **Cách sửa:** Đổi toàn bộ sang ký tự xuống dòng thật `str_replace(["\r\n", "\r"], "\n", $value)` ở cả `ApplyFingerprint.php`, `EditorPreviewController.php` và `SeoModule.php` (tốt nhất gọi chung 1 helper tĩnh `ApplyFingerprint::normalizeText(string $value): string`).

### `3B3-2` [Blocker — Gây crash `RuntimeCompositionTest` / `Plugin::boot()`] `SeoModule::register()` gọi `get(ApplyToken::class)` không điều kiện
- **Hiện trạng (`SeoModule.php:114-208`):** Các biến `$fingerprint` và `$resolver` chỉ được khởi tạo bên trong khối `if ($this->container->has(ApplyToken::class))` (dòng 114–168), nhưng ở dòng 205–207 khi tạo `EditorPreviewController`, code gọi thẳng `$this->container->get(ApplyToken::class)` và truyền `$fingerprint`, `$resolver` không điều kiện! Khi chạy môi trường không định nghĩa `AUTH_KEY` (bao gồm `RuntimeCompositionTest` và `RuntimeCompositionSmokeTest`), `Container::get()` ném exception và biến `$fingerprint`/`$resolver` undefined!
- **Cách sửa:** Khởi tạo `$fingerprint = $this->container->get(ApplyFingerprint::class)` và `$resolver` độc lập (hoặc gán `$applyToken = $this->container->has(ApplyToken::class) ? $this->container->get(ApplyToken::class) : null`), rồi truyền an toàn `$applyToken`, `$fingerprint`, `$resolver` vào `EditorPreviewController`. Khi `$applyToken === null`, Preview vẫn hoạt động chế độ read-only 3A bình thường mà không crash.

### `3B3-3` [Blocker] `ApplyController` chặn nhầm ký tự xuống dòng `\n` hợp lệ và lỏng điều kiện `canonical`
- **Hiện trạng (`ApplyController.php:48-54`):**
  1. Dòng 48 kiểm tra `preg_match('/<[^>]*>|javascript:|[[:cntrl:]]/i', $value) === 1` **trước** khi chuẩn hóa `\r\n` → `\n`. Trong PCRE, `[[:cntrl:]]` khớp cả `\r` (`0x0D`) và `\n` (`0x0A`), trong khi `AiOutputValidator` cho phép multiline trong `product_description_draft` (`MAX_DRAFT_CODE_POINTS = 2000`)! Mọi bản nháp mô tả sản phẩm có xuống dòng đều bị từ chối oan `422 invalid_proposal`.
  2. Dòng 52 thiếu ngoặc nhóm toán tử `&&`/`||` và chưa giới hạn độ dài theo từng field (`title`/`meta_description` đơn dòng tối đa 300 code points như `AiOutputValidator`, `description` tối đa 2000 code points, `canonical` đơn dòng `http/https` hợp lệ không khoảng trắng/control chars).
  3. Dòng 44 chỉ cho phép `$task === 'title_meta_suggestion'`, khiến `field = 'canonical'` đi kèm `title_meta_suggestion` không khớp với `AiOutputValidator` (vốn chỉ xuất `target_field` là `title` hoặc `meta_description`), hoặc không có cách Apply `canonical` hợp lệ.
- **Cách sửa:**
  - Chuẩn hóa `\r\n`/`\r` → `\n` trước, kiểm tra UTF-8 hợp lệ (`preg_match('//u', $normalized) === 1`).
  - Chặn control chars nguy hiểm bằng `/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/` cho `description` (cho phép `\n`), và chặn thêm `[\r\n\t]` (bắt buộc single-line) cho `title`, `meta_description`, `canonical`.
  - Áp giới hạn độ dài rõ ràng (`mb_strlen` / `preg_match` UTF-8): `title` & `meta_description` `1..300`, `canonical` `1..2048` + `filter_var($normalized, FILTER_VALIDATE_URL)` + `^https?://` có host hợp lệ, `description` `1..2000`.
  - Khóa rõ mapping `task` ↔ `field`: `title_meta_suggestion` cho `article|page` với `title|meta_description`; với `canonical` trên `article|page`, cho phép task chuyên biệt `canonical_suggestion` (hoặc `title_meta_suggestion` khi token server phát hành đúng `field = 'canonical'`) nhất quán giữa `ApplyController` và `$resolver` trong `SeoModule`.

### `3B3-4` [Blocker] Luồng xác nhận Rank Math Ownership chưa khép kín từ Preview sang Apply
- **Hiện trạng (`ApplyController.php:72-87`, `EditorPreviewController.php:128-130`):** `ApplyController` đòi `$request['confirmation']` phải là một `ApplyToken` hợp lệ có `purpose = 'ownership_confirmation'` và `apply_token_hash = hash('sha256', $token)`. Tuy nhiên `EditorPreviewController::handlePreviewRequest()` chỉ gắn cờ `'ownership_confirmation_required' => true` mà **không hề phát hành token confirmation này**, khiến phía UI/người dùng không bao giờ có giá trị hợp lệ để gửi lên khi xác nhận!
- **Cách sửa:** Khi `requires_confirmation === true`, `EditorPreviewController::handlePreviewRequest()` phát hành kèm `'ownership_confirmation_token' => $this->applyToken->issue([...])` với đúng bộ claims mà `ApplyController` kiểm tra (`purpose`, `post_id`, `resource_type`, `task`, `field`, `fingerprint`, `apply_token_hash`). Chỉ khi người dùng tích checkbox xác nhận rõ ràng trên UI sau khi đọc cảnh báo Rank Math thì JS mới gửi `confirmation = data.ownership_confirmation_token` lên `myseo_ai_apply` (nếu không tích, không gửi `confirmation` → server trả đúng `409 ownership_conflict`).

### `3B3-5` [Blocker] Bổ sung đọc canonical `_myseo_*` vào `$seoOverrideResolver` trong `SeoModule`
- **Hiện trạng (`SeoModule.php:68-97`):** `WordPressContentReader` và `WooCommerceProductReader` đã chấp nhận `'source' => 'myseo_for_all'`, nhưng `$seoOverrideResolver` trong `SeoModule` vẫn chỉ đọc `RankMathReadAdapter` mà **chưa đọc `CanonicalSeoMetaRepository`**! Hệ quả là sau khi người dùng Apply thành công `_myseo_title` hoặc `_myseo_meta_description`, lần mở metabox / Preview kế tiếp vẫn không thấy giá trị canonical của My SEO For All!
- **Cách sửa:** Trong `SeoModule` (`$seoOverrideResolver`), đọc `CanonicalSeoMetaRepository` (`_myseo_title`, `_myseo_meta_description`, `_myseo_canonical`):
  - Khi Rank Math **không** sở hữu `title_meta` (hoặc không có giá trị Rank Math cho slot đó), nếu `_myseo_*` có giá trị khác `null` thì trả `['state' => 'value', 'value' => $val, 'source' => 'myseo_for_all']`.
  - Khi Rank Math đang active & sở hữu `title_meta` và có giá trị Rank Math hợp lệ, giữ nguyên hiển thị Rank Math theo coexistence policy của 3A; đồng thời snapshot của Apply (`$resolver`) vẫn đọc trực tiếp canonical target (`CanonicalSeoMetaRepository::read()` / `WooCommerceDescriptionWriter::read()`) cộng với source field liên quan của đối tượng theo Mục 3.1 & 4 của `increment-3b.md`.
  - Bổ sung kiểm tra `$this->wooAvailable()` cho `product` bên trong `$resolver` của `SeoModule` (nếu WooCommerce không khả dụng thì trả `'unsupported_resource'`, và nếu `wc_get_product($postId)` không trả về product hợp lệ khi hàm tồn tại thì trả `'not_found'`).

### `3B3-6` [Blocker — Bị bỏ sót ở R2] Hoàn thiện Metabox UI cho Controlled Apply (`EditorPreviewController::renderMetabox`)
- **Hiện trạng (`EditorPreviewController.php:175, 244-317`):** Vẫn chỉ có `data-nonce` của `myseo_ai_preview` và nút `Sao chép`, hoàn toàn chưa có UI Apply!
- **Cách sửa:**
  1. Gắn thêm `data-apply-nonce="' . $this->escape($this->applyNonce()) . '"` trên `.myseo-for-all-metabox` (tạo bằng `$this->nonceFactory` hoặc `wp_create_nonce('myseo_ai_apply')`, giữ nguyên `data-nonce` cho `myseo_ai_preview` để không gãy `Increment3ATest`).
  2. Trong JS xử lý kết quả Preview (khi `data.task !== 'seo_analysis'` và có `data.apply_token` + `data.source_fingerprint`):
     - Nếu `data.ownership_confirmation_required === true`, hiển thị hộp cảnh báo rõ bằng DOM API (`textContent`) kèm `<label><input type="checkbox"> Tôi hiểu Rank Math đang quản lý SEO và xác nhận chỉ lưu vào dữ liệu chuẩn của My SEO For All.</label>`.
     - Render nút `button` (`type="button"`, class `button button-primary myseo-ai-apply`) nhãn `'Áp dụng vào My SEO'` (hoặc `'Áp dụng mô tả sản phẩm'` cho product).
     - Khi bấm nút Apply: gửi `POST` tới `box.dataset.ajaxUrl` với `action=myseo_ai_apply`, `post_id`, `_ajax_nonce=box.dataset.applyNonce`, `resource_type=data.resource_type`, `task=data.task`, `field = product ? 'description' : proposal.target_field`, `proposal[value]` (hoặc `proposal[content]`), `source_fingerprint=data.source_fingerprint`, `apply_token=data.apply_token`, và chỉ đính kèm `confirmation=data.ownership_confirmation_token` khi checkbox xác nhận đã được tích.
     - Hiển thị kết quả thành công/lỗi vào vùng thông báo bằng `textContent` (tuyệt đối không dùng `innerHTML`), khóa nút khi đang gửi, không auto-apply, không auto-publish.

### `3B3-7` [Major] Đầy đủ hóa `tests/Unit/Increment3BTest.php` và đảm bảo xanh 100% toàn suite
- Mở rộng và sửa `Increment3BTest.php` để kiểm tra thật cả wiring `SeoModule` + `EditorPreviewController` + `ApplyController`:
  1. `testApplyTokenRoundTripAndExpiry`: giữ test nhiều độ dài + tiếng Việt + hết hạn + sửa chữ ký.
  2. `testFingerprintIsDeterministicAndScoped`: chứng minh `\r\n` vs `\n` và thứ tự key khác nhau cho cùng hash; chứng minh snapshot builder của `SeoModule` không đưa trường rác ngoài contract vào hash (đổi dữ liệu ngoài snapshot contract của đối tượng không làm stale, đổi field/canonical/ownership liên quan làm stale `409 stale_preview` với 0 write).
  3. `testEarlyGatesDoNotReadSnapshot`: bao phủ đầy đủ Gate 1–6 (GET, `post_id` xấu, `forbidden`, `invalid_nonce`, `unsupported_resource`, `unsupported_field`, `protected_field`, `rank_math_*` → `409 ownership_conflict`, thiếu/sai `source_fingerprint`, thiếu/hết hạn/tamper `apply_token`, HTML/script/`javascript:`, canonical URL xấu) → assert exact `http_status`, 3-key body, `$snapshotCalls === 0` và `$writes === 0`.
  4. `testPreviewToApplyEndToEndForArticlePageAndProduct`: gọi thật `EditorPreviewController::handlePreviewRequest()` lấy `source_fingerprint` + `apply_token` cho `title`, `meta_description` và `product_description_draft` (có xuống dòng `\n`), sau đó đưa thẳng sang `ApplyController::handleApplyRequest()` → `200 saved`, read-back exact, `post_status` vẫn giữ nguyên `'draft'` (không auto-publish), và `seo_analysis` không phát hành `apply_token`.
  5. `testRankMathOwnershipConfirmationMatrix`: không active → `saved`; active + thiếu confirmation → `409 ownership_conflict` (0 write); active + confirmation sai field/token → `409 ownership_conflict` (0 write); active + `ownership_confirmation_token` hợp lệ từ Preview → `200 saved` vào `_myseo_*` và 0 write sang `rank_math_*`.
  6. `testStorageFailureAndReadBackMismatchHaveZeroCompensationWrites`: writer trả `false`, writer ném exception, hoặc writer trả `true` nhưng read-back trả giá trị lệch → đều trả `502 storage_failed` (body đúng 3 khóa, không lộ message exception nội bộ `'storage'`) và số lần gọi writer tối đa là `1` (0 compensation write).
  7. `testSeoModuleBootsSafelyWithoutAuthKeyAndReadsCanonicalMySeo`: kiểm chứng `Plugin::boot()` / `SeoModule::register()` khi chưa có `AUTH_KEY` không ném lỗi, và khi `_myseo_title` có dữ liệu thì `ContentSeoInspector` đọc lại đúng với provenance `myseo_for_all`; đồng thời `renderMetabox()` xuất đủ `data-apply-nonce` và logic `myseo_ai_apply` không dùng `innerHTML`.

### `3B3-8` [Blocker] Chạy pass thực tế PHPUnit và lập báo cáo `docs/giai-doan-3/increment-3b-r3-rp.md`
- Bắt buộc chạy bằng cấu hình hợp lệ của repo (`--configuration phpunit.xml`) và đạt **PASS (exit code 0)** cho cả hai lệnh:
  - `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result tests/Unit/Increment3BTest.php`
  - `php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result tests/Unit`
- Đảm bảo không làm bẩn `.phpunit.result.cache` và giữ nguyên `myseoforall.php` (`sha256:06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef`).
- Ghi báo cáo trung thực vào `docs/giai-doan-3/increment-3b-r3-rp.md` gồm: danh sách VERIFY-RUN ID, exact test count & assertion count của cả 2 lệnh, SHA-256 của mọi file liên quan, và phân tách rõ bằng chứng Offline PHPUnit với phần kiểm thử WordPress/WooCommerce runtime thật.
