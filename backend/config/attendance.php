<?php
return [
    // IMPORTANT: Set for the exact chosen face-embedding model. A value here does NOT make the model reliable.
    'model_id' => env('FACE_MODEL_ID', 'UNCONFIGURED'),
    'model_version' => env('FACE_MODEL_VERSION', 'UNCONFIGURED'),
    'embedding_dimensions' => (int) env('FACE_EMBEDDING_DIMENSIONS', 512),
    'min_score' => (float) env('FACE_MATCH_THRESHOLD', 0.75),
    'cooldown_seconds' => (int) env('ATTENDANCE_COOLDOWN_SECONDS', 60),
    'image_retention_days' => (int) env('ATTENDANCE_IMAGE_RETENTION_DAYS', 60),
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
];
