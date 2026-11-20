@extends('admin.layout')
@section('title','Báo cáo')
@section('content')
@php
  $tz = config('attendance.timezone');
  $statusMap = [
    'complete'     => ['Đủ công', 'success'],
    'incomplete'   => ['Thiếu lượt', 'warning'],
    'absent'       => ['Vắng', 'danger'],
    'off'          => ['Ngày nghỉ', 'neutral'],
    'not_employed' => ['Chưa/đã nghỉ', 'neutral'],
    'unconfigured' => ['Chưa gán ca', 'warning'],
  ];
@endphp

<div class="page-header">
  <div>
    <h1>Báo cáo chấm công</h1>
    <p class="page-subtitle">Tính lượt đầu tiên (Vào) và lượt cuối cùng (Ra) đã duyệt trong khung ca (±4h). Hỗ trợ cả thiết bị chấm công chung lẫn cổng tách riêng.</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-accent" href="{{ route('admin.reports.detail', ['from'=>$from, 'to'=>$to, 'employee_id'=>$employeeId]) }}">
      <x-icon name="download" :size="16" /> Excel chi tiết
    </a>
    <a class="btn btn-outline" href="{{ route('admin.reports.summary', ['from'=>$from, 'to'=>$to, 'employee_id'=>$employeeId]) }}">
      <x-icon name="download" :size="16" /> Excel tổng hợp
    </a>
  </div>
</div>

<section class="card">
  <form method="get" class="form-grid" style="align-items:end">
    <div class="field">
      <label for="rep-from">Từ ngày</label>
      <input id="rep-from" type="date" name="from" required value="{{ $from }}">
    </div>
    <div class="field">
      <label for="rep-to">Đến ngày</label>
      <input id="rep-to" type="date" name="to" required value="{{ $to }}">
    </div>
    <div class="field">
      <label for="rep-emp">Nhân viên</label>
      <select id="rep-emp" name="employee_id">
        <option value="">Tất cả nhân viên</option>
        @foreach($employees as $e)
          <option value="{{ $e->id }}" @selected($employeeId==$e->id)>{{ $e->code }} – {{ $e->full_name }}</option>
        @endforeach
      </select>
    </div>
    <div>
      <button type="submit" style="width:100%"><x-icon name="filter" :size="15" /> Xem bảng công</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="users" :size="18" /> Tổng hợp theo nhân viên</h2>
  </div>
  <div tabindex="0" role="region" aria-label="Bảng tổng hợp công theo nhân viên" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nhân viên</th>
          <th>Ngày đủ lượt</th>
          <th>Vắng</th>
          <th>Thiếu lượt</th>
          <th>Đi muộn</th>
          <th>Về sớm</th>
          <th>Giờ công</th>
          <th>Lượt trong ca</th>
        </tr>
      </thead>
      <tbody>
        @forelse($summary as $row)
          @php($ini = mb_strtoupper(mb_substr(trim($row['employee_name']), 0, 2)))
          <tr>
            <td data-label="Nhân viên">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $row['employee_name'] }}</strong>
                  <div class="muted small">{{ $row['employee_code'] }}</div>
                </div>
              </div>
            </td>
            <td data-label="Ngày đủ lượt" class="num">{{ $row['work_days'] }}</td>
            <td data-label="Vắng" class="num">{{ $row['absent_days'] }}</td>
            <td data-label="Thiếu lượt" class="num">{{ $row['incomplete_days'] }}</td>
            <td data-label="Đi muộn" class="num">{{ $row['late_days'] }}</td>
            <td data-label="Về sớm" class="num">{{ $row['early_days'] }}</td>
            <td data-label="Giờ công" class="num">{{ number_format($row['work_minutes'] / 60, 2) }}h</td>
            <td data-label="Lượt trong ca" class="num">{{ $row['punches'] }}</td>
          </tr>
        @empty
          <tr><td colspan="8" class="empty-state">Chưa có dữ liệu tổng hợp trong khoảng thời gian đã chọn.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <div>
      <h2><x-icon name="chart" :size="18" /> Bảng công theo ngày</h2>
      <p class="card-subtitle">Hiển thị tối đa 100 / {{ $total }} dòng · Tải file Excel để xem đầy đủ toàn kỳ</p>
    </div>
  </div>
  <div tabindex="0" role="region" aria-label="Bảng công chi tiết theo ngày" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Ngày</th>
          <th>Nhân viên</th>
          <th>Ca làm</th>
          <th>Giờ vào</th>
          <th>Giờ ra</th>
          <th>Giờ công</th>
          <th>Trễ (phút)</th>
          <th>Sớm (phút)</th>
          <th>Trạng thái</th>
        </tr>
      </thead>
      <tbody>
        @forelse($rows as $row)
          @php([$stText, $stVariant] = $statusMap[$row['status']] ?? [$row['status'], 'neutral'])
          <tr>
            <td data-label="Ngày" class="num">{{ \Carbon\CarbonImmutable::parse($row['day'])->format('d/m/Y') }}</td>
            <td data-label="Nhân viên"><strong>{{ $row['employee_name'] }}</strong> <span class="muted small">{{ $row['employee_code'] }}</span></td>
            <td data-label="Ca làm">{{ $row['shift'] }}</td>
            <td data-label="Giờ vào" class="num">{{ $row['in']?->timezone($tz)->format('H:i:s') ?? '—' }}</td>
            <td data-label="Giờ ra" class="num">{{ $row['out']?->timezone($tz)->format('H:i:s') ?? '—' }}</td>
            <td data-label="Giờ công" class="num">{{ number_format($row['work_minutes'] / 60, 2) }}h</td>
            <td data-label="Trễ (phút)" class="num">{{ $row['late_minutes'] }}</td>
            <td data-label="Sớm (phút)" class="num">{{ $row['early_minutes'] }}</td>
            <td data-label="Trạng thái"><x-badge :variant="$stVariant">{{ $stText }}</x-badge></td>
          </tr>
        @empty
          <tr><td colspan="9" class="empty-state">Không có bản ghi nào trong khoảng ngày đã chọn.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
