@php($colors = ['good' => 'text-success-600', 'needs' => 'text-warning-600', 'poor' => 'text-danger-600 font-semibold'])
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <tr class="text-left opacity-70"><th>Sayfa</th><th>Tür</th><th>Mobil puan</th><th>Gerçek kullanıcılar (28 gün)</th><th>Laboratuvar</th></tr>
        @foreach ($getRecord()->pagespeed as $row)
            <tr class="align-top">
                <td class="break-all pe-3">{{ $row['url'] }}</td>
                <td class="pe-3 whitespace-nowrap">{{ \App\Segments\Segments::TYPES[$row['type'] ?? ''] ?? '—' }}</td>
                @if ($row['state'] === 'pending')
                    <td colspan="3" class="opacity-70">Sırada</td>
                @elseif ($row['state'] === 'failed')
                    <td colspan="3" class="opacity-70">Ölçülemedi: {{ $row['error'] ?? '' }}</td>
                @else
                    <td @class([$colors[$row['score'] >= 90 ? 'good' : ($row['score'] >= \App\Performance\PageSpeed::POOR_SCORE ? 'needs' : 'poor')] => $row['score'] !== null])>{{ $row['score'] ?? '—' }}</td>
                    <td>
                        @if ($row['field'])
                            @php($ratings = \App\Performance\PageSpeed::ratings($row['field']))
                            @foreach ($ratings as $metric => $rating)
                                <span class="{{ $colors[$rating] }} me-2">{{ \App\Performance\PageSpeed::label($metric, $row['field'][$metric]) }}</span>
                            @endforeach
                        @else
                            <span class="opacity-70">Yeterli ziyaretçi verisi yok</span>
                        @endif
                    </td>
                    <td class="opacity-80">{{ collect($row['lab'] ?? [])->filter(fn ($v) => $v !== null)->map(fn ($v, $m) => \App\Performance\PageSpeed::label($m, $v))->implode(' · ') }}</td>
                @endif
            </tr>
        @endforeach
    </table>
</div>
@php($trend = \App\Performance\PageSpeedTrend::upTo($getRecord()))
@if (count($trend) >= 2)
    <h3 class="font-semibold text-sm mt-4 mb-1">Son taramalarda hız</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70"><th>Tarama</th><th>Ortalama mobil puan</th><th>Ana sayfa puanı</th><th>Ana sayfa (gerçek kullanıcılar)</th><th>Core Web Vitals kötü sayfa</th></tr>
            @foreach ($trend as $i => $point)
                @php($previous = $trend[$i - 1]['average'] ?? null)
                <tr>
                    <td class="pe-3">{{ $point['date'] }}</td>
                    <td>{{ $point['average'] }}
                        @if ($previous !== null && $point['average'] !== $previous)
                            <span @class(['text-success-600' => $point['average'] > $previous, 'text-danger-600' => $point['average'] < $previous])>({{ $point['average'] > $previous ? '+' : '' }}{{ $point['average'] - $previous }})</span>
                        @endif
                    </td>
                    <td>{{ $point['home'] ?? '—' }}</td>
                    <td>{{ $point['field'] ? collect(\App\Performance\PageSpeed::ratings($point['field']))->map(fn ($r, $m) => \App\Performance\PageSpeed::label($m, $point['field'][$m]))->implode(' · ') : '—' }}</td>
                    <td @class(['text-danger-600 font-semibold' => $point['poor'] > 0])>{{ $point['poor'] ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
<p class="text-xs opacity-70 mt-2">"İyi" sınırları: LCP ≤ 2,5 sn, INP ≤ 200 ms, CLS ≤ 0,1 (75. yüzdelik). Google sıralamada gerçek kullanıcı verisini kullanır.</p>
