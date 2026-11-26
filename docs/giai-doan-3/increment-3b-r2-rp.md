# Increment 3B R2 — Báo cáo triển khai

## Phạm vi

Đã triển khai các sửa lỗi 3B2-1 đến 3B2-8 trong phạm vi source/test:

- Token Apply ký và xác minh cùng payload base64url, padding/decode chặt chẽ, clock injectable.
- Fingerprint canonical hóa đệ quy, sắp xếp key associative, chuẩn hóa newline/trim và JSON flags ổn định.
- ApplyController kiểm tra gate trước snapshot, proposal/fingerprint/task/resource, protected fields, Rank Math ownership confirmation và storage failure.
- SeoModule nối snapshot canonical dùng chung cho Preview/Apply, ApplyController, ownership resolver và persistence adapters.
- Preview dùng target field từ output, không phát hành Apply token cho `seo_analysis`.
- Canonical post/page writer và WooCommerce description writer fail closed; same-value write được xác nhận bằng read-back.
- Bổ sung provenance `myseo_for_all` và test focused `Increment3BTest.php`.

## Verification

Lint đã pass:

- `VERIFY-RUN-a16ba0a9-ed6f-4db9-a37e-2f7009e4c086` — `SeoModule.php`
- `VERIFY-RUN-609134a2-5e0e-483b-a93e-bd09d3c47d68` — `EditorPreviewController.php`
- `VERIFY-RUN-6bb53728-1f70-42e2-84ed-20e5e19f0fe4` — `ApplyController.php`
- `VERIFY-RUN-b78e427c-1d95-4904-9978-3b2f5e7272af` — `CanonicalSeoMetaRepository.php`
- `VERIFY-RUN-91be1104-b32f-4cb3-8a97-49652e644b7a` — `WooCommerceDescriptionWriter.php`
- `VERIFY-RUN-cc994675-2259-4b01-8b5c-cf5e9fb4ca68` — `WordPressContentReader.php`
- `VERIFY-RUN-5c35f203-45f6-41e2-9020-35ed9159441e` — `WooCommerceProductReader.php`
- `VERIFY-RUN-421dcf44-a68c-4347-a7c2-acaf86234489` — `ProviderPayloadBuilder.php`
- `VERIFY-RUN-2babcd2d-007e-4ac2-96ec-a238da9262d6` — `Increment3BTest.php`

Focused PHPUnit:

- `VERIFY-RUN-d31b5baf-3f8e-492b-ae53-38e1ac64b5c9` — `php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/Increment3BTest.php` — **failed, exit 1**.
- Earlier invocation without the PHPUnit configuration was rejected by the check contract with exit 2.

Vì focused PHPUnit chưa pass, báo cáo này không tuyên bố Increment 3B đã nghiệm thu.

## SHA-256

- `src/SEO/Runtime/ApplyController.php`: `0173d5dae7f1d773ff9b09162e1ac9be9784be944a64d37b24352fa26c2feb77`
- `src/SEO/Runtime/ApplyFingerprint.php`: `77c4b31efc384732f3a8e060f69ffe176f5d27323a3a7418a82e01e10192d`
- `src/SEO/Runtime/ApplyToken.php`: `5d77056fa090ae9f86cd43e055adc45b8ecf75f0bf1ce6b307fff55fc8aac43e`
- `src/SEO/Runtime/CanonicalSeoMetaRepository.php`: `c5be004672381927e3f5cf96b2e5d53e25894e50c5b33443922dd45447634f77`
- `src/SEO/Runtime/WooCommerceDescriptionWriter.php`: `01ed61b2b66901e4cb5a321ce627f9077d7408026704d70ed3c43dbf342bc84d`
- `src/SEO/Runtime/EditorPreviewController.php`: `c44acb01ff6ba0cdd2f611862260199f56b9b7d8b1fff06380a46b21e2f8fd7c`
- `src/SEO/Runtime/SeoModule.php`: `712c0c4df5bd4e8377fd4564743d7fc610f10562ff89764c6d20060e7e3b978e`
- `src/SEO/Runtime/WordPressContentReader.php`: `6825e87e24b2a065b9f951d55663e8cc1a3c2c7b7de0a4f66096b7502127141c`
- `src/SEO/Runtime/WooCommerceProductReader.php`: `b097c1678f1d1dc0cf613e3792ebde9750d7f59070a3d458c633ba67134c7416`
- `src/SEO/Context/ProviderPayloadBuilder.php`: `51502841f2e5ee78d1f87ae54ff25013ba4a07119d4d05363bc0a0ed3a6d2901`
- `tests/Unit/Increment3BTest.php`: `23fe5a6f7cd040045ec66df9edc3563ab81ec3bf4b215847475a77e8783bc073`
- `myseoforall.php`: `06900bbed1e16f49258a9287bab512d75c21aaf2bb48635c0783df1b6de22aef` (unchanged)

## Limitations

Verification hiện chỉ là lint và PHPUnit offline. Chưa có bằng chứng runtime WordPress/WooCommerce thật, AJAX hook/nonce/capability production, persistence production, revision behavior hoặc upstream retention. Không commit/push trong lượt này.
