@extends('admin.layout') @section('title','Lượt chấm công') @section('content')
<h1>Lượt chấm công</h1>
<section><form method="get" class="form-grid">
<div><label>Ngày</label><input type="date" name="date" value="{{ $date }}"></div>
<div><label>Nhân viên</label><select name="employee_id"><option value="">Tất cả</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(request('employee_id')==$e->id)>{{ $e->code }} · {{ $e->full_name }}</option>@endforeach</select></div>
<div><label>Trạng thái</label><select name="state"><option value="">Tất cả</option>@foreach(['accepted'=>'Hợp lệ','pending_review'=>'Chờ duyệt','rejected'=>'Từ chối'] as $key=>$label)<option value="{{ $key }}" @selected(request('state')===$key)>{{ $label }}</option>@endforeach</select></div>
<div style="align-self:end"><button type="submit">Lọc kết quả</button></div>
</form></section>
<section><div class="table-scroll"><table><thead><tr><th>Nhân viên</th><th>Thời gian quét</th><th>Thiết bị</th><th>Hướng</th><th>Độ khớp</th><th>Trạng thái</th><th>Ảnh</th><th>Xét duyệt</th></tr></thead><tbody>
@forelse($events as $ev)<tr>
<td><b>{{ $ev->employee->code }}</b><br>{{ $ev->employee->full_name }}</td>
<td>{{ $ev->captured_at->timezone(config('attendance.timezone'))->format('d/m/Y H:i:s') }}<br><span class="small muted">Nhận: {{ $ev->received_at->timezone(config('attendance.timezone'))->format('H:i:s') }}</span></td>
<td>{{ $ev->device->name }}</td><td>{{ $ev->direction }}</td><td>{{ number_format($ev->match_score,3) }}</td>
<td class="{{ $ev->review_state }}">{{ $ev->review_state }}@if($ev->is_offline)<br><span class="small">Ngoại tuyến</span>@endif</td>
<td>@if($ev->image_path)<a href="{{ route('admin.attendance.image',$ev) }}" target="_blank" rel="noopener">Xem ảnh</a>@else <span class="muted">Đã xóa</span> @endif</td>
<td>@if($ev->review_state === 'pending_review')<details><summary>Kiểm tra</summary>
<form method="post" action="{{ route('admin.attendance.review',$ev) }}">@csrf
<label>Lý do / chứng cứ</label><input name="reason" minlength="5" required placeholder="Đối chiếu camera gốc..." style="width:210px">
<select name="decision"><option value="accepted">Duyệt</option><option value="rejected">Từ chối</option></select><p><button type="submit">Xác nhận</button></p></form></details>@else — @endif</td>
</tr>@empty<tr><td colspan="8">Không có lượt chấm công phù hợp.</td></tr>@endforelse
</tbody></table></div><p>{{ $events->links() }}</p></section>
@endsection
