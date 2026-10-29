@php($colors = ['good' => 'text-success-600', 'needs' => 'text-warning-600', 'poor' => 'text-danger-600 font-semibold'])
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <tr class="text-left opacity-70"><th>Sayfa</th><th>Mobil puan</th><th>Gerçek kullanıcılar (28 gün)</th><th>Laboratuvar</th></tr>
        @foreach ($getRecord()->pagespeed as $row)
            <tr class="align-top">
                <td class="break-all pe-3">{{ $row['url'] }}</td>
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
<p class="text-xs opacity-70 mt-2">"İyi" sınırları: LCP ≤ 2,5 sn, INP ≤ 200 ms, CLS ≤ 0,1 (75. yüzdelik). Google sıralamada gerçek kullanıcı verisini kullanır.</p>
