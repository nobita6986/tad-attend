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
        $pendingDevices = Device::where('approval_status', 'pending')
            ->orderByDesc('requested_at')
            ->orderByDesc('created_at')
            ->get();

        $devices = Device::where('approval_status', '!=', 'pending')
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.devices.index', [
            'pendingDevices' => $pendingDevices,
            'devices' => $devices,
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
            'approval_status' => 'approved',
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

    public function approve(Request $request, Device $device)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'direction' => ['nullable', 'in:both,in,out'],
        ]);

        $name = isset($data['name']) && trim((string) $data['name']) !== ''
            ? trim((string) $data['name'])
            : $device->name;
        $direction = $data['direction'] ?? $device->direction ?? 'both';

        $device->update([
            'name' => $name,
            'direction' => $direction,
            'approval_status' => 'approved',
            'active' => true,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.approved',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'hardware_id' => $device->hardware_id,
                'direction' => $device->direction,
            ],
        ]);

        return redirect()->route('admin.devices.index')
            ->with('success', "Đã duyệt thiết bị \"{$device->name}\". Ứng dụng trên điện thoại sẽ tự kích hoạt trong vài giây.");
    }

    public function reject(Request $request, Device $device)
    {
        $device->update([
            'approval_status' => 'rejected',
            'active' => false,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.rejected',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'hardware_id' => $device->hardware_id,
            ],
        ]);

        return redirect()->route('admin.devices.index')
            ->with('success', "Đã từ chối yêu cầu đăng ký của thiết bị \"{$device->name}\".");
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
