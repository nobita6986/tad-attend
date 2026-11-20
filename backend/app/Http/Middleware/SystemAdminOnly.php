<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SystemAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && $request->user()->isSystemAdmin(), 403, 'Chỉ tài khoản Quản trị hệ thống (Admin) mới có quyền truy cập mục này.');
        return $next($request);
    }
}
