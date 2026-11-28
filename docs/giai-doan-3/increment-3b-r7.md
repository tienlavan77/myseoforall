# Giai đoạn 3 — Increment 3B (Revision 7): Sửa lỗi JS đọc sai DOM container khiến Preview luôn báo "Không thể tải bản xem trước."

## 1. Nguyên nhân đã xác định bằng source evidence

Tại `src/SEO/Runtime/EditorPreviewController.php`:

- Dòng `184`: Các thuộc tính `data-ajax-url`, `data-nonce`, `data-apply-nonce`, `data-post-id` được đặt trên container ngoài cùng `<div class="myseo-for-all-metabox" ...>`.
- Dòng `237`: Khu vực Trợ lý AI được bọc thêm thẻ `<section class="myseo-for-all-metabox__section myseo-for-all-metabox__ai">`.
- Dòng `265`: Đoạn `<script>` nằm bên trong thẻ `<section>` đó nhưng vẫn viết:
  ```js
  const box = document.currentScript.parentElement;
  ```
  Do đó `box` trỏ nhầm vào thẻ `<section>` (không có `dataset.ajaxUrl`, `dataset.postId`, `dataset.nonce`, `dataset.applyNonce`), khiến `fetch(box.dataset.ajaxUrl, ...)` gọi tới URL `"undefined"`, trả về HTML 404, `response.json()` ném lỗi và rơi thẳng vào `catch` hiển thị `"Không thể tải bản xem trước."` trước khi chạm tới `admin-ajax.php` hay AI Gateway.

## 2. Yêu cầu sửa bắt buộc

### `3B7-1` — Sửa DOM lookup trong `EditorPreviewController::renderMetabox()`

- Thay `const box = document.currentScript.parentElement;` thành:
  ```js
  const box = document.currentScript ? document.currentScript.closest('.myseo-for-all-metabox') : null;
  if (!box) return;
  ```
- Kiểm tra lại các truy vấn tiếp theo trong cùng `<script>` (`box.querySelector('.myseo-ai-preview-panel')`, `box.querySelectorAll('.myseo-ai-preview')`, `box.dataset.ajaxUrl`, `box.dataset.postId`, `box.dataset.nonce`, `box.dataset.applyNonce`) đều hoạt động đúng trên `<div class="myseo-for-all-metabox">` cho cả nhánh Preview (`myseo_ai_preview`) và Apply (`myseo_ai_apply`).
- Giữ nguyên nguyên tắc DOM-safe (`textContent`, không dùng `innerHTML`), không thay đổi backend contract, không thay đổi CSS/HTML structure ngoài yêu cầu này.

### `3B7-2` — Bổ sung regression test trong `tests/Unit/Increment3ATest.php`

- Bổ sung assertion trong test render metabox (`testEnabledMetaboxDisclosureAndActions` hoặc test chuyên biệt) xác nhận:
  - `<div class="myseo-for-all-metabox"` chứa đủ `data-ajax-url`, `data-nonce`, `data-apply-nonce`, `data-post-id`;
  - `<script>` tra container qua `closest('.myseo-for-all-metabox')` và **không** còn dùng `document.currentScript.parentElement`.

## 3. Verification và báo cáo

1. Chạy `php -l src/SEO/Runtime/EditorPreviewController.php` và `php -l tests/Unit/Increment3ATest.php` (PASS).
2. Chạy focused test `php vendor/bin/phpunit --verbose tests/Unit/Increment3ATest.php` (PASS, exit 0).
3. Chạy full suite `php vendor/bin/phpunit --verbose --configuration phpunit.xml tests/Unit` (PASS, exit 0, 0 failures/errors).
4. Không sửa `myseoforall.php`, không commit `.phpunit.result.cache`, không đổi schema `_myseo_*` hay phạm vi 3C.
5. Ghi báo cáo ngắn kèm run ID, số test/assertion và exact SHA-256 checksum các file sửa vào **`docs/giai-doan-3/increment-3b-r7-rp.md`**.
