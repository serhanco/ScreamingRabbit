@php
    /** @var \App\Models\Crawl $crawl */
    $crawl = $getRecord();
    $active = $crawl->isActive();
    $progress = $active ? $crawl->progress() : null;
    $tz = config('screamingrabbit.alerts.timezone');

    $codes = $crawl->pages()->where('state', 'done')
        ->selectRaw("COUNT(*) as done,
            SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as ok,
            SUM(CASE WHEN status_code BETWEEN 300 AND 399 THEN 1 ELSE 0 END) as redirects,
            SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as client,
            SUM(CASE WHEN status_code >= 500 OR status_code IS NULL THEN 1 ELSE 0 END) as server")
        ->first();
    $severity = $crawl->issues()->groupBy('severity')->selectRaw('severity, COUNT(*) as n')->pluck('n', 'severity');
    $issueTotal = (int) $severity->sum();

    $score = $crawl->health_score;
    $scoreTone = \App\Filament\Resources\Crawls\CrawlColors::health($score);
    $statusTone = ['completed' => 'success', 'running' => 'warning', 'pending' => 'warning', 'failed' => 'danger'][$crawl->status] ?? 'gray';
    $ringValue = $active ? $progress['percent'] : ($score ?? 0);
    $ringTone = $active ? 'primary' : $scoreTone;
    $circumference = 2 * M_PI * 52;

    $minutes = $crawl->started_at ? (int) $crawl->started_at->diffInMinutes($crawl->finished_at ?? now()) : null;
    $duration = $minutes === null ? null : ($minutes < 1 ? '1 dk\'dan kısa' : ($minutes >= 60 ? intdiv($minutes, 60).' sa '.($minutes % 60).' dk' : $minutes.' dk'));
    $mode = \App\Crawler\CrawlRunner::mode($crawl->settings ?? []);
    $site = rtrim((string) $crawl->project?->base_url, '/');

    $kpis = [
        ['Taranan URL', $codes->done ?? 0, 'gray', null],
        ['Başarılı (2xx)', $codes->ok ?? 0, 'success', null],
        ['Yönlendirme (3xx)', $codes->redirects ?? 0, 'warning', null],
        ['Bulunamadı (4xx)', $codes->client ?? 0, 'danger', null],
        ['Sunucu hatası / yanıtsız', $codes->server ?? 0, 'danger', null],
    ];
    if ($active) {
        $kpis[] = ['Sırada bekleyen', $crawl->pages()->where('state', 'queued')->count(), 'primary', null];
    }

    $phases = ['Sırada, birazdan başlayacak', 'Sayfalar taranıyor', 'Görseller kontrol ediliyor', 'Dış linkler kontrol ediliyor', 'Bot erişimi test ediliyor', 'WordPress kontrolleri yapılıyor', 'Sonuçlar hesaplanıyor'];
    $phaseLabels = ['Sırada', 'Sayfalar', 'Görseller', 'Dış linkler', 'Bot erişimi', 'WordPress', 'Sonuçlar'];
    $phaseIndex = $active ? max(0, array_search($progress['phase'], $phases, true) ?: 0) : null;
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
@endphp

<div class="sr-ov" @if ($active) wire:poll.5s="refreshProgress" @endif>
    <div class="sr-ov-hero">
        <div class="sr-ov-ring" title="{{ $active ? 'İlerleme' : 'Sağlık skoru' }}">
            <svg viewBox="0 0 120 120" width="132" height="132" aria-hidden="true">
                <circle cx="60" cy="60" r="52" fill="none" stroke="color-mix(in oklab, var(--gray-500) 18%, transparent)" stroke-width="10"/>
                <circle cx="60" cy="60" r="52" fill="none" stroke="var(--{{ $ringTone }}-500)" stroke-width="10" stroke-linecap="round"
                        stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $ringValue / 100) }}"
                        transform="rotate(-90 60 60)" style="transition:stroke-dashoffset .6s ease"/>
            </svg>
            <div class="sr-ov-ring-label">
                @if ($active)
                    <strong>%{{ $progress['percent'] }}</strong><span>ilerleme</span>
                @elseif ($score !== null)
                    <strong style="color:var(--{{ $scoreTone }}-600)">{{ $score }}</strong><span>/ 100 sağlık</span>
                @else
                    <strong style="opacity:.4">—</strong><span>skor yok</span>
                @endif
            </div>
        </div>

        <div class="sr-ov-main">
            <div class="sr-ov-title">
                <a class="sr-ov-site" href="{{ $site }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', $site) }} ↗</a>
                <span class="sr-ov-pill" style="color:var(--{{ $statusTone }}-600);background:color-mix(in oklab, var(--{{ $statusTone }}-500) 14%, transparent)">
                    <span class="sr-ov-dot @if ($active) sr-ov-pulse @endif" style="background:var(--{{ $statusTone }}-500)"></span>
                    {{ \App\Models\Crawl::STATUSES[$crawl->status] ?? $crawl->status }}
                </span>
            </div>
            <div class="sr-ov-meta">
                @if ($crawl->started_at)<span>{{ $crawl->started_at->timezone($tz)->format('d.m.Y H:i') }}</span>@endif
                @if ($duration)<span>{{ $duration }} {{ $active ? 'sürüyor' : 'sürdü' }}</span>@endif
                <span>{{ \App\Filament\Resources\Projects\Schemas\ProjectForm::MODES[$mode] ?? $mode }}</span>
                <span>en fazla {{ $fmt($crawl->settings['max_urls'] ?? 0) }} URL</span>
            </div>

            @if ($active)
                <div class="sr-ov-steps">
                    @foreach ($phaseLabels as $i => $label)
                        <div class="sr-ov-step @if ($i < $phaseIndex) is-done @elseif ($i === $phaseIndex) is-now @endif">
                            <span></span>{{ $label }}
                        </div>
                    @endforeach
                </div>
                <div class="sr-ov-bar"><div style="width:{{ $progress['percent'] }}%"></div></div>
                <p class="sr-ov-note">{{ $progress['phase'] }} · {{ $fmt($progress['done']) }} / {{ $fmt($progress['total']) }} URL. Tarama sunucuda arka planda sürer; bu sayfayı kapatabilirsin.</p>
            @endif

            @foreach (array_filter([
                $crawl->error ? ['danger', 'Hata: '.$crawl->error] : null,
                $crawl->login_result ? [$crawl->session_cookie !== null || str_starts_with($crawl->login_result, 'Giriş yapıldı') ? 'success' : 'warning', 'Giriş formu: '.$crawl->login_result] : null,
                filled($crawl->settings['robots_override'] ?? null) ? ['warning', 'Bu tarama proje ayarlarındaki deneme robots.txt ile yapıldı (canlı dosya değil).'] : null,
            ]) as [$tone, $text])
                <div class="sr-ov-alert" style="border-color:color-mix(in oklab, var(--{{ $tone }}-500) 35%, transparent);background:color-mix(in oklab, var(--{{ $tone }}-500) 8%, transparent)">{{ $text }}</div>
            @endforeach
        </div>
    </div>

    <div class="sr-ov-kpis">
        @foreach ($kpis as [$label, $value, $tone])
            <div class="sr-ov-kpi">
                <span class="sr-ov-kpi-label"><i style="background:var(--{{ $tone }}-500)"></i>{{ $label }}</span>
                <strong @if ($tone !== 'gray' && $tone !== 'primary' && $tone !== 'success' && $value > 0) style="color:var(--{{ $tone }}-600)" @endif>{{ $fmt($value) }}</strong>
            </div>
        @endforeach
    </div>

    @if (! $active || $issueTotal > 0)
        <div class="sr-ov-issues">
            <div class="sr-ov-issues-head">
                <strong>{{ $fmt($issueTotal) }} bulgu</strong>
                <span>önem derecesine göre</span>
            </div>
            <div class="sr-ov-stack">
                @foreach (['high' => 'danger', 'medium' => 'warning', 'low' => 'info', 'info' => 'gray'] as $key => $tone)
                    @if (($severity[$key] ?? 0) > 0)
                        <div style="flex:{{ $severity[$key] }};background:var(--{{ $tone }}-500)"></div>
                    @endif
                @endforeach
            </div>
            <div class="sr-ov-legend">
                @foreach (['high' => 'danger', 'medium' => 'warning', 'low' => 'info', 'info' => 'gray'] as $key => $tone)
                    <span><i style="background:var(--{{ $tone }}-500)"></i>{{ \App\Audit\RuleCatalog::SEVERITIES[$key] }} <b>{{ $fmt($severity[$key] ?? 0) }}</b></span>
                @endforeach
            </div>
        </div>
    @endif
</div>

<style>
    .sr-ov { display: grid; gap: 16px; }
    .sr-ov-hero { display: flex; gap: 28px; align-items: flex-start; }
    .sr-ov-ring { position: relative; flex: none; width: 132px; height: 132px; }
    .sr-ov-ring-label { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1; }
    .sr-ov-ring-label strong { font-size: 34px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .sr-ov-ring-label span { font-size: 12px; opacity: .6; margin-top: 2px; }
    .sr-ov-main { flex: 1; min-width: 0; display: grid; gap: 8px; align-content: start; padding-top: 6px; }
    .sr-ov-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .sr-ov-title h2 { font-size: 22px; font-weight: 700; letter-spacing: -.01em; margin: 0; }
    .sr-ov-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
    .sr-ov-dot { width: 7px; height: 7px; border-radius: 50%; }
    .sr-ov-pulse { animation: sr-ov-pulse 1.4s ease-in-out infinite; }
    @keyframes sr-ov-pulse { 0%, 100% { opacity: 1 } 50% { opacity: .25 } }
    .sr-ov-site { font-size: 20px; font-weight: 700; letter-spacing: -.01em; color: inherit; text-decoration: none; width: fit-content; }
    .sr-ov-site:hover { color: var(--primary-600); }
    .sr-ov-meta { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px; opacity: .7; }
    .sr-ov-meta span + span::before { content: "·"; margin-right: 16px; opacity: .6; }
    .sr-ov-steps { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 8px; font-size: 12px; }
    .sr-ov-step { display: inline-flex; align-items: center; gap: 6px; opacity: .45; }
    .sr-ov-step span { width: 8px; height: 8px; border-radius: 50%; border: 2px solid currentColor; }
    .sr-ov-step.is-done { opacity: .8; }
    .sr-ov-step.is-done span { background: var(--success-500); border-color: var(--success-500); }
    .sr-ov-step.is-now { opacity: 1; font-weight: 600; color: var(--primary-600); }
    .sr-ov-step.is-now span { border-color: var(--primary-500); background: var(--primary-500); animation: sr-ov-pulse 1.4s ease-in-out infinite; }
    .sr-ov-bar { height: 8px; border-radius: 4px; background: color-mix(in oklab, var(--gray-500) 18%, transparent); overflow: hidden; margin-top: 4px; }
    .sr-ov-bar div { height: 100%; background: var(--primary-500); border-radius: 4px; transition: width .6s ease; }
    .sr-ov-note { font-size: 12px; opacity: .65; margin: 0; }
    .sr-ov-alert { font-size: 13px; padding: 8px 12px; border: 1px solid; border-radius: 8px; }
    .sr-ov-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
    .sr-ov-kpi { padding: 12px 14px; border-radius: 10px; background: color-mix(in oklab, var(--gray-500) 7%, transparent); display: grid; gap: 4px; }
    .sr-ov-kpi-label { font-size: 12px; opacity: .7; display: flex; align-items: center; gap: 6px; }
    .sr-ov-kpi-label i, .sr-ov-legend i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }
    .sr-ov-kpi strong { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
    .sr-ov-issues { display: grid; gap: 8px; }
    .sr-ov-issues-head { display: flex; align-items: baseline; gap: 8px; font-size: 14px; }
    .sr-ov-issues-head span { font-size: 12px; opacity: .6; }
    .sr-ov-stack { display: flex; gap: 2px; height: 10px; border-radius: 5px; overflow: hidden; background: color-mix(in oklab, var(--gray-500) 15%, transparent); }
    .sr-ov-legend { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 13px; }
    .sr-ov-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .sr-ov-legend b { font-variant-numeric: tabular-nums; }
    @media (max-width: 640px) { .sr-ov-hero { flex-direction: column; align-items: center; text-align: center; } .sr-ov-title, .sr-ov-meta, .sr-ov-steps { justify-content: center; } .sr-ov-site { margin: 0 auto; } }
</style>
