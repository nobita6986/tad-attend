<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Device;
use App\Models\AttendanceEvent;
use Carbon\CarbonImmutable;
class DashboardController extends Controller
{
    public function index()
    {
        $today = CarbonImmutable::now(config('attendance.timezone'))->startOfDay();
        $start = $today->utc(); $end = $today->addDay()->utc();
        $todayEvents = AttendanceEvent::where('captured_at', '>=', $start)->where('captured_at', '<', $end);
        return view('admin.dashboard', [
            'employees' => Employee::where('active', true)->count(),
            'devices' => Device::where('active', true)->count(),
            'punches' => (clone $todayEvents)->where('review_state', 'accepted')->count(),
            'pending' => (clone $todayEvents)->where('review_state', 'pending_review')->count(),
            'recent' => AttendanceEvent::with(['employee','device'])->latest('received_at')->limit(15)->get(),
        ]);
    }
}
