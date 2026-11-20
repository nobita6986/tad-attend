<?php
use App\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:20,1'])->group(function () {
    Route::post('/devices/register', [DeviceController::class, 'register']);
    Route::post('/devices/activate', [DeviceController::class, 'activate']);
});

Route::prefix('v1')->middleware(['throttle:60,1', 'device.auth'])->group(function () {
    Route::get('/device', [DeviceController::class, 'device']);
    Route::get('/roster', [DeviceController::class, 'roster']);
    Route::get('/enrollment-options', [DeviceController::class, 'enrollmentOptions']);
    Route::post('/enrollments', [DeviceController::class, 'enroll']);
    Route::post('/employees/enroll', [DeviceController::class, 'directEnroll']);
    Route::post('/attendance', [DeviceController::class, 'attendance']);
});
