<?php
return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => true],
    ],
    'links' => [], // NEVER link biometric/evidence image storage into public/.
];
