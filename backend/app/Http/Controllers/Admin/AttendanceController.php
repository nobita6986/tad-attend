<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable','integer','exists:employees,id'],
            'state' => ['nullable','in:accepted,pending_review,rejected'],
        ]);
        $date = $data['date'] ?? CarbonImmutable::now(config('attendance.timezone'))->format('Y-m-d');
        $start = CarbonImmutable::parse($date, config('attendance.timezone'))->startOfDay()->utc();
        $end = CarbonImmutable::parse($date, config('attendance.timezone'))->addDay()->startOfDay()->utc();
        $events = AttendanceEvent::with(['employee','device'])
            ->where('captured_at', '>=', $start)->where('captured_at', '<', $end)
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('review_state', $state))
            ->orderByDesc('captured_at')->paginate(40)->withQueryString();
        return view('admin.attendance.index', ['events' => $events, 'date' => $date, 'employees' => Employee::orderBy('code')->get()]);
    }
    public function image(AttendanceEvent $event)
    {
        abort_unless($event->image_path && Storage::disk('local')->exists($event->image_path), 404);
        return response()->file(Storage::disk('local')->path($event->image_path), [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline',
        ]);
    }
    public function review(Request $request, AttendanceEvent $event)
    {
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'reason' => ['required','string','min:5','max:500']]);
        abort_unless($event->review_state === 'pending_review', 422, 'Chỉ xét duyệt sự kiện chờ.');
        $event->update(['review_state' => $data['decision']]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'attendance.review',
            'subject_type' => 'attendance_event', 'subject_id' => (string)$event->id,
            'details' => ['from' => 'pending_review', 'to' => $data['decision'], 'reason' => $data['reason']]]);
        return back()->with('success', 'Đã lưu quyết định xét duyệt.');
    }
}
