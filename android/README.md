# TAD Attend — Android Kiosk App

Ứng dụng chấm công khuôn mặt on-device cho máy Android đặt tại cổng (`minSdk 26` / Android 8.0+).

## Công nghệ & Mô hình AI
- **CameraX** (camera trước) + **Google ML Kit Face Detection** (phát hiện 1 khuôn mặt, 5 điểm landmark, thử thách quay đầu `mlkit_head_turn`).
- **ONNX Runtime Android** chạy mô hình **OpenCV Zoo SFace** (`face_recognition_sface_2021dec.onnx`, giấy phép **Apache-2.0**, đầu vào 112×112 căn chỉnh 5 điểm chuẩn ArcFace, đầu ra vector 128 chiều chuẩn hóa L2).
- Task Gradle `ensureSfaceModel` tự động tải và kiểm tra mã SHA-256 (`0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79`) khi build, không commit file 38 MB vào Git.
- Token thiết bị được mã hóa bằng **Android Keystore (AES-256-GCM)**; danh sách vector khuôn mặt chỉ giữ trong **RAM** (không lưu ra bộ nhớ máy).

## Cài đặt & Sử dụng thử
1. Tải file `.apk` do GitHub Actions build về máy Windows:
   ```powershell
   .\scripts\download-apk.ps1
   ```
2. Chép `.apk` sang điện thoại Android và cài đặt (bật cho phép cài ứng dụng từ nguồn không xác định).
3. Mở trang quản trị `https://chamcong.tananhduong.com.vn/admin/devices` → **Tạo thiết bị & sinh token** → sao chép token.
4. Mở app **TAD Attend** → tab **Cấu hình** → đặt mã PIN quản trị (≥ 6 ký tự) → giữ URL `https://chamcong.tananhduong.com.vn` và dán token thiết bị → **Lưu & Kết nối**.
5. Vào web `/admin/employees` → ghi nhận đồng ý → **Cấp mã 10 phút** → nhập vào tab **Đăng ký** trên app để chụp 5 mẫu khuôn mặt, sau đó thử chấm công tại tab **Chấm công**.
