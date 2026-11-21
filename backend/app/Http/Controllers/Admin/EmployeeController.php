<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesBranchScope;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Models\EnrollmentTicket;
use App\Models\FaceTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    use ResolvesBranchScope;

    public function index(Request $request)
    {
        $scope = $this->resolveBranchFilter($request);
        $perPage = (int) $request->query('per_page', 30);
        if (!in_array($perPage, [20, 30, 50, 100], true)) {
            $perPage = 30;
        }

        $statusFilter = $request->query('status'); // active | inactive | null
        $effectiveBranchIds = $scope['effectiveBranchIds'];

        $employeesQuery = Employee::with(['branch', 'shift', 'templates', 'shiftAssignments.shift', 'transfers.fromBranch', 'transfers.toBranch'])
            ->when($effectiveBranchIds !== null, function ($q) use ($effectiveBranchIds) {
                if ($effectiveBranchIds === []) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('branch_id', $effectiveBranchIds);
                }
            })
            ->when($statusFilter === 'active', fn ($q) => $q->where('active', true))
            ->when($statusFilter === 'inactive', fn ($q) => $q->where('active', false))
            ->orderByDesc('active')
            ->orderBy('code');

        $allowedBranchIds = $request->user()->accessibleBranchIds();
        $shiftsQuery = Shift::with('branch')->orderBy('code');
        if ($allowedBranchIds !== null) {
            $shiftsQuery->where(function ($q) use ($allowedBranchIds) {
                $q->whereNull('branch_id');
                if ($allowedBranchIds !== []) {
                    $q->orWhereIn('branch_id', $allowedBranchIds);
                }
            });
        }

        return view('admin.employees.index', [
            'employees' => $employeesQuery->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'shifts' => $shiftsQuery->get(),
            'branches' => $scope['accessibleBranches'],
            'allActiveBranches' => Branch::where('active', true)->orderBy('region')->orderBy('code')->get(),
            'regions' => Branch::REGIONS,
            'selectedRegion' => $scope['selectedRegion'],
            'selectedBranchId' => $scope['selectedBranchId'],
            'statusFilter' => $statusFilter,
            'suggestedCode' => Employee::suggestNextCode(),
            'todayVn' => CarbonImmutable::now(config('attendance.timezone'))->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $rawCode = strtoupper(trim((string) $request->input('code', '')));
        $request->merge([
            'code' => $rawCode !== '' ? $rawCode : Employee::suggestNextCode(),
        ]);

        $user = $request->user();
        $allowedBranchIds = $user->accessibleBranchIds();

        $branchRules = $allowedBranchIds !== null
            ? ['required', 'integer', 'exists:branches,id']
            : ['nullable', 'integer', 'exists:branches,id'];

        $data = $request->validate([
            'branch_id' => $branchRules,
            'code' => ['required', 'string', 'max:32', 'unique:employees,code'],
            'full_name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'employed_from' => ['nullable', 'date_format:Y-m-d'],
            'employed_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:employed_from'],
            'address' => ['nullable', 'string', 'max:500'],
            'department' => ['nullable', 'string', 'max:120'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
        ]);

        $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($allowedBranchIds !== null) {
            abort_unless($user->canAccessBranch($branchId), 403, 'Bạn chỉ có thể thêm nhân viên vào chi nhánh mình phụ trách.');
        }

        // Default to branch's default shift if none explicitly selected
        if (empty($data['shift_id']) && $branchId !== null) {
            $branch = Branch::find($branchId);
            if ($branch && $branch->default_shift_id) {
                $data['shift_id'] = $branch->default_shift_id;
            }
        }

        $employee = Employee::create([...$data, 'branch_id' => $branchId, 'active' => true]);
        $this->audit($request, 'employee.created', $employee, ['branch_id' => $branchId]);

        return back()->with('success', "Đã tạo nhân viên {$employee->code} – {$employee->full_name}. Hãy mở tab Đăng ký trên app chấm công để chụp khuôn mặt.");
    }

    public function update(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403, 'Bạn không có quyền sửa nhân viên thuộc chi nhánh khác.');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('employees', 'code')->ignore($employee->id)],
            'full_name' => ['required', 'string', 'max:160'],
            'gender' => ['nullable', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'employed_from' => ['nullable', 'date_format:Y-m-d'],
            'employed_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:employed_from'],
            'address' => ['nullable', 'string', 'max:500'],
            'department' => ['nullable', 'string', 'max:120'],
            'shift_id' => ['nullable', 'exists:shifts,id'],
            'active' => ['required', 'boolean'],
        ]);
        if (($data['shift_id'] ?? null) != $employee->shift_id && $employee->events()->exists()) {
            abort(422, 'Đã có lịch sử chấm công. Không sửa ca mặc định hồi tố; hãy dùng Phân ca theo ngày có hiệu lực.');
        }
        $oldRules = $employee->only(['code', 'branch_id', 'shift_id', 'active', 'employed_from', 'employed_until']);
        $employee->update($data);
        $newRules = $employee->only(['code', 'branch_id', 'shift_id', 'active', 'employed_from', 'employed_until']);
        $this->audit($request, 'employee.updated', $employee, [
            'rules_before' => $oldRules,
            'rules_after' => $newRules,
            'fields_changed' => array_keys($data),
        ]);

        return back()->with('success', 'Đã cập nhật hồ sơ.');
    }

    public function toggleActive(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403, 'Bạn không có quyền thay đổi trạng thái nhân viên chi nhánh khác.');

        $employee->update(['active' => !$employee->active]);
        $this->audit($request, $employee->active ? 'employee.activated' : 'employee.deactivated', $employee, [
            'active' => $employee->active,
        ]);

        $label = $employee->active ? 'kích hoạt' : 'vô hiệu hóa';

        return back()->with('success', "Đã {$label} nhân viên {$employee->code} – {$employee->full_name}.");
    }

    public function transfer(Request $request, Employee $employee)
    {
        $user = $request->user();
        abort_unless($user->canAccessBranch($employee->branch_id), 403, 'Bạn không có quyền điều chuyển nhân viên chi nhánh khác.');

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $toBranch = Branch::findOrFail((int) $data['to_branch_id']);
        abort_unless($toBranch->active, 422, 'Không thể điều chuyển nhân viên tới chi nhánh đang bị vô hiệu hóa.');
        abort_if($employee->branch_id === $toBranch->id, 422, 'Nhân viên hiện đã thuộc chi nhánh này.');

        // Regional accountants may transfer between branches within their managed regions;
        // System Admin / HR may transfer between any active branches.
        if (!$user->hasGlobalBranchAccess() && !$user->canAccessBranch($toBranch->id)) {
            abort(403, 'Bạn chỉ có thể điều chuyển nhân viên sang chi nhánh thuộc phạm vi phụ trách.');
        }

        $fromBranchId = $employee->branch_id;
        $fromShiftId = $employee->shift_id;
        $toShiftId = !empty($data['to_shift_id']) ? (int) $data['to_shift_id'] : $toBranch->default_shift_id;

        DB::transaction(function () use ($request, $employee, $fromBranchId, $toBranch, $fromShiftId, $toShiftId, $data) {
            EmployeeTransfer::create([
                'employee_id' => $employee->id,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranch->id,
                'from_shift_id' => $fromShiftId,
                'to_shift_id' => $toShiftId,
                'effective_date' => $data['effective_date'],
                'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
                'transferred_by' => $request->user()->id,
            ]);

            $updates = ['branch_id' => $toBranch->id];
            if ($toShiftId && !$employee->events()->exists()) {
                $updates['shift_id'] = $toShiftId;
            } elseif ($toShiftId && $toShiftId !== $fromShiftId) {
                // Preserve historical shift calculation by creating an effective-dated shift assignment
                $eff = $data['effective_date'];
                $prevDay = CarbonImmutable::parse($eff)->subDay()->toDateString();
                ShiftAssignment::where('employee_id', $employee->id)
                    ->whereNull('effective_to')
                    ->where('effective_from', '<', $eff)
                    ->update(['effective_to' => $prevDay]);

                $hasOverlap = ShiftAssignment::where('employee_id', $employee->id)
                    ->where('effective_from', '<=', '9999-12-31')
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $eff))
                    ->exists();
                if (!$hasOverlap) {
                    ShiftAssignment::create([
                        'employee_id' => $employee->id,
                        'shift_id' => $toShiftId,
                        'effective_from' => $eff,
                        'effective_to' => null,
                    ]);
                }
            }

            $employee->update($updates);

            $this->audit($request, 'employee.transferred', $employee, [
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranch->id,
                'to_branch_code' => $toBranch->code,
                'to_shift_id' => $toShiftId,
                'effective_date' => $data['effective_date'],
                'note' => $data['note'] ?? null,
            ]);
        });

        return back()->with('success', "Đã điều chuyển nhân viên {$employee->code} – {$employee->full_name} sang chi nhánh {$toBranch->code} – {$toBranch->name}.");
    }

    public function consent(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403);
        $data = $request->validate(['method' => ['required', 'in:signed_form,electronic_consent']]);
        $employee->update(['consent_at' => now('UTC'), 'consent_method' => $data['method']]);
        $this->audit($request, 'consent.recorded', $employee, ['method' => $data['method']]);

        return back()->with('success', 'Đã ghi nhận bằng chứng đồng ý theo phương thức đã chọn.');
    }

    public function revokeConsent(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403);
        DB::transaction(function () use ($employee, $request) {
            $employee->update(['consent_at' => null, 'consent_method' => null]);
            FaceTemplate::where('employee_id', $employee->id)->delete();
            EnrollmentTicket::where('employee_id', $employee->id)->delete();
            $this->audit($request, 'consent.revoked_and_templates_deleted', $employee);
        });

        return back()->with('success', 'Đã thu hồi sự đồng ý và xóa mẫu khuôn mặt trên server. Hãy đồng bộ lại thiết bị chấm công.');
    }

    public function assignShift(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403);
        $data = $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
        $to = $data['effective_to'] ?? '9999-12-31';
        $overlap = ShiftAssignment::where('employee_id', $employee->id)
            ->where('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $data['effective_from']))
            ->exists();
        abort_if($overlap, 422, 'Khoảng thời gian đã có phân ca; không được chồng lấn.');
        ShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $data['shift_id'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
        ]);
        $this->audit($request, 'shift.assigned', $employee, ['shift_id' => $data['shift_id'], 'from' => $data['effective_from'], 'to' => $data['effective_to'] ?? null]);

        return back()->with('success', 'Đã xếp ca theo giai đoạn.');
    }

    public function ticket(Request $request, Employee $employee)
    {
        abort_unless($request->user()->canAccessBranch($employee->branch_id), 403);
        abort_unless($employee->active && $employee->consent_at, 422, 'Nhân viên chưa có bằng chứng đồng ý.');
        $token = strtoupper(bin2hex(random_bytes(12)));
        EnrollmentTicket::create([
            'employee_id' => $employee->id,
            'token_hash' => hash('sha256', $token),
            'created_by' => $request->user()->id,
            'expires_at' => now('UTC')->addMinutes(10),
        ]);
        $this->audit($request, 'enrollment.ticket_issued', $employee);

        return back()->with('ticket', ['employee' => $employee->full_name, 'code' => $token]);
    }

    private function audit(Request $request, string $action, Employee $employee, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'employee',
            'subject_id' => (string) $employee->id,
            'details' => array_merge(['employee_code' => $employee->code], $extra),
        ]);
    }
}
