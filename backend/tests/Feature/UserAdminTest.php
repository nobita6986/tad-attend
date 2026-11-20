<?php
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email = 'boss@example.com'): User
    {
        return User::create([
            'name' => 'Boss Admin',
            'email' => $email,
            'password' => Hash::make('StrongPass123!'),
            'is_admin' => true,
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_admin_can_create_hr_and_hr_has_scoped_permissions(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Nguyễn Thị HR',
            'email' => 'hr@example.com',
            'role' => 'hr',
            'password' => 'HrSecret2026!',
            'password_confirmation' => 'HrSecret2026!',
        ])->assertRedirect('/admin/users');

        $hr = User::where('email', 'hr@example.com')->firstOrFail();
        $this->assertSame('hr', $hr->role);
        $this->assertFalse($hr->is_admin);
        $this->assertTrue($hr->active);

        $audit = AuditLog::where('action', 'user.created')->firstOrFail();
        $this->assertStringNotContainsString('HrSecret2026!', json_encode($audit->details));

        // HR can log in via web form.
        $this->post('/admin/login', [
            'email' => 'hr@example.com',
            'password' => 'HrSecret2026!',
        ])->assertRedirect('/admin');

        // HR can access operational pages.
        $this->actingAs($hr)->get('/admin')->assertOk();
        $this->actingAs($hr)->get('/admin/employees')->assertOk();
        $this->actingAs($hr)->get('/admin/shifts')->assertOk();
        $this->actingAs($hr)->get('/admin/attendance')->assertOk();
        $this->actingAs($hr)->get('/admin/reports/daily')->assertOk();

        // HR is forbidden from device management & user management.
        $this->actingAs($hr)->get('/admin/devices')->assertForbidden();
        $this->actingAs($hr)->post('/admin/devices', ['name' => 'X', 'direction' => 'both'])->assertForbidden();
        $this->actingAs($hr)->get('/admin/users')->assertForbidden();
        $this->actingAs($hr)->post('/admin/users', [
            'name' => 'Fake', 'email' => 'fake@example.com', 'role' => 'admin',
            'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertForbidden();
    }

    public function test_deactivated_hr_cannot_login_and_admin_cannot_lock_self(): void
    {
        $admin = $this->makeAdmin();
        $hr = User::create([
            'name' => 'HR 2',
            'email' => 'hr2@example.com',
            'password' => Hash::make('HrSecret2026!'),
            'is_admin' => false,
            'role' => 'hr',
            'active' => true,
        ]);

        // Admin cannot deactivate or demote self.
        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => 'Boss Admin',
            'email' => $admin->email,
            'role' => 'hr',
            'active' => 1,
        ])->assertUnprocessable();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => 'Boss Admin',
            'email' => $admin->email,
            'role' => 'admin',
            'active' => 0,
        ])->assertUnprocessable();

        // Admin deactivates HR.
        $this->actingAs($admin)->put("/admin/users/{$hr->id}", [
            'name' => 'HR 2',
            'email' => $hr->email,
            'role' => 'hr',
            'active' => 0,
        ])->assertRedirect();

        $hr->refresh();
        $this->assertFalse($hr->active);
        $this->actingAs($hr)->get('/admin')->assertForbidden();

        // Deactivated HR cannot log in.
        $this->post('/admin/login', [
            'email' => 'hr2@example.com',
            'password' => 'HrSecret2026!',
        ])->assertSessionHasErrors('email');
    }
}
