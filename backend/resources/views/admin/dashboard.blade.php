@extends('admin.layout')
@section('title','Tổng quan')
@section('content')
@php
  $tz = config('attendance.timezone');
  $dirLabels = ['both' => 'Chấm công', 'in' => 'Vào', 'out' => 'Ra'];
  $stateLabels = ['accepted' => ['Hợp lệ', 'success'], 'pending_review' => ['Chờ duyệt', 'warning'], 'rejected' => ['Từ chối', 'danger']];
@endphp

<div class="page-header">
  <div>
    <h1>Tổng quan chấm công</h1>
    <p class="page-subtitle">Cập nhật theo thời gian thực · Hôm nay {{ now($tz)->format('d/m/Y') }} (UTC+7)</p>
  </div>
  <div class="page-actions">
    <a class="btn btn-outline" href="{{ route('admin.devices.index') }}"><x-icon name="device" :size="16" /> Thiết bị</a>
    <a class="btn btn-outline" href="{{ route('admin.employees.index') }}"><x-icon name="plus" :size="16" /> Nhân viên</a>
    <a class="btn" href="{{ route('admin.reports.index') }}"><x-icon name="chart" :size="16" /> Xem báo cáo</a>
  </div>
</div>

<div class="kpi-grid">
  <x-stat-card label="Nhân viên đang hoạt động" :value="$employees" icon="users" tone="blue" />
  <x-stat-card label="Thiết bị đã cấp quyền" :value="$devices" icon="device" tone="violet" />
  <x-stat-card label="Lượt hợp lệ hôm nay" :value="$punches" icon="check" tone="emerald" />
  <x-stat-card label="Lượt chờ kiểm duyệt" :value="$pending" icon="alert" tone="amber" />
</div>

<section class="card">
  <div class="card-head">
    <div>
      <h2><x-icon name="scan" :size="18" /> Lượt chấm công gần đây</h2>
      <p class="card-subtitle">15 lượt ghi nhận mới nhất trên toàn bộ thiết bị</p>
    </div>
    <a class="btn btn-outline btn-sm" href="{{ route('admin.attendance.index') }}">Xem tất cả lượt quét</a>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng lượt chấm công gần đây" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nhân viên</th>
          <th>Ngày giờ</th>
          <th>Thiết bị</th>
          <th>Loại lượt</th>
          <th>Trạng thái</th>
          <th>Minh chứng</th>
        </tr>
      </thead>
      <tbody>
        @forelse($recent as $e)
          @php
            [$stText, $stVariant] = $stateLabels[$e->review_state] ?? [$e->review_state, 'neutral'];
            $ini = mb_strtoupper(mb_substr(trim($e->employee->full_name), 0, 2));
          @endphp
          <tr>
            <td data-label="Nhân viên">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $e->employee->full_name }}</strong>
                  <div class="muted small">{{ $e->employee->code }}</div>
                </div>
              </div>
            </td>
            <td data-label="Ngày giờ" class="num">{{ $e->captured_at->timezone($tz)->format('d/m/Y H:i:s') }}</td>
            <td data-label="Thiết bị">{{ $e->device->name }}</td>
            <td data-label="Loại lượt"><x-badge variant="info">{{ $dirLabels[$e->direction] ?? $e->direction }}</x-badge></td>
            <td data-label="Trạng thái"><x-badge :variant="$stVariant">{{ $stText }}</x-badge></td>
            <td data-label="Minh chứng">
              @if($e->image_path)
                <a class="btn btn-outline btn-sm" href="{{ route('admin.attendance.image', $e) }}" data-preview-image data-preview-title="{{ $e->employee->code }} · {{ $e->employee->full_name }} ({{ $e->captured_at->timezone($tz)->format('d/m/Y H:i:s') }})">
                  <x-icon name="photo" :size="14" /> Xem ảnh
                </a>
              @else
                <span class="muted small">Đã xóa</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-state">Chưa có lượt chấm công nào được ghi nhận.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
