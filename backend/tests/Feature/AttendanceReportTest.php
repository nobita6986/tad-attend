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

    public function test_single_shared_device_uses_first_and_last_punch_in_shift_window(): void
    {
        $shift = Shift::create(['code' => 'HC', 'name' => 'Hành chính', 'starts_at' => '08:00:00',
            'ends_at' => '17:30:00', 'grace_minutes' => 5, 'break_minutes' => 90,
            'weekdays' => [1,2,3,4,5,6,7]]);
        $employee = Employee::create(['code' => 'NV002', 'full_name' => 'Trần Văn B', 'shift_id' => $shift->id]);
        $device = Device::create(['name' => 'Cổng chính', 'direction' => 'both', 'token_hash' => hash('sha256', 'shared')]);

        // Day 1: 3 punches on same device -> first = 08:10 (5m late after 5m grace), last = 17:20 (10m early)
        foreach (['2026-11-10 08:10:00', '2026-11-10 12:05:00', '2026-11-10 17:20:00'] as $date) {
            AttendanceEvent::create(['event_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'employee_id' => $employee->id, 'device_id' => $device->id,
                'captured_at' => CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh')->utc(),
                'received_at' => CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh')->utc(),
                'direction' => 'both', 'review_state' => 'accepted', 'match_score' => 0.72,
                'liveness_method' => 'mlkit_head_turn', 'model_id' => 'sface', 'model_version' => '2021dec',
            ]);
        }

        // Day 2: only 1 punch on same device -> incomplete for HR review
        AttendanceEvent::create(['event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id, 'device_id' => $device->id,
            'captured_at' => CarbonImmutable::parse('2026-11-11 08:02:00', 'Asia/Ho_Chi_Minh')->utc(),
            'received_at' => CarbonImmutable::parse('2026-11-11 08:02:00', 'Asia/Ho_Chi_Minh')->utc(),
            'direction' => 'both', 'review_state' => 'accepted', 'match_score' => 0.75,
            'liveness_method' => 'mlkit_head_turn', 'model_id' => 'sface', 'model_version' => '2021dec',
        ]);

        $rows = app(AttendanceReportService::class)->dailyRows('2026-11-10', '2026-11-11', $employee->id);
        $this->assertCount(2, $rows);
        $this->assertSame('complete', $rows[0]['status']);
        $this->assertSame(3, $rows[0]['punches']);
        $this->assertSame(460, $rows[0]['work_minutes']); // 9h10m (550m) - 90m break
        $this->assertSame(5, $rows[0]['late_minutes']);
        $this->assertSame(10, $rows[0]['early_minutes']);

        $this->assertSame('incomplete', $rows[1]['status']);
        $this->assertSame(1, $rows[1]['punches']);
        $this->assertNotNull($rows[1]['in']);
        $this->assertNull($rows[1]['out']);
    }
}
