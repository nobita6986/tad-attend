<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceController extends Controller
{
    use ResolvesBranchScope;

    public function index(Request $request)
    {
        $scope = $this->resolveBranchFilter($request);
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'state' => ['nullable', 'in:accepted,pending_review,rejected'],
        ]);

        if (!empty($data['employee_id'])) {
            $emp = Employee::findOrFail((int) $data['employee_id']);
            abort_unless($request->user()->canAccessBranch($emp->branch_id), 403, 'Bạn không có quyền xem chấm công nhân viên chi nhánh khác.');
        }

        $tz = config('attendance.timezone');
        $today = CarbonImmutable::now($tz);
        if (!empty($data['from']) || !empty($data['to'])) {
            $from = $data['from'] ?? $data['to'];
            $to = $data['to'] ?? $data['from'];
        } elseif (!empty($data['date'])) {
            // Backward compatibility with legacy ?date=YYYY-MM-DD links
            $from = $data['date'];
            $to = $data['date'];
        } else {
            // Default: Tháng này (từ đầu tháng đến hôm nay)
            $from = $today->startOfMonth()->format('Y-m-d');
            $to = $today->format('Y-m-d');
        }
        abort_if($from > $to, 422, 'Ngày bắt đầu phải trước ngày kết thúc.');
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 93, 422, 'Khoảng tra cứu không quá 93 ngày.');

        $start = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc();
        $perPage = (int) $request->query('per_page', 30);
        if (!in_array($perPage, [20, 30, 50, 100], true)) {
            $perPage = 30;
        }

        $eventsQuery = AttendanceEvent::with(['employee.branch', 'device', 'branch'])
            ->where('captured_at', '>=', $start)
            ->where('captured_at', '<', $end)
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('review_state', $state));
        $this->applyAttendanceBranchScope($eventsQuery, $scope['effectiveBranchIds']);

        $empListQuery = Employee::query()->orderBy('code');
        if ($scope['effectiveBranchIds'] !== null) {
            if ($scope['effectiveBranchIds'] === []) {
                $empListQuery->whereRaw('1 = 0');
            } else {
                $empListQuery->whereIn('branch_id', $scope['effectiveBranchIds']);
            }
        }

        return view('admin.attendance.index', [
            'events' => $eventsQuery->orderByDesc('captured_at')->paginate($perPage)->withQueryString(),
            'from' => $from,
            'to' => $to,
            'date' => $to,
            'perPage' => $perPage,
            'employees' => $empListQuery->get(),
            'branches' => $scope['accessibleBranches'],
            'regions' => Branch::REGIONS,
            'selectedRegion' => $scope['selectedRegion'],
            'selectedBranchId' => $scope['selectedBranchId'],
        ]);
    }

    public function image(Request $request, AttendanceEvent $event)
    {
        $eventBranchId = $event->branch_id ?? $event->employee?->branch_id;
        abort_unless($request->user()->canAccessBranch($eventBranchId), 403);
        abort_unless($event->image_path && Storage::disk('local')->exists($event->image_path), 404);

        return response()->file(Storage::disk('local')->path($event->image_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function review(Request $request, AttendanceEvent $event)
    {
        $eventBranchId = $event->branch_id ?? $event->employee?->branch_id;
        abort_unless($request->user()->canAccessBranch($eventBranchId), 403, 'Bạn không có quyền xét duyệt lượt chấm công của chi nhánh khác.');
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        abort_unless($event->review_state === 'pending_review', 422, 'Chỉ xét duyệt sự kiện chờ.');
        $event->update(['review_state' => $data['decision']]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'attendance.review',
            'subject_type' => 'attendance_event',
            'subject_id' => (string) $event->id,
            'details' => ['from' => 'pending_review', 'to' => $data['decision'], 'reason' => $data['reason']],
        ]);

        return back()->with('success', 'Đã lưu quyết định xét duyệt.');
    }
}
