@extends('admin.layout')
@section('title','Chi nhánh')
@section('content')
<div class="page-header">
  <div>
    <h1>Quản lý chi nhánh</h1>
    <p class="page-subtitle">Cấu hình thông tin chi nhánh theo miền, người quản lý, ca làm việc mặc định và trạng thái hoạt động. Nhân viên &amp; thiết bị chấm công được tách bạch hoàn toàn theo chi nhánh.</p>
  </div>
</div>

<section class="card">
  <details class="disclosure" @if($errors->any()) open @endif>
    <summary><x-icon name="plus" :size="16" /> Thêm chi nhánh mới</summary>
    <form method="post" action="{{ route('admin.branches.store') }}" style="margin-top:1rem">
      @csrf
      <div class="form-grid">
        <div class="field">
          <label for="br-code">Mã chi nhánh *</label>
          <input id="br-code" required name="code" maxlength="30" value="{{ old('code') }}" placeholder="VD: HN, PT, DN">
        </div>
        <div class="field">
          <label for="br-name">Tên chi nhánh *</label>
          <input id="br-name" required name="name" maxlength="160" value="{{ old('name') }}" placeholder="Trụ sở Hà Nội">
        </div>
        <div class="field">
          <label for="br-region">Miền *</label>
          <select id="br-region" name="region" required>
            @foreach($regions as $rKey => $rLabel)
              <option value="{{ $rKey }}" @selected(old('region', 'north') === $rKey)>{{ $rLabel }}</option>
            @endforeach
          </select>
        </div>
        <div class="field">
          <label for="br-phone">Số điện thoại chi nhánh</label>
          <input id="br-phone" name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="024 3634 2899">
        </div>
        <div class="field">
          <label for="br-manager">Người quản lý chi nhánh</label>
          <input id="br-manager" name="manager_name" maxlength="160" value="{{ old('manager_name') }}" placeholder="Nguyễn Văn A">
        </div>
        <div class="field">
          <label for="br-manager-phone">SĐT người quản lý</label>
          <input id="br-manager-phone" name="manager_phone" maxlength="40" value="{{ old('manager_phone') }}" placeholder="0948.381.938">
        </div>
        <div class="field">
          <label for="br-shift">Ca làm / giờ vào-ra mặc định</label>
          <select id="br-shift" name="default_shift_id">
            <option value="">Chưa gắn ca mặc định</option>
            @foreach($shifts as $shift)
              <option value="{{ $shift->id }}" @selected(old('default_shift_id') == $shift->id)>
                {{ $shift->code }} – {{ $shift->name }} ({{ substr($shift->starts_at, 0, 5) }}–{{ substr($shift->ends_at, 0, 5) }})
              </option>
            @endforeach
          </select>
        </div>
        <div class="field" style="grid-column:1 / -1">
          <label for="br-addr">Địa chỉ chi nhánh</label>
          <input id="br-addr" name="address" maxlength="500" value="{{ old('address') }}" placeholder="Số 111C, Ngách 299/62, Đ. Hoàng Mai, Hà Nội">
        </div>
        <div class="field">
          <label for="br-lat">Vĩ độ GPS trụ sở (Latitude)</label>
          <input id="br-lat" type="number" step="0.000001" min="-90" max="90" name="latitude" value="{{ old('latitude') }}" placeholder="20.985421">
        </div>
        <div class="field">
          <label for="br-lng">Kinh độ GPS trụ sở (Longitude)</label>
          <input id="br-lng" type="number" step="0.000001" min="-180" max="180" name="longitude" value="{{ old('longitude') }}" placeholder="105.853192">
        </div>
        <div class="field">
          <label for="br-radius">Bán kính cho phép (mét)</label>
          <input id="br-radius" type="number" min="10" max="10000" name="allowed_radius_meters" value="{{ old('allowed_radius_meters', 150) }}" placeholder="150">
        </div>
        <div class="field" style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600">
            <input type="checkbox" name="enforce_geofence" value="1" @checked(old('enforce_geofence'))>
            <span>Bắt buộc kiểm tra vị trí GPS (chống mang máy về nhà)</span>
          </label>
          <button type="button" class="btn-outline btn-sm" onclick="tadFillGps('br-lat','br-lng',this)" style="width:max-content">📍 Lấy tọa độ hiện tại</button>
        </div>
      </div>
      <div style="margin-top:1rem">
        <button type="submit"><x-icon name="plus" :size="16" /> Tạo chi nhánh</button>
      </div>
    </form>
  </details>
</section>

<section class="card">
  <div class="card-head" style="flex-wrap:wrap;gap:10px">
    <h2>Danh sách chi nhánh ({{ $branches->total() }})</h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn btn-sm {{ !$selectedRegion ? '' : 'btn-outline' }}" href="{{ route('admin.branches.index') }}">Tất cả miền</a>
      @foreach($regions as $rKey => $rLabel)
        <a class="btn btn-sm {{ $selectedRegion === $rKey ? '' : 'btn-outline' }}" href="{{ route('admin.branches.index', ['region' => $rKey]) }}">{{ $rLabel }}</a>
      @endforeach
    </div>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách chi nhánh" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Chi nhánh / Miền</th>
          <th>Quản lý &amp; Liên hệ</th>
          <th>Ca mặc định</th>
          <th>Quy mô</th>
          <th>Trạng thái</th>
          <th>Quản lý</th>
        </tr>
      </thead>
      <tbody>
        @forelse($branches as $b)
          <tr>
            <td data-label="Chi nhánh / Miền">
              <strong>{{ $b->name }}</strong>
              <div class="muted small">
                <code>{{ $b->code }}</code> · <x-badge variant="info">{{ $b->regionLabel() }}</x-badge>
              </div>
              @if($b->address)
                <div class="muted small" style="margin-top:3px">{{ $b->address }}</div>
              @endif
              @if($b->hasCoordinates())
                <div class="muted small" style="margin-top:3px">
                  📍 <a href="https://maps.google.com/?q={{ $b->latitude }},{{ $b->longitude }}" target="_blank" rel="noopener noreferrer" style="text-decoration:underline">{{ number_format($b->latitude, 6, '.', '') }}, {{ number_format($b->longitude, 6, '.', '') }}</a>
                  @if($b->allowed_radius_meters)
                    · Giới hạn {{ $b->allowed_radius_meters }} m
                  @endif
                  @if($b->enforce_geofence)
                    <x-badge variant="success" style="margin-inline-start:4px">Bật GPS</x-badge>
                  @endif
                </div>
              @endif
            </td>
            <td data-label="Quản lý &amp; Liên hệ">
              <div><strong>{{ $b->manager_name ?: 'Chưa gán quản lý' }}</strong></div>
              @if($b->manager_phone)
                <div class="muted small">QL: {{ $b->manager_phone }}</div>
              @endif
              @if($b->phone)
                <div class="muted small">CN: {{ $b->phone }}</div>
              @endif
            </td>
            <td data-label="Ca mặc định">
              @if($b->defaultShift)
                <div><strong>{{ $b->defaultShift->name }}</strong></div>
                <div class="muted small num">{{ substr($b->defaultShift->starts_at, 0, 5) }} – {{ substr($b->defaultShift->ends_at, 0, 5) }}</div>
              @else
                <span class="muted small">Chưa chọn</span>
              @endif
            </td>
            <td data-label="Quy mô" class="num">
              <div>{{ $b->employees_count }} nhân viên</div>
              <div class="muted small">{{ $b->devices_count }} thiết bị · {{ $b->shifts_count }} ca riêng</div>
            </td>
            <td data-label="Trạng thái">
              <x-badge :variant="$b->active ? 'success' : 'danger'">{{ $b->active ? 'Đang hoạt động' : 'Vô hiệu hóa' }}</x-badge>
            </td>
            <td data-label="Quản lý" style="min-width:260px">
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px">
                <form method="post" action="{{ route('admin.branches.toggle-active', $b) }}" style="margin:0">
                  @csrf
                  <button type="submit" class="btn-sm {{ $b->active ? 'btn-outline' : '' }}">
                    {{ $b->active ? 'Vô hiệu hóa' : 'Kích hoạt lại' }}
                  </button>
                </form>
              </div>
              <details class="disclosure">
                <summary><x-icon name="edit" :size="14" /> Sửa cấu hình chi nhánh</summary>
                <form method="post" action="{{ route('admin.branches.update', $b) }}" style="margin-top:0.8rem">
                  @csrf @method('PUT')
                  <div class="form-grid">
                    <div class="field"><label>Mã CN</label><input required name="code" value="{{ $b->code }}"></div>
                    <div class="field"><label>Tên chi nhánh</label><input required name="name" value="{{ $b->name }}"></div>
                    <div class="field">
                      <label>Miền</label>
                      <select name="region" required>
                        @foreach($regions as $rKey => $rLabel)
                          <option value="{{ $rKey }}" @selected($b->region === $rKey)>{{ $rLabel }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="field"><label>SĐT chi nhánh</label><input name="phone" value="{{ $b->phone }}"></div>
                    <div class="field"><label>Người quản lý</label><input name="manager_name" value="{{ $b->manager_name }}"></div>
                    <div class="field"><label>SĐT quản lý</label><input name="manager_phone" value="{{ $b->manager_phone }}"></div>
                    <div class="field">
                      <label>Ca mặc định</label>
                      <select name="default_shift_id">
                        <option value="">Chưa chọn</option>
                        @foreach($shifts as $shift)
                          <option value="{{ $shift->id }}" @selected($b->default_shift_id == $shift->id)>
                            {{ $shift->code }} – {{ $shift->name }} ({{ substr($shift->starts_at, 0, 5) }}–{{ substr($shift->ends_at, 0, 5) }})
                          </option>
                        @endforeach
                      </select>
                    </div>
                    <div class="field">
                      <label>Trạng thái</label>
                      <select name="active">
                        <option value="1" @selected($b->active)>Đang hoạt động</option>
                        <option value="0" @selected(!$b->active)>Vô hiệu hóa</option>
                      </select>
                    </div>
                    <div class="field" style="grid-column:1 / -1"><label>Địa chỉ</label><input name="address" value="{{ $b->address }}"></div>
                    <div class="field"><label>Vĩ độ (Lat)</label><input id="br-lat-{{ $b->id }}" type="number" step="0.000001" min="-90" max="90" name="latitude" value="{{ $b->latitude }}"></div>
                    <div class="field"><label>Kinh độ (Lng)</label><input id="br-lng-{{ $b->id }}" type="number" step="0.000001" min="-180" max="180" name="longitude" value="{{ $b->longitude }}"></div>
                    <div class="field"><label>Bán kính (m)</label><input type="number" min="10" max="10000" name="allowed_radius_meters" value="{{ $b->allowed_radius_meters ?? 150 }}"></div>
                    <div class="field" style="display:flex;flex-direction:column;justify-content:flex-end;gap:6px">
                      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.84rem">
                        <input type="checkbox" name="enforce_geofence" value="1" @checked($b->enforce_geofence)>
                        <span>Chặn chấm ngoài bán kính GPS</span>
                      </label>
                      <button type="button" class="btn-outline btn-sm" onclick="tadFillGps('br-lat-{{ $b->id }}','br-lng-{{ $b->id }}',this)" style="width:max-content">📍 Lấy tọa độ hiện tại</button>
                    </div>
                  </div>
                  <div style="margin-top:0.75rem"><button type="submit" class="btn-sm">Lưu thay đổi</button></div>
                </form>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-state">Chưa có chi nhánh nào. Hãy thêm chi nhánh đầu tiên ở phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($branches->hasPages())
    <div class="pagination-wrap">{{ $branches->links() }}</div>
  @endif
</section>

<script>
function tadFillGps(latId, lngId, btn) {
  if (!navigator.geolocation) { alert('Trình duyệt không hỗ trợ lấy vị trí GPS.'); return; }
  var oldText = btn.textContent;
  btn.textContent = 'Đang lấy tọa độ...';
  btn.disabled = true;
  navigator.geolocation.getCurrentPosition(
    function (pos) {
      document.getElementById(latId).value = pos.coords.latitude.toFixed(6);
      document.getElementById(lngId).value = pos.coords.longitude.toFixed(6);
      btn.textContent = '✓ Đã điền tọa độ (±' + Math.round(pos.coords.accuracy) + 'm)';
      btn.disabled = false;
    },
    function () {
      alert('Không thể lấy tọa độ GPS. Hãy cấp quyền vị trí cho trình duyệt hoặc nhập tay từ Google Maps.');
      btn.textContent = oldText;
      btn.disabled = false;
    },
    { enableHighAccuracy: true, timeout: 10000 }
  );
}
</script>
@endsection
