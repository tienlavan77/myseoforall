# Increment F r4 — báo cáo sửa code

## Phạm vi

Đã đối chiếu trực tiếp với docs/giai-doan-2-f-r4.md và sửa các ranh giới F4-1 đến F4-5 trong code. F4-6 được giữ ở mức test hiện có và cập nhật fixture revision để phản ánh contract POST mới.

## Thay đổi đã thực hiện

- **F4-1 — Gateway URL/IP/path:** GatewayEndpointValidator chặn traversal raw và encoded nhiều lớp, backslash, control byte, phần trăm không hợp lệ, path rỗng kép, query/fragment, credentials, port ngoài 443, host DNS sai, provider host, obfuscated IPv4, IP private/reserved/mapped/NAT64/CGNAT/documentation. IPv6 public được canonicalize và giữ dấu ngoặc vuông khi ghép URL. Allowlist host nội bộ có thể lấy từ MYSEO_AI_ALLOWED_GATEWAY_HOSTS.
- **F4-2 — Admin POST/UI:** AiSettingsController yêu cầu expected_revision, ép kiểu số/boolean, parse allowlist model từ chuỗi form, đóng tập khóa gửi vào repository, unslash POST và redirect an toàn. AdminModule tách form AI khỏi form core, render revision hiện tại, các trường AI, trạng thái credential đã redacted và password input rỗng.
- **F4-3 — Crypto:** AiSettingsRepository hỗ trợ Sodium secretbox có AAD binding và tag, cùng fallback AES-256-GCM 12-byte nonce/16-byte tag. AAD cố định theo option, cipher và fingerprint; tamper ở envelope hoặc AAD fail-closed. Khóa ưu tiên fixture, MYSEO_AI_SECRET_KEY, environment và đủ bốn WordPress salts qua HKDF.
- **F4-4 — Storage:** OptionStore::update() trả đúng kết quả add_option, coi giá trị không đổi là no-op và compareAndSwap() fail-closed khi không có seam atomic. hasCas() được thêm để purgeLegacyCoreKeys() dùng CAS khi có.
- **F4-5 — Locale/activation:** Configuration kiểm tra locale gốc nên giữ được en_US/vi_VN. Plugin::activate() chỉ tạo default khi option core vắng; option hiện hữu đi qua purge allowlist.
- **F4-6 — fixture:** request controller trong IncrementFTest gửi expected_revision rõ ràng theo contract mới.

## Kiểm tra

- php vendor/bin/phpunit --configuration phpunit.xml tests/Unit/IncrementFTest.php — **passed**, VERIFY-RUN-e4174f5a-02f4-4da1-89b9-6a316386aeda.
- php vendor/bin/phpunit --configuration phpunit.xml tests/Unit — **passed**, VERIFY-RUN-bddcf462-7868-4fec-97a4-01918c0f9788.
- PHP lint đã passed cho các file source đã sửa. Các run cuối cho phần thay đổi sau cùng: VERIFY-RUN-97347c99-79f4-4869-b49f-95c44ecbb004 (Plugin), VERIFY-RUN-4dcd249f-6616-4b05-b773-1028b29c1690 (OptionStore), VERIFY-RUN-a343d0c4-f3b6-4d6b-8a3c-cfee4cc01bb6 (AiSettingsRepository).

## Evidence source checksums

- src/AI/GatewayEndpointValidator.php — sha256:b31a0e5554d96e1c31ded9820a89d1503215d49a5485371de091510db8f776ae
- src/AI/AiSettingsController.php — sha256:c4fb32bb3df24a3d4789502e46e734e33d2a35d00612f511b3c7adaece5009ac
- src/AI/AiSettingsRepository.php — sha256:e3429010064c445936780ec5fa803b2566ef82d2b80ce8bb48a1d940b8b81c4f
- src/Admin/AdminModule.php — sha256:f8584dc22dcf2d53c8571b1930f00df54729261eda958ed879278b3ea890d901
- src/Core/Configuration/Configuration.php — sha256:f1c6904a89d956b2516114b55eb28853a82d7bddc07392f62c8e6086687a8820
- src/Core/Plugin.php — sha256:d5d579aeb257313e17b65ebab7fcb216d44e2917f95c0c6a5b4b10fb2bce0ff4
- src/Infrastructure/WordPress/OptionStore.php — sha256:df9ce3f35205dd09b75861eb3622dccb2cc4eb31a10e98a69505e77a2736d499
- tests/Unit/IncrementFTest.php — sha256:7db69d63227feca169458f0c95733fa8f0c4fd1909651e6049726eb5d389b97d

## Giới hạn còn lại

Các test hiện tại chưa bao phủ toàn bộ ma trận r4 yêu cầu cho cả hai nhánh crypto, mọi biến thể obfuscated IP/path, repeated Admin UI submit qua WordPress runtime và tất cả trạng thái purge. Chưa có snapshot commit sạch trong lượt này; vì vậy báo cáo không gọi đây là nghiệm thu production hoặc bằng chứng runtime WordPress đầy đủ.
