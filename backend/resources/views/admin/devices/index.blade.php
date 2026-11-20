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
    <p class="page-subtitle">Gắn cố định thiết bị theo <strong>Mã máy (Hardware ID)</strong> để tự động kết nối lại sau mỗi lần nâng cấp/cài lại app mà không cần nhập lại token.</p>
  </div>
</div>

@if(session('issued_device'))
  @php($issued = session('issued_device'))
  <div class="alert alert--highlight" role="status">
    <x-icon name="shield" :size="22" style="color:var(--color-primary);flex-shrink:0;margin-top:2px" />
    <div style="flex:1;min-width:0">
      <strong style="color:var(--color-primary)">ĐÃ TẠO THIẾT BỊ THÀNH CÔNG</strong>
      <p style="margin:4px 0 8px">
        <strong>{{ $issued['name'] }}</strong>
        <x-badge variant="info" style="margin-inline-start:6px">{{ $dirLabels[$issued['direction']] ?? $issued['direction'] }}</x-badge>
        @if(!empty($issued['hardware_id']))
          <x-badge variant="success" style="margin-inline-start:6px">Mã máy: {{ $issued['hardware_id'] }}</x-badge>
        @endif
      </p>
      @if(!empty($issued['hardware_id']))
        <p style="margin:0 0 8px;font-size:0.88rem">Thiết bị đã gắn <strong>Mã máy cố định</strong> — trên điện thoại Android chỉ cần mở app bấm <strong>Kết nối máy chủ</strong> là tự kích hoạt, không cần dán token thủ công.</p>
      @endif
      <div class="secret-box">
        <code id="issued-device-token" class="secret-code">{{ $issued['token'] }}</code>
        <button type="button" class="btn-outline btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('issued-device-token').textContent).then(()=>{this.textContent='Đã sao chép'})">
          <x-icon name="copy" :size="14" /> Sao chép token (dự phòng)
        </button>
      </div>
      <span class="muted small">Token thủ công chỉ hiển thị một lần. Nếu đã nhập Mã máy cố định, bạn không cần lưu lại token này.</span>
    </div>
  </div>
@endif

<section class="card">
  <div class="card-head">
    <div>
      <h2><x-icon name="plus" :size="18" /> Cấp thiết bị mới</h2>
      <p class="card-subtitle">Mở app Android để sao chép <strong>Mã máy</strong> (vd: <code>AND-XXXX-XXXX-XXXX-XXXX</code>) rồi dán vào đây để cố định thiết bị.</p>
    </div>
  </div>

  <form method="post" action="{{ route('admin.devices.store') }}">
    @csrf
    <div class="form-grid">
      <div class="field">
        <label for="device-name">Tên thiết bị / vị trí *</label>
        <input id="device-name" required name="name" maxlength="120" value="{{ old('name') }}" placeholder="Ví dụ: Cổng chính (Android)">
      </div>
      <div class="field">
        <label for="device-hw">Mã máy cố định (Hardware ID)</label>
        <input id="device-hw" name="hardware_id" maxlength="80" value="{{ old('hardware_id') }}" placeholder="AND-XXXX-XXXX-XXXX-XXXX" style="font-family:var(--font-mono)">
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
      <button type="submit"><x-icon name="plus" :size="16" /> Tạo thiết bị</button>
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
          <th>Mã máy cố định &amp; Chế độ</th>
          <th>Lần dùng gần nhất</th>
          <th>Trạng thái</th>
          <th>Hành động</th>
        </tr>
      </thead>
      <tbody>
        @forelse($devices as $device)
          <tr>
            <td data-label="Thiết bị">
              <strong>{{ $device->name }}</strong>
              <div class="muted small"><code>{{ $device->id }}</code></div>
            </td>
            <td data-label="Mã máy &amp; Chế độ">
              @if($device->active)
                <form method="post" action="{{ route('admin.devices.update', $device) }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  @csrf @method('PUT')
                  <input
                    name="hardware_id"
                    value="{{ $device->hardware_id }}"
                    placeholder="Nhập Mã máy (AND-...)"
                    aria-label="Mã máy cố định của {{ $device->name }}"
                    style="min-block-size:34px;padding:4px 8px;width:215px;font-family:var(--font-mono);font-size:0.82rem"
                  >
                  <select name="direction" aria-label="Chế độ thiết bị {{ $device->name }}" style="min-block-size:34px;padding:4px 8px;width:auto">
                    <option value="both" @selected($device->direction === 'both')>Vào / Ra (chung)</option>
                    <option value="in" @selected($device->direction === 'in')>Chỉ vào (in)</option>
                    <option value="out" @selected($device->direction === 'out')>Chỉ ra (out)</option>
                  </select>
                  <button type="submit" class="btn-outline btn-sm">Lưu</button>
                </form>
              @else
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                  @if($device->hardware_id)<code>{{ $device->hardware_id }}</code>@endif
                  <x-badge variant="neutral">{{ $dirLabels[$device->direction] ?? $device->direction }}</x-badge>
                </div>
              @endif
            </td>
            <td data-label="Kết nối gần nhất" class="num muted">{{ $device->last_seen_at?->timezone($tz)->format('d/m/Y H:i:s') ?? 'Chưa kết nối' }}</td>
            <td data-label="Trạng thái">
              <x-badge :variant="$device->active ? 'success' : 'danger'">{{ $device->active ? 'Hoạt động' : 'Đã thu hồi' }}</x-badge>
            </td>
            <td data-label="Hành động">
              @if($device->active)
                <form method="post" action="{{ route('admin.devices.revoke', $device) }}" onsubmit="return confirm('Thu hồi ngay quyền chấm công của thiết bị này?')" style="margin:0">
                  @csrf
                  <button class="btn-danger btn-sm" type="submit">Thu hồi</button>
                </form>
              @else
                <span class="muted small">—</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-state">Chưa có thiết bị nào. Hãy tạo thiết bị ở biểu mẫu phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
