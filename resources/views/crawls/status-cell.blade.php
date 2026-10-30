@php
    $crawl = $getRecord();
    $active = $crawl->isActive();
    $progress = $active ? $crawl->progress() : null;
    $tone = ['completed' => 'success', 'running' => 'warning', 'pending' => 'warning', 'failed' => 'danger'][$crawl->status] ?? 'gray';
@endphp
<div style="min-width:150px;padding:4px 0">
    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:2px 9px;border-radius:999px;color:var(--{{ $tone }}-600);background:color-mix(in oklab, var(--{{ $tone }}-500) 14%, transparent)">
        <span style="width:6px;height:6px;border-radius:50%;background:var(--{{ $tone }}-500)@if ($active);animation:sr-pulse 1.4s ease-in-out infinite @endif"></span>
        {{ \App\Models\Crawl::STATUSES[$crawl->status] ?? $crawl->status }}
    </span>
    @if ($active)
        <div style="margin-top:6px;height:4px;width:140px;border-radius:2px;background:color-mix(in oklab, var(--gray-500) 20%, transparent);overflow:hidden">
            <div style="height:100%;width:{{ $progress['percent'] }}%;background:var(--primary-500)"></div>
        </div>
        <div style="font-size:11px;opacity:.65;margin-top:3px">%{{ $progress['percent'] }} · {{ $progress['phase'] }}</div>
    @elseif ($crawl->status === 'failed' && $crawl->error)
        <div style="font-size:11px;opacity:.65;margin-top:3px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="{{ $crawl->error }}">{{ $crawl->error }}</div>
    @endif
</div>
<style>@keyframes sr-pulse{0%,100%{opacity:1}50%{opacity:.25}}</style>
