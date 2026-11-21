@props([
  'from',
  'to',
  'route',
  'preserve' => [],
])
@php
  $tz = config('attendance.timezone');
  $today = \Carbon\CarbonImmutable::now($tz);
  $todayStr = $today->format('Y-m-d');
  $weekStartStr = $today->startOfWeek(\Carbon\CarbonInterface::MONDAY)->format('Y-m-d');
  $monthStartStr = $today->startOfMonth()->format('Y-m-d');
  $prevMonth = $today->subMonthNoOverflow();
  $prevMonthStartStr = $prevMonth->startOfMonth()->format('Y-m-d');
  $prevMonthEndStr = $prevMonth->endOfMonth()->format('Y-m-d');

  $presets = [
    ['label' => 'Hôm nay',     'from' => $todayStr,          'to' => $todayStr],
    ['label' => 'Tuần này',    'from' => $weekStartStr,      'to' => $todayStr],
    ['label' => 'Tháng này',   'from' => $monthStartStr,     'to' => $todayStr],
    ['label' => 'Tháng trước', 'from' => $prevMonthStartStr, 'to' => $prevMonthEndStr],
  ];

  $baseQuery = [];
  foreach ($preserve as $key) {
    $val = request()->query($key);
    if ($val !== null && $val !== '') {
      $baseQuery[$key] = $val;
    }
  }
@endphp

<div class="date-presets-bar" role="group" aria-label="Chọn nhanh khoảng thời gian">
  <span class="date-presets-label">Chọn nhanh:</span>
  @foreach($presets as $p)
    @php
      $isActive = ($from === $p['from'] && $to === $p['to']);
      $url = route($route, array_merge($baseQuery, ['from' => $p['from'], 'to' => $p['to']]));
    @endphp
    <a
      href="{{ $url }}"
      class="preset-pill{{ $isActive ? ' is-active' : '' }}"
      @if($isActive) aria-current="true" @endif
    >{{ $p['label'] }}</a>
  @endforeach
</div>
