<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    use ResolvesBranchScope;

    public function index(Request $request)
    {
        $scope = $this->resolveBranchFilter($request);
        $effectiveBranchIds = $scope['effectiveBranchIds'];

        $query = Shift::with('branch')->orderByRaw('branch_id IS NOT NULL')->orderBy('code');
        if ($scope['selectedBranchId'] !== null) {
            $query->where(fn ($q) => $q->where('branch_id', $scope['selectedBranchId'])->orWhereNull('branch_id'));
        } elseif ($effectiveBranchIds !== null) {
            $query->where(function ($q) use ($effectiveBranchIds) {
                $q->whereNull('branch_id');
                if ($effectiveBranchIds !== []) {
                    $q->orWhereIn('branch_id', $effectiveBranchIds);
                }
            });
        }

        return view('admin.shifts.index', [
            'shifts' => $query->paginate(20)->withQueryString(),
            'branches' => $scope['accessibleBranches'],
            'selectedBranchId' => $scope['selectedBranchId'],
            'canCreateGlobal' => $request->user()->hasGlobalBranchAccess(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $shift = Shift::create($data);
        $this->audit($request, $shift, ['created' => $data]);

        return back()->with('success', 'Đã tạo ca làm.');
    }

    public function update(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($shift->branch_id === null) {
            abort_unless($user->hasGlobalBranchAccess(), 403, 'Chỉ Quản trị hệ thống / Nhân sự mới được sửa ca dùng chung toàn công ty.');
        } else {
            abort_unless($user->canAccessBranch($shift->branch_id), 403, 'Bạn không có quyền sửa ca làm của chi nhánh khác.');
        }

        $data = $this->validated($request, $shift);
        // Changing shift rules retroactively would recalculate old timesheets incorrectly.
        $hasHistoricalEvents = AttendanceEvent::whereHas('employee', fn ($q) => $q->where('shift_id', $shift->id))->exists()
            || AttendanceEvent::whereHas('employee.shiftAssignments', fn ($q) => $q->where('shift_id', $shift->id))->exists();
        abort_if($hasHistoricalEvents, 422, 'Ca đã có dữ liệu chấm công. Hãy tạo ca mới và gán hiệu lực từ ngày thay đổi.');
        $before = $shift->only(['branch_id', 'code', 'starts_at', 'ends_at', 'break_minutes', 'grace_minutes', 'weekdays']);
        $shift->update($data);
        $this->audit($request, $shift, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Đã cập nhật ca làm.');
    }

    private function validated(Request $request, ?Shift $shift): array
    {
        $user = $request->user();
        $branchRules = $user->hasGlobalBranchAccess()
            ? ['nullable', 'integer', 'exists:branches,id']
            : ['required', 'integer', 'exists:branches,id'];

        $data = $request->validate([
            'branch_id' => $branchRules,
            'code' => ['required', 'string', 'max:30', Rule::unique('shifts', 'code')->ignore($shift?->id)],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'break_minutes' => ['required', 'integer', 'between:0,480'],
            'grace_minutes' => ['required', 'integer', 'between:0,120'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
        ]);
        $data['branch_id'] = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        if (!$user->hasGlobalBranchAccess()) {
            abort_unless($user->canAccessBranch($data['branch_id']), 403, 'Bạn chỉ có thể cấu hình ca làm cho chi nhánh mình phụ trách.');
        }
        $data['weekdays'] = array_map('intval', $data['weekdays']);

        return $data;
    }

    private function audit(Request $request, Shift $shift, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'shift.saved',
            'subject_type' => 'shift',
            'subject_id' => (string) $shift->id,
            'details' => array_merge(['code' => $shift->code, 'branch_id' => $shift->branch_id], $extra),
        ]);
    }
}
