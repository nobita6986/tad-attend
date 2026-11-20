<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaginationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Admin Pager',
            'email' => 'admin-pager@example.com',
            'password' => Hash::make('secret1234'),
            'is_admin' => true,
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_reports_daily_paginates_both_daily_and_summary_tables_past_100_rows(): void
    {
        $admin = $this->makeAdmin();
        $shift = Shift::create([
            'code' => 'HC',
            'name' => 'Hành chính',
            'starts_at' => '08:00:00',
            'ends_at' => '17:30:00',
            'break_minutes' => 90,
            'grace_minutes' => 10,
            'weekdays' => [1, 2, 3, 4, 5, 6],
        ]);

        // 25 employees over 10 days = 250 daily rows (> old 100-row hard cap) & 25 summary rows (> 20/page).
        for ($i = 1; $i <= 25; $i++) {
            Employee::create([
                'code' => sprintf('NV%03d', $i),
                'full_name' => sprintf('Nhân Viên %02d', $i),
                'shift_id' => $shift->id,
                'active' => true,
            ]);
        }

        $resPage1 = $this->actingAs($admin)
            ->get('/admin/reports/daily?from=2026-11-01&to=2026-11-10&per_page=20')
            ->assertOk()
            ->assertSee('Bảng công theo ngày (250 dòng)')
            ->assertSee('Tổng hợp theo nhân viên (25)')
            ->assertSee('Trang 1/13')
            ->assertSee('Trang 1/2')
            ->assertSee('Sau ›')
            ->assertDontSee('Hiển thị tối đa 100')
            ->assertDontSee('Showing ');

        // Links must preserve filter query parameters.
        $resPage1->assertSee('from=2026-11-01', false);
        $resPage1->assertSee('to=2026-11-10', false);
        $resPage1->assertSee('per_page=20', false);
        $resPage1->assertSee('page=2#daily-table', false);
        $resPage1->assertSee('summary_page=2#summary-table', false);

        // Navigating to page 13 (rows 241-250, beyond old 100-row limit) works cleanly.
        $this->actingAs($admin)
            ->get('/admin/reports/daily?from=2026-11-01&to=2026-11-10&per_page=20&page=13&summary_page=2')
            ->assertOk()
            ->assertSee('Trang 13/13')
            ->assertSee('Trang 2/2')
            ->assertSee('Nhân Viên 25')
            ->assertSee('‹ Trước');
    }

    public function test_employees_attendance_devices_shifts_and_users_pages_paginate_cleanly(): void
    {
        $admin = $this->makeAdmin();

        // Shifts (22 > 20/page)
        for ($i = 1; $i <= 22; $i++) {
            Shift::create([
                'code' => sprintf('CA%02d', $i),
                'name' => sprintf('Ca làm %02d', $i),
                'starts_at' => '08:00:00',
                'ends_at' => '17:00:00',
                'break_minutes' => 60,
                'grace_minutes' => 10,
                'weekdays' => [1, 2, 3, 4, 5],
            ]);
        }

        // Employees (25 > 20/page when per_page=20)
        $firstEmp = null;
        for ($i = 1; $i <= 25; $i++) {
            $emp = Employee::create([
                'code' => sprintf('E%03d', $i),
                'full_name' => sprintf('Người Lao Động %02d', $i),
                'active' => true,
            ]);
            if ($i === 1) {
                $firstEmp = $emp;
            }
        }

        // Devices (22 approved > 20/page)
        $firstDevice = null;
        for ($i = 1; $i <= 22; $i++) {
            $dev = Device::create([
                'name' => sprintf('Máy %02d', $i),
                'direction' => 'both',
                'token_hash' => hash('sha256', Str::random(64)),
                'active' => true,
                'approval_status' => 'approved',
            ]);
            if ($i === 1) {
                $firstDevice = $dev;
            }
        }

        // Attendance events on 2026-11-20 (25 > 20/page when per_page=20)
        $base = CarbonImmutable::parse('2026-11-20 08:00:00', config('attendance.timezone'))->utc();
        for ($i = 0; $i < 25; $i++) {
            AttendanceEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'employee_id' => $firstEmp->id,
                'device_id' => $firstDevice->id,
                'captured_at' => $base->addMinutes($i),
                'received_at' => $base->addMinutes($i),
                'direction' => 'both',
                'match_score' => 0.85,
                'liveness_passed' => true,
                'liveness_method' => 'mlkit_head_turn',
                'model_id' => 'sface',
                'model_version' => '2021dec',
                'is_offline' => false,
                'review_state' => 'accepted',
            ]);
        }

        // Users (22 total including admin > 20/page)
        for ($i = 1; $i <= 21; $i++) {
            User::create([
                'name' => sprintf('HR User %02d', $i),
                'email' => sprintf('hr%02d@example.com', $i),
                'password' => Hash::make('secret1234'),
                'is_admin' => false,
                'role' => 'hr',
                'active' => true,
            ]);
        }

        $this->actingAs($admin)
            ->get('/admin/employees?per_page=20')
            ->assertOk()
            ->assertSee('Danh sách nhân viên (25)')
            ->assertSee('Trang 1/2')
            ->assertSee('per_page=20&amp;page=2', false)
            ->assertDontSee('Showing ');

        $this->actingAs($admin)
            ->get('/admin/attendance?date=2026-11-20&per_page=20')
            ->assertOk()
            ->assertSee('(25 lượt)')
            ->assertSee('Trang 1/2')
            ->assertSee('date=2026-11-20', false)
            ->assertSee('per_page=20', false);

        $this->actingAs($admin)
            ->get('/admin/devices?page=2')
            ->assertOk()
            ->assertSee('Danh sách thiết bị (22)')
            ->assertSee('Trang 2/2');

        $this->actingAs($admin)
            ->get('/admin/shifts?page=2')
            ->assertOk()
            ->assertSee('Danh sách ca hiện tại (22)')
            ->assertSee('Trang 2/2');

        $this->actingAs($admin)
            ->get('/admin/users?page=2')
            ->assertOk()
            ->assertSee('Danh sách tài khoản (22)')
            ->assertSee('Trang 2/2');
    }
}
