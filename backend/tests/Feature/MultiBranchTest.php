<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Models\FaceTemplate;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiBranchTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Admin TAD',
            'email' => 'admin@tad.test',
            'password' => Hash::make('Password123!'),
            'role' => 'admin',
            'is_admin' => true,
            'active' => true,
        ]);
    }

    private function makeBranches(): array
    {
        $hn = Branch::create([
            'code' => 'HN',
            'name' => 'Trụ sở Hà Nội',
            'region' => 'north',
            'address' => 'Hoàng Mai, Hà Nội',
            'phone' => '02436342899',
            'manager_name' => 'Nguyễn Quản Lý HN',
            'manager_phone' => '0948381938',
            'active' => true,
        ]);
        $pt = Branch::create([
            'code' => 'PT',
            'name' => 'Chi nhánh Phú Thọ',
            'region' => 'north',
            'address' => 'Xuân Hòa, Phú Thọ',
            'phone' => '0210111222',
            'manager_name' => 'Trần Quản Lý PT',
            'active' => true,
        ]);
        $dn = Branch::create([
            'code' => 'DN',
            'name' => 'Chi nhánh Đà Nẵng',
            'region' => 'central',
            'address' => '194 Thăng Long, Đà Nẵng',
            'phone' => '0236111222',
            'manager_name' => 'Lê Quản Lý DN',
            'active' => true,
        ]);

        return [$hn, $pt, $dn];
    }

    private function makeShift(string $code, ?int $branchId = null, string $start = '08:00', string $end = '17:00'): Shift
    {
        return Shift::create([
            'branch_id' => $branchId,
            'code' => $code,
            'name' => 'Ca ' . $code,
            'starts_at' => $start,
            'ends_at' => $end,
            'break_minutes' => 60,
            'grace_minutes' => 5,
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        ]);
    }

    public function test_admin_can_create_update_and_toggle_branch_and_branch_shift(): void
    {
        $admin = $this->makeAdmin();
        $shift = $this->makeShift('HC');

        $this->actingAs($admin)->post('/admin/branches', [
            'code' => 'hcm',
            'name' => 'Chi nhánh TP.HCM',
            'region' => 'south',
            'address' => 'Quận 1, TP.HCM',
            'phone' => '0281234567',
            'manager_name' => 'Phạm Giám Đốc',
            'manager_phone' => '0909123456',
            'default_shift_id' => $shift->id,
        ])->assertRedirect('/admin/branches');

        $branch = Branch::where('code', 'HCM')->firstOrFail();
        $this->assertTrue($branch->active);
        $this->assertSame('south', $branch->region);
        $this->assertSame('Phạm Giám Đốc', $branch->manager_name);
        $this->assertSame($shift->id, $branch->default_shift_id);

        // Toggle deactivate then reactivate
        $this->actingAs($admin)->post("/admin/branches/{$branch->id}/toggle-active")->assertRedirect();
        $this->assertFalse($branch->fresh()->active);

        $this->actingAs($admin)->post("/admin/branches/{$branch->id}/toggle-active")->assertRedirect();
        $this->assertTrue($branch->fresh()->active);

        // Create branch-specific shift
        $this->actingAs($admin)->post('/admin/shifts', [
            'branch_id' => $branch->id,
            'code' => 'CA-HCM',
            'name' => 'Ca riêng HCM',
            'starts_at' => '07:30',
            'ends_at' => '16:30',
            'break_minutes' => 60,
            'grace_minutes' => 10,
            'weekdays' => [1, 2, 3, 4, 5],
        ])->assertRedirect();

        $this->assertDatabaseHas('shifts', ['code' => 'CA-HCM', 'branch_id' => $branch->id]);
    }

    public function test_branch_accountant_is_strictly_isolated_to_assigned_branch(): void
    {
        [$hn, , $dn] = $this->makeBranches();
        $shift = $this->makeShift('HC');

        $empHn = Employee::create(['branch_id' => $hn->id, 'code' => 'HN001', 'full_name' => 'Nhân viên Hà Nội', 'shift_id' => $shift->id, 'active' => true]);
        $empDn = Employee::create(['branch_id' => $dn->id, 'code' => 'DN001', 'full_name' => 'Nhân viên Đà Nẵng', 'shift_id' => $shift->id, 'active' => true]);

        $acctHn = User::create([
            'name' => 'Kế toán HN',
            'email' => 'kt.hn@tad.test',
            'password' => Hash::make('Password123!'),
            'role' => 'branch_accountant',
            'is_admin' => false,
            'active' => true,
        ]);
        $acctHn->branches()->sync([$hn->id]);

        // Sees HN employee, does NOT see DN employee
        $this->actingAs($acctHn)->get('/admin/employees')
            ->assertOk()
            ->assertSee('Nhân viên Hà Nội')
            ->assertDontSee('Nhân viên Đà Nẵng');

        // Cannot filter or export DN branch or DN employee -> 403
        $this->actingAs($acctHn)->get('/admin/employees?branch_id=' . $dn->id)->assertForbidden();
        $this->actingAs($acctHn)->get('/admin/reports/daily?branch_id=' . $dn->id)->assertForbidden();
        $this->actingAs($acctHn)->get('/admin/reports/export-summary?branch_id=' . $dn->id)->assertForbidden();
        $this->actingAs($acctHn)->get('/admin/reports/daily?employee_id=' . $empDn->id)->assertForbidden();
        $this->actingAs($acctHn)->post("/admin/employees/{$empDn->id}/toggle-active")->assertForbidden();

        // Can toggle active & view report for own HN branch
        $this->actingAs($acctHn)->post("/admin/employees/{$empHn->id}/toggle-active")->assertRedirect();
        $this->assertFalse($empHn->fresh()->active);

        $this->actingAs($acctHn)->get('/admin/reports/daily?branch_id=' . $hn->id)
            ->assertOk()
            ->assertSee('Nhân viên Hà Nội')
            ->assertDontSee('Nhân viên Đà Nẵng');

        // Cannot access system-admin-only /admin/branches or /admin/users
        $this->actingAs($acctHn)->get('/admin/branches')->assertForbidden();
        $this->actingAs($acctHn)->get('/admin/users')->assertForbidden();
    }

    public function test_regional_accountant_sees_all_branches_in_managed_region_only(): void
    {
        [$hn, $pt, $dn] = $this->makeBranches();
        $shift = $this->makeShift('HC');

        $empHn = Employee::create(['branch_id' => $hn->id, 'code' => 'HN001', 'full_name' => 'NV Bắc HN', 'shift_id' => $shift->id, 'active' => true]);
        $empPt = Employee::create(['branch_id' => $pt->id, 'code' => 'PT001', 'full_name' => 'NV Bắc PT', 'shift_id' => $shift->id, 'active' => true]);
        $empDn = Employee::create(['branch_id' => $dn->id, 'code' => 'DN001', 'full_name' => 'NV Trung DN', 'shift_id' => $shift->id, 'active' => true]);

        $acctNorth = User::create([
            'name' => 'Kế toán Miền Bắc',
            'email' => 'kt.north@tad.test',
            'password' => Hash::make('Password123!'),
            'role' => 'regional_accountant',
            'managed_regions' => ['north'],
            'is_admin' => false,
            'active' => true,
        ]);

        $this->actingAs($acctNorth)->get('/admin/employees')
            ->assertOk()
            ->assertSee('NV Bắc HN')
            ->assertSee('NV Bắc PT')
            ->assertDontSee('NV Trung DN');

        // Can transfer employee between HN and PT (both north)
        $this->actingAs($acctNorth)->post("/admin/employees/{$empHn->id}/transfer", [
            'to_branch_id' => $pt->id,
            'effective_date' => now('Asia/Ho_Chi_Minh')->toDateString(),
            'note' => 'Điều chuyển nội bộ Miền Bắc',
        ])->assertRedirect();

        $this->assertSame($pt->id, $empHn->fresh()->branch_id);
        $this->assertSame(1, EmployeeTransfer::where('employee_id', $empHn->id)->count());

        // Cannot transfer to DN (central) -> 403
        $this->actingAs($acctNorth)->post("/admin/employees/{$empPt->id}/transfer", [
            'to_branch_id' => $dn->id,
            'effective_date' => now('Asia/Ho_Chi_Minh')->toDateString(),
        ])->assertForbidden();

        // Cannot view DN report -> 403
        $this->actingAs($acctNorth)->get('/admin/reports/daily?branch_id=' . $dn->id)->assertForbidden();
        $this->assertSame($dn->id, $empDn->branch_id);
    }

    public function test_device_blocks_cross_branch_roster_and_attendance(): void
    {
        Storage::fake('local');
        config([
            'attendance.model_id' => 'mobilefacenet_test',
            'attendance.model_version' => '1.0',
            'attendance.embedding_dimensions' => 4,
            'attendance.min_score' => 0.7,
        ]);

        [$hn, , $dn] = $this->makeBranches();
        $shift = $this->makeShift('HC');

        $empHn = Employee::create(['branch_id' => $hn->id, 'code' => 'HN001', 'full_name' => 'NV HN', 'shift_id' => $shift->id, 'active' => true, 'consent_at' => now('UTC'), 'consent_method' => 'electronic_consent']);
        $empDn = Employee::create(['branch_id' => $dn->id, 'code' => 'DN001', 'full_name' => 'NV DN', 'shift_id' => $shift->id, 'active' => true, 'consent_at' => now('UTC'), 'consent_method' => 'electronic_consent']);

        $vec = [0.5, 0.5, 0.5, 0.5];
        foreach ([$empHn, $empDn] as $emp) {
            FaceTemplate::create([
                'employee_id' => $emp->id,
                'model_id' => 'mobilefacenet_test',
                'model_version' => '1.0',
                'embedding' => $vec,
                'active' => true,
                'enrolled_at' => now('UTC'),
            ]);
        }

        $token = 'branch-hn-device-secret-token-1234567890';
        $deviceHn = Device::create([
            'branch_id' => $hn->id,
            'name' => 'Máy HN',
            'hardware_id' => 'AND-HN01-0001-0001',
            'direction' => 'both',
            'token_hash' => hash('sha256', $token),
            'active' => true,
            'approval_status' => 'approved',
        ]);

        // Roster returns ONLY HN employee
        $rosterRes = $this->withToken($token)->getJson('/api/v1/roster?model_id=mobilefacenet_test&model_version=1.0')
            ->assertOk();
        $this->assertCount(1, $rosterRes->json('employees'));
        $this->assertSame('HN001', $rosterRes->json('employees.0.code'));

        // Attendance for DN employee on HN device -> 422 rejected (no cross-branch punching!)
        $img = UploadedFile::fake()->image('face.jpg', 200, 200);
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => Str::uuid()->toString(),
            'employee_id' => $empDn->id,
            'captured_at' => now('UTC')->toIso8601String(),
            'match_score' => 0.95,
            'liveness_passed' => '1',
            'liveness_method' => 'mlkit_head_turn',
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'offline' => '0',
            'image' => $img,
        ])->assertStatus(422);

        // Attendance for HN employee on HN device -> 201 and snapshots branch_id = HN
        $img2 = UploadedFile::fake()->image('face2.jpg', 200, 200);
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => Str::uuid()->toString(),
            'employee_id' => $empHn->id,
            'captured_at' => now('UTC')->toIso8601String(),
            'match_score' => 0.95,
            'liveness_passed' => '1',
            'liveness_method' => 'mlkit_head_turn',
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'offline' => '0',
            'image' => $img2,
        ])->assertStatus(201);

        $ev = AttendanceEvent::where('employee_id', $empHn->id)->firstOrFail();
        $this->assertSame($hn->id, $ev->branch_id);

        // Deactivating branch blocks device roster with 403
        $hn->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/roster?model_id=mobilefacenet_test&model_version=1.0')
            ->assertForbidden();
        $this->assertSame($deviceHn->branch_id, $hn->id);
    }

    public function test_branch_geofence_blocks_remote_punches_and_records_distance(): void
    {
        Storage::fake('local');
        config([
            'attendance.model_id' => 'mobilefacenet_test',
            'attendance.model_version' => '1.0',
            'attendance.embedding_dimensions' => 4,
            'attendance.min_score' => 0.7,
        ]);

        [$hn] = $this->makeBranches();
        $hn->update([
            'latitude' => 20.985421,
            'longitude' => 105.853192,
            'allowed_radius_meters' => 100,
            'enforce_geofence' => true,
        ]);
        $shift = $this->makeShift('HC');
        $emp = Employee::create([
            'branch_id' => $hn->id,
            'code' => 'HN099',
            'full_name' => 'NV Test GPS',
            'shift_id' => $shift->id,
            'active' => true,
            'consent_at' => now('UTC'),
            'consent_method' => 'electronic_consent',
        ]);
        FaceTemplate::create([
            'employee_id' => $emp->id,
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'embedding' => [0.5, 0.5, 0.5, 0.5],
            'active' => true,
            'enrolled_at' => now('UTC'),
        ]);

        $token = 'geofence-device-secret-token-123456789012';
        Device::create([
            'branch_id' => $hn->id,
            'name' => 'iPhone Cổng HN',
            'hardware_id' => 'IOS-HN01-0001-0001',
            'direction' => 'both',
            'token_hash' => hash('sha256', $token),
            'active' => true,
            'approval_status' => 'approved',
        ]);

        // 1. Missing GPS when enforce_geofence=true -> 422
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => Str::uuid()->toString(),
            'employee_id' => $emp->id,
            'captured_at' => now('UTC')->toIso8601String(),
            'match_score' => 0.95,
            'liveness_passed' => '1',
            'liveness_method' => 'arkit_head_turn',
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'offline' => '0',
            'image' => UploadedFile::fake()->image('a.jpg', 200, 200),
        ])->assertStatus(422);

        // 2. GPS ~1.1km away (taken home!) -> 422 rejected
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => Str::uuid()->toString(),
            'employee_id' => $emp->id,
            'captured_at' => now('UTC')->toIso8601String(),
            'match_score' => 0.95,
            'liveness_passed' => '1',
            'liveness_method' => 'arkit_head_turn',
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'offline' => '0',
            'latitude' => 20.995421,
            'longitude' => 105.853192,
            'gps_accuracy_meters' => 12,
            'image' => UploadedFile::fake()->image('b.jpg', 200, 200),
        ])->assertStatus(422);

        // 3. GPS ~11m away (inside 100m HQ radius) -> 201 accepted and distance recorded!
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => Str::uuid()->toString(),
            'employee_id' => $emp->id,
            'captured_at' => now('UTC')->toIso8601String(),
            'match_score' => 0.95,
            'liveness_passed' => '1',
            'liveness_method' => 'arkit_head_turn',
            'model_id' => 'mobilefacenet_test',
            'model_version' => '1.0',
            'offline' => '0',
            'latitude' => 20.985521,
            'longitude' => 105.853192,
            'gps_accuracy_meters' => 10.5,
            'image' => UploadedFile::fake()->image('c.jpg', 200, 200),
        ])->assertStatus(201);

        $ev = AttendanceEvent::where('employee_id', $emp->id)->firstOrFail();
        $this->assertNotNull($ev->distance_meters);
        $this->assertLessThan(20.0, $ev->distance_meters);
        $this->assertEquals(10.5, $ev->gps_accuracy_meters);
    }

    public function test_device_registration_stores_gps_and_can_apply_to_branch_on_approval(): void
    {
        $admin = $this->makeAdmin();
        [$hn] = $this->makeBranches();

        $res = $this->postJson('/api/v1/devices/register', [
            'hardware_id' => 'IOS-REG1-2222-3333-4444',
            'name' => 'iPhone Lễ Tân HN',
            'device_model' => 'iPhone (iOS 18.0)',
            'latitude' => 20.985421,
            'longitude' => 105.853192,
            'gps_accuracy_meters' => 9.5,
        ])->assertStatus(202);

        $device = Device::where('hardware_id', 'IOS-REG1-2222-3333-4444')->firstOrFail();
        $this->assertEquals(20.985421, $device->registered_latitude);
        $this->assertEquals(105.853192, $device->registered_longitude);
        $this->assertEquals(9.5, $device->registered_gps_accuracy_meters);
        $this->assertSame('pending', $res->json('status'));

        // Admin approves and applies registered GPS as branch HQ coordinates
        $this->actingAs($admin)->post("/admin/devices/{$device->id}/approve", [
            'branch_id' => $hn->id,
            'name' => 'iPhone Lễ Tân HN',
            'direction' => 'both',
            'apply_gps_to_branch' => '1',
        ])->assertRedirect('/admin/devices');

        $hn->refresh();
        $this->assertEquals(20.985421, $hn->latitude);
        $this->assertEquals(105.853192, $hn->longitude);
        $this->assertSame(150, $hn->allowed_radius_meters);
    }
}
