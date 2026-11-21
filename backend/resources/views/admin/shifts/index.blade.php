@extends('admin.layout')
@section('title','Ca làm')
@section('content')
@php
  $dayNames = [1=>'T2', 2=>'T3', 3=>'T4', 4=>'T5', 5=>'T6', 6=>'T7', 7=>'CN'];
@endphp

<div class="page-header">
  <div>
    <h1>Cấu hình ca làm</h1>
    <p class="page-subtitle">Hỗ trợ ca dùng chung toàn công ty và ca kíp / giờ vào-ra riêng cho từng chi nhánh. Nếu giờ ra sớm hơn giờ vào, hệ thống tự tính ca qua đêm (±4 giờ).</p>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="plus" :size="18" /> Thêm ca làm mới</h2>
  </div>
  <form method="post" action="{{ route('admin.shifts.store') }}">
    @csrf
    <div class="form-grid">
      <div class="field">
        <label for="shift-branch">Chi nhánh áp dụng {{ $canCreateGlobal ? '(tùy chọn)' : '*' }}</label>
        <select id="shift-branch" name="branch_id" @if(!$canCreateGlobal) required @endif>
          @if($canCreateGlobal)
            <option value="">Dùng chung toàn công ty</option>
          @endif
          @foreach($branches as $b)
            <option value="{{ $b->id }}" @selected(old('branch_id', $selectedBranchId) == $b->id)>{{ $b->code }} – {{ $b->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field"><label for="shift-code">Mã ca *</label><input id="shift-code" required name="code" value="{{ old('code') }}" placeholder="HC-HN"></div>
      <div class="field"><label for="shift-name">Tên ca *</label><input id="shift-name" required name="name" value="{{ old('name') }}" placeholder="Hành chính Hà Nội"></div>
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
  <div class="card-head" style="flex-wrap:wrap;gap:10px">
    <h2><x-icon name="clock" :size="18" /> Danh sách ca hiện tại ({{ $shifts->total() }})</h2>
    @if($branches->count() > 1)
      <form method="get" style="display:flex;gap:8px;align-items:center;margin:0">
        <select name="branch_id" onchange="this.form.submit()" aria-label="Lọc ca theo chi nhánh" style="min-block-size:34px;padding:4px 10px">
          <option value="">Tất cả chi nhánh</option>
          @foreach($branches as $b)
            <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->code }} – {{ $b->name }}</option>
          @endforeach
        </select>
      </form>
    @endif
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách ca làm" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Ca làm</th>
          <th>Phạm vi chi nhánh</th>
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
            <td data-label="Chi nhánh">
              @if($shift->branch)
                <x-badge variant="success">{{ $shift->branch->code }} – {{ $shift->branch->name }}</x-badge>
              @else
                <x-badge variant="neutral">Toàn công ty</x-badge>
              @endif
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
              @if($shift->branch_id !== null || $canCreateGlobal)
                <details class="disclosure">
                  <summary><x-icon name="edit" :size="14" /> Thay đổi</summary>
                  <form method="post" action="{{ route('admin.shifts.update', $shift) }}" style="margin-top:0.8rem">
                    @csrf @method('PUT')
                    <div class="form-grid">
                      <div class="field">
                        <label>Chi nhánh</label>
                        <select name="branch_id" @if(!$canCreateGlobal) required @endif>
                          @if($canCreateGlobal)
                            <option value="">Toàn công ty</option>
                          @endif
                          @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected($shift->branch_id == $b->id)>{{ $b->code }} – {{ $b->name }}</option>
                          @endforeach
                        </select>
                      </div>
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
              @else
                <span class="muted small">Ca chung toàn công ty</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-state">Chưa có ca làm nào. Hãy tạo ca đầu tiên ở biểu mẫu phía trên.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($shifts->hasPages())
    <div class="pagination-wrap">{{ $shifts->links() }}</div>
  @endif
</section>
@endsection
