# Đặc tả kỹ thuật — FaceAttendance v0.1
Ngày: 2026-11-08 · Đối tượng: < 50 nhân viên, 2–5 iPhone, một đơn vị vận hành.

## Mục tiêu / bất biến
- iPhone ở cổng nhận một khuôn mặt trong camera trước, so khớp on-device, yêu cầu kiểm chứng chống giả mạo, tạo **một** lượt chấm với UUID và ảnh minh chứng.
- Máy chủ đóng dấu `received_at` bằng giờ server UTC; lưu `captured_at` do thiết bị cung cấp và trạng thái kiểm duyệt.
- Không nhận diện bằng Face ID của hệ điều hành; Vision chỉ phát hiện khuôn mặt, cần mô hình embedding dùng Core ML.
- Nhận diện mơ hồ hoặc phát hiện nhiều mặt phải thất bại an toàn: không chọn nhân viên theo suy đoán.
- Không ghi đè/sửa trực tiếp sự kiện gốc; điều chỉnh phải tạo nhật ký kiểm toán riêng ở các bản phát triển tiếp theo.
- Dữ liệu sinh trắc (template) truyền TLS, mã hóa ở DB (Laravel encrypted cast), iPhone cache trong RAM, không log vector.
- Ảnh minh chứng trong `storage/app/private/...` chỉ đọc qua route quản trị; tự xóa sau 60 ngày (dựa vào `received_at`). DB logs vẫn giữ.

## Stack
- iOS 16+, SwiftUI, ARKit face tracking, Vision để định vị/cắt mặt, Core ML để trích vector, CryptoKit/Keychain (token), URLSession.
- Laravel 13 / PHP >= 8.3, Blade (không cần Livewire), MySQL 8+, PhpSpreadsheet, cron. Không cần Redis, queue worker hay GPU server.
- APP_TIMEZONE=UTC và dữ liệu datetime UTC trong DB; mọi báo cáo hiển thị Asia/Ho_Chi_Minh (ATTENDANCE_TIMEZONE).

## Luồng triển khai
1. Quản trị tạo ca, nhân viên và lấy xác nhận đồng ý hợp lệ (không coi checkbox của admin là sự đồng ý tự động).
2. Admin sinh mã đăng ký một lần (hết hạn 10 phút), nhân viên chụp nhiều mẫu trên iPhone được cấp quyền.
3. Core ML tạo embeddings 512 chiều (ví dụ; **phụ thuộc mô hình đã chọn**), gộp chuẩn hóa, gửi qua API cùng model_version và enrollment code.
4. Server kiểm tra nhân sự còn hoạt động + ghi nhận đồng ý, mã đăng ký một lần, vector có độ dài và số hữu hạn; lưu template mã hóa.
5. Thiết bị đồng bộ roster (họ tên, code, xưng hô, vector); chỉ so khớp nếu cùng mô hình và phiên bản.
6. Chấm công: tracking/liveness, face crop, embedding L2 norm, cosine similarity -> employee; xác minh vượt ngưỡng **đã hiệu chuẩn** và khoảng cách so với ứng viên thứ hai.
7. Nếu thành công và có mạng, POST ảnh minh chứng cùng UUID, nhân viên, score, model version. Server nhận idempotent, chống lặp trong 60 giây, ghi sự kiện. Lời chào hiển thị sau ACK server.
8. Máy chủ gửi response ISO-8601 `recorded_at`, tên và chấm công `in`/`out` theo cấu hình thiết bị.

## Mô hình dữ liệu
- `users`: tài khoản admin (từ migration mặc định Laravel + cột `is_admin`).
- `shifts`: mã ca, giờ đầu/cuối, ngưỡng trễ, thời gian nghỉ, weekday JSON.
- `employees`: code duy nhất, họ tên, giới tính, ngày sinh, thời gian làm việc, địa chỉ, bộ phận, ca mặc định, trạng thái, consent_at, consent_method.
- `shift_assignments`: phân công ca với khoảng hiệu lực, ưu tiên cao hơn ca mặc định.
- `devices`: UUID ID, tên, hướng vào/ra, hash SHA-256 token riêng, active, last_seen_at.
- `face_templates`: employee, encrypted vector, model ID/version, active, enrolled_at.
- `enrollment_tickets`: token hash, employee, expiration, consumed_at, created_by.
- `attendance_events`: UUID, employee/device, captured_at/received_at, direction, review_state, match_score, liveness_method, image_path, is_offline, model_version.
- `audit_logs`: actor, action, resource, snapshot metadata (không ghi vector/ảnh/token).

## Đồng bộ / chống lặp
- Khóa duy nhất `event_uuid` để POST lại không tăng số lượt; bản ghi trả về với `duplicate=true`.
- Cooldown 60 giây cùng nhân viên trên tất cả thiết bị đối với sự kiện online mới (theo giờ nhận server); với lô offline so theo captured_at, trả HTTP 409 nếu trùng khoảng.
- Sự kiện có captured_at lệch quá 5 phút hoặc có cờ offline: `pending_review`, không tự cộng công.
- Device auth dùng bearer token random 32 byte, server lưu hash, route throttle, cho phép thu hồi.
- Mã đăng ký token random riêng, 10 phút, single-use, không hiển thị lại trên server.

## Luật tính công v0.1
- Chỉ tính sự kiện `accepted`, direction `in` và `out` rõ ràng. `unknown` và `pending_review` không tự tính.
- Với ngày D và ca làm: cửa sổ từ giờ bắt đầu ca - 4h tới giờ kết thúc ca + 4h; qua đêm cộng một ngày cho giờ kết thúc <= giờ bắt đầu.
- Lấy lượt vào sớm nhất + ra muộn nhất trong cửa sổ. Thiếu một trong hai là `incomplete`, không tính 1 ngày công.
- `late_minutes`: trễ so với ca + grace, `early_minutes`: về trước giờ kết thúc; `work_minutes` = thực tế - nghỉ cố định (chỉ khi đủ vào/ra).
- Ngày không nằm trong `weekdays` là `off`, ngoài thời gian làm việc là `not_employed` (không ghi vắng); ca chưa khai báo là `unconfigured`.
- Ca biến đổi, lễ, nghỉ phép, tăng ca, thiếu quét và điều chỉnh công nằm ngoài phạm vi tự động v0.1.

## Bảo mật và vận hành
- TLS bắt buộc trên production, APP_DEBUG=false, APP_KEY không thay đổi, SQL backup mã hóa; hosting document root trỏ vào `/public`.
- Token trong Keychain, bảo vệ API theo device, không cấp quyền web bằng bearer device token.
- Đăng nhập admin dùng Auth session + CSRF, throttle; tránh dùng dữ liệu sinh trắc làm phương thức duy nhất nếu nhân viên không đồng ý.
- Áp dụng chính sách xử lý/xóa dữ liệu và thông báo nội bộ theo pháp luật hiện hành; tham vấn pháp lý với thông tin sinh trắc.
- Ký cam kết và kiểm thử PAD/độ chính xác trước khi dùng cho bảng lương.

## Quy mô / kích thước
- 50 người x 2 lần/ngày x 60 ngày = 6000 ảnh; giả định 100 KB/ảnh ~ 600 MB, chưa kể backup (không bảo đảm kích thước).
- 5 thiết bị vẫn dùng MySQL/PHP hosting nếu hosting có đủ tài nguyên, file storage, cron và request upload.
