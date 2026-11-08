@extends('admin.layout') @section('title','Thiết bị') @section('content')
<h1>Thiết bị iPhone</h1>
<section><p>Để cấp iPhone mới, thực hiện trên máy chủ bằng CLI, token chỉ hiển thị một lần:</p>
<pre style="overflow:auto;background:#f1f5f9;padding:15px;border-radius:8px">php artisan devices:issue 'Cổng vào 1' --direction=in
php artisan devices:issue 'Cổng ra 1' --direction=out</pre>
<p class="muted">Không được gửi token qua nhóm chat; nhập trực tiếp vào Keychain của iPhone qua giao diện cấu hình.</p></section>
<section><div class="table-scroll"><table><thead><tr><th>Thiết bị</th><th>UUID</th><th>Hướng</th><th>Lần dùng gần nhất</th><th>Trạng thái</th><th>Hành động</th></tr></thead><tbody>
@foreach($devices as $device)<tr><td>{{ $device->name }}</td><td><code>{{ $device->id }}</code></td><td>{{ $device->direction }}</td><td>{{ $device->last_seen_at?->timezone(config('attendance.timezone'))->format('d/m/Y H:i:s') ?? 'Chưa kết nối' }}</td><td>{{ $device->active ? 'Hoạt động' : 'Đã thu hồi' }}</td>
<td>@if($device->active)<form method="post" action="{{ route('admin.devices.revoke',$device) }}" onsubmit="return confirm('Thu hồi ngay token của thiết bị?')">@csrf<button class="danger">Thu hồi</button></form>@endif</td></tr>@endforeach
</tbody></table></div></section>@endsection
