<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $region = $request->query('region');
        if (!in_array($region, array_keys(Branch::REGIONS), true)) {
            $region = null;
        }

        $branches = Branch::query()
            ->with('defaultShift')
            ->withCount(['employees', 'devices', 'shifts'])
            ->when($region, fn ($q) => $q->where('region', $region))
            ->orderByDesc('active')
            ->orderBy('region')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('admin.branches.index', [
            'branches' => $branches,
            'regions' => Branch::REGIONS,
            'selectedRegion' => $region,
            'shifts' => Shift::with('branch')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code', ''))),
        ]);

        $data = $this->validated($request, null);
        $branch = Branch::create([...$data, 'active' => true]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'branch.created',
            'subject_type' => 'branch',
            'subject_id' => (string) $branch->id,
            'details' => [
                'code' => $branch->code,
                'name' => $branch->name,
                'region' => $branch->region,
            ],
        ]);

        return redirect()->route('admin.branches.index')
            ->with('success', "Đã tạo chi nhánh {$branch->code} – {$branch->name}.");
    }

    public function update(Request $request, Branch $branch)
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code', ''))),
        ]);

        $data = $this->validated($request, $branch);
        $before = $branch->only(['code', 'name', 'region', 'phone', 'manager_name', 'default_shift_id', 'active']);
        $branch->update($data);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'branch.updated',
            'subject_type' => 'branch',
            'subject_id' => (string) $branch->id,
            'details' => [
                'before' => $before,
                'after' => $branch->only(['code', 'name', 'region', 'phone', 'manager_name', 'default_shift_id', 'active']),
            ],
        ]);

        return back()->with('success', "Đã cập nhật chi nhánh {$branch->code} – {$branch->name}.");
    }

    public function toggleActive(Request $request, Branch $branch)
    {
        $branch->update(['active' => !$branch->active]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $branch->active ? 'branch.activated' : 'branch.deactivated',
            'subject_type' => 'branch',
            'subject_id' => (string) $branch->id,
            'details' => ['code' => $branch->code, 'active' => $branch->active],
        ]);

        $label = $branch->active ? 'kích hoạt' : 'vô hiệu hóa';

        return back()->with('success', "Đã {$label} chi nhánh {$branch->code} – {$branch->name}.");
    }

    private function validated(Request $request, ?Branch $branch): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:30', Rule::unique('branches', 'code')->ignore($branch?->id)],
            'name' => ['required', 'string', 'max:160'],
            'region' => ['required', Rule::in(array_keys(Branch::REGIONS))],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'manager_name' => ['nullable', 'string', 'max:160'],
            'manager_phone' => ['nullable', 'string', 'max:40'],
            'default_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ];
        if ($branch !== null) {
            $rules['active'] = ['required', 'boolean'];
        }

        $data = $request->validate($rules);
        foreach (['name', 'address', 'phone', 'manager_name', 'manager_phone'] as $key) {
            if (array_key_exists($key, $data)) {
                $trimmed = trim((string) $data[$key]);
                $data[$key] = $trimmed === '' ? null : $trimmed;
            }
        }
        $data['default_shift_id'] = !empty($data['default_shift_id']) ? (int) $data['default_shift_id'] : null;

        return $data;
    }
}
