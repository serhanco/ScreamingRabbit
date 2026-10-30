@php
    $crawl = $getRecord();
    $chips = [['high_count', 'danger', 'Yüksek'], ['medium_count', 'warning', 'Orta'], ['low_count', 'info', 'Düşük']];
@endphp
<div style="display:flex;gap:6px;align-items:center;padding:4px 0">
    @if ($crawl->isActive() && ! $crawl->high_count && ! $crawl->medium_count && ! $crawl->low_count)
        <span style="font-size:12px;opacity:.5">tarama bitince</span>
    @else
        @foreach ($chips as [$key, $tone, $label])
            <span title="{{ $label }} önem" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-variant-numeric:tabular-nums;padding:1px 7px;border-radius:6px;background:color-mix(in oklab, var(--{{ $tone }}-500) {{ $crawl->{$key} ? 14 : 6 }}%, transparent);@if (! $crawl->{$key}) opacity:.45; @endif">
                <span style="width:6px;height:6px;border-radius:2px;background:var(--{{ $tone }}-500)"></span>{{ number_format((int) $crawl->{$key}, 0, ',', '.') }}
            </span>
        @endforeach
    @endif
</div>
