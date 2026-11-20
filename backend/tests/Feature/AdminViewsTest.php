<?php
namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_admin_pages_render_cleanly(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Đăng nhập quản trị');

        $admin = User::create([
            'name' => 'Quản trị viên',
            'email' => 'ui-admin@example.com',
            'password' => Hash::make('StrongPass123!'),
            'is_admin' => true,
        ]);

        $shift = Shift::create([
            'code' => 'HC', 'name' => 'Hành chính', 'starts_at' => '08:00:00', 'ends_at' => '17:30:00',
            'break_minutes' => 90, 'grace_minutes' => 5, 'weekdays' => [1,2,3,4,5,6],
        ]);
        $emp = Employee::create([
            'code' => 'NV001', 'full_name' => 'Nguyễn Văn A', 'shift_id' => $shift->id,
            'active' => true, 'consent_at' => now('UTC'), 'consent_method' => 'electronic_consent',
        ]);
        $device = Device::create([
            'name' => 'Cổng chính', 'direction' => 'both', 'token_hash' => hash('sha256', 'tok'), 'active' => true,
        ]);
        AttendanceEvent::create([
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $emp->id, 'device_id' => $device->id,
            'captured_at' => now('UTC'), 'received_at' => now('UTC'),
            'direction' => 'both', 'review_state' => 'accepted', 'match_score' => 0.72,
            'liveness_method' => 'mlkit_head_turn', 'model_id' => 'sface', 'model_version' => '2021dec',
        ]);

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Tổng quan chấm công');
        $this->actingAs($admin)->get('/admin/employees')->assertOk()->assertSee('Nguyễn Văn A');
        $this->actingAs($admin)->get('/admin/shifts')->assertOk()->assertSee('Hành chính');
        $this->actingAs($admin)->get('/admin/attendance')->assertOk()->assertSee('Lượt chấm công');
        $this->actingAs($admin)->get('/admin/reports/daily')->assertOk()->assertSee('Báo cáo chấm công');
        $this->actingAs($admin)->get('/admin/devices')->assertOk()->assertSee('Cổng chính');
    }
}
