@props(['label', 'value', 'icon' => 'dashboard', 'tone' => 'blue'])
<div class="stat-card stat-card--{{ $tone }}">
  <div>
    <span class="stat-label">{{ $label }}</span>
    <span class="stat-value">{{ $value }}</span>
  </div>
  <div class="stat-icon">
    <x-icon :name="$icon" :size="22" />
  </div>
</div>
