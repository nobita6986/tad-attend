<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserAdminController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::orderByDesc('active')
                ->orderByRaw("CASE WHEN role = 'admin' OR is_admin = 1 THEN 0 WHEN role = 'hr' THEN 1 ELSE 2 END")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,hr'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $data['role'],
            'is_admin' => $data['role'] === 'admin',
            'active' => true,
            'password' => Hash::make($data['password']),
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'user.created',
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'details' => [
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);

        return redirect()->route('admin.users.index')->with('success', "Đã tạo tài khoản {$user->email} ({$this->roleLabel($user->role)}).");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'in:admin,hr'],
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
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere(fn ($q2) => $q2->where('is_admin', true)->where('role', '!=', 'hr')))
                ->count();
            abort_if($otherActiveAdmins === 0, 422, 'Hệ thống phải có ít nhất 1 tài khoản Admin đang hoạt động.');
        }

        $before = [
            'email' => $user->email,
            'role' => $user->effectiveRole(),
            'active' => $user->isActiveAccount(),
        ];

        $updates = [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'role' => $newRole,
            'is_admin' => $newRole === 'admin',
            'active' => $newActive,
        ];
        if (!empty($data['password'])) {
            $updates['password'] = Hash::make($data['password']);
        }
        $user->update($updates);

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
                ],
                'password_changed' => !empty($data['password']),
            ],
        ]);

        return back()->with('success', "Đã cập nhật tài khoản {$user->email}.");
    }

    private function roleLabel(string $role): string
    {
        return $role === 'admin' ? 'Admin' : 'HR';
    }
}
