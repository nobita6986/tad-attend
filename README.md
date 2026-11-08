# FaceAttendance — hệ thống chấm công khuôn mặt iOS / Laravel

**Phạm vi**: < 50 nhân viên, 2–5 iPhone tại cổng; PHP hosting + MySQL; timezone Asia/Ho_Chi_Minh.

> **Trạng thái: SOURCE STARTER / PILOT; CHƯA ĐỦ ĐIỀU KIỆN SỬ DỤNG ĐỂ TRẢ LƯƠNG.** Backend có composer.json và cấu trúc ứng dụng Laravel 13 tối thiểu; app iOS có giao diện, API client và điểm tích hợp Core ML nhưng **không kèm mô hình face-embedding, ngưỡng đã hiệu chuẩn hoặc bộ kiểm định chống giả mạo**. Không thể tự động ghi công khi các thành phần này chưa được kiểm chứng. Không kèm thư mục `vendor/` hay dự án `.xcodeproj` sinh tự động; phải cài dependencies qua Composer/XcodeGen.

## Mục lục
- `docs/SPEC.md`: đặc tả kỹ thuật, nghiệp vụ, dữ liệu, bảo mật và tiêu chí nghiệm thu.
- `docs/ERD.md`: sơ đồ quan hệ database.
- `docs/REFERENCES.md`: tài liệu chính thức liên quan.
- `docs/API.md`: hợp đồng REST API và ví dụ.
- `docs/DEPLOY.md`: dựng Laravel 13, cấu hình PHP hosting, cron và sao lưu.
- `docs/TEST_PLAN.md`: test cases/pilot chống nhầm và giả mạo.
- `backend/`: mã nguồn Laravel 13 độc lập (composer.json, migrations, routes, Controllers, Blade).
- `ios/`: mã SwiftUI + ARKit + Vision + Core ML, `project.yml` cho XcodeGen.

## Chạy backend nhanh (máy có PHP >= 8.3, Composer, MySQL)
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Tạo database MySQL và chỉnh các giá trị DB_* trong .env
php artisan migrate
php artisan admin:create you@example.com
php artisan devices:issue 'Cổng vào 1' --direction=in
php artisan devices:issue 'Cổng ra 1' --direction=out
php artisan serve
# Mở http://127.0.0.1:8000/admin/login
```
APP_TIMEZONE phải để UTC để Laravel đọc timestamp đúng; ATTENDANCE_TIMEZONE=Asia/Ho_Chi_Minh cho giao diện/báo cáo. Trước triển khai vào thực tế cấu hình `FACE_MODEL_ID`, `FACE_MODEL_VERSION`, `FACE_EMBEDDING_DIMENSIONS`, `FACE_MATCH_THRESHOLD` theo model Core ML đã kiểm định. Khi để `UNCONFIGURED`, API không nhận đăng ký/chấm công; đây là cơ chế an toàn có chủ đích.

**Test** (máy dev phải có `pdo_sqlite`, `gd`, `dom`, `zip`, v.v.): `cd backend && php artisan test`.

**Production**: PHP >= 8.3; cài đầy đủ extension Laravel và PhpSpreadsheet (bao gồm GD, DOM, Mbstring, XMLReader, XMLWriter, ZIP); `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS và cron. Xem `docs/DEPLOY.md`.

## API tài khoản iPhone
- Mỗi iPhone có **token riêng**, chỉ hiển thị lúc tạo bởi `devices:issue`.
- Chỉ iPhone còn hiệu lực được truy cập API; thu hồi trong web `/admin/devices`.
- Lượt chấm công yêu cầu mẫu nhận diện cùng phiên bản, liveness, ngưỡng đối chiếu và ảnh JPEG.
- Giao thức dành cho các iPhone do đơn vị quản lý; bearer token phải lưu Keychain, không hardcode.

## Các hạn chế có chủ đích
1. Core ML FaceEmbedding.mlmodel **không được cung cấp**; phải chọn mô hình có bản quyền phù hợp và hiệu chuẩn threshold, preprocessing và kiểm thử thực tế; ứng dụng từ chối ghi công nếu thiếu mô hình.
2. Ví dụ iOS nêu quy trình ARKit chứ không tuyên bố đạt chứng nhận chống giả mạo (PAD). Cần đánh giá thêm với ảnh in/video/deepfake.
3. Báo cáo ngày chỉ tính lịch ca cố định và ngày làm trong tuần; phép, lễ, tăng ca, phân ca xoay và trường hợp ngoại lệ cần module riêng trước payroll.
4. Chưa triển khai hàng đợi chấm công offline trên iOS; app yêu cầu mạng khi gửi. API chấp nhận sự kiện ngoại tuyến nhưng đưa về `pending_review`.
5. SwiftUI yêu cầu macOS/Xcode để build; ở môi trường tạo mã này không có iOS SDK/Xcode nên chưa thể build hoặc chạy thiết bị.
6. Backend là project Laravel 13 có composer.json nhưng không kèm thư viện trong `vendor/`; cần `composer install` trên máy có Internet và extension thích hợp.
