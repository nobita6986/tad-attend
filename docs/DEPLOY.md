# Deploy hosting PHP (cPanel/DirectAdmin) — Laravel 13

1. Kiểm tra PHP 8.3+, MySQL, extensions bắt buộc (pdo_mysql, fileinfo, mbstring, openssl, dom, xml, GD, iconv, simplexml, XMLReader, XMLWriter, ZIP, zlib; PhpSpreadsheet cần GD/ZIP), Composer hoặc CI tạo vendor, cron và HTTPS.
2. Máy dev: giải nén dự án rồi chạy `cd backend && composer install`; kiểm thử `php artisan migrate` và `php artisan test`.
3. Tạo MySQL database + user riêng, cập nhật `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `APP_TIMEZONE=UTC`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_SECURE_COOKIE=true`.
4. `php artisan key:generate` lần đầu; sao lưu APP_KEY vào nơi an toàn. **Không thay APP_KEY** sau khi mã hóa templates.
5. `php artisan migrate --force`; `php artisan admin:create admin@company.vn`; `php artisan devices:issue 'Cổng vào' --direction=in` và `php artisan devices:issue 'Cổng ra' --direction=out`.
6. Document root của subdomain phải là đường dẫn tuyệt đối tới **`.../app/public`**. Không chép `index.php` ra thư mục chung bên ngoài rồi đưa toàn bộ project vào public_html.
7. Cấp quyền ghi cho `storage/` và `bootstrap/cache/`, không `chmod 777`. Private storage không có public symlink. HTTPS bật mọi lúc.
8. Cron mỗi phút: `* * * * * cd /ABS/PATH/TO/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1`. Tác vụ 02:10 mỗi ngày xóa ảnh quá 60 ngày.
9. Backup DB mã hóa hằng ngày và kiểm tra khả năng khôi phục; giới hạn quyền file server, xem lại quy tắc xóa ảnh trong backup.
10. Chạy `php artisan optimize`; xem log `storage/logs/laravel.log` khi lỗi; không ghi token/embedding vào log.

## Không phù hợp shared hosting khi nào?
- Không cho chỉnh document root tới `public/`, thiếu cron/CLI/Composer, upload giới hạn quá nhỏ, hoặc PHP không đạt phiên bản yêu cầu.
- Nếu phải dùng proxy/vhost tuỳ chỉnh hoặc muốn kiểm soát hệ thống, nên đổi sang VPS; không giảm mức an toàn bằng cách công khai source Laravel.

## Môi trường kiểm thử
Trong môi trường tạo bộ mã này không có Composer, `pdo_mysql`, `mbstring`, `dom`, Xcode/iOS SDK, nên chỉ có thể kiểm PHP syntax và kiểm cấu trúc file. Phải chạy migration/test thực tế ở máy phát triển/macOS trước khi sản xuất.
