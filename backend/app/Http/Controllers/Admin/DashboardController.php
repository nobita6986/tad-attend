<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ResolvesBranchScope;

    public function index(Request $request)
    {
        $scope = $this->resolveBranchFilter($request);
        $branchIds = $scope['effectiveBranchIds'];

        $today = CarbonImmutable::now(config('attendance.timezone'))->startOfDay();
        $start = $today->utc();
        $end = $today->addDay()->utc();

        $empQuery = Employee::where('active', true);
        $devQuery = Device::where('active', true);
        if ($branchIds !== null) {
            if ($branchIds === []) {
                $empQuery->whereRaw('1 = 0');
                $devQuery->whereRaw('1 = 0');
            } else {
                $empQuery->whereIn('branch_id', $branchIds);
                $devQuery->whereIn('branch_id', $branchIds);
            }
        }

        $todayEvents = $this->applyAttendanceBranchScope(
            AttendanceEvent::where('captured_at', '>=', $start)->where('captured_at', '<', $end),
            $branchIds
        );

        $recent = $this->applyAttendanceBranchScope(
            AttendanceEvent::with(['employee.branch', 'device', 'branch'])->latest('received_at')->limit(15),
            $branchIds
        )->get();

        return view('admin.dashboard', [
            'employees' => $empQuery->count(),
            'devices' => $devQuery->count(),
            'punches' => (clone $todayEvents)->where('review_state', 'accepted')->count(),
            'pending' => (clone $todayEvents)->where('review_state', 'pending_review')->count(),
            'recent' => $recent,
            'branches' => $scope['accessibleBranches'],
            'regions' => Branch::REGIONS,
            'selectedRegion' => $scope['selectedRegion'],
            'selectedBranchId' => $scope['selectedBranchId'],
            'activeBranchCount' => $scope['accessibleBranches']->where('active', true)->count(),
        ]);
    }
}
