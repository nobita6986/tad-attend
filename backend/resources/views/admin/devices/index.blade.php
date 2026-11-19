@extends('admin.layout') @section('title','Thiết bị') @section('content')
<h1>Thiết bị chấm công</h1>

@if(session('issued_device'))
@php($issued = session('issued_device'))
<div class="alert" style="border-width:2px">
  <strong>TOKEN THIẾT BỊ — CHỈ HIỂN THỊ MỘT LẦN</strong>
  <p style="margin:6px 0">
    <strong>{{ $issued['name'] }}</strong>
    ({{ $issued['direction'] === 'in' ? 'Cổng vào' : 'Cổng ra' }}) · UUID: <code>{{ $issued['id'] }}</code>
  </p>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0">
    <code id="issued-device-token" style="font-size:1.05rem;background:#fff;padding:8px 10px;border:1px solid #b8e2c1;border-radius:6px;word-break:break-all">{{ $issued['token'] }}</code>
    <button type="button" class="outline" onclick="navigator.clipboard.writeText(document.getElementById('issued-device-token').textContent).then(()=>{this.textContent='Đã sao chép'})">Sao chép token</button>
  </div>
  <span class="small">Sao chép ngay và nhập vào màn hình Cấu hình trên máy chấm công. Tải lại trang sẽ không xem lại được token này; không gửi qua nhóm chat công khai.</span>
</div>
@endif

<section>
  <h2>Cấp thiết bị mới</h2>
  <form method="post" action="{{ route('admin.devices.store') }}">
    @csrf
    <div class="form-grid" style="max-width:720px">
      <div>
        <label>Tên thiết bị / vị trí *</label>
        <input required name="name" maxlength="120" value="{{ old('name') }}" placeholder="Ví dụ: Cổng vào 1 (Android)">
      </div>
      <div>
        <label>Hướng chấm công *</label>
        <select name="direction" required>
          <option value="in" @selected(old('direction') === 'in')>Vào (in)</option>
          <option value="out" @selected(old('direction') === 'out')>Ra (out)</option>
        </select>
      </div>
    </div>
    <p style="margin-bottom:0"><button type="submit">Tạo thiết bị &amp; sinh token</button></p>
  </form>
  <details style="margin-top:14px">
    <summary class="small muted">Hoặc cấp qua CLI trên máy chủ</summary>
    <pre style="overflow:auto;background:#f1f5f9;padding:12px;border-radius:8px;margin:8px 0 0">php artisan devices:issue 'Cổng vào 1' --direction=in
php artisan devices:issue 'Cổng ra 1' --direction=out</pre>
  </details>
</section>

<section>
  <h2>Danh sách thiết bị</h2>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Thiết bị</th>
          <th>UUID</th>
          <th>Hướng</th>
          <th>Lần dùng gần nhất</th>
          <th>Trạng thái</th>
          <th>Hành động</th>
        </tr>
      </thead>
      <tbody>
        @forelse($devices as $device)
        <tr>
          <td><strong>{{ $device->name }}</strong></td>
          <td><code>{{ $device->id }}</code></td>
          <td>{{ $device->direction === 'in' ? 'Vào (in)' : 'Ra (out)' }}</td>
          <td>{{ $device->last_seen_at?->timezone(config('attendance.timezone'))->format('d/m/Y H:i:s') ?? 'Chưa kết nối' }}</td>
          <td><span class="pill {{ $device->active ? 'accepted' : 'rejected' }}">{{ $device->active ? 'Hoạt động' : 'Đã thu hồi' }}</span></td>
          <td>
            @if($device->active)
            <form method="post" action="{{ route('admin.devices.revoke', $device) }}" onsubmit="return confirm('Thu hồi ngay token của thiết bị này?')">
              @csrf
              <button class="danger">Thu hồi</button>
            </form>
            @else
            <span class="muted small">—</span>
            @endif
          </td>
        </tr>
        @empty
        <tr><td colspan="6" class="muted">Chưa có thiết bị nào. Hãy tạo thiết bị ở biểu mẫu phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
