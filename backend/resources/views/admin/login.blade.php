@extends('admin.layout')
@section('title','Đăng nhập')
@section('content')
<div class="auth-shell">
  <section class="auth-card">
    <div class="auth-badge"><x-icon name="shield" :size="26" /></div>
    <h1>Đăng nhập quản trị</h1>
    <p class="muted" style="margin:0 0 1.4rem">Chỉ tài khoản được cấp quyền mới có thể xem và quản lý dữ liệu chấm công sinh trắc.</p>

    <form method="post" action="{{ route('admin.login') }}" style="display:grid;gap:1rem">
      @csrf
      <div class="field">
        <label for="login-email">Email quản trị</label>
        <input id="login-email" required type="email" name="email" autocomplete="email" value="{{ old('email') }}" placeholder="admin@company.vn">
      </div>
      <div class="field">
        <label for="login-password">Mật khẩu</label>
        <input id="login-password" required type="password" name="password" autocomplete="current-password" placeholder="Nhập mật khẩu">
      </div>
      <button type="submit" style="width:100%;margin-top:0.25rem">
        <span>Đăng nhập hệ thống</span>
      </button>
    </form>
  </section>
</div>
@endsection
