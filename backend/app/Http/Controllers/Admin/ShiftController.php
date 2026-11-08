<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\AuditLog;
use App\Models\AttendanceEvent;
use Illuminate\Http\Request;
class ShiftController extends Controller
{
    public function index() { return view('admin.shifts.index', ['shifts' => Shift::orderBy('code')->get()]); }
    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $shift = Shift::create($data); $this->audit($request, $shift, ['created' => $data]);
        return back()->with('success', 'Đã tạo ca làm.');
    }
    public function update(Request $request, Shift $shift)
    {
        $data = $this->validated($request, $shift);
        // Changing shift rules retroactively would recalculate old timesheets incorrectly.
        $hasHistoricalEvents = AttendanceEvent::whereHas('employee', fn ($q) => $q->where('shift_id', $shift->id))->exists()
            || AttendanceEvent::whereHas('employee.shiftAssignments', fn ($q) => $q->where('shift_id', $shift->id))->exists();
        abort_if($hasHistoricalEvents, 422, 'Ca đã có dữ liệu chấm công. Hãy tạo ca mới và gán hiệu lực từ ngày thay đổi.');
        $before = $shift->only(['code','starts_at','ends_at','break_minutes','grace_minutes','weekdays']);
        $shift->update($data); $this->audit($request, $shift, ['before' => $before, 'after' => $data]);
        return back()->with('success', 'Đã cập nhật ca làm.');
    }
    private function validated(Request $request, ?Shift $shift): array
    {
        $data = $request->validate([
            'code' => ['required','string','max:30', \Illuminate\Validation\Rule::unique('shifts','code')->ignore($shift?->id)],
            'name' => ['required','string','max:255'],
            'starts_at' => ['required','date_format:H:i'],
            'ends_at' => ['required','date_format:H:i','different:starts_at'],
            'break_minutes' => ['required','integer','between:0,480'],
            'grace_minutes' => ['required','integer','between:0,120'],
            'weekdays' => ['required','array','min:1','max:7'],
            'weekdays.*' => ['integer','between:1,7','distinct'],
        ]);
        $data['weekdays'] = array_map('intval', $data['weekdays']);
        return $data;
    }
    private function audit(Request $request, Shift $shift, array $extra = []): void
    {
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'shift.saved',
            'subject_type' => 'shift', 'subject_id' => (string)$shift->id,
            'details' => array_merge(['code' => $shift->code], $extra)]);
    }
}
