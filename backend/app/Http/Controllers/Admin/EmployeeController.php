<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EnrollmentTicket;
use App\Models\AuditLog;
use App\Models\FaceTemplate;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class EmployeeController extends Controller
{
    public function index()
    {
        return view('admin.employees.index', [
            'employees' => Employee::with(['shift','templates','shiftAssignments.shift'])->orderBy('code')->paginate(30),
            'shifts' => Shift::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required','string','max:32','unique:employees,code'],
            'full_name' => ['required','string','max:160'],
            'gender' => ['nullable','in:male,female,other'],
            'date_of_birth' => ['nullable','date','before:today'],
            'employed_from' => ['nullable','date_format:Y-m-d'],
            'employed_until' => ['nullable','date_format:Y-m-d','after_or_equal:employed_from'],
            'address' => ['nullable','string','max:500'],
            'department' => ['nullable','string','max:120'],
            'shift_id' => ['nullable','exists:shifts,id'],
        ]);
        $employee = Employee::create([...$data, 'active' => true]);
        $this->audit($request, 'employee.created', $employee);
        return back()->with('success', 'Đã tạo nhân viên.');
    }

    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'code' => ['required','string','max:32',\Illuminate\Validation\Rule::unique('employees','code')->ignore($employee->id)],
            'full_name' => ['required','string','max:160'],
            'gender' => ['nullable','in:male,female,other'],
            'date_of_birth' => ['nullable','date','before:today'],
            'employed_from' => ['nullable','date_format:Y-m-d'],
            'employed_until' => ['nullable','date_format:Y-m-d','after_or_equal:employed_from'],
            'address' => ['nullable','string','max:500'],
            'department' => ['nullable','string','max:120'],
            'shift_id' => ['nullable','exists:shifts,id'],
            'active' => ['required','boolean'],
        ]);
        if (($data['shift_id'] ?? null) != $employee->shift_id && $employee->events()->exists()) {
            abort(422, 'Đã có lịch sử chấm công. Không sửa ca mặc định hồi tố; hãy dùng Phân ca theo ngày có hiệu lực.');
        }
        $oldRules = $employee->only(['code','shift_id','active','employed_from','employed_until']);
        $employee->update($data);
        $newRules = $employee->only(['code','shift_id','active','employed_from','employed_until']);
        $this->audit($request, 'employee.updated', $employee,
            ['rules_before' => $oldRules, 'rules_after' => $newRules, 'fields_changed' => array_keys($data)]);
        return back()->with('success', 'Đã cập nhật hồ sơ.');
    }

    public function consent(Request $request, Employee $employee)
    {
        $data = $request->validate(['method' => ['required', 'in:signed_form,electronic_consent']]);
        // An admin must VERIFY external evidence; this is not a substitute for the employee's actual consent.
        $employee->update(['consent_at' => now('UTC'), 'consent_method' => $data['method']]);
        $this->audit($request, 'consent.recorded', $employee, ['method' => $data['method']]);
        return back()->with('success', 'Đã ghi nhận bằng chứng đồng ý theo phương thức đã chọn.');
    }

    public function revokeConsent(Request $request, Employee $employee)
    {
        DB::transaction(function () use ($employee, $request) {
            $employee->update(['consent_at' => null, 'consent_method' => null]);
            FaceTemplate::where('employee_id', $employee->id)->delete();
            EnrollmentTicket::where('employee_id', $employee->id)->delete();
            $this->audit($request, 'consent.revoked_and_templates_deleted', $employee);
        });
        return back()->with('success', 'Đã thu hồi sự đồng ý và xóa mẫu khuôn mặt trên server. Hãy cập nhật roster trên mọi iPhone.');
    }

    public function assignShift(Request $request, Employee $employee)
    {
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
        ShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $data['shift_id'],
            'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null]);
        $this->audit($request, 'shift.assigned', $employee, ['shift_id' => $data['shift_id'], 'from' => $data['effective_from'], 'to' => $data['effective_to'] ?? null]);
        return back()->with('success', 'Đã xếp ca theo giai đoạn.');
    }

    public function ticket(Request $request, Employee $employee)
    {
        abort_unless($employee->active && $employee->consent_at, 422, 'Nhân viên chưa có bằng chứng đồng ý.');
        $token = strtoupper(bin2hex(random_bytes(12)));
        EnrollmentTicket::create(['employee_id' => $employee->id, 'token_hash' => hash('sha256', $token),
            'created_by' => $request->user()->id, 'expires_at' => now('UTC')->addMinutes(10)]);
        $this->audit($request, 'enrollment.ticket_issued', $employee);
        return back()->with('ticket', ['employee' => $employee->full_name, 'code' => $token]);
    }

    private function audit(Request $request, string $action, Employee $employee, array $extra = []): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => $action,
            'subject_type' => 'employee', 'subject_id' => (string) $employee->id,
            'details' => array_merge(['employee_code' => $employee->code], $extra)]);
    }
}
