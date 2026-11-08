<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller
{
    public function showLogin() { return view('admin.login'); }
    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        if (!Auth::attempt([...$data, 'is_admin' => true])) {
            throw ValidationException::withMessages(['email' => 'Thông tin đăng nhập không đúng hoặc không có quyền.']);
        }
        $request->session()->regenerate();
        return redirect()->route('admin.dashboard');
    }
    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
