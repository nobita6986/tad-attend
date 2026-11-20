@extends('admin.layout')
@section('title','Ca làm')
@section('content')
@php
  $dayNames = [1=>'T2', 2=>'T3', 3=>'T4', 4=>'T5', 5=>'T6', 6=>'T7', 7=>'CN'];
@endphp

<div class="page-header">
  <div>
    <h1>Cấu hình ca làm</h1>
    <p class="page-subtitle">Nếu giờ ra sớm hơn giờ vào, hệ thống tự động tính là ca qua đêm. Khung ghi nhận mỗi ca mở rộng ±4 giờ.</p>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="plus" :size="18" /> Thêm ca làm mới</h2>
  </div>
  <form method="post" action="{{ route('admin.shifts.store') }}">
    @csrf
    <div class="form-grid">
      <div class="field"><label for="shift-code">Mã ca *</label><input id="shift-code" required name="code" value="{{ old('code') }}" placeholder="HC"></div>
      <div class="field"><label for="shift-name">Tên ca *</label><input id="shift-name" required name="name" value="{{ old('name') }}" placeholder="Hành chính"></div>
      <div class="field"><label for="shift-start">Giờ vào *</label><input id="shift-start" required type="time" name="starts_at" value="{{ old('starts_at', '08:00') }}"></div>
      <div class="field"><label for="shift-end">Giờ ra *</label><input id="shift-end" required type="time" name="ends_at" value="{{ old('ends_at', '17:30') }}"></div>
      <div class="field"><label for="shift-break">Nghỉ giữa ca (phút)</label><input id="shift-break" type="number" min="0" max="480" name="break_minutes" value="{{ old('break_minutes', 90) }}"></div>
      <div class="field"><label for="shift-grace">Cho phép trễ (phút)</label><input id="shift-grace" type="number" min="0" max="120" name="grace_minutes" value="{{ old('grace_minutes', 5) }}"></div>
    </div>

    <div class="weekday-group" role="group" aria-label="Ngày làm việc trong tuần">
      <span style="font-size:0.84rem;font-weight:600;color:var(--color-fg-secondary);margin-inline-end:4px">Ngày làm việc:</span>
      @foreach($dayNames as $d => $label)
        <label class="weekday-chip">
          <input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, old('weekdays', [1,2,3,4,5,6])))>
          <span>{{ $label }}</span>
        </label>
      @endforeach
    </div>

    <button type="submit"><x-icon name="plus" :size="16" /> Tạo ca làm</button>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="clock" :size="18" /> Danh sách ca hiện tại ({{ $shifts->count() }})</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách ca làm" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Ca làm</th>
          <th>Khung giờ</th>
          <th>Nghỉ / Trễ</th>
          <th>Ngày áp dụng</th>
          <th>Chỉnh sửa</th>
        </tr>
      </thead>
      <tbody>
        @forelse($shifts as $shift)
          <tr>
            <td data-label="Ca làm">
              <strong>{{ $shift->name }}</strong>
              <div class="muted small">{{ $shift->code }}</div>
            </td>
            <td data-label="Khung giờ" class="num">{{ substr($shift->starts_at, 0, 5) }} – {{ substr($shift->ends_at, 0, 5) }}</td>
            <td data-label="Nghỉ / Trễ" class="num">{{ $shift->break_minutes }}p nghỉ · {{ $shift->grace_minutes }}p trễ</td>
            <td data-label="Ngày áp dụng">
              <div style="display:flex;gap:4px;flex-wrap:wrap">
                @foreach(($shift->weekdays ?? [1,2,3,4,5,6,7]) as $d)
                  <x-badge variant="info">{{ $dayNames[$d] ?? $d }}</x-badge>
                @endforeach
              </div>
            </td>
            <td data-label="Chỉnh sửa" style="min-width:250px">
              <details class="disclosure">
                <summary><x-icon name="edit" :size="14" /> Thay đổi</summary>
                <form method="post" action="{{ route('admin.shifts.update', $shift) }}" style="margin-top:0.8rem">
                  @csrf @method('PUT')
                  <div class="form-grid">
                    <div class="field"><label>Mã ca</label><input name="code" required value="{{ $shift->code }}"></div>
                    <div class="field"><label>Tên ca</label><input name="name" required value="{{ $shift->name }}"></div>
                    <div class="field"><label>Giờ vào</label><input type="time" name="starts_at" required value="{{ substr($shift->starts_at, 0, 5) }}"></div>
                    <div class="field"><label>Giờ ra</label><input type="time" name="ends_at" required value="{{ substr($shift->ends_at, 0, 5) }}"></div>
                    <div class="field"><label>Nghỉ (phút)</label><input type="number" name="break_minutes" value="{{ $shift->break_minutes }}" min="0" max="480"></div>
                    <div class="field"><label>Trễ (phút)</label><input type="number" name="grace_minutes" value="{{ $shift->grace_minutes }}" min="0" max="120"></div>
                  </div>
                  <div class="weekday-group">
                    @foreach($dayNames as $d => $label)
                      <label class="weekday-chip">
                        <input type="checkbox" name="weekdays[]" value="{{ $d }}" @checked(in_array($d, $shift->weekdays ?? []))>
                        <span>{{ $label }}</span>
                      </label>
                    @endforeach
                  </div>
                  <button type="submit" class="btn-sm">Lưu thay đổi</button>
                </form>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-state">Chưa có ca làm nào. Hãy tạo ca đầu tiên ở biểu mẫu phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
