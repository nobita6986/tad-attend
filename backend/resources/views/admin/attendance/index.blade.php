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
  <x-date-presets
    :from="$from"
    :to="$to"
    route="admin.attendance.index"
    :preserve="['branch_id', 'employee_id', 'state', 'per_page']"
  />
  <form method="get" class="form-grid" style="align-items:end">
    <div class="field">
      <label for="filter-from">Từ ngày</label>
      <input id="filter-from" type="date" name="from" required value="{{ $from }}">
    </div>
    <div class="field">
      <label for="filter-to">Đến ngày</label>
      <input id="filter-to" type="date" name="to" required value="{{ $to }}">
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
    <h2>
      <x-icon name="scan" :size="18" />
      @if($from === $to)
        Kết quả ngày {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} ({{ $events->total() }} lượt)
      @else
        Kết quả từ {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} đến {{ \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') }} ({{ $events->total() }} lượt)
      @endif
    </h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng lượt chấm công" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nhân viên</th>
          <th>Ngày &amp; giờ chấm công</th>
          <th>Tọa độ GPS &amp; Thiết bị</th>
          <th>Khoảng cách tới trụ sở</th>
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
            $evBranch = $ev->branch ?? $ev->employee?->branch;
          @endphp
          <tr>
            <td data-label="Nhân viên">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $ev->employee->full_name }}</strong>
                  <div class="muted small">Mã NV: {{ $ev->employee->code }}</div>
                </div>
              </div>
            </td>
            <td data-label="Ngày &amp; giờ">
              <div class="num"><strong>{{ $ev->captured_at->timezone($tz)->format('Y-m-d H:i:s') }}</strong></div>
              <div class="muted small">{{ $dirLabels[$ev->direction] ?? $ev->direction }} · Khớp {{ number_format((float)$ev->match_score, 3) }}</div>
            </td>
            <td data-label="Tọa độ GPS &amp; Thiết bị">
              @if($ev->latitude !== null && $ev->longitude !== null)
                <div class="num">
                  <a href="https://maps.google.com/?q={{ $ev->latitude }},{{ $ev->longitude }}" target="_blank" rel="noopener noreferrer" style="color:var(--color-primary);text-decoration:underline">
                    {{ number_format($ev->latitude, 6, '.', '') }}, {{ number_format($ev->longitude, 6, '.', '') }}
                  </a>
                  @if($ev->gps_accuracy_meters !== null)
                    <span class="muted small">(±{{ round($ev->gps_accuracy_meters) }} m)</span>
                  @endif
                </div>
              @else
                <span class="muted small">Không có tọa độ GPS</span>
              @endif
              <div class="muted small">{{ $ev->device->name }}</div>
            </td>
            <td data-label="Khoảng cách tới trụ sở">
              @if($ev->distance_meters !== null)
                <div class="num"><strong>{{ number_format($ev->distance_meters, 1, ',', '.') }} m</strong></div>
                <div class="muted small">Theo trụ sở {{ $evBranch?->code ?? '—' }}@if($evBranch?->allowed_radius_meters) (≤ {{ $evBranch->allowed_radius_meters }} m)@endif</div>
              @else
                <span class="muted small">{{ $evBranch ? ('Trụ sở ' . $evBranch->code) : '—' }}</span>
              @endif
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
