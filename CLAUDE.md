# Quy ước làm việc cho agent — `dev-extensions/kernel`

## Nhánh
- **Mọi điều chỉnh làm trên `v7.x`.** Chỉ merge sang `main` **sau khi test chạy pass**: PHP
  `vendor/bin/phpunit`, Go `cd tools/gdrive-mirror-cli && go vet ./... && go test ./...`. `main` không nhận
  commit trực tiếp.
- **Dòng v6** (`lte.6x`, `lte.6x-is_dev`, `lte.6x-is_platform`): phục vụ nền tảng cũ (PHP 7.4, CMS ≤ 6.x),
  lịch sử git riêng.
  - **Không merge v7 ↔ v6.**
  - Port tay từng bản vá, viết lại cho PHP 7.4: không union type, `mixed`, nullsafe `?->`, `match`, named
    argument, spread mảng key chuỗi.
- Nhánh tạm được phép tạo, nhưng merge xong phải xoá.

## Commit
- Author và committer: `Visual Weber <dev@visualweber.com>`.
- Dòng cuối message: `Co-Authored-By: Visual Weber <dev@visualweber.com>`.
- Ghi bản vá vào `CHANGELOG.md`, mục `[Unreleased]`.

## Trước khi sửa
- Làm theo mục "⛔ BẮT BUỘC" ở `tasks/audit-he-thong-toi-uu-chuyen-sau.md`:
  - tự kiểm chứng tại nguồn;
  - đo trước và sau;
  - tự phản biện;
  - tái hiện trước, sửa sau;
  - ghi bằng chứng vào commit;
  - dừng khi không kiểm chứng được.
- Vùng code có cảnh báo "ĐỪNG SỬA" (khối `append()` trong `apps_google_sheet()`): hỏi chủ sản phẩm trước.
- Việc kiểm thử còn treo và lỗi chưa tường minh: `tasks/kiem-thu-sau-trien-khai.md`.
