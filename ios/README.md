# iOS app — SwiftUI + ARKit + Vision + Core ML

## Build
1. Mở trên máy **Mac**, Xcode hiện hành; cài XcodeGen nếu muốn tạo project tự động.
2. `cd ios && xcodegen generate` rồi mở `FaceAttendance.xcodeproj`. Có thể tạo iOS App mới và thêm file `FaceAttendance/Sources/` thủ công nếu không cài XcodeGen.
3. Chọn Bundle ID và Signing Team của bạn.
4. Thêm **mô hình thực** có license và input/output đúng yêu cầu dưới đây: `FaceEmbedding.mlmodel` vào `FaceAttendance/Resources/` (Xcode tự compile thành `.mlmodelc`). Chưa có mô hình thì app **không cho ghi công**.
5. Trên server cấu hình `.env`: FACE_MODEL_ID, FACE_MODEL_VERSION, FACE_EMBEDDING_DIMENSIONS, FACE_MATCH_THRESHOLD (được hiệu chuẩn); không dùng threshold ví dụ để chấm công thật.
6. Cấp token qua `php artisan devices:issue`, vào tab Cấu hình trên iPhone để nhập HTTPS URL và token. Nếu thiết lập cho nhân viên dùng, khóa giao diện bằng Guided Access và kiểm soát vật lý.

## Giao diện / giới hạn
- `ContentView`: 3 tab `Chấm công`, `Đăng ký`, `Cấu hình`; hai tab quản trị có PIN cục bộ lưu trong Keychain và tự khóa khi app vào nền. Trên production vẫn cần MDM/Guided Access và phân quyền mạnh hơn.
- `FaceTrackingService`: ARKit face anchor và thử thách quay đầu trái/phải; phương pháp này **KHÔNG phải chống giả mạo được chứng nhận**.
- `FaceEmbeddingEngine`: Vision định vị một khuôn mặt, Core ML lấy vector, chuẩn hóa L2. Input tên `image` là CVPixelBuffer 112x112 BGRA; output `embedding` là MLMultiArray 512 chiều theo cấu hình ví dụ. Cách cắt/căn chỉnh ảnh **phải khớp preprocessing khi huấn luyện**.
- `RecognitionCoordinator`: đăng ký 5 embedding, gộp thành một vector; nhận diện bằng cosine và ngưỡng + margin với người thứ hai; POST ảnh JPEG đã giảm kích thước.
- `APIClient`: URLSession + bearer token Keychain, HTTPS. Chưa có queue offline.

## Việc phải làm trước production
- Kiểm chứng đầu vào/số chiều mô hình thực tế; thêm căn chỉnh landmark, quality gate, kiểm định FAR/FRR trên người thật.
- Tăng cường PAD dựa trên camera depth/TrueDepth và kiểm thử tấn công ảnh/video, không chỉ challenge quay đầu.
- Bổ sung bảo vệ phần enrollment/settings bằng xác thực quản trị server, MDM/Single App Mode/Guided Access và monitoring; PIN cục bộ không đủ cho môi trường bị tấn công.
- Chế độ offline có lưu queue mã hóa và đồng bộ; hiện tại không chấm công khi không có mạng.
- Test/build bằng Xcode trên iPhone thật — ARKit face tracking không chạy iOS Simulator.
