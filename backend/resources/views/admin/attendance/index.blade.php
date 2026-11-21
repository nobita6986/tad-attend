@extends('admin.layout')
@section('title','Lượt chấm công')
@section('content')
@php
  $tz = config('attendance.timezone');
  $dirLabels = ['both' => 'Chấm công', 'in' => 'Vào', 'out' => 'Ra'];
  $stateLabels = ['accepted' => ['Hợp lệ', 'success'], 'pending_review' => ['Chờ duyệt', 'warning'], 'rejected' => ['Từ chối', 'danger']];
@endphp

<div class="page-header">
  <div>
    <h1>Lượt chấm công</h1>
    <p class="page-subtitle">Tra cứu toàn bộ lượt quét theo ngày, đối chiếu ảnh minh chứng và duyệt các lượt ngoại tuyến / lệch giờ.</p>
  </div>
</div>

<section class="card">
  <form method="get" class="form-grid" style="align-items:end">
    <div class="field">
      <label for="filter-date">Ngày</label>
      <input id="filter-date" type="date" name="date" value="{{ $date }}">
    </div>
    @if($branches->count() > 1)
      <div class="field">
        <label for="filter-branch">Chi nhánh</label>
        <select id="filter-branch" name="branch_id">
          <option value="">Tất cả chi nhánh</option>
          @foreach($branches as $b)
            <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->code }} – {{ $b->name }}</option>
          @endforeach
        </select>
      </div>
    @endif
    <div class="field">
      <label for="filter-emp">Nhân viên</label>
      <select id="filter-emp" name="employee_id">
        <option value="">Tất cả nhân viên</option>
        @foreach($employees as $e)
          <option value="{{ $e->id }}" @selected(request('employee_id')==$e->id)>{{ $e->code }} · {{ $e->full_name }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="filter-state">Trạng thái</label>
      <select id="filter-state" name="state">
        <option value="">Tất cả trạng thái</option>
        @foreach(['accepted'=>'Hợp lệ','pending_review'=>'Chờ duyệt','rejected'=>'Từ chối'] as $key=>$label)
          <option value="{{ $key }}" @selected(request('state')===$key)>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="filter-per-page">Số dòng / trang</label>
      <select id="filter-per-page" name="per_page">
        @foreach([20, 30, 50, 100] as $opt)
          <option value="{{ $opt }}" @selected(($perPage ?? 30) === $opt)>{{ $opt }} dòng</option>
        @endforeach
      </select>
    </div>
    <div>
      <button type="submit" style="width:100%"><x-icon name="filter" :size="15" /> Lọc kết quả</button>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="scan" :size="18" /> Kết quả ngày {{ \Carbon\CarbonImmutable::parse($date)->format('d/m/Y') }} ({{ $events->total() }} lượt)</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng lượt chấm công" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nhân viên</th>
          <th>Thời gian quét</th>
          <th>Thiết bị</th>
          <th>Loại lượt</th>
          <th>Độ khớp</th>
          <th>Trạng thái</th>
          <th>Minh chứng</th>
          <th>Xét duyệt</th>
        </tr>
      </thead>
      <tbody>
        @forelse($events as $ev)
          @php
            [$stText, $stVariant] = $stateLabels[$ev->review_state] ?? [$ev->review_state, 'neutral'];
            $ini = mb_strtoupper(mb_substr(trim($ev->employee->full_name), 0, 2));
            $pct = max(0, min(100, (int) round(((float) $ev->match_score) * 100)));
          @endphp
          <tr>
            <td data-label="Nhân viên">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $ev->employee->full_name }}</strong>
                  <div class="muted small">{{ $ev->employee->code }}</div>
                </div>
              </div>
            </td>
            <td data-label="Thời gian">
              <div class="num">{{ $ev->captured_at->timezone($tz)->format('d/m/Y H:i:s') }}</div>
              <div class="muted small">Nhận: {{ $ev->received_at->timezone($tz)->format('H:i:s') }}</div>
            </td>
            <td data-label="Thiết bị">{{ $ev->device->name }}</td>
            <td data-label="Loại lượt"><x-badge variant="info">{{ $dirLabels[$ev->direction] ?? $ev->direction }}</x-badge></td>
            <td data-label="Độ khớp">
              <div class="score-pill">
                <span class="score-bar" aria-hidden="true"><span style="width:{{ $pct }}%"></span></span>
                <span>{{ number_format((float)$ev->match_score, 3) }}</span>
              </div>
            </td>
            <td data-label="Trạng thái">
              <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                <x-badge :variant="$stVariant">{{ $stText }}</x-badge>
                @if($ev->is_offline)
                  <span class="muted small">Ngoại tuyến</span>
                @endif
              </div>
            </td>
            <td data-label="Minh chứng">
              @if($ev->image_path)
                <a class="btn btn-outline btn-sm" href="{{ route('admin.attendance.image', $ev) }}" data-preview-image data-preview-title="{{ $ev->employee->code }} · {{ $ev->employee->full_name }} ({{ $ev->captured_at->timezone($tz)->format('d/m/Y H:i:s') }})">
                  <x-icon name="photo" :size="14" /> Xem ảnh
                </a>
              @else
                <span class="muted small">Đã xóa</span>
              @endif
            </td>
            <td data-label="Xét duyệt">
              @if($ev->review_state === 'pending_review')
                <details class="disclosure">
                  <summary>Kiểm tra &amp; duyệt</summary>
                  <form method="post" action="{{ route('admin.attendance.review', $ev) }}" style="display:grid;gap:0.5rem;margin-top:0.65rem;min-width:210px">
                    @csrf
                    <div class="field">
                      <label>Lý do / chứng cứ</label>
                      <input name="reason" minlength="5" required placeholder="Đối chiếu camera gốc...">
                    </div>
                    <div class="field">
                      <label>Quyết định</label>
                      <select name="decision">
                        <option value="accepted">Duyệt hợp lệ</option>
                        <option value="rejected">Từ chối</option>
                      </select>
                    </div>
                    <button type="submit" class="btn-sm">Xác nhận</button>
                  </form>
                </details>
              @else
                <span class="muted small">—</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="empty-state">Không có lượt chấm công nào phù hợp với bộ lọc.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($events->hasPages())
    <div class="pagination-wrap">{{ $events->links() }}</div>
  @endif
</section>
@endsection
