<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Admin HR',
            'email' => 'admin-empcode@example.com',
            'password' => Hash::make('secret1234'),
            'is_admin' => true,
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_auto_suggests_employee_code_adapts_to_pattern_and_allows_custom_override(): void
    {
        $admin = $this->makeAdmin();

        // 1. Empty DB suggests NV001 in form.
        $this->actingAs($admin)->get('/admin/employees')
            ->assertOk()
            ->assertSee('value="NV001"', false);

        // 2. Leaving code blank auto-generates NV001.
        $this->actingAs($admin)->post('/admin/employees', [
            'code' => '',
            'full_name' => 'Nguyễn Văn Một',
        ])->assertRedirect();
        $this->assertDatabaseHas('employees', ['code' => 'NV001', 'full_name' => 'Nguyễn Văn Một']);
        $this->assertSame('NV002', Employee::suggestNextCode());

        // 3. Admin overrides code to 5-digit numeric style (00001) -> next suggestion adapts to 00002.
        $this->actingAs($admin)->post('/admin/employees', [
            'code' => '00001',
            'full_name' => 'Nguyễn Đức Thuận',
        ])->assertRedirect();
        $this->assertDatabaseHas('employees', ['code' => '00001', 'full_name' => 'Nguyễn Đức Thuận']);
        $this->assertSame('00002', Employee::suggestNextCode());

        $this->actingAs($admin)->get('/admin/employees')
            ->assertOk()
            ->assertSee('value="00002"', false);

        // 4. Custom prefix pattern (TAD-09 -> TAD-10).
        $this->actingAs($admin)->post('/admin/employees', [
            'code' => 'tad-09',
            'full_name' => 'Trần Thị Chín',
        ])->assertRedirect();
        $this->assertDatabaseHas('employees', ['code' => 'TAD-09']);
        $this->assertSame('TAD-10', Employee::suggestNextCode());

        // 5. Duplicate custom code is rejected.
        $this->actingAs($admin)->post('/admin/employees', [
            'code' => '00001',
            'full_name' => 'Người Trùng Mã',
        ])->assertSessionHasErrors('code');
    }
}
