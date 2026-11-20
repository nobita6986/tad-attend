<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['nullable', 'in:both,in,out'],
        ]);

        $direction = $data['direction'] ?? 'both';
        $token = bin2hex(random_bytes(32));
        $device = Device::create([
            'name' => trim($data['name']),
            'direction' => $direction,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.issued',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => ['name' => $device->name, 'direction' => $device->direction],
        ]);

        return redirect()->route('admin.devices.index')->with('issued_device', [
            'id' => $device->id,
            'name' => $device->name,
            'direction' => $device->direction,
            'token' => $token,
        ]);
    }

    public function update(Request $request, Device $device)
    {
        abort_unless($device->active, 422, 'Không thể sửa thiết bị đã thu hồi.');

        $data = $request->validate([
            'direction' => ['required', 'in:both,in,out'],
        ]);

        $oldDirection = $device->direction;
        $device->update(['direction' => $data['direction']]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.updated',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'direction_before' => $oldDirection,
                'direction_after' => $device->direction,
            ],
        ]);

        return back()->with('success', 'Đã cập nhật chế độ chấm công của thiết bị.');
    }

    public function revoke(Request $request, Device $device)
    {
        $device->update(['active' => false]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.revoked',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => ['name' => $device->name],
        ]);

        return back()->with('success', 'Đã thu hồi thiết bị.');
    }
}
