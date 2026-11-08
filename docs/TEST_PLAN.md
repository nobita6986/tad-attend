# Kế hoạch nghiệm thu và kiểm thử

## Backend
- Migration sạch trên MySQL 8; không mất dữ liệu khi migrate thêm.
- Token thiết bị hợp lệ/không hợp lệ/đã thu hồi; CSRF/admin không được mở API device.
- Enrollment ticket sai, dùng lại, hết hạn, không consent, sai vector -> không đăng ký.
- API attendance: idempotency UUID, cooldown 60s đa thiết bị, score thấp, liveness false, model mismatch, ảnh quá dung lượng, ảnh không hợp lệ, offline -> pending.
- 60 ngày: quá hạn ảnh bị xóa nhưng attendance event còn, ảnh mới không bị xóa; backup có retention tương ứng.
- Export Excel có đủ UTF-8 Vietnamese, định dạng ngày, nhiều trang; không chứa đường dẫn nội bộ/embedding.
- Báo cáo: ca ban ngày, qua đêm, vào thiếu ra, ra thiếu vào, đi trễ, về sớm, ngày nghỉ, nhiều lượt cùng loại, phân ca hiệu lực.

## iOS và nhận diện
- Camera permission từ chối; thiếu Core ML model; mất mạng; 2 người trong khung; ánh sáng tối/nắng; khẩu trang; kính.
- Test chéo ít nhất 50 người, nhiều thời điểm, cả 2–5 máy; đo FAR, FRR và số lần phải quét lại.
- Test ảnh giấy, ảnh trên smartphone, video, deepfake/3D mask nếu rủi ro yêu cầu; không tuyên bố đạt PAD chỉ với lắc/quay đầu.
- Đồng bộ roster sau khi thêm/sửa/xóa template, thu hồi thiết bị, hết ticket, xóa employee.
- Test tính giờ máy/giờ máy chủ và các thời điểm qua ngày/tháng/năm.

## Điều kiện đưa vào tính lương
Người quản trị và bộ phận nhân sự ký duyệt độ chính xác dựa trên dữ liệu pilot, quy tắc công theo thực tế công ty, xử lý ngoại lệ, kế hoạch bảo vệ dữ liệu cá nhân, và đường xử lý khi AI từ chối nhận diện.
