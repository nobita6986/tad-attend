<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceAdminController extends Controller
{
    public function index()
    {
        return view('admin.devices.index', [
            'devices' => Device::orderByDesc('active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $normalizedHwId = $this->normalizeHardwareId($request->input('hardware_id'));
        $request->merge(['hardware_id' => $normalizedHwId]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['nullable', 'in:both,in,out'],
            'hardware_id' => ['nullable', 'string', 'min:8', 'max:80', 'unique:devices,hardware_id'],
        ]);

        $direction = $data['direction'] ?? 'both';
        $token = bin2hex(random_bytes(32));
        $device = Device::create([
            'name' => trim($data['name']),
            'hardware_id' => $data['hardware_id'] ?? null,
            'direction' => $direction,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.issued',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'direction' => $device->direction,
                'hardware_id' => $device->hardware_id,
            ],
        ]);

        return redirect()->route('admin.devices.index')->with('issued_device', [
            'id' => $device->id,
            'name' => $device->name,
            'hardware_id' => $device->hardware_id,
            'direction' => $device->direction,
            'token' => $token,
        ]);
    }

    public function update(Request $request, Device $device)
    {
        abort_unless($device->active, 422, 'Không thể sửa thiết bị đã thu hồi.');

        $normalizedHwId = $this->normalizeHardwareId($request->input('hardware_id'));
        $request->merge(['hardware_id' => $normalizedHwId]);

        $data = $request->validate([
            'direction' => ['required', 'in:both,in,out'],
            'hardware_id' => ['nullable', 'string', 'min:8', 'max:80', Rule::unique('devices', 'hardware_id')->ignore($device->id)],
        ]);

        $before = ['direction' => $device->direction, 'hardware_id' => $device->hardware_id];
        $device->update([
            'direction' => $data['direction'],
            'hardware_id' => $data['hardware_id'] ?? null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.updated',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'before' => $before,
                'after' => ['direction' => $device->direction, 'hardware_id' => $device->hardware_id],
            ],
        ]);

        return back()->with('success', 'Đã cập nhật cấu hình thiết bị.');
    }

    public function revoke(Request $request, Device $device)
    {
        $device->update(['active' => false]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.revoked',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => ['name' => $device->name, 'hardware_id' => $device->hardware_id],
        ]);

        return back()->with('success', 'Đã thu hồi thiết bị.');
    }

    private function normalizeHardwareId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = strtoupper(trim($value));
        return $clean === '' ? null : $clean;
    }
}
