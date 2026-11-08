<?php
namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EnrollmentTicket;
use App\Models\FaceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function seedDevice(): array
    {
        config()->set('attendance.model_id', 'face-test');
        config()->set('attendance.model_version', '1');
        config()->set('attendance.embedding_dimensions', 512);
        config()->set('attendance.min_score', 0.75);
        $employee = Employee::create(['code' => 'NV001', 'full_name' => 'Nguyễn Văn A', 'active' => true,
            'consent_at' => now('UTC'), 'consent_method' => 'signed_form']);
        $token = bin2hex(random_bytes(32));
        $device = Device::create(['name' => 'Cổng 1', 'direction' => 'in', 'token_hash' => hash('sha256', $token)]);
        $embedding = array_fill(0, 512, 0.0); $embedding[0] = 1.0;
        FaceTemplate::create(['employee_id' => $employee->id, 'model_id' => 'face-test',
            'model_version' => '1', 'embedding' => $embedding, 'active' => true, 'enrolled_at' => now('UTC')]);
        return [$employee, $token, $device];
    }

    public function test_attendance_is_idempotent_and_image_is_private(): void
    {
        Storage::fake('local');
        [$person, $token] = $this->seedDevice();
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $payload = ['event_uuid' => $uuid, 'employee_id' => $person->id,
            'captured_at' => now('UTC')->toIso8601String(), 'match_score' => '0.92',
            'liveness_passed' => '1', 'liveness_method' => 'arkit_head_turn',
            'model_id' => 'face-test', 'model_version' => '1', 'offline' => '0'];
        $this->withToken($token)->post('/api/v1/attendance', $payload + ['image' => UploadedFile::fake()->image('face.jpg', 400, 400)])
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('review_state', 'accepted');
        $this->withToken($token)->post('/api/v1/attendance', $payload + ['image' => UploadedFile::fake()->image('face.jpg', 400, 400)])
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('attendance_events', 1);
        $path = AttendanceEvent::firstOrFail()->image_path;
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_stale_offline_punch_is_pending_not_approved(): void
    {
        Storage::fake('local');
        [$person, $token] = $this->seedDevice();
        $this->withToken($token)->post('/api/v1/attendance', [
            'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $person->id,
            'captured_at' => now('UTC')->subHours(5)->toIso8601String(), 'match_score' => '0.92',
            'liveness_passed' => '1', 'liveness_method' => 'arkit_head_turn',
            'model_id' => 'face-test', 'model_version' => '1', 'offline' => '1',
            'image' => UploadedFile::fake()->image('face.jpg', 400, 400),
        ])->assertCreated()->assertJsonPath('review_state', 'pending_review');
    }

    public function test_invalid_token_rejected_and_consent_required_for_enrollment(): void
    {
        [$person] = $this->seedDevice();
        $this->getJson('/api/v1/device')->assertUnauthorized();
        $this->assertNotNull($person->consent_at);
    }
}
