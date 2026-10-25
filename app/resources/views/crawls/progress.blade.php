@php
    $crawl = $getRecord();
    $progress = $crawl->progress();
@endphp
<div @if ($crawl->isActive()) wire:poll.5s="refreshProgress" @endif>
    <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:6px">
        <span><strong>{{ $progress['phase'] }}</strong></span>
        <span>{{ number_format($progress['done'], 0, ',', '.') }} / {{ number_format($progress['total'], 0, ',', '.') }} URL · %{{ $progress['percent'] }}</span>
    </div>
    <div style="height:10px;border-radius:5px;background:rgba(127,127,127,.2);overflow:hidden">
        <div style="height:100%;width:{{ $progress['percent'] }}%;background:#ea580c;transition:width .5s"></div>
    </div>
    <p style="font-size:12px;opacity:.7;margin-top:6px">Tarama sunucuda arka planda sürer; bu sayfayı kapatabilirsin. Toplam, bulundukça artan URL sayısıdır (en fazla {{ number_format((int) ($crawl->settings['max_urls'] ?? 0), 0, ',', '.') }}).</p>
</div>
