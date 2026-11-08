# Kiểm tra tĩnh / Static QA — 2026-11-08

- PHP syntax: **PASS** 56/56 file (`php -l`).
- Swift syntax: **PASS** 9/9 file (`swiftc -frontend -parse`).
- Composer JSON, PHPUnit XML: **PASS** cấu trúc.
- Routes -> methods: **PASS** không có method bị thiếu theo quét tĩnh.
- Controllers -> Blade views: **PASS** mọi view được gọi có file tương ứng.
- Kiểm thử backend Laravel thực tế: **CHƯA CHẠY**, vì môi trường tạo artifact không có Composer và các PHP extension cần thiết.
- iOS build / Core ML inference / kết nối iPhone: **CHƯA CHẠY**, vì không có macOS/Xcode và chưa kèm mô hình FaceEmbedding.mlmodel.
- Kiểm thử người thật/chống giả mạo/mức sai lệch: **CHƯA CHẠY**. Không dùng để trả lương cho đến khi nghiệm thu.

Sau khi thiết lập máy dev: chạy `cd backend && composer install && php artisan test` và mở `ios/` bằng Xcode/XcodeGen.
