<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceAdminController extends Controller
{
    public function index(Request $request)
    {
        $branchId = is_numeric($request->query('branch_id')) ? (int) $request->query('branch_id') : null;

        $pendingDevices = Device::with('branch')
            ->where('approval_status', 'pending')
            ->orderByDesc('requested_at')
            ->orderByDesc('created_at')
            ->get();

        $devices = Device::with('branch')
            ->where('approval_status', '!=', 'pending')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.devices.index', [
            'pendingDevices' => $pendingDevices,
            'devices' => $devices,
            'branches' => Branch::orderBy('region')->orderBy('code')->get(),
            'selectedBranchId' => $branchId,
        ]);
    }

    public function store(Request $request)
    {
        $normalizedHwId = $this->normalizeHardwareId($request->input('hardware_id'));
        $request->merge(['hardware_id' => $normalizedHwId]);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'direction' => ['nullable', 'in:both,in,out'],
            'hardware_id' => ['nullable', 'string', 'min:8', 'max:80', 'unique:devices,hardware_id'],
        ]);

        $direction = $data['direction'] ?? 'both';
        $branchId = !empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        $token = bin2hex(random_bytes(32));
        $device = Device::create([
            'branch_id' => $branchId,
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
                'branch_id' => $device->branch_id,
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'direction' => ['required', 'in:both,in,out'],
            'hardware_id' => ['nullable', 'string', 'min:8', 'max:80', Rule::unique('devices', 'hardware_id')->ignore($device->id)],
        ]);

        $before = ['branch_id' => $device->branch_id, 'direction' => $device->direction, 'hardware_id' => $device->hardware_id];
        $device->update([
            'branch_id' => !empty($data['branch_id']) ? (int) $data['branch_id'] : null,
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
                'after' => ['branch_id' => $device->branch_id, 'direction' => $device->direction, 'hardware_id' => $device->hardware_id],
            ],
        ]);

        return back()->with('success', 'Đã cập nhật cấu hình thiết bị.');
    }

    public function approve(Request $request, Device $device)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'direction' => ['nullable', 'in:both,in,out'],
            'apply_gps_to_branch' => ['nullable', 'boolean'],
        ]);

        $name = isset($data['name']) && trim((string) $data['name']) !== ''
            ? trim((string) $data['name'])
            : $device->name;
        $direction = $data['direction'] ?? $device->direction ?? 'both';
        $branchId = array_key_exists('branch_id', $data) && !empty($data['branch_id'])
            ? (int) $data['branch_id']
            : $device->branch_id;

        $device->update([
            'branch_id' => $branchId,
            'name' => $name,
            'direction' => $direction,
            'approval_status' => 'approved',
            'active' => true,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        ]);

        if ($request->boolean('apply_gps_to_branch') && $branchId && $device->registered_latitude !== null && $device->registered_longitude !== null) {
            $branch = Branch::find($branchId);
            if ($branch) {
                $branch->update([
                    'latitude' => $device->registered_latitude,
                    'longitude' => $device->registered_longitude,
                    'allowed_radius_meters' => $branch->allowed_radius_meters ?? 150,
                ]);
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'device.approved',
            'subject_type' => 'device',
            'subject_id' => $device->id,
            'details' => [
                'name' => $device->name,
                'branch_id' => $device->branch_id,
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
