@extends('admin.layout') @section('title','Ca làm') @section('content')
<h1>Cấu hình ca làm</h1><p class="muted">Nếu ca kết thúc sớm hơn giờ bắt đầu, hệ thống coi là ca qua đêm. Ngày trong tuần: Thứ 2=1 ... Chủ nhật=7.</p>
<section><h2>Thêm ca</h2><form method="post" action="{{ route('admin.shifts.store') }}">@csrf
<div class="form-grid"><div><label>Mã ca</label><input required name="code" placeholder="HC"></div><div><label>Tên ca</label><input required name="name" placeholder="Hành chính"></div>
<div><label>Giờ vào</label><input required type="time" name="starts_at" value="08:00"></div><div><label>Giờ ra</label><input required type="time" name="ends_at" value="17:00"></div>
<div><label>Nghỉ (phút)</label><input type="number" min="0" max="480" name="break_minutes" value="60"></div>
<div><label>Cho phép trễ (phút)</label><input type="number" min="0" max="120" name="grace_minutes" value="5"></div></div>
<p><strong>Ngày làm:</strong> @foreach([1=>'T2',2=>'T3',3=>'T4',4=>'T5',5=>'T6',6=>'T7',7=>'CN'] as $d=>$label)<label style="display:inline;margin-right:15px"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked($d<=6)> {{ $label }}</label>@endforeach</p>
<button type="submit">Tạo ca</button></form></section>
<section><h2>Ca hiện tại</h2><div class="table-scroll"><table><thead><tr><th>Ca</th><th>Giờ</th><th>Nghỉ / Trễ</th><th>Ngày làm</th><th>Sửa</th></tr></thead><tbody>
@foreach($shifts as $shift)<tr><td>{{ $shift->code }}<br>{{ $shift->name }}</td><td>{{ $shift->starts_at }} – {{ $shift->ends_at }}</td><td>{{ $shift->break_minutes }} / {{ $shift->grace_minutes }} phút</td><td>{{ implode(', ', $shift->weekdays ?? []) }}</td>
<td><details><summary>Thay đổi</summary><form method="post" action="{{ route('admin.shifts.update',$shift) }}">@csrf @method('PUT')
<div class="form-grid"><div><label>Mã</label><input name="code" required value="{{ $shift->code }}"></div><div><label>Tên</label><input name="name" required value="{{ $shift->name }}"></div>
<div><label>Vào</label><input type="time" name="starts_at" required value="{{ substr($shift->starts_at,0,5) }}"></div><div><label>Ra</label><input type="time" name="ends_at" required value="{{ substr($shift->ends_at,0,5) }}"></div>
<div><label>Nghỉ phút</label><input type="number" name="break_minutes" value="{{ $shift->break_minutes }}" min="0"></div><div><label>Trễ phút</label><input type="number" name="grace_minutes" value="{{ $shift->grace_minutes }}" min="0"></div></div>
<p>@foreach([1=>'T2',2=>'T3',3=>'T4',4=>'T5',5=>'T6',6=>'T7',] as $d=>$label)<label style="display:inline;margin-right:10px"><input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d,$shift->weekdays??[]))> {{ $label }}</label>@endforeach</p><button type="submit">Lưu</button></form></details></td></tr>@endforeach</tbody></table></div></section>@endsection
