@extends('admin.layout') @section('title','Nhân viên') @section('content')
<h1>Quản lý nhân viên</h1>
@if(session('ticket'))<div class="alert"><strong>MÃ ĐĂNG KÝ — HIỂN THỊ MỘT LẦN</strong><br>
{{ session('ticket')['employee'] }}: <code style="font-size:1.3rem">{{ session('ticket')['code'] }}</code><br>
<span class="small">Hết hạn sau 10 phút; nhập trong ứng dụng iPhone, không chụp màn hình công khai.</span></div>@endif
<section><h2>Thêm nhân viên</h2><form method="post" action="{{ route('admin.employees.store') }}">@csrf
<div class="form-grid">
<div><label>Mã nhân viên *</label><input required name="code" placeholder="NV001"></div>
<div><label>Họ và tên *</label><input required name="full_name"></div>
<div><label>Giới tính</label><select name="gender"><option value="">—</option><option value="male">Nam</option><option value="female">Nữ</option><option value="other">Khác</option></select></div>
<div><label>Ngày sinh</label><input type="date" name="date_of_birth"></div>
<div><label>Bắt đầu làm</label><input type="date" name="employed_from"></div><div><label>Nghỉ việc từ sau ngày</label><input type="date" name="employed_until"></div><div><label>Bộ phận</label><input name="department"></div>
<div><label>Địa chỉ</label><input name="address"></div>
<div><label>Ca làm mặc định</label><select name="shift_id"><option value="">Chưa phân ca</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->name }}</option>@endforeach</select></div>
</div><p><button type="submit">Thêm nhân viên</button></p></form></section>
<section><h2>Danh sách nhân viên</h2><div class="table-scroll"><table><thead><tr><th>Mã</th><th>Họ tên</th><th>Bộ phận / Ca</th><th>Trạng thái</th><th>Đăng ký khuôn mặt</th><th>Quản lý</th></tr></thead><tbody>
@foreach($employees as $e)<tr>
<td>{{ $e->code }}</td><td><strong>{{ $e->full_name }}</strong></td><td>{{ $e->department ?: '—' }}<br><span class="muted">{{ $e->shift?->name ?: 'Chưa phân ca' }}</span></td>
<td><span class="pill">{{ $e->active ? 'Đang làm' : 'Tạm khóa' }}</span><br><span class="small">{{ $e->consent_at ? 'Đã ghi nhận đồng ý' : 'Chưa ghi nhận đồng ý' }}</span></td>
<td>{{ $e->templates->where('active',true)->count() ? 'Đã đăng ký' : 'Chưa đăng ký' }}
@if($e->consent_at && $e->active)<form method="post" action="{{ route('admin.employees.ticket',$e) }}">@csrf<button class="outline" style="margin-top:6px">Cấp mã 10 phút</button></form>@endif</td>
<td style="min-width:270px"><details><summary>Sửa hồ sơ / đồng ý</summary>
<form method="post" action="{{ route('admin.employees.update',$e) }}">@csrf @method('PUT')
<div class="form-grid" style="margin-top:10px">
<div><label>Mã NV</label><input name="code" required value="{{ $e->code }}"></div><div><label>Họ tên</label><input name="full_name" required value="{{ $e->full_name }}"></div>
<div><label>Giới tính</label><select name="gender"><option value="">—</option>@foreach(['male'=>'Nam','female'=>'Nữ','other'=>'Khác'] as $k=>$v)<option value="{{ $k }}" @selected($e->gender===$k)>{{ $v }}</option>@endforeach</select></div>
<div><label>Ngày sinh</label><input name="date_of_birth" type="date" value="{{ $e->date_of_birth?->format('Y-m-d') }}"></div>
<div><label>Bắt đầu làm</label><input type="date" name="employed_from" value="{{ $e->employed_from?->format('Y-m-d') }}"></div><div><label>Ngày làm cuối</label><input type="date" name="employed_until" value="{{ $e->employed_until?->format('Y-m-d') }}"></div><div><label>Bộ phận</label><input name="department" value="{{ $e->department }}"></div><div><label>Địa chỉ</label><input name="address" value="{{ $e->address }}"></div>
<div><label>Ca làm</label><select name="shift_id"><option value="">Chưa phân ca</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" @selected($e->shift_id==$shift->id)>{{ $shift->name }}</option>@endforeach</select></div>
<div><label>Hoạt động</label><select name="active"><option value="1" @selected($e->active)>Có</option><option value="0" @selected(!$e->active)>Không</option></select></div>
</div><p><button type="submit">Lưu hồ sơ</button></p></form>
<details><summary>Phân ca theo ngày (lưu lịch sử)</summary>
<form method="post" action="{{ route('admin.employees.assign-shift',$e) }}">@csrf
<div class="form-grid">
<div><label>Ca áp dụng</label><select name="shift_id" required>@foreach($shifts as $sh)<option value="{{ $sh->id }}">{{ $sh->name }}</option>@endforeach</select></div>
<div><label>Từ ngày</label><input type="date" required name="effective_from"></div>
<div><label>Đến ngày (để trống: vô hạn)</label><input type="date" name="effective_to"></div>
</div><p><button type="submit">Thêm phân ca</button></p></form>
<ul class="small">@forelse($e->shiftAssignments as $assignment)<li>{{ $assignment->shift?->name }}: {{ $assignment->effective_from->format('d/m/Y') }} – {{ $assignment->effective_to?->format('d/m/Y') ?? 'hiện tại' }}</li>@empty<li>Chưa có phân ca theo ngày.</li>@endforelse</ul>
</details>
@if(!$e->consent_at)<form method="post" action="{{ route('admin.employees.consent',$e) }}">@csrf
<label>Đã kiểm tra bằng chứng đồng ý của người lao động</label><select name="method" required><option value="signed_form">Phiếu ký giấy</option><option value="electronic_consent">Đồng ý điện tử đã lưu chứng cứ</option></select>
<p><button type="submit" class="outline">Ghi nhận sự đồng ý</button></p></form>
@else<form method="post" action="{{ route('admin.employees.revoke-consent',$e) }}" onsubmit="return confirm('Xóa mẫu khuôn mặt trên server? Hãy đồng bộ lại các iPhone!')">@csrf<button class="danger">Thu hồi đồng ý và xóa mẫu</button></form>@endif
</details></td></tr>@endforeach
</tbody></table></div><p>{{ $employees->links() }}</p></section>@endsection
