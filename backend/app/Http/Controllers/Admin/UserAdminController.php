<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserAdminController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::with('branches')
                ->orderByDesc('active')
                ->orderByRaw("CASE WHEN role = 'admin' OR is_admin = 1 THEN 0 WHEN role = 'regional_accountant' THEN 1 WHEN role = 'branch_accountant' THEN 2 WHEN role = 'hr' THEN 3 ELSE 4 END")
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'branches' => Branch::orderBy('region')->orderBy('code')->get(),
            'regions' => Branch::REGIONS,
            'roleLabels' => User::ROLE_LABELS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::PORTAL_ROLES)],
            'managed_regions' => ['nullable', 'array'],
            'managed_regions.*' => [Rule::in(array_keys(Branch::REGIONS))],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        [$managedRegions, $branchIds] = $this->validateScopeForRole($data['role'], $data['managed_regions'] ?? [], $data['branch_ids'] ?? []);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
            'managed_regions' => $managedRegions,
            'is_admin' => $data['role'] === 'admin',
            'active' => true,
            'password' => Hash::make($data['password']),
        ]);
        $user->branches()->sync($branchIds);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.created',
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'details' => [
                'email' => $user->email,
                'role' => $user->role,
                'managed_regions' => $managedRegions,
                'branch_ids' => $branchIds,
            ],
        ]);

        return redirect()->route('admin.users.index')->with('success', "Đã tạo tài khoản {$user->email} ({$user->roleLabel()}).");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(User::PORTAL_ROLES)],
            'managed_regions' => ['nullable', 'array'],
            'managed_regions.*' => [Rule::in(array_keys(Branch::REGIONS))],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $newActive = (bool) $data['active'];
        $newRole = $data['role'];

        if ($user->id === $request->user()->id && (!$newActive || $newRole !== 'admin')) {
            abort(422, 'Không thể tự khóa hoặc hạ quyền Admin của chính bạn.');
        }

        $wasActiveAdmin = $user->isSystemAdmin();
        $willBeActiveAdmin = $newActive && $newRole === 'admin';
        if ($wasActiveAdmin && !$willBeActiveAdmin) {
            $otherActiveAdmins = User::where('id', '!=', $user->id)
                ->where('active', true)
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere(fn ($q2) => $q2->where('is_admin', true)->whereNotIn('role', ['hr', 'regional_accountant', 'branch_accountant'])))
                ->count();
            abort_if($otherActiveAdmins === 0, 422, 'Hệ thống phải có ít nhất 1 tài khoản Admin đang hoạt động.');
        }

        [$managedRegions, $branchIds] = $this->validateScopeForRole($newRole, $data['managed_regions'] ?? [], $data['branch_ids'] ?? []);

        $before = [
            'email' => $user->email,
            'role' => $user->effectiveRole(),
            'active' => $user->isActiveAccount(),
            'managed_regions' => $user->managed_regions,
            'branch_ids' => $user->branches()->pluck('branches.id')->all(),
        ];

        $updates = [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $newRole,
            'managed_regions' => $managedRegions,
            'is_admin' => $newRole === 'admin',
            'active' => $newActive,
        ];
        if (!empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }
        $user->update($updates);
        $user->branches()->sync($branchIds);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.updated',
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'details' => [
                'before' => $before,
                'after' => [
                    'email' => $user->email,
                    'role' => $user->role,
                    'active' => $user->active,
                    'managed_regions' => $managedRegions,
                    'branch_ids' => $branchIds,
                ],
                'password_changed' => !empty($data['password']),
            ],
        ]);

        return back()->with('success', "Đã cập nhật tài khoản {$user->email}.");
    }

    private function validateScopeForRole(string $role, array $regions, array $branchIds): array
    {
        if ($role === 'regional_accountant') {
            $cleanRegions = array_values(array_unique(array_intersect($regions, array_keys(Branch::REGIONS))));
            if ($cleanRegions === []) {
                throw ValidationException::withMessages(['managed_regions' => 'Kế toán toàn miền cần được gán ít nhất 1 miền phụ trách.']);
            }

            return [$cleanRegions, []];
        }

        if ($role === 'branch_accountant') {
            $cleanBranches = array_values(array_unique(array_map('intval', $branchIds)));
            if ($cleanBranches === []) {
                throw ValidationException::withMessages(['branch_ids' => 'Kế toán chi nhánh cần được gán ít nhất 1 chi nhánh phụ trách.']);
            }

            return [null, $cleanBranches];
        }

        return [null, []];
    }
}
