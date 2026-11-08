<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EnrollmentTicket;
use App\Models\FaceTemplate;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceController extends Controller
{
    private function deviceFor(Request $request): Device { return $request->attributes->get('attendance_device'); }

    public function device(Request $request): JsonResponse
    {
        $device = $this->deviceFor($request);
        return response()->json([
            'device_id' => $device->id, 'name' => $device->name, 'direction' => $device->direction,
            'server_time' => now('UTC')->toIso8601String(),
            'model_id' => config('attendance.model_id'),
            'model_version' => config('attendance.model_version'),
            'min_score' => config('attendance.min_score'),
        ]);
    }

    public function roster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_id' => ['required', 'string', 'max:80'],
            'model_version' => ['required', 'string', 'max:40'],
        ]);
        abort_unless($this->isConfiguredModel($data['model_id'], $data['model_version']), 409, 'Mô hình không đúng hoặc chưa cấu hình.');
        $templates = FaceTemplate::query()
            ->where('active', true)->where('model_id', $data['model_id'])->where('model_version', $data['model_version'])
            ->whereHas('employee', fn ($q) => $q->where('active', true)->whereNotNull('consent_at'))
            ->with('employee')->get();
        return response()->json(['employees' => $templates->map(fn ($t) => [
            'id' => $t->employee->id, 'code' => $t->employee->code,
            'full_name' => $t->employee->full_name, 'gender' => $t->employee->gender,
            'template' => $t->embedding,
        ])->values()]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'min:20', 'max:200'],
            'model_id' => ['required', 'string', 'max:80'],
            'model_version' => ['required', 'string', 'max:40'],
            'embedding' => ['required', 'array', 'size:'.config('attendance.embedding_dimensions')],
            'embedding.*' => ['required', 'numeric', 'between:-1,1'],
        ]);
        abort_unless($this->isConfiguredModel($data['model_id'], $data['model_version']), 409, 'Mô hình không đúng hoặc chưa cấu hình.');
        $embedding = array_map('floatval', array_values($data['embedding']));
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $embedding)));
        if (!is_finite($norm) || $norm < 0.98 || $norm > 1.02) {
            throw ValidationException::withMessages(['embedding' => 'Vector phải chuẩn hóa L2 và có giá trị hữu hạn.']);
        }
        $employeeId = DB::transaction(function () use ($request, $data, $embedding) {
            $ticket = EnrollmentTicket::where('token_hash', hash('sha256', $data['ticket']))->lockForUpdate()->first();
            abort_unless($ticket && !$ticket->consumed_at && $ticket->expires_at->isFuture(), 422, 'Mã đăng ký không hợp lệ hoặc hết hạn.');
            $employee = Employee::whereKey($ticket->employee_id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->active && $employee->consent_at, 422, 'Nhân viên chưa có xác nhận đồng ý hợp lệ.');
            FaceTemplate::where('employee_id', $employee->id)->update(['active' => false]);
            FaceTemplate::updateOrCreate(
                ['employee_id' => $employee->id, 'model_id' => $data['model_id'], 'model_version' => $data['model_version']],
                ['embedding' => $embedding, 'active' => true, 'enrolled_at' => now('UTC')]
            );
            $ticket->update(['consumed_at' => now('UTC')]);
            AuditLog::create(['action' => 'face.enrolled', 'subject_type' => 'employee', 'subject_id' => (string) $employee->id,
                'details' => ['device_id' => $this->deviceFor($request)->id, 'model_version' => $data['model_version']]]);
            return $employee->id;
        });
        return response()->json(['employee_id' => $employeeId, 'enrolled_at' => now('UTC')->toIso8601String()], 201);
    }

    public function attendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_uuid' => ['required', 'uuid'],
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'captured_at' => ['required', 'date'],
            'match_score' => ['required', 'numeric', 'between:0,1'],
            'liveness_passed' => ['required', 'accepted'],
            'liveness_method' => ['required', 'string', 'in:arkit_head_turn'],
            'model_id' => ['required', 'string', 'max:80'],
            'model_version' => ['required', 'string', 'max:40'],
            'offline' => ['required', 'boolean'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg', 'max:250', 'dimensions:max_width=1600,max_height=1600'],
        ]);
        $device = $this->deviceFor($request);
        // Do not accept unconfigured or uncalibrated model deployments.
        abort_unless($this->isConfiguredModel($data['model_id'], $data['model_version']), 409, 'Mô hình chưa cấu hình hoặc phiên bản không khớp.');
        abort_unless((float) $data['match_score'] >= (float) config('attendance.min_score'), 422, 'Độ tương đồng không đạt ngưỡng.');

        $existing = AttendanceEvent::where('event_uuid', $data['event_uuid'])->with('employee')->first();
        if ($existing) {
            abort_unless($existing->device_id === $device->id, 409, 'UUID sự kiện đã được thiết bị khác sử dụng.');
            return response()->json($this->result($existing, true));
        }

        $now = now('UTC');
        $captured = CarbonImmutable::parse($data['captured_at'])->utc();
        abort_if($captured->greaterThan($now->copy()->addMinute()), 422, 'Thời gian thiết bị ở tương lai.');
        abort_if($captured->lessThan($now->copy()->subDays(2)), 422, 'Sự kiện quá cũ.');
        $offline = $request->boolean('offline');
        $needsReview = $offline || abs($captured->diffInSeconds($now)) > 300;
        $imagePath = null;

        try {
            // Stored on private disk; no direct public URL.
            $imagePath = $request->file('image')->storeAs(
                'attendance/'.$now->format('Y/m'), Str::uuid()->toString().'.jpg', 'local'
            );
            $event = DB::transaction(function () use ($request, $data, $device, $now, $captured, $offline, $needsReview, $imagePath) {
                // Serialize per employee to prevent two devices creating simultaneous punches.
                $employee = Employee::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();
                $repeat = AttendanceEvent::where('event_uuid', $data['event_uuid'])->first();
                if ($repeat) {
                    abort_unless($repeat->device_id === $device->id, 409, 'UUID sự kiện đã thuộc thiết bị khác.');
                    return $repeat;
                }
                abort_unless($employee->active && $employee->consent_at, 422, 'Nhân viên chưa được phép chấm công sinh trắc.');
                $hasTemplate = FaceTemplate::where('employee_id', $employee->id)->where('active', true)
                    ->where('model_id', $data['model_id'])->where('model_version', $data['model_version'])->exists();
                abort_unless($hasTemplate, 422, 'Không có mẫu khuôn mặt hợp lệ cho phiên bản này.');
                $cooldown = (int) config('attendance.cooldown_seconds');
                // Offline sync may batch hours-apart punches in the same minute.
                // Compare captured timestamps for offline, server receipt for live requests.
                $recent = AttendanceEvent::where('employee_id', $employee->id)
                    ->when($offline,
                        fn ($q) => $q->whereBetween('captured_at', [$captured->subSeconds($cooldown), $captured->addSeconds($cooldown)]),
                        fn ($q) => $q->where('received_at', '>=', $now->copy()->subSeconds($cooldown)))
                    ->exists();
                abort_if($recent, 409, 'Chưa qua thời gian chống chấm lặp.');
                return AttendanceEvent::create([
                    'event_uuid' => $data['event_uuid'], 'employee_id' => $employee->id,
                    'device_id' => $device->id, 'captured_at' => $captured, 'received_at' => $now,
                    'direction' => $device->direction, 'review_state' => $needsReview ? 'pending_review' : 'accepted',
                    'is_offline' => $offline, 'match_score' => $data['match_score'],
                    'liveness_method' => $data['liveness_method'], 'model_id' => $data['model_id'],
                    'model_version' => $data['model_version'], 'image_path' => $imagePath,
                ]);
            });
            if ($event->image_path !== $imagePath) { Storage::disk('local')->delete($imagePath); }
            $event->load('employee');
            return response()->json($this->result($event, $event->image_path !== $imagePath), $event->image_path === $imagePath ? 201 : 200);
        } catch (\Throwable $e) {
            if ($imagePath) { Storage::disk('local')->delete($imagePath); }
            throw $e;
        }
    }

    private function isConfiguredModel(string $modelId, string $modelVersion): bool
    {
        return config('attendance.model_id') !== 'UNCONFIGURED'
            && config('attendance.model_version') !== 'UNCONFIGURED'
            && hash_equals((string) config('attendance.model_id'), $modelId)
            && hash_equals((string) config('attendance.model_version'), $modelVersion);
    }

    private function result(AttendanceEvent $event, bool $duplicate): array
    {
        return [
            'event_uuid' => $event->event_uuid, 'employee_name' => $event->employee->full_name,
            'direction' => $event->direction, 'recorded_at' => $event->received_at->toIso8601String(),
            'review_state' => $event->review_state, 'duplicate' => $duplicate,
        ];
    }
}
