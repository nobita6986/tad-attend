<?php
namespace Tests\Feature;
use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageRetentionTest extends TestCase
{
    use RefreshDatabase;
    public function test_prune_deletes_old_file_but_keeps_event(): void
    {
        Storage::fake('local');
        $employee = Employee::create(['code' => 'NV001', 'full_name' => 'A']);
        $device = Device::create(['name' => 'Cổng 1', 'direction' => 'in', 'token_hash' => hash('sha256','t')]);
        Storage::disk('local')->put('attendance/old.jpg', 'jpeg-file');
        AttendanceEvent::create(['event_uuid' => (string)\Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id, 'device_id' => $device->id,
            'captured_at' => now('UTC')->subDays(61), 'received_at' => now('UTC')->subDays(61),
            'direction' => 'in', 'review_state' => 'accepted', 'match_score' => 0.95,
            'liveness_method' => 'arkit_head_turn', 'model_id' => 'test', 'model_version' => '1',
            'image_path' => 'attendance/old.jpg']);
        $this->artisan('attendance:prune-images')->assertExitCode(0);
        $this->assertFalse(Storage::disk('local')->exists('attendance/old.jpg'));
        $this->assertDatabaseCount('attendance_events',1);
        $this->assertNull(AttendanceEvent::first()->image_path);
    }
}
