<?php
namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('StrongPass123!'),
            'is_admin' => true,
        ]);
    }

    public function test_guest_and_non_admin_cannot_issue_device(): void
    {
        $this->post('/admin/devices', ['name' => 'Cổng 1', 'direction' => 'in'])
            ->assertRedirect('/admin/login');

        $nonAdmin = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('StrongPass123!'),
            'is_admin' => false,
        ]);

        $this->actingAs($nonAdmin)
            ->post('/admin/devices', ['name' => 'Cổng 1', 'direction' => 'in'])
            ->assertForbidden();

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_admin_can_issue_device_on_web_use_token_and_revoke(): void
    {
        config()->set('attendance.model_id', 'sface');
        config()->set('attendance.model_version', '2021dec');
        config()->set('attendance.embedding_dimensions', 128);
        config()->set('attendance.min_score', 0.42);

        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post('/admin/devices', [
            'name' => 'Cổng chính Android',
        ]);

        $response->assertRedirect('/admin/devices');
        $issued = session('issued_device');
        $this->assertIsArray($issued);
        $this->assertSame('Cổng chính Android', $issued['name']);
        $this->assertSame('both', $issued['direction']);
        $this->assertSame(64, strlen($issued['token']));

        $device = Device::findOrFail($issued['id']);
        $this->assertSame(hash('sha256', $issued['token']), $device->token_hash);
        $this->assertTrue($device->active);

        // Audit log must record issuance without storing the plaintext token.
        $audit = AuditLog::where('action', 'device.issued')->firstOrFail();
        $this->assertSame($device->id, $audit->subject_id);
        $this->assertStringNotContainsString($issued['token'], json_encode($audit->details));

        // Plaintext token authenticates the device API and exposes SFace 128-dim config + both direction.
        $this->withToken($issued['token'])
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJsonPath('device_id', $device->id)
            ->assertJsonPath('direction', 'both')
            ->assertJsonPath('model_id', 'sface')
            ->assertJsonPath('model_version', '2021dec')
            ->assertJsonPath('embedding_dimensions', 128);

        // Admin can switch an active device's direction and audit log records the change.
        $this->actingAs($admin)
            ->put("/admin/devices/{$device->id}", ['direction' => 'in'])
            ->assertRedirect();
        $this->assertSame('in', $device->fresh()->direction);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.updated', 'subject_id' => $device->id]);

        // Revoking immediately invalidates the bearer token.
        $this->actingAs($admin)
            ->post("/admin/devices/{$device->id}/revoke")
            ->assertRedirect();

        $this->withToken($issued['token'])
            ->getJson('/api/v1/device')
            ->assertUnauthorized();
    }
}
