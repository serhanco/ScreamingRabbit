@php
    $days = \App\Monitoring\MonitorRunner::dailyUptime($monitor, $count);
    $known = array_filter($days, fn ($v) => $v !== null);
    $average = $known ? round(array_sum($known) / count($known), 2) : null;
    $color = fn (?float $v) => match (true) {
        $v === null => '#d1d5db',
        $v >= 99.9 => '#16a34a',
        $v >= 99 => '#84cc16',
        $v >= 95 => '#f59e0b',
        default => '#dc2626',
    };
@endphp
<div style="display:flex;align-items:center;gap:8px">
    <div style="display:flex;gap:{{ $count > 40 ? 1 : 2 }}px;align-items:flex-end">
        @foreach ($days as $date => $value)
            <span title="{{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}: {{ $value === null ? 'veri yok' : '%'.number_format($value, 2, ',', '.') }}"
                  style="display:inline-block;width:{{ $count > 40 ? 4 : 5 }}px;height:18px;border-radius:1px;background:{{ $color($value) }}"></span>
        @endforeach
    </div>
    @if ($showAverage ?? false)
        <span style="font-size:12px;opacity:.75">{{ $count }} gün: {{ $average === null ? '—' : '%'.number_format($average, 2, ',', '.') }}</span>
    @endif
</div>
