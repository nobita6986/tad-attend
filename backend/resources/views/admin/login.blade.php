@extends('admin.layout') @section('title','Đăng nhập') @section('content')
<section style="max-width:440px;margin:65px auto"><h1>Đăng nhập quản trị</h1><p class="muted">Chỉ tài khoản được cấp quyền mới có thể xem dữ liệu chấm công.</p>
<form method="post" action="{{ route('admin.login') }}">@csrf
<div style="margin:15px 0"><label>Email</label><input required type="email" name="email" value="{{ old('email') }}" style="width:100%"></div>
<div style="margin:15px 0"><label>Mật khẩu</label><input required type="password" name="password" style="width:100%"></div>
<button type="submit">Đăng nhập</button></form></section>@endsection
