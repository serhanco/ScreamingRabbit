@php
    $cmp = (new \App\Crawler\CrawlComparison($getRecord()))->data();
    $catalog = \App\Audit\RuleCatalog::all();
    $short = fn ($v) => \Illuminate\Support\Str::limit((string) $v === '' ? '—' : (string) $v, 70);
@endphp
@if ($cmp)
    <div style="font-size:14px">
        <p style="margin-bottom:8px">
            Önceki tarama: <strong>{{ $cmp['previous_date'] }}</strong>.
            Sağlık skoru <strong>{{ $cmp['score'][0] ?? '-' }} → {{ $cmp['score'][1] ?? '-' }}</strong>
            @if (is_int($cmp['score'][0]) && is_int($cmp['score'][1]) && $cmp['score'][1] !== $cmp['score'][0])
                ({{ $cmp['score'][1] > $cmp['score'][0] ? '+' : '' }}{{ $cmp['score'][1] - $cmp['score'][0] }})
            @endif
            · Yeni sayfa <strong>{{ $cmp['new_pages']['count'] }}</strong> · Kaybolan sayfa <strong>{{ $cmp['lost_pages']['count'] }}</strong>
            · Yeni sorun <strong>{{ $cmp['new_issues']->sum('pages') }}</strong> · Düzelen sorun <strong>{{ $cmp['fixed_issues']->sum('pages') }}</strong>
        </p>
        @if ($cmp['capped'])
            <p style="font-size:12px;opacity:.7;margin-bottom:8px">Taramalardan biri URL sınırına ulaştı; yeni/kaybolan sayfalar gerçek değişim yerine sınır yüzünden olabilir.</p>
        @endif

        @foreach ([['new_issues', 'Yeni çıkan sorunlar', 'danger'], ['fixed_issues', 'Düzelen sorunlar', 'success']] as [$key, $heading, $tone])
            @if ($cmp[$key]->isNotEmpty())
                <h4 style="margin:10px 0 4px;font-weight:600">{{ $heading }}</h4>
                <table class="w-full text-sm">
                    @foreach ($cmp[$key] as $row)
                        <tr><td class="pe-3">{{ $catalog[$row->rule]['title'] ?? $row->rule }}</td><td class="pe-3 opacity-70">{{ \App\Audit\RuleCatalog::SEVERITIES[$row->severity] ?? $row->severity }}</td><td>{{ $row->pages }} sayfa</td></tr>
                    @endforeach
                </table>
            @endif
        @endforeach

        @foreach ($cmp['changed'] as $change)
            <h4 style="margin:10px 0 4px;font-weight:600">{{ $change['label'] }} değişen sayfalar ({{ $change['count'] }})</h4>
            <table class="w-full text-sm">
                @foreach ($change['sample'] as $row)
                    <tr><td class="break-all pe-3">{{ $row->url }}</td><td class="pe-3 opacity-70">{{ $short($row->before_value) }}</td><td>→ {{ $short($row->after_value) }}</td></tr>
                @endforeach
            </table>
            @if ($change['count'] > count($change['sample']))<p style="font-size:12px;opacity:.7">İlk {{ count($change['sample']) }} gösteriliyor.</p>@endif
        @endforeach

        @foreach ([['new_pages', 'Yeni sayfalar'], ['lost_pages', 'Kaybolan sayfalar']] as [$key, $heading])
            @if ($cmp[$key]['count'] > 0)
                <h4 style="margin:10px 0 4px;font-weight:600">{{ $heading }} ({{ $cmp[$key]['count'] }})</h4>
                <table class="w-full text-sm">
                    @foreach ($cmp[$key]['sample'] as $row)
                        <tr><td class="break-all pe-3">{{ $row->url }}</td><td class="opacity-70">{{ $row->status_code }}</td></tr>
                    @endforeach
                </table>
                @if ($cmp[$key]['count'] > count($cmp[$key]['sample']))<p style="font-size:12px;opacity:.7">İlk {{ count($cmp[$key]['sample']) }} gösteriliyor.</p>@endif
            @endif
        @endforeach
    </div>
@endif
