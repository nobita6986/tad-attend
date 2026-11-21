@extends('admin.layout')
@section('title','Tài khoản')
@section('content')
<div class="page-header">
  <div>
    <h1>Tài khoản Quản trị &amp; Kế toán</h1>
    <p class="page-subtitle">Phân quyền rõ ràng: <strong>Quản trị hệ thống</strong> (toàn quyền), <strong>Kế toán toàn miền</strong> (xem &amp; xuất công mọi chi nhánh trong miền phụ trách), <strong>Kế toán chi nhánh</strong> (chỉ truy cập đúng chi nhánh được gán) và <strong>Nhân sự toàn hệ thống</strong>.</p>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="plus" :size="18" /> Thêm tài khoản mới</h2>
  </div>
  <form method="post" action="{{ route('admin.users.store') }}" data-role-form>
    @csrf
    <div class="form-grid">
      <div class="field">
        <label for="user-name">Họ và tên *</label>
        <input id="user-name" required name="name" value="{{ old('name') }}" placeholder="Nguyễn Thị Kế Toán">
      </div>
      <div class="field">
        <label for="user-email">Email đăng nhập *</label>
        <input id="user-email" required type="email" name="email" autocomplete="off" value="{{ old('email') }}" placeholder="ketoan.hn@tananhduong.com.vn">
      </div>
      <div class="field">
        <label for="user-role">Vai trò *</label>
        <select id="user-role" name="role" required data-role-select>
          <option value="branch_accountant" @selected(old('role', 'branch_accountant') === 'branch_accountant')>Kế toán chi nhánh (chỉ xem chi nhánh được gán)</option>
          <option value="regional_accountant" @selected(old('role') === 'regional_accountant')>Kế toán toàn miền (xem mọi CN trong miền)</option>
          <option value="hr" @selected(old('role') === 'hr')>Nhân sự toàn hệ thống (HR)</option>
          <option value="admin" @selected(old('role') === 'admin')>Quản trị hệ thống (Admin — Toàn quyền)</option>
        </select>
      </div>
      <div class="field">
        <label for="user-pass">Mật khẩu * (≥ 8 ký tự)</label>
        <input id="user-pass" required type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Nhập mật khẩu mạnh">
      </div>
      <div class="field">
        <label for="user-pass-confirm">Nhập lại mật khẩu *</label>
        <input id="user-pass-confirm" required type="password" name="password_confirmation" minlength="8" autocomplete="new-password" placeholder="Nhập lại mật khẩu">
      </div>
    </div>

    <div class="weekday-group" data-scope-regions style="margin-top:0.9rem">
      <span style="font-size:0.84rem;font-weight:600;color:var(--color-fg-secondary);margin-inline-end:6px">Miền phụ trách *:</span>
      @foreach($regions as $rKey => $rLabel)
        <label class="weekday-chip">
          <input type="checkbox" name="managed_regions[]" value="{{ $rKey }}" @checked(in_array($rKey, old('managed_regions', [])))>
          <span>{{ $rLabel }}</span>
        </label>
      @endforeach
    </div>

    <div class="weekday-group" data-scope-branches style="margin-top:0.9rem">
      <span style="font-size:0.84rem;font-weight:600;color:var(--color-fg-secondary);margin-inline-end:6px">Chi nhánh phụ trách *:</span>
      @forelse($branches as $b)
        <label class="weekday-chip">
          <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, old('branch_ids', [])))>
          <span>{{ $b->code }} – {{ $b->name }}</span>
        </label>
      @empty
        <span class="muted small">Chưa có chi nhánh nào — hãy tạo chi nhánh tại mục Chi nhánh trước.</span>
      @endforelse
    </div>

    <div style="margin-top:1rem">
      <button type="submit"><x-icon name="plus" :size="16" /> Tạo tài khoản</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="shield" :size="18" /> Danh sách tài khoản ({{ $users->total() }})</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách tài khoản" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Người dùng</th>
          <th>Vai trò &amp; Phạm vi</th>
          <th>Trạng thái</th>
          <th>Ngày tạo</th>
          <th>Quản lý</th>
        </tr>
      </thead>
      <tbody>
        @forelse($users as $u)
          @php
            $role = $u->effectiveRole();
            $active = $u->isActiveAccount();
            $ini = mb_strtoupper(mb_substr(trim($u->name ?: $u->email), 0, 2));
            $isMe = $u->id === auth()->id();
            $userBranchIds = $u->branches->pluck('id')->all();
            $userRegions = is_array($u->managed_regions) ? $u->managed_regions : [];
          @endphp
          <tr>
            <td data-label="Người dùng">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $u->name }}</strong>
                  @if($isMe)
                    <x-badge variant="info" style="margin-inline-start:6px">Bạn</x-badge>
                  @endif
                  <div class="muted small">{{ $u->email }}</div>
                </div>
              </div>
            </td>
            <td data-label="Vai trò &amp; Phạm vi">
              @if($role === 'admin')
                <x-badge variant="info">Quản trị hệ thống</x-badge>
              @elseif($role === 'regional_accountant')
                <x-badge variant="warning">Kế toán toàn miền</x-badge>
              @elseif($role === 'branch_accountant')
                <x-badge variant="success">Kế toán chi nhánh</x-badge>
              @elseif($role === 'hr')
                <x-badge variant="neutral">Nhân sự toàn hệ thống</x-badge>
              @else
                <x-badge variant="neutral">{{ $role }}</x-badge>
              @endif
              <div class="muted small" style="margin-top:3px">{{ $u->scopeLabel() }}</div>
            </td>
            <td data-label="Trạng thái">
              <x-badge :variant="$active ? 'success' : 'danger'">{{ $active ? 'Hoạt động' : 'Đã khóa' }}</x-badge>
            </td>
            <td data-label="Ngày tạo" class="num muted">{{ $u->created_at?->timezone(config('attendance.timezone'))->format('d/m/Y H:i') ?? '—' }}</td>
            <td data-label="Quản lý" style="min-width:260px">
              <details class="disclosure">
                <summary><x-icon name="edit" :size="14" /> Sửa quyền / Phạm vi / Mật khẩu</summary>
                <form method="post" action="{{ route('admin.users.update', $u) }}" style="margin-top:0.8rem" data-role-form>
                  @csrf @method('PUT')
                  <div class="form-grid">
                    <div class="field"><label>Họ tên</label><input required name="name" value="{{ $u->name }}"></div>
                    <div class="field"><label>Email</label><input required type="email" name="email" value="{{ $u->email }}"></div>
                    <div class="field">
                      <label>Vai trò</label>
                      <select name="role" data-role-select @if($isMe) title="Không thể tự hạ quyền của chính bạn" @endif>
                        <option value="admin" @selected($role === 'admin')>Quản trị hệ thống</option>
                        <option value="regional_accountant" @selected($role === 'regional_accountant') @if($isMe) disabled @endif>Kế toán toàn miền</option>
                        <option value="branch_accountant" @selected($role === 'branch_accountant') @if($isMe) disabled @endif>Kế toán chi nhánh</option>
                        <option value="hr" @selected($role === 'hr') @if($isMe) disabled @endif>Nhân sự toàn hệ thống</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Trạng thái</label>
                      <select name="active">
                        <option value="1" @selected($active)>Hoạt động</option>
                        <option value="0" @selected(!$active) @if($isMe) disabled @endif>Tạm khóa</option>
                      </select>
                    </div>
                    <div class="field"><label>Mật khẩu mới (để trống nếu giữ)</label><input type="password" name="password" minlength="8" autocomplete="new-password"></div>
                    <div class="field"><label>Nhập lại mật khẩu mới</label><input type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></div>
                  </div>
                  <div class="weekday-group" data-scope-regions style="margin-top:0.7rem">
                    <span class="muted small" style="font-weight:600">Miền phụ trách:</span>
                    @foreach($regions as $rKey => $rLabel)
                      <label class="weekday-chip">
                        <input type="checkbox" name="managed_regions[]" value="{{ $rKey }}" @checked(in_array($rKey, $userRegions, true))>
                        <span>{{ $rLabel }}</span>
                      </label>
                    @endforeach
                  </div>
                  <div class="weekday-group" data-scope-branches style="margin-top:0.7rem">
                    <span class="muted small" style="font-weight:600">Chi nhánh phụ trách:</span>
                    @foreach($branches as $b)
                      <label class="weekday-chip">
                        <input type="checkbox" name="branch_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, $userBranchIds, true))>
                        <span>{{ $b->code }} – {{ $b->name }}</span>
                      </label>
                    @endforeach
                  </div>
                  <div style="margin-top:0.8rem">
                    <button type="submit" class="btn-sm">Lưu thay đổi</button>
                  </div>
                </form>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-state">Chưa có tài khoản nào.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($users->hasPages())
    <div class="pagination-wrap">{{ $users->links() }}</div>
  @endif
</section>

<script>
document.querySelectorAll('[data-role-form]').forEach(function (form) {
  var sel = form.querySelector('[data-role-select]');
  var regBox = form.querySelector('[data-scope-regions]');
  var brBox = form.querySelector('[data-scope-branches]');
  function sync() {
    var v = sel ? sel.value : '';
    if (regBox) regBox.style.display = v === 'regional_accountant' ? '' : 'none';
    if (brBox) brBox.style.display = v === 'branch_accountant' ? '' : 'none';
  }
  if (sel) { sel.addEventListener('change', sync); sync(); }
});
</script>
@endsection
