<?php
namespace Tests\Feature;
use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AttendanceReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_night_shift_counts_punch_out_on_next_day(): void
    {
        $shift = Shift::create(['code' => 'D', 'name' => 'Ca đêm', 'starts_at' => '22:00:00',
            'ends_at' => '06:00:00', 'grace_minutes' => 5, 'break_minutes' => 30,
            'weekdays' => [1,2,3,4,5,6,7]]);
        $employee = Employee::create(['code' => 'NV001', 'full_name' => 'Nguyễn Văn A', 'shift_id' => $shift->id]);
        $device = Device::create(['name' => 'Cổng 1', 'direction' => 'in', 'token_hash' => hash('sha256', 'test')]);
        foreach ([['2026-11-08 22:03:00','in'],['2026-11-09 06:05:00','out']] as [$date, $direction]) {
            AttendanceEvent::create(['event_uuid' => (string)\Illuminate\Support\Str::uuid(),
                'employee_id' => $employee->id, 'device_id' => $device->id,
                'captured_at' => CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh')->utc(),
                'received_at' => CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh')->utc(),
                'direction' => $direction, 'review_state' => 'accepted', 'match_score' => 0.90,
                'liveness_method' => 'arkit_head_turn', 'model_id' => 'test', 'model_version' => '1',
            ]);
        }
        $rows = app(AttendanceReportService::class)->dailyRows('2026-11-08', '2026-11-08', $employee->id);
        $this->assertCount(1, $rows);
        $this->assertSame('complete', $rows[0]['status']);
        $this->assertSame(452, $rows[0]['work_minutes']); // 8h02m - 30m
    }
}
