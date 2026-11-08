@extends('admin.layout') @section('title','Báo cáo') @section('content')
<h1>Báo cáo chấm công</h1><p class="muted">Quy tắc v0.1: chỉ tính cặp lượt VÀO/RA đã được duyệt theo ca. Chưa bao gồm phép, ngày lễ, tăng ca và ca xoay. Cần kiểm duyệt trước khi làm lương.</p>
<section><form method="get" class="form-grid">
<div><label>Từ ngày</label><input type="date" name="from" required value="{{ $from }}"></div>
<div><label>Đến ngày</label><input type="date" name="to" required value="{{ $to }}"></div>
<div><label>Nhân viên</label><select name="employee_id"><option value="">Tất cả</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected($employeeId==$e->id)>{{ $e->code }} – {{ $e->full_name }}</option>@endforeach</select></div>
<div style="align-self:end"><button type="submit">Xem bảng công</button></div>
</form><div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:17px">
<a class="btn" href="{{ route('admin.reports.detail',['from'=>$from,'to'=>$to,'employee_id'=>$employeeId]) }}">Tải Excel chi tiết</a>
<a class="btn outline" href="{{ route('admin.reports.summary',['from'=>$from,'to'=>$to,'employee_id'=>$employeeId]) }}">Tải Excel tổng hợp</a></div></section>
<section><h2>Tổng hợp theo nhân viên</h2><div class="table-scroll"><table><thead><tr><th>Nhân viên</th><th>Ngày đủ lượt</th><th>Vắng</th><th>Thiếu lượt</th><th>Đi muộn</th><th>Về sớm</th><th>Giờ công</th><th>Lượt trong ca</th></tr></thead><tbody>
@foreach($summary as $row)<tr><td><b>{{ $row['employee_code'] }}</b> {{ $row['employee_name'] }}</td><td>{{ $row['work_days'] }}</td><td>{{ $row['absent_days'] }}</td><td>{{ $row['incomplete_days'] }}</td><td>{{ $row['late_days'] }}</td><td>{{ $row['early_days'] }}</td><td>{{ number_format($row['work_minutes']/60,2) }}</td><td>{{ $row['punches'] }}</td></tr>@endforeach
</tbody></table></div></section>
<section><h2>Bảng công theo ngày <span class="muted">(hiển thị tối đa 100/{{ $total }} dòng; tải XLSX để xem tổng hợp đầy đủ)</span></h2>
<div class="table-scroll"><table><thead><tr><th>Ngày</th><th>Nhân viên</th><th>Ca</th><th>Vào</th><th>Ra</th><th>Giờ công</th><th>Trễ phút</th><th>Sớm phút</th><th>Trạng thái</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['day'] }}</td><td>{{ $row['employee_code'] }} {{ $row['employee_name'] }}</td><td>{{ $row['shift'] }}</td>
<td>{{ $row['in']?->timezone(config('attendance.timezone'))->format('H:i:s') ?? '—' }}</td>
<td>{{ $row['out']?->timezone(config('attendance.timezone'))->format('H:i:s') ?? '—' }}</td>
<td>{{ number_format($row['work_minutes']/60,2) }}</td><td>{{ $row['late_minutes'] }}</td><td>{{ $row['early_minutes'] }}</td>
<td class="{{ $row['status'] }}">{{ $row['status'] }}</td></tr>@endforeach
</tbody></table></div></section>
@endsection
