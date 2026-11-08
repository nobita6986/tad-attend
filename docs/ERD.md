# Sơ đồ dữ liệu (mức logic)

```mermaid
erDiagram
    USERS ||--o{ ENROLLMENT_TICKETS : issues
    USERS ||--o{ AUDIT_LOGS : records
    SHIFTS ||--o{ EMPLOYEES : default_shift
    SHIFTS ||--o{ SHIFT_ASSIGNMENTS : assigned
    EMPLOYEES ||--o{ SHIFT_ASSIGNMENTS : works
    EMPLOYEES ||--o{ FACE_TEMPLATES : enrolled
    EMPLOYEES ||--o{ ENROLLMENT_TICKETS : enrolls
    EMPLOYEES ||--o{ ATTENDANCE_EVENTS : punches
    DEVICES ||--o{ ATTENDANCE_EVENTS : scans
    USERS {
      bigint id PK
      string email UK
      boolean is_admin
    }
    SHIFTS {
      bigint id PK
      string code UK
      time starts_at
      time ends_at
      json weekdays
    }
    EMPLOYEES {
      bigint id PK
      string code UK
      string full_name
      date employed_from
      date employed_until
      bigint shift_id FK
      datetime consent_at
    }
    SHIFT_ASSIGNMENTS {
      bigint id PK
      bigint employee_id FK
      bigint shift_id FK
      date effective_from
      date effective_to
    }
    DEVICES {
      uuid id PK
      string direction
      char token_hash UK
      boolean active
    }
    FACE_TEMPLATES {
      bigint id PK
      bigint employee_id FK
      longtext embedding_encrypted
      string model_id
      string model_version
    }
    ENROLLMENT_TICKETS {
      bigint id PK
      bigint employee_id FK
      char token_hash UK
      datetime expires_at
      datetime consumed_at
    }
    ATTENDANCE_EVENTS {
      bigint id PK
      uuid event_uuid UK
      bigint employee_id FK
      uuid device_id FK
      datetime captured_at
      datetime received_at
      string direction
      string review_state
      string image_path
    }
    AUDIT_LOGS {
      bigint id PK
      bigint user_id FK
      string action
      string subject_type
      string subject_id
    }
```

Giờ trong `DATETIME` MySQL lưu theo **UTC**. Các báo cáo đổi sang `Asia/Ho_Chi_Minh` khi hiển thị. Vector mã hóa dùng Eloquent cast `encrypted:array` và phụ thuộc APP_KEY.
