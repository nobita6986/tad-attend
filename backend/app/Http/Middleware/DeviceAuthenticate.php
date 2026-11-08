<?php
namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!is_string($token) || strlen($token) < 40 || strlen($token) > 200) {
            return response()->json(['message' => 'Thiết bị chưa được cấp quyền.'], 401);
        }
        $device = Device::where('token_hash', hash('sha256', $token))->where('active', true)->first();
        if (!$device) {
            return response()->json(['message' => 'Token không hợp lệ hoặc thiết bị đã bị thu hồi.'], 401);
        }
        $request->attributes->set('attendance_device', $device);
        $device->forceFill(['last_seen_at' => now('UTC')])->save();
        return $next($request);
    }
}
