@extends('admin.layout')
@section('title','Nhân viên')
@section('content')
<div class="page-header">
  <div>
    <h1>Quản lý nhân viên</h1>
    <p class="page-subtitle">Quản lý hồ sơ, phân ca, sự đồng ý sinh trắc và trạng thái khuôn mặt. Có thể thêm nhân viên và chụp khuôn mặt trực tiếp trên app chấm công.</p>
  </div>
</div>

<section class="card">
  <details class="disclosure" @if($errors->any()) open @endif>
    <summary><x-icon name="plus" :size="16" /> Thêm nhân viên mới trên web</summary>
    <form method="post" action="{{ route('admin.employees.store') }}" style="margin-top:1rem">
      @csrf
      <div class="form-grid">
        <div class="field">
          <label for="emp-code">Mã nhân viên * <span class="muted small">(tự sinh · có thể sửa)</span></label>
          <input id="emp-code" name="code" maxlength="32" value="{{ old('code', $suggestedCode) }}" placeholder="{{ $suggestedCode }}">
        </div>
        <div class="field"><label for="emp-name">Họ và tên *</label><input id="emp-name" required name="full_name" value="{{ old('full_name') }}" placeholder="Nguyễn Văn A"></div>
        <div class="field">
          <label for="emp-gender">Giới tính</label>
          <select id="emp-gender" name="gender">
            <option value="">—</option>
            <option value="male" @selected(old('gender')==='male')>Nam</option>
            <option value="female" @selected(old('gender')==='female')>Nữ</option>
            <option value="other" @selected(old('gender')==='other')>Khác</option>
          </select>
        </div>
        <div class="field"><label for="emp-dob">Ngày sinh</label><input id="emp-dob" type="date" name="date_of_birth" value="{{ old('date_of_birth') }}"></div>
        <div class="field"><label for="emp-from">Bắt đầu làm</label><input id="emp-from" type="date" name="employed_from" value="{{ old('employed_from') }}"></div>
        <div class="field"><label for="emp-until">Nghỉ việc sau ngày</label><input id="emp-until" type="date" name="employed_until" value="{{ old('employed_until') }}"></div>
        <div class="field"><label for="emp-dept">Bộ phận</label><input id="emp-dept" name="department" value="{{ old('department') }}" placeholder="Kinh doanh / Kỹ thuật"></div>
        <div class="field"><label for="emp-addr">Địa chỉ</label><input id="emp-addr" name="address" value="{{ old('address') }}"></div>
        <div class="field">
          <label for="emp-shift">Ca làm mặc định</label>
          <select id="emp-shift" name="shift_id">
            <option value="">Chưa phân ca</option>
            @foreach($shifts as $shift)
              <option value="{{ $shift->id }}" @selected(old('shift_id')==$shift->id)>{{ $shift->name }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <div style="margin-top:1rem">
        <button type="submit"><x-icon name="plus" :size="16" /> Tạo nhân viên</button>
      </div>
    </form>
  </details>
</section>

<section class="card">
  <div class="card-head">
    <h2><x-icon name="users" :size="18" /> Danh sách nhân viên ({{ $employees->total() }})</h2>
  </div>

  <div tabindex="0" role="region" aria-label="Bảng danh sách nhân viên" class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Nhân viên</th>
          <th>Bộ phận / Ca</th>
          <th>Trạng thái</th>
          <th>Khuôn mặt</th>
          <th>Quản lý</th>
        </tr>
      </thead>
      <tbody>
        @forelse($employees as $e)
          @php
            $hasFace = $e->templates->where('active', true)->count() > 0;
            $ini = mb_strtoupper(mb_substr(trim($e->full_name), 0, 2));
          @endphp
          <tr>
            <td data-label="Nhân viên">
              <div class="person-cell">
                <span class="person-avatar">{{ $ini }}</span>
                <div>
                  <strong>{{ $e->full_name }}</strong>
                  <div class="muted small">{{ $e->code }}</div>
                </div>
              </div>
            </td>
            <td data-label="Bộ phận / Ca">
              <div>{{ $e->department ?: '—' }}</div>
              <div class="muted small">{{ $e->shift?->name ?: 'Chưa phân ca' }}</div>
            </td>
            <td data-label="Trạng thái">
              <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                <x-badge :variant="$e->active ? 'success' : 'danger'">{{ $e->active ? 'Đang làm' : 'Tạm khóa' }}</x-badge>
                <span class="muted small">{{ $e->consent_at ? 'Đã đồng ý sinh trắc' : 'Chưa đồng ý' }}</span>
              </div>
            </td>
            <td data-label="Khuôn mặt">
              <x-badge :variant="$hasFace ? 'info' : 'warning'">{{ $hasFace ? 'Đã đăng ký' : 'Chưa có mặt' }}</x-badge>
            </td>
            <td data-label="Quản lý" style="min-width:260px">
              <details class="disclosure">
                <summary><x-icon name="edit" :size="14" /> Sửa hồ sơ / Phân ca / Đồng ý</summary>
                <form method="post" action="{{ route('admin.employees.update', $e) }}" style="margin-top:0.85rem">
                  @csrf @method('PUT')
                  <div class="form-grid">
                    <div class="field"><label>Mã NV</label><input name="code" required value="{{ $e->code }}"></div>
                    <div class="field"><label>Họ tên</label><input name="full_name" required value="{{ $e->full_name }}"></div>
                    <div class="field">
                      <label>Giới tính</label>
                      <select name="gender">
                        <option value="">—</option>
                        @foreach(['male'=>'Nam','female'=>'Nữ','other'=>'Khác'] as $k=>$v)
                          <option value="{{ $k }}" @selected($e->gender===$k)>{{ $v }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="field"><label>Ngày sinh</label><input name="date_of_birth" type="date" value="{{ $e->date_of_birth?->format('Y-m-d') }}"></div>
                    <div class="field"><label>Bắt đầu làm</label><input type="date" name="employed_from" value="{{ $e->employed_from?->format('Y-m-d') }}"></div>
                    <div class="field"><label>Ngày làm cuối</label><input type="date" name="employed_until" value="{{ $e->employed_until?->format('Y-m-d') }}"></div>
                    <div class="field"><label>Bộ phận</label><input name="department" value="{{ $e->department }}"></div>
                    <div class="field"><label>Địa chỉ</label><input name="address" value="{{ $e->address }}"></div>
                    <div class="field">
                      <label>Ca làm</label>
                      <select name="shift_id">
                        <option value="">Chưa phân ca</option>
                        @foreach($shifts as $shift)
                          <option value="{{ $shift->id }}" @selected($e->shift_id==$shift->id)>{{ $shift->name }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="field">
                      <label>Hoạt động</label>
                      <select name="active">
                        <option value="1" @selected($e->active)>Có</option>
                        <option value="0" @selected(!$e->active)>Không</option>
                      </select>
                    </div>
                  </div>
                  <div style="margin-top:0.8rem"><button type="submit" class="btn-sm">Lưu hồ sơ</button></div>
                </form>

                <details class="disclosure" style="margin-top:0.75rem">
                  <summary>Phân ca theo giai đoạn (lưu lịch sử)</summary>
                  <form method="post" action="{{ route('admin.employees.assign-shift', $e) }}" style="margin-top:0.65rem">
                    @csrf
                    <div class="form-grid">
                      <div class="field">
                        <label>Ca áp dụng</label>
                        <select name="shift_id" required>
                          @foreach($shifts as $sh)
                            <option value="{{ $sh->id }}">{{ $sh->name }}</option>
                          @endforeach
                        </select>
                      </div>
                      <div class="field"><label>Từ ngày</label><input type="date" required name="effective_from"></div>
                      <div class="field"><label>Đến ngày (trống = vô hạn)</label><input type="date" name="effective_to"></div>
                    </div>
                    <div style="margin-top:0.65rem"><button type="submit" class="btn-sm">Thêm phân ca</button></div>
                  </form>
                  <ul class="muted small" style="margin:0.65rem 0 0;padding-inline-start:1.2rem">
                    @forelse($e->shiftAssignments as $assignment)
                      <li>{{ $assignment->shift?->name }}: {{ $assignment->effective_from->format('d/m/Y') }} – {{ $assignment->effective_to?->format('d/m/Y') ?? 'hiện tại' }}</li>
                    @empty
                      <li>Chưa có phân ca theo giai đoạn.</li>
                    @endforelse
                  </ul>
                </details>

                <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--color-border)">
                  @if(!$e->consent_at)
                    <form method="post" action="{{ route('admin.employees.consent', $e) }}" style="display:grid;gap:0.5rem">
                      @csrf
                      <label>Ghi nhận bằng chứng đồng ý sinh trắc</label>
                      <select name="method" required>
                        <option value="signed_form">Phiếu ký giấy</option>
                        <option value="electronic_consent">Đồng ý điện tử đã lưu chứng cứ</option>
                      </select>
                      <div><button type="submit" class="btn-outline btn-sm">Ghi nhận sự đồng ý</button></div>
                    </form>
                  @else
                    <form method="post" action="{{ route('admin.employees.revoke-consent', $e) }}" onsubmit="return confirm('Xóa mẫu khuôn mặt trên server? Hãy bấm đồng bộ lại trên các máy chấm công!')" style="margin:0">
                      @csrf
                      <button class="btn-danger btn-sm" type="submit">Thu hồi đồng ý &amp; xóa mẫu</button>
                    </form>
                  @endif
                </div>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-state">Chưa có nhân viên nào. Bạn có thể tạo ngay ở trên hoặc tạo trực tiếp trên app Android.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  @if($employees->hasPages())
    <div class="pagination-wrap">{{ $employees->links() }}</div>
  @endif
</section>
@endsection
