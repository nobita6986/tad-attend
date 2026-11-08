<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\AuditLog;
use Illuminate\Http\Request;
class DeviceAdminController extends Controller
{
    public function index() { return view('admin.devices.index', ['devices' => Device::orderBy('name')->get()]); }
    public function revoke(Request $request, Device $device)
    {
        $device->update(['active' => false]);
        AuditLog::create(['user_id' => $request->user()->id, 'action' => 'device.revoked',
            'subject_type' => 'device', 'subject_id' => $device->id,
            'details' => ['name' => $device->name]]);
        return back()->with('success', 'Đã thu hồi thiết bị.');
    }
}
