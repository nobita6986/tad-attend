@extends('admin.layout') @section('title','Tổng quan') @section('content')
<h1>Tổng quan chấm công</h1><div class="grid">
<div class="stat"><span>Nhân viên đang hoạt động</span><b>{{ $employees }}</b></div>
<div class="stat"><span>iPhone đã cấp quyền</span><b>{{ $devices }}</b></div>
<div class="stat"><span>Lượt hợp lệ hôm nay</span><b>{{ $punches }}</b></div>
<div class="stat"><span>Lượt cần kiểm tra hôm nay</span><b>{{ $pending }}</b></div></div>
<section style="margin-top:20px"><h2>Lượt chấm gần đây</h2><div class="table-scroll"><table><thead><tr><th>Nhân viên</th><th>Ngày giờ</th><th>Cổng</th><th>Hướng</th><th>Trạng thái</th><th>Ảnh</th></tr></thead><tbody>
@foreach($recent as $e)<tr><td>{{ $e->employee->code }} — {{ $e->employee->full_name }}</td><td>{{ $e->captured_at->timezone(config('attendance.timezone'))->format('d/m/Y H:i:s') }}</td><td>{{ $e->device->name }}</td><td>{{ $e->direction }}</td><td class="{{ $e->review_state }}">{{ $e->review_state }}</td><td>@if($e->image_path)<a href="{{ route('admin.attendance.image',$e) }}" target="_blank" rel="noopener">Xem ảnh</a>@else Đã xóa @endif</td></tr>
@endforeach</tbody></table></div></section>@endsection
