<?php
use App\Http\Controllers\Api\DeviceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:60,1', 'device.auth'])->group(function () {
    Route::get('/device', [DeviceController::class, 'device']);
    Route::get('/roster', [DeviceController::class, 'roster']);
    Route::post('/enrollments', [DeviceController::class, 'enroll']);
    Route::post('/attendance', [DeviceController::class, 'attendance']);
});
