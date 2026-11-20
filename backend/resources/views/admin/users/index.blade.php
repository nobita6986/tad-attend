@extends('admin.layout')
@section('title','Tài khoản')
@section('content')
<div class="page-header">
  <div>
    <h1>Tài khoản Quản trị &amp; HR</h1>
    <p class="page-subtitle">Quản lý người được phép đăng nhập web quản trị. <strong>Admin</strong> có toàn quyền (gồm Thiết bị &amp; Tài khoản); <strong>HR</strong> quản lý Nhân viên, Ca làm, Lượt quét và Báo cáo Excel.</p>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="plus" :size="18" /> Thêm tài khoản Quản trị / HR</h2>
  </div>
  <form method="post" action="{{ route('admin.users.store') }}">
    @csrf
    <div class="form-grid">
      <div class="field">
        <label for="user-name">Họ và tên *</label>
        <input id="user-name" required name="name" value="{{ old('name') }}" placeholder="Nguyễn Thị HR">
      </div>
      <div class="field">
        <label for="user-email">Email đăng nhập *</label>
        <input id="user-email" required type="email" name="email" autocomplete="off" value="{{ old('email') }}" placeholder="hr@tananhduong.com.vn">
      </div>
      <div class="field">
        <label for="user-role">Vai trò *</label>
        <select id="user-role" name="role" required>
          <option value="hr" @selected(old('role', 'hr') === 'hr')>HR (Nhân sự — xem &amp; quản lý công)</option>
          <option value="admin" @selected(old('role') === 'admin')>Admin (Toàn quyền hệ thống)</option>
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
    <div style="margin-top:1rem">
      <button type="submit"><x-icon name="plus" :size="16" /> Tạo tài khoản</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="shield" :size="18" /> Danh sách tài khoản ({{ $users->count() }})</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách tài khoản quản trị và HR" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Người dùng</th>
          <th>Vai trò</th>
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
            <td data-label="Vai trò">
              @if($role === 'admin')
                <x-badge variant="info">Admin (Toàn quyền)</x-badge>
              @elseif($role === 'hr')
                <x-badge variant="success">HR (Nhân sự)</x-badge>
              @else
                <x-badge variant="neutral">{{ $role }}</x-badge>
              @endif
            </td>
            <td data-label="Trạng thái">
              <x-badge :variant="$active ? 'success' : 'danger'">{{ $active ? 'Hoạt động' : 'Đã khóa' }}</x-badge>
            </td>
            <td data-label="Ngày tạo" class="num muted">{{ $u->created_at?->timezone(config('attendance.timezone'))->format('d/m/Y H:i') ?? '—' }}</td>
            <td data-label="Quản lý" style="min-width:250px">
              <details class="disclosure">
                <summary><x-icon name="edit" :size="14" /> Sửa / Đổi mật khẩu / Khóa</summary>
                <form method="post" action="{{ route('admin.users.update', $u) }}" style="margin-top:0.8rem">
                  @csrf @method('PUT')
                  <div class="form-grid">
                    <div class="field"><label>Họ tên</label><input required name="name" value="{{ $u->name }}"></div>
                    <div class="field"><label>Email</label><input required type="email" name="email" value="{{ $u->email }}"></div>
                    <div class="field">
                      <label>Vai trò</label>
                      <select name="role" @if($isMe) title="Không thể tự hạ quyền của chính bạn" @endif>
                        <option value="admin" @selected($role === 'admin')>Admin (Toàn quyền)</option>
                        <option value="hr" @selected($role === 'hr') @if($isMe) disabled @endif>HR (Nhân sự)</option>
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
                  <div style="margin-top:0.8rem"><button type="submit" class="btn-sm">Lưu thay đổi</button></div>
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
</section>
@endsection
