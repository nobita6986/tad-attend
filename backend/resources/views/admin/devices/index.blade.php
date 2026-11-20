@extends('admin.layout')
@section('title','Thiết bị')
@section('content')
@php
  $dirLabels = ['both' => 'Vào / Ra (chung)', 'in' => 'Chỉ cổng vào (in)', 'out' => 'Chỉ cổng ra (out)'];
  $tz = config('attendance.timezone');
@endphp

<div class="page-header">
  <div>
    <h1>Thiết bị chấm công</h1>
    <p class="page-subtitle">Cấp phát, cấu hình chế độ Vào/Ra và thu hồi token cho các máy Android / iPhone đặt tại cổng.</p>
  </div>
</div>

@if(session('issued_device'))
  @php($issued = session('issued_device'))
  <div class="alert alert--highlight" role="status">
    <x-icon name="shield" :size="22" style="color:var(--color-primary);flex-shrink:0;margin-top:2px" />
    <div style="flex:1;min-width:0">
      <strong style="color:var(--color-primary)">TOKEN THIẾT BỊ — CHỈ HIỂN THỊ MỘT LẦN</strong>
      <p style="margin:4px 0 8px">
        <strong>{{ $issued['name'] }}</strong>
        <x-badge variant="info" style="margin-inline-start:6px">{{ $dirLabels[$issued['direction']] ?? $issued['direction'] }}</x-badge>
        <span class="muted small" style="margin-inline-start:6px">UUID: <code>{{ $issued['id'] }}</code></span>
      </p>
      <div class="secret-box">
        <code id="issued-device-token" class="secret-code">{{ $issued['token'] }}</code>
        <button type="button" class="btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('issued-device-token').textContent).then(()=>{this.textContent='Đã sao chép'})">
          <x-icon name="copy" :size="14" /> Sao chép token
        </button>
      </div>
      <span class="muted small">Sao chép ngay và dán vào màn hình Cấu hình trên máy chấm công. Tải lại trang sẽ không xem lại được token này.</span>
    </div>
  </div>
@endif

<section class="card">
  <div class="card-head">
    <div>
      <h2><x-icon name="plus" :size="18" /> Cấp thiết bị mới</h2>
      <p class="card-subtitle">Mặc định 1 thiết bị dùng chung cho cả Vào/Ra (chỉ ghi nhận đúng người, đúng giờ; HR tổng hợp trên báo cáo).</p>
    </div>
  </div>

  <form method="post" action="{{ route('admin.devices.store') }}">
    @csrf
    <div class="form-grid" style="max-width:760px">
      <div class="field">
        <label for="device-name">Tên thiết bị / vị trí *</label>
        <input id="device-name" required name="name" maxlength="120" value="{{ old('name') }}" placeholder="Ví dụ: Cổng chính (Android)">
      </div>
      <div class="field">
        <label for="device-direction">Chế độ chấm công</label>
        <select id="device-direction" name="direction">
          <option value="both" @selected(old('direction', 'both') === 'both')>Chấm công chung — Vào / Ra (mặc định)</option>
          <option value="in" @selected(old('direction') === 'in')>Chỉ cổng vào (in)</option>
          <option value="out" @selected(old('direction') === 'out')>Chỉ cổng ra (out)</option>
        </select>
      </div>
    </div>
    <div style="margin-top:1rem">
      <button type="submit"><x-icon name="plus" :size="16" /> Tạo thiết bị &amp; sinh token</button>
    </div>
  </form>

  <details class="disclosure" style="margin-top:1rem">
    <summary>Hoặc cấp qua dòng lệnh CLI trên máy chủ</summary>
    <pre style="overflow:auto;background:#F1F5F9;padding:12px;border-radius:8px;margin:10px 0 0;font-size:0.84rem">php artisan devices:issue 'Cổng chính'
php artisan devices:issue 'Cổng vào 1' --direction=in
php artisan devices:issue 'Cổng ra 1' --direction=out</pre>
  </details>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="device" :size="18" /> Danh sách thiết bị ({{ $devices->count() }})</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách thiết bị" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Thiết bị</th>
          <th>UUID</th>
          <th>Chế độ</th>
          <th>Lần dùng gần nhất</th>
          <th>Trạng thái</th>
          <th>Hành động</th>
        </tr>
      </thead>
      <tbody>
        @forelse($devices as $device)
          <tr>
            <td data-label="Thiết bị"><strong>{{ $device->name }}</strong></td>
            <td data-label="UUID"><code>{{ $device->id }}</code></td>
            <td data-label="Chế độ">
              @if($device->active)
                <form method="post" action="{{ route('admin.devices.update', $device) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  @csrf @method('PUT')
                  <select name="direction" aria-label="Chế độ thiết bị {{ $device->name }}" style="min-block-size:34px;padding:4px 8px;width:auto">
                    <option value="both" @selected($device->direction === 'both')>Vào / Ra (chung)</option>
                    <option value="in" @selected($device->direction === 'in')>Chỉ vào (in)</option>
                    <option value="out" @selected($device->direction === 'out')>Chỉ ra (out)</option>
                  </select>
                  <button type="submit" class="btn-outline btn-sm">Lưu</button>
                </form>
              @else
                <x-badge variant="neutral">{{ $dirLabels[$device->direction] ?? $device->direction }}</x-badge>
              @endif
            </td>
            <td data-label="Kết nối gần nhất" class="num muted">{{ $device->last_seen_at?->timezone($tz)->format('d/m/Y H:i:s') ?? 'Chưa kết nối' }}</td>
            <td data-label="Trạng thái">
              <x-badge :variant="$device->active ? 'success' : 'danger'">{{ $device->active ? 'Hoạt động' : 'Đã thu hồi' }}</x-badge>
            </td>
            <td data-label="Hành động">
              @if($device->active)
                <form method="post" action="{{ route('admin.devices.revoke', $device) }}" onsubmit="return confirm('Thu hồi ngay token của thiết bị này?')" style="margin:0">
                  @csrf
                  <button class="btn-danger btn-sm" type="submit">Thu hồi</button>
                </form>
              @else
                <span class="muted small">—</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-state">Chưa có thiết bị nào. Hãy tạo thiết bị ở biểu mẫu phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
