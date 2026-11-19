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
            'name' => 'Cổng vào Android 1',
            'direction' => 'in',
        ]);

        $response->assertRedirect('/admin/devices');
        $issued = session('issued_device');
        $this->assertIsArray($issued);
        $this->assertSame('Cổng vào Android 1', $issued['name']);
        $this->assertSame('in', $issued['direction']);
        $this->assertSame(64, strlen($issued['token']));

        $device = Device::findOrFail($issued['id']);
        $this->assertSame(hash('sha256', $issued['token']), $device->token_hash);
        $this->assertTrue($device->active);

        // Audit log must record issuance without storing the plaintext token.
        $audit = AuditLog::where('action', 'device.issued')->firstOrFail();
        $this->assertSame($device->id, $audit->subject_id);
        $this->assertStringNotContainsString($issued['token'], json_encode($audit->details));

        // Plaintext token authenticates the device API and exposes SFace 128-dim config.
        $this->withToken($issued['token'])
            ->getJson('/api/v1/device')
            ->assertOk()
            ->assertJsonPath('device_id', $device->id)
            ->assertJsonPath('direction', 'in')
            ->assertJsonPath('model_id', 'sface')
            ->assertJsonPath('model_version', '2021dec')
            ->assertJsonPath('embedding_dimensions', 128);

        // Revoking immediately invalidates the bearer token.
        $this->actingAs($admin)
            ->post("/admin/devices/{$device->id}/revoke")
            ->assertRedirect();

        $this->withToken($issued['token'])
            ->getJson('/api/v1/device')
            ->assertUnauthorized();
    }
}
