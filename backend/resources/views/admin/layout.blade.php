<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>@yield('title', 'Quản trị') · TAD Attend</title>
  <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
  <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=20261121a">
</head>
<body class="{{ auth()->check() ? 'has-sidebar' : 'auth-page' }}">
@if(auth()->check())
  @php
    $user = auth()->user();
    $isSystemAdmin = $user->isSystemAdmin();
    $initials = mb_strtoupper(mb_substr(trim($user->name ?: $user->email), 0, 2));
    $roleTag = $user->roleLabel();
    $scopeTag = $user->scopeLabel();

    $navGroups = [
      [
        'label' => 'Tổng quan',
        'items' => [
          ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'label' => 'Tổng quan', 'icon' => 'dashboard'],
        ],
      ],
      [
        'label' => 'Chấm công',
        'items' => [
          ['route' => 'admin.attendance.index', 'match' => 'admin.attendance.*', 'label' => 'Lượt quét',    'icon' => 'scan'],
          ['route' => 'admin.reports.index',    'match' => 'admin.reports.*',    'label' => 'Báo cáo công', 'icon' => 'chart'],
        ],
      ],
      [
        'label' => 'Nhân sự',
        'items' => [
          ['route' => 'admin.employees.index', 'match' => 'admin.employees.*', 'label' => 'Nhân viên', 'icon' => 'users'],
          ['route' => 'admin.shifts.index',    'match' => 'admin.shifts.*',    'label' => 'Ca làm',    'icon' => 'clock'],
        ],
      ],
    ];

    if ($isSystemAdmin) {
      $pendingDevicesCount = \App\Models\Device::where('approval_status', 'pending')->count();
      $navGroups[] = [
        'label' => 'Hệ thống',
        'items' => [
          ['route' => 'admin.branches.index', 'match' => 'admin.branches.*', 'label' => 'Chi nhánh', 'icon' => 'building'],
          ['route' => 'admin.devices.index',  'match' => 'admin.devices.*',  'label' => 'Thiết bị',  'icon' => 'device', 'badge' => $pendingDevicesCount],
          ['route' => 'admin.users.index',    'match' => 'admin.users.*',    'label' => 'Tài khoản', 'icon' => 'shield'],
        ],
      ];
    }
  @endphp

  <div class="app-shell">
    <header class="mobile-header" id="mobile-header">
      <a class="brand" href="{{ route('admin.dashboard') }}">
        <img src="{{ asset('images/tad-logo.png') }}" alt="TAD Attend" style="height:30px;width:auto;display:block">
        <span>TAD Attend</span>
      </a>
      <button
        class="menu-button"
        type="button"
        popovertarget="app-sidebar"
        popovertargetaction="toggle"
        aria-controls="app-sidebar"
      >
        <x-icon name="menu" :size="18" />
        <span>Danh mục</span>
      </button>
    </header>

    <aside id="app-sidebar" class="app-sidebar" popover="auto">
      <div class="sidebar-top">
        <a class="brand" href="{{ route('admin.dashboard') }}">
          <img src="{{ asset('images/tad-logo.png') }}" alt="TAD Attend" style="height:34px;width:auto;display:block">
          <div>
            <span class="brand-title">TAD Attend</span>
            <span class="brand-caption">Quản trị chấm công</span>
          </div>
        </a>
        <button type="button" class="sidebar-close btn-ghost btn-sm" popovertarget="app-sidebar" popovertargetaction="hide" aria-label="Đóng menu">
          ✕
        </button>
      </div>

      <nav class="sidebar-nav" aria-label="Điều hướng quản trị">
        @foreach($navGroups as $group)
          <div class="nav-group">
            <div class="nav-group-label">{{ $group['label'] }}</div>
            <ul class="nav-list">
              @foreach($group['items'] as $item)
                @php($isCurrent = request()->routeIs($item['match']))
                <li>
                  <a href="{{ route($item['route']) }}" @if($isCurrent) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" :size="17" />
                    <span style="flex:1">{{ $item['label'] }}</span>
                    @if(!empty($item['badge']))
                      <span style="background:#EA580C;color:#fff;font-size:0.72rem;font-weight:700;padding:1px 7px;border-radius:999px;line-height:1.4">{{ $item['badge'] }}</span>
                    @endif
                  </a>
                </li>
              @endforeach
            </ul>
          </div>
        @endforeach
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-user" title="{{ $user->email }}">
          <span class="avatar-dot">{{ $initials }}</span>
          <div class="sidebar-user-meta">
            <strong class="sidebar-user-name">{{ $user->name ?: $roleTag }}</strong>
            <span class="sidebar-user-role">{{ $roleTag }} · {{ $scopeTag }}</span>
          </div>
        </div>
        <form method="post" action="{{ route('admin.logout') }}" style="margin:0">
          @csrf
          <button class="btn-outline btn-sm" type="submit" style="width:100%">
            <x-icon name="logout" :size="15" />
            <span>Đăng xuất</span>
          </button>
        </form>
      </div>
    </aside>

    <div class="app-main">
      <main class="container">
        @if(session('success'))
          <div class="alert" role="status">
            <x-icon name="check" :size="18" style="flex-shrink:0;margin-top:2px" />
            <div>{{ session('success') }}</div>
          </div>
        @endif

        @if($errors->any())
          <div class="alert alert--error" role="alert">
            <x-icon name="alert" :size="18" style="flex-shrink:0;margin-top:2px" />
            <div>
              <strong>Vui lòng kiểm tra lại thông tin:</strong>
              <ul>
                @foreach($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          </div>
        @endif

        @yield('content')
      </main>

      <footer class="site-footer">
        TAD Attend · Hệ thống chấm công khuôn mặt on-device (SFace 128d) · Múi giờ Việt Nam (UTC+7)
      </footer>
    </div>
  </div>
@else
  <header class="auth-header">
    <a class="brand" href="{{ route('login') }}">
      <span class="brand-mark"><x-icon name="scan" :size="20" /></span>
      <span>TAD Attend</span>
      <span class="brand-sub">Quản trị</span>
    </a>
  </header>

  <main class="container">
    @if(session('success'))
      <div class="alert" role="status">
        <x-icon name="check" :size="18" style="flex-shrink:0;margin-top:2px" />
        <div>{{ session('success') }}</div>
      </div>
    @endif

    @if($errors->any())
      <div class="alert alert--error" role="alert">
        <x-icon name="alert" :size="18" style="flex-shrink:0;margin-top:2px" />
        <div>
          <strong>Vui lòng kiểm tra lại thông tin:</strong>
          <ul>
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
      </div>
    @endif

    @yield('content')
  </main>

  <footer class="site-footer">
    TAD Attend · Hệ thống chấm công khuôn mặt on-device (SFace 128d) · Múi giờ Việt Nam (UTC+7)
  </footer>
@endif

<dialog id="evidence-lightbox" class="lightbox" aria-labelledby="lightbox-title">
  <div class="lightbox-head">
    <strong id="lightbox-title">Ảnh minh chứng chấm công</strong>
    <div style="display:flex;gap:8px;align-items:center">
      <a id="lightbox-open-tab" class="btn btn-outline btn-sm" href="#" target="_blank" rel="noopener">Mở tab mới</a>
      <button type="button" class="btn-outline btn-sm" onclick="document.getElementById('evidence-lightbox').close()">Đóng</button>
    </div>
  </div>
  <div class="lightbox-body">
    <img id="lightbox-img" src="" alt="Ảnh minh chứng chấm công">
  </div>
</dialog>

<script>
(() => {
  // Conditional Popover polyfill only when native support is absent
  if (!Object.hasOwn(HTMLElement.prototype, 'popover')) {
    import('https://unpkg.com/@oddbird/popover-polyfill@latest/dist/popover.min.js').catch(() => {});
  }

  // Synchronize sidebar popover attribute between desktop (>= 1024px) and mobile drawer (< 1024px)
  const sidebar = document.getElementById('app-sidebar');
  if (sidebar) {
    const mq = window.matchMedia('(min-width: 1024px)');
    const syncSidebar = () => {
      if (mq.matches) {
        sidebar.removeAttribute('popover');
      } else {
        sidebar.setAttribute('popover', 'auto');
      }
    };
    mq.addEventListener('change', syncSidebar);
    syncSidebar();
  }

  // Sync aria-invalid with CSS :user-invalid (and fallback if unsupported)
  const supportsUserInvalid = CSS.supports('selector(:user-invalid)');
  const dirty = new WeakMap();
  const syncField = (el) => {
    if (!el || !el.matches || !el.matches('input, select, textarea')) return;
    if (supportsUserInvalid) {
      el.setAttribute('aria-invalid', el.matches(':user-invalid') ? 'true' : 'false');
    } else if (el.checkValidity) {
      const ok = el.validity.valid;
      el.classList.toggle('user-invalid-fallback', !ok);
      el.setAttribute('aria-invalid', ok ? 'false' : 'true');
    }
  };
  document.querySelectorAll('input, select, textarea').forEach((el) => {
    if (!el.hasAttribute('aria-invalid')) el.setAttribute('aria-invalid', 'false');
  });
  document.addEventListener('blur', (e) => {
    const t = e.target;
    if (!supportsUserInvalid && t && t.matches && t.matches('input, select, textarea')) {
      const st = dirty.get(t) || { changed: false };
      if (st.changed) syncField(t);
    } else {
      syncField(t);
    }
  }, true);
  document.addEventListener('input', (e) => {
    const t = e.target;
    if (!supportsUserInvalid && t) dirty.set(t, { changed: true });
    if (t && t.getAttribute && t.getAttribute('aria-invalid') === 'true') syncField(t);
  });
  document.addEventListener('invalid', (e) => syncField(e.target), true);

  // Evidence photo modal preview
  const dlg = document.getElementById('evidence-lightbox');
  const dlgImg = document.getElementById('lightbox-img');
  const dlgTitle = document.getElementById('lightbox-title');
  const dlgLink = document.getElementById('lightbox-open-tab');
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-preview-image]');
    if (!trigger || !dlg || typeof dlg.showModal !== 'function') return;
    e.preventDefault();
    const url = trigger.getAttribute('href');
    dlgImg.src = url;
    dlgLink.href = url;
    dlgTitle.textContent = trigger.getAttribute('data-preview-title') || 'Ảnh minh chứng chấm công';
    dlg.showModal();
  });
  if (dlg) {
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
  }
})();
</script>
</body>
</html>
