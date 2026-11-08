<?php
return [
    'default' => env('CACHE_STORE', 'file'),
    'stores' => [
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data'), 'lock_path' => storage_path('framework/cache/data')],
        'array' => ['driver' => 'array', 'serialize' => false],
        'database' => ['driver' => 'database', 'connection' => env('DB_CACHE_CONNECTION'), 'table' => 'cache', 'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'), 'lock_table' => 'cache_locks'],
    ],
    'prefix' => env('CACHE_PREFIX', 'faceattendance_cache'),
];
