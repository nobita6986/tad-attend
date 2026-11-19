<?php
return [
    // IMPORTANT: Set for the exact chosen face-embedding model. A value here does NOT make the model reliable.
    // Default target model: OpenCV Zoo SFace (face_recognition_sface_2021dec, Apache-2.0, 128-dim L2-normalized embedding).
    'model_id' => env('FACE_MODEL_ID', 'UNCONFIGURED'),
    'model_version' => env('FACE_MODEL_VERSION', 'UNCONFIGURED'),
    'embedding_dimensions' => (int) env('FACE_EMBEDDING_DIMENSIONS', 128),
    'min_score' => (float) env('FACE_MATCH_THRESHOLD', 0.42),
    'cooldown_seconds' => (int) env('ATTENDANCE_COOLDOWN_SECONDS', 60),
    'image_retention_days' => (int) env('ATTENDANCE_IMAGE_RETENTION_DAYS', 60),
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
];
