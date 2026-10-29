@php
    $color = fn (?int $s) => $s === null ? '#6b7280' : ($s >= 80 ? '#15803d' : ($s >= 50 ? '#b45309' : '#b91c1c'));
    $sevColor = ['high' => '#b91c1c', 'medium' => '#b45309', 'low' => '#1d4ed8', 'info' => '#6b7280'];
    // Embedded so the PDF renderer, which never fetches remote files, shows it too.
    $logoFile = public_path('images/logo-mark.svg');
    $logo = is_file($logoFile) ? 'data:image/svg+xml;base64,'.base64_encode(file_get_contents($logoFile)) : null;
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>SEO Raporu · {{ $report['project'] }}</title>
    <style>
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #1f2937; margin: 0; padding: {{ $forPdf ? '0' : '24px' }}; background: {{ $forPdf ? '#fff' : '#f3f4f6' }}; }
        .page { max-width: 820px; margin: 0 auto; background: #fff; padding: {{ $forPdf ? '0' : '32px' }}; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 26px 0 8px; border-bottom: 2px solid #ea580c; padding-bottom: 4px; }
        .muted { color: #6b7280; }
        .meta td { padding: 2px 16px 2px 0; }
        .score { font-size: 44px; font-weight: bold; margin: 0; }
        table.grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.grid td, table.grid th { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        table.grid th { background: #f9fafb; }
        .num { font-size: 18px; font-weight: bold; }
        .finding { border: 1px solid #e5e7eb; border-left: 4px solid #6b7280; margin: 10px 0; padding: 8px 12px; page-break-inside: avoid; }
        .badge { display: inline-block; padding: 1px 7px; border-radius: 9px; color: #fff; font-size: 10px; }
        .urls { word-break: break-all; font-size: 10px; color: #4b5563; margin: 4px 0 0; padding-left: 16px; }
        .footer { margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 10px; color: #6b7280; }
        a { color: #c2410c; }
        .actions { margin-bottom: 14px; }
        .brandbar { border-bottom: 1px solid #e5e7eb; padding-bottom: 10px; margin-bottom: 18px; }
        .brandbar td { vertical-align: middle; }
        .wordmark { font-size: 17px; font-weight: bold; color: #1f2937; }
        @media print { body { background: #fff; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
<div class="page">
    @unless ($forPdf)
        <div class="actions"><a href="{{ $pdfUrl ?? url()->current().'/pdf' }}">PDF olarak indir</a></div>
    @endunless

    <table class="brandbar" style="width:100%"><tr>
        <td style="width:40px">@if ($logo)<img src="{{ $logo }}" width="34" height="34" alt="">@endif</td>
        <td><span class="wordmark">Screaming<span style="color:#ea580c">Rabbit</span></span></td>
        <td style="text-align:right" class="muted">Bir <a href="{{ $report['brand']['url'] }}">{{ $report['brand']['name'] }}</a> ürünü</td>
    </tr></table>

    <h1>SEO Teknik Denetim Raporu</h1>
    <table class="meta">
        <tr><td class="muted">Müşteri</td><td>{{ $report['client'] }}</td></tr>
        <tr><td class="muted">Site</td><td>{{ $report['site'] }}</td></tr>
        <tr><td class="muted">Tarih</td><td>{{ $report['date'] }}</td></tr>
    </table>

    <h2>Genel sağlık</h2>
    <table style="width:100%"><tr>
        <td style="width:200px">
            <p class="score" style="color: {{ $color($report['score']) }}">{{ $report['score'] ?? '-' }}<span style="font-size:16px" class="muted"> / 100</span></p>
            <div class="muted">Sağlık skoru</div>
        </td>
        <td>
            <table class="grid">
                <tr><th>Taranan URL</th><th>200</th><th>3xx</th><th>4xx</th><th>5xx</th></tr>
                <tr>
                    <td class="num">{{ $report['crawled'] }}</td><td class="num">{{ $report['ok'] }}</td>
                    <td class="num">{{ $report['redirects'] }}</td><td class="num">{{ $report['client_errors'] }}</td><td class="num">{{ $report['server_errors'] }}</td>
                </tr>
            </table>
        </td>
    </tr></table>

    <table class="grid">
        <tr>@foreach ($report['by_severity'] as $row)<th>Bulgu: {{ $row['label'] }}</th>@endforeach</tr>
        <tr>@foreach ($report['by_severity'] as $row)<td class="num">{{ $row['count'] }}</td>@endforeach</tr>
    </table>

    <h3 style="font-size:13px;margin:16px 0 4px">Sağlık skoru nasıl hesaplanır?</h3>
    <p style="margin:0" class="muted">{{ \App\Audit\HealthScore::EXPLANATION }}</p>
    @if (! empty($report['deductions']))
        <table class="grid">
            <tr><th>Puanı en çok düşüren sorunlar</th><th>Önem</th><th>Etkilenen sayfa</th><th>Düşülen puan</th></tr>
            @foreach ($report['deductions'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td><span class="badge" style="background: {{ $sevColor[$row['severity']] ?? '#6b7280' }}">{{ $row['severity_label'] }}</span></td>
                    <td>{{ $row['pages'] }}</td>
                    <td>-{{ number_format($row['points'], 1, ',', '.') }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($report['trend'] ?? []) >= 2)
        <div style="margin-top: 14px" class="muted">Sağlık skoru trendi (son taramalar)</div>
        <x-score-trend :points="$report['trend']" />
    @endif

    @if (! empty($report['tasks']))
        <h2>Çalışma planı</h2>
        @foreach (['done' => 'Tamamlanan çalışmalar', 'doing' => 'Devam eden çalışmalar', 'open' => 'Planlanan çalışmalar'] as $group => $heading)
            @if (! empty($report['tasks'][$group]))
                <h3 style="margin:10px 0 4px;font-size:14px">{{ $heading }}</h3>
                <table class="grid">
                    @foreach ($report['tasks'][$group] as $task)
                        <tr>
                            <td style="width:24px;text-align:center;{{ $group === 'done' ? 'color:#15803d' : 'color:#6b7280' }}">{{ $group === 'done' ? '✓' : ($group === 'doing' ? '…' : '○') }}</td>
                            <td><strong>{{ $task['title'] }}</strong><br><span class="muted">{{ $task['why'] }}</span></td>
                            @if ($group === 'done')
                                <td style="width:140px" class="muted">{{ $task['date'] }}{{ $task['verified'] ? ' · taramayla doğrulandı' : '' }}@if ($task['impact'])<br><span style="color:#111827">{{ $task['impact'] }}</span>@endif</td>
                            @endif
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
    @endif

    @php($stateStyle = ['ok' => 'color:#15803d', 'warn' => 'color:#b45309;font-weight:bold', 'bad' => 'color:#b91c1c;font-weight:bold'])
    @php($stateMark = ['ok' => '✓', 'warn' => '!', 'bad' => '✗'])
    @if (! empty($report['crawlability']))
        <h2>Botlar tarafından taranabilirlik</h2>
        <p style="margin:4px 0">Google ve diğer botların siteye ulaşıp sayfaları dizine ekleyebilmesi için gereken temel koşullar.</p>
        <table class="grid">
            @foreach ($report['crawlability'] as $line)
                <tr>
                    <td style="width:24px;text-align:center;{{ $stateStyle[$line['state']] }}">{{ $stateMark[$line['state']] }}</td>
                    <td>{{ $line['label'] }}</td>
                    <td style="{{ $stateStyle[$line['state']] }}">{{ $line['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @if (! empty($report['bots']))
        <h3 style="font-size:13px;margin:14px 0 0">Bot bazında erişim</h3>
        <table class="grid">
            <tr><th>Bot</th><th>Tür</th><th>robots.txt</th><th>Erişim testi</th></tr>
            @foreach ($report['bots'] as $bot)
                <tr>
                    <td>{{ $bot['name'] }}</td>
                    <td>{{ $bot['kind'] }}</td>
                    <td style="{{ $bot['robots'] === 'Engelli' ? 'color:#b91c1c;font-weight:bold' : '' }}">{{ $bot['robots'] }}</td>
                    <td style="{{ str_starts_with($bot['fetch'], 'Reddedildi') ? 'color:#b45309;font-weight:bold' : '' }}">{{ $bot['fetch'] }}</td>
                </tr>
            @endforeach
        </table>
        <p style="font-size:11px;color:#6b7280">Erişim testi ana sayfayı her botun kimliğiyle bir kez ister. "Reddedildi", güvenlik duvarının bu kimliği engellediğini gösterir; gerçek botlar IP doğrulamasıyla geçebilir.</p>
    @endif

    @if (! empty($report['ai']))
        <h2>AI arama görünürlüğü</h2>
        <p style="margin:4px 0">ChatGPT, Perplexity, Google AI Overviews gibi yapay zekâ aramalarında yer alabilmek için sitenin bu botlara açık ve makinelerin anlayacağı biçimde olması gerekir.</p>
        <table class="grid">
            @foreach ($report['ai'] as $line)
                <tr>
                    <td style="width:24px;text-align:center;{{ $stateStyle[$line['state']] }}">{{ $stateMark[$line['state']] }}</td>
                    <td>{{ $line['label'] }}</td>
                    <td style="{{ $stateStyle[$line['state']] }}">{{ $line['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($report['wordpress']))
        @php($wp = $report['wordpress'])
        <h2>WordPress</h2>
        <table class="grid">
            <tr><th>WordPress sürümü</th><th>Güncel sürüm</th><th>Eklenti</th><th>Tema</th></tr>
            <tr>
                <td class="num" style="{{ $wp['version'] && $wp['latest'] && version_compare($wp['version'], $wp['latest'], '<') ? 'color:#b91c1c' : '' }}">{{ $wp['version'] ?? 'Gizli' }}</td>
                <td class="num">{{ $wp['latest'] ?? '—' }}</td>
                <td class="num">{{ count($wp['plugins']) }}</td>
                <td>{{ implode(', ', $wp['themes']) ?: '—' }}</td>
            </tr>
        </table>
        @if ($wp['plugins'])
            <table class="grid">
                <tr><th>Dışarıdan görünen eklenti</th><th>Sitedeki sürüm</th><th>Güncel sürüm</th></tr>
                @foreach ($wp['plugins'] as $plugin)
                    @php($old = $plugin['seen'] && $plugin['latest'] && version_compare($plugin['seen'], $plugin['latest'], '<'))
                    <tr>
                        <td>{{ $plugin['slug'] }}</td>
                        <td style="{{ $old ? 'color:#b91c1c;font-weight:bold' : '' }}">{{ $plugin['seen'] ?? '—' }}</td>
                        <td>{{ $plugin['latest'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
        <h3 style="font-size:13px;margin:14px 0 0">Güvenlik ve bakım kontrolleri</h3>
        <table class="grid">
            @foreach ($wp['checks'] as $check)
                <tr>
                    <td style="width:24px;text-align:center;{{ $check['passed'] ? $stateStyle['ok'] : $stateStyle['bad'] }}">{{ $check['passed'] ? '✓' : '✗' }}</td>
                    <td>{{ $check['title'] }}</td>
                    <td style="{{ $check['passed'] ? $stateStyle['ok'] : $stateStyle['bad'] }}">{{ $check['passed'] ? 'Sorun yok' : 'Sorun var, ayrıntı aşağıda' }}</td>
                </tr>
            @endforeach
        </table>
        <p style="font-size:11px;color:#6b7280">Kontroller siteye dışarıdan, bir ziyaretçinin görebileceği kadarıyla yapılır; yönetim paneline giriş yapılmaz.</p>
    @endif

    <h2>Bulgular ve çözüm önerileri</h2>
    @forelse ($report['findings'] as $finding)
        <div class="finding" style="border-left-color: {{ $sevColor[$finding['severity']] ?? '#6b7280' }}">
            <strong>{{ $finding['title'] }}</strong>
            <span class="badge" style="background: {{ $sevColor[$finding['severity']] ?? '#6b7280' }}">{{ $finding['severity_label'] }}</span>
            <span class="muted"> · {{ $finding['pages'] }} sayfa · {{ $finding['category'] }}</span>
            <p style="margin: 6px 0 2px">{{ $finding['description'] }}</p>
            <p style="margin: 2px 0"><strong>Ne yapılmalı:</strong> {{ $finding['fix'] }}</p>
            @if ($finding['samples'])
                <ul class="urls">@foreach ($finding['samples'] as $url)<li>{{ $url }}</li>@endforeach</ul>
            @endif
        </div>
    @empty
        <p>Bu taramada herhangi bir bulgu çıkmadı.</p>
    @endforelse

    <div class="footer">
        @if ($logo)<img src="{{ $logo }}" width="14" height="14" alt="" style="vertical-align:middle">@endif
        Bu rapor {{ $report['brand']['product'] }} ile hazırlanmıştır ·
        <a href="{{ $report['brand']['url'] }}">Bir {{ $report['brand']['name'] }} ürünü</a> ·
        <a href="{{ $report['brand']['url'] }}">{{ preg_replace('#^https?://#', '', $report['brand']['url']) }}</a>
    </div>
</div>
</body>
</html>
