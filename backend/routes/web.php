<?php
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\DeviceAdminController;
use App\Http\Controllers\Admin\UserAdminController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/admin/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/admin/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:5,1'])->name('admin.login');
Route::post('/admin/logout', [AuthController::class, 'logout'])->middleware('auth')->name('admin.logout');
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin.only'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])->name('employees.toggle-active');
    Route::post('/employees/{employee}/transfer', [EmployeeController::class, 'transfer'])->name('employees.transfer');
    Route::post('/employees/{employee}/consent', [EmployeeController::class, 'consent'])->name('employees.consent');
    Route::post('/employees/{employee}/revoke-consent', [EmployeeController::class, 'revokeConsent'])->name('employees.revoke-consent');
    Route::post('/employees/{employee}/assign-shift', [EmployeeController::class, 'assignShift'])->name('employees.assign-shift');
    Route::post('/employees/{employee}/ticket', [EmployeeController::class, 'ticket'])->name('employees.ticket');
    Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/{event}/image', [AttendanceController::class, 'image'])->name('attendance.image');
    Route::post('/attendance/{event}/review', [AttendanceController::class, 'review'])->name('attendance.review');
    Route::get('/reports/daily', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export-detail', [ReportController::class, 'exportDetail'])->name('reports.detail');
    Route::get('/reports/export-summary', [ReportController::class, 'exportSummary'])->name('reports.summary');

    Route::middleware('system.admin')->group(function () {
        Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('/branches/{branch}/toggle-active', [BranchController::class, 'toggleActive'])->name('branches.toggle-active');

        Route::get('/devices', [DeviceAdminController::class, 'index'])->name('devices.index');
        Route::post('/devices', [DeviceAdminController::class, 'store'])->name('devices.store');
        Route::put('/devices/{device}', [DeviceAdminController::class, 'update'])->name('devices.update');
        Route::post('/devices/{device}/approve', [DeviceAdminController::class, 'approve'])->name('devices.approve');
        Route::post('/devices/{device}/reject', [DeviceAdminController::class, 'reject'])->name('devices.reject');
        Route::post('/devices/{device}/revoke', [DeviceAdminController::class, 'revoke'])->name('devices.revoke');

        Route::get('/users', [UserAdminController::class, 'index'])->name('users.index');
        Route::post('/users', [UserAdminController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserAdminController::class, 'update'])->name('users.update');
    });
});
