<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>@yield('title', 'Quản trị') · TAD Attend</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v=20261120">
</head>
<body>
<header class="site-header" id="site-header">
  <div class="header-inner" id="header-inner">
    <a class="brand" href="{{ auth()->check() ? route('admin.dashboard') : route('login') }}">
      <span class="brand-mark"><x-icon name="scan" :size="20" /></span>
      <span>TAD Attend</span>
      <span class="brand-sub">Quản trị</span>
    </a>

    @if(auth()->check())
    <nav class="site-nav" aria-label="Điều hướng quản trị">
      <button
        class="menu-button"
        type="button"
        popovertarget="site-menu"
        popovertargetaction="toggle"
        aria-controls="site-menu"
      >
        <x-icon name="menu" :size="18" />
        <span>Danh mục</span>
      </button>

      @php
        $navItems = [
          ['route' => 'admin.dashboard',        'match' => 'admin.dashboard',   'label' => 'Tổng quan', 'icon' => 'dashboard'],
          ['route' => 'admin.employees.index',  'match' => 'admin.employees.*', 'label' => 'Nhân viên', 'icon' => 'users'],
          ['route' => 'admin.shifts.index',     'match' => 'admin.shifts.*',    'label' => 'Ca làm',    'icon' => 'clock'],
          ['route' => 'admin.attendance.index', 'match' => 'admin.attendance.*','label' => 'Lượt quét', 'icon' => 'scan'],
          ['route' => 'admin.reports.index',    'match' => 'admin.reports.*',   'label' => 'Báo cáo',   'icon' => 'chart'],
          ['route' => 'admin.devices.index',    'match' => 'admin.devices.*',   'label' => 'Thiết bị',  'icon' => 'device'],
        ];
      @endphp
      <ul id="site-menu" class="site-menu" popover="auto">
        @foreach($navItems as $item)
          @php($isCurrent = request()->routeIs($item['match']))
          <li>
            <a href="{{ route($item['route']) }}" @if($isCurrent) aria-current="page" @endif>
              <x-icon :name="$item['icon']" :size="16" />
              <span>{{ $item['label'] }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    </nav>

    <div class="header-actions">
      @php($initials = mb_strtoupper(mb_substr(trim(auth()->user()->name ?: auth()->user()->email), 0, 2)))
      <span class="user-chip" title="{{ auth()->user()->email }}">
        <span class="avatar-dot">{{ $initials }}</span>
        <span>{{ auth()->user()->name ?: 'Admin' }}</span>
      </span>
      <form method="post" action="{{ route('admin.logout') }}" style="margin:0">
        @csrf
        <button class="btn-outline btn-sm" type="submit" aria-label="Đăng xuất">
          <x-icon name="logout" :size="15" />
          <span>Đăng xuất</span>
        </button>
      </form>
    </div>
    @endif
  </div>
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

  // Responsive navbar measurement (single semantic nav tree, wide inline vs narrow popover)
  const header = document.getElementById('site-header');
  const inner = document.getElementById('header-inner');
  const menu = document.getElementById('site-menu');
  if (header && inner && menu) {
    let lastWidth = 0;
    const syncNav = () => {
      const currentWidth = header.clientWidth;
      const isCurrentlyWide = header.classList.contains('is-wide');
      if (!isCurrentlyWide && currentWidth <= lastWidth && lastWidth > 0) return;
      lastWidth = currentWidth;

      const wasOpen = menu.matches(':popover-open') || menu.classList.contains(':popover-open');
      header.classList.add('is-measuring');
      menu.removeAttribute('popover');
      header.classList.add('is-wide');

      const fits = inner.scrollWidth <= inner.clientWidth + 1;
      if (fits) {
        header.classList.add('is-wide');
        menu.removeAttribute('popover');
      } else {
        header.classList.remove('is-wide');
        menu.setAttribute('popover', 'auto');
        if (wasOpen && typeof menu.showPopover === 'function') {
          try { menu.showPopover(); } catch (_) {}
        }
      }
      requestAnimationFrame(() => header.classList.remove('is-measuring'));
    };

    const ro = new ResizeObserver(() => syncNav());
    ro.observe(header);
    ro.observe(menu);
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(() => { lastWidth = 0; syncNav(); });
    }
    syncNav();
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
