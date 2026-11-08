# REST API v1 — HTTPS + Bearer device token
Base URL: `https://YOUR_DOMAIN/api/v1`. JSON trừ API upload multipart. Lỗi theo JSON HTTP 4xx/5xx. Token mỗi thiết bị.

## GET /device
Response: `{ "device_id":"uuid", "name":"Cổng 1", "direction":"in", "server_time":"2026-11-08T00:00:00Z", "min_score":0.75, "model_id":"your-face-model", "model_version":"1" }`.
`min_score` chỉ là cấu hình kỹ thuật **không phải** ngưỡng dùng thực tế nếu chưa hiệu chuẩn.

## GET /roster?model_id=...&model_version=...
Response: `{ "employees": [{"id":1,"code":"NV001","full_name":"Nguyễn Văn A","gender":"male","template":[0.1,...]}] }`. Chỉ người active + có consent + template đúng model. Template là dữ liệu nhạy cảm: không cache ngoài RAM trong iOS.

## POST /enrollments (application/json)
Body: `{ "ticket":"...", "model_id":"your-face-model", "model_version":"1", "embedding":[...]} `.
Vector phải là JSON floats (512 phần tử trong cấu hình mặc định), L2 norm xấp xỉ 1. Ticket do web admin sinh, một lần và hết hạn 10 phút.
Response: `{ "employee_id":1,"enrolled_at":"..." }` (201).

## POST /attendance (multipart/form-data)
- `event_uuid`: UUID v4 (duy nhất, cố định khi retry)
- `employee_id`: int
- `captured_at`: ISO-8601 kèm offset/Z
- `match_score`: float [0,1]
- `liveness_passed`: true
- `liveness_method`: `arkit_head_turn` (MVP; chưa chứng nhận PAD)
- `model_id`, `model_version`: giống template đã đăng ký
- `offline`: `0` hoặc `1`
- `image`: JPEG <= 250KB (đề xuất resize 800 px trước POST)

Success 201: `{ "event_uuid":"...", "employee_name":"...", "direction":"in", "recorded_at":"2026-11-08T00:30:01Z", "review_state":"accepted", "duplicate":false }`.
Retry 200: cùng dữ liệu với `duplicate:true`. Cooldown 409: `{ "message":"Chưa qua thời gian chống chấm lặp." }`.
Request với `offline=1` hoặc lệch thời gian quá 5 phút được lưu `pending_review` (không tự tính công).

## POST /admin/login
Đây là form web Session + CSRF, **không phải API thiết bị**.

## Các route quản trị
`/admin/dashboard`, `/admin/employees`, `/admin/shifts`, `/admin/attendance`, `/admin/reports/daily`, `/admin/reports/export-detail`, `/admin/reports/export-summary`, `/admin/devices`, `/admin/employees/{employee}/ticket`, `/admin/attendance/{event}/image`.
