@php($crawl = $getRecord())
@php($summary = \App\Performance\SpeedScan::summary($crawl))
<div @if ($crawl->isActive() || $summary['pending'] > 0) wire:poll.30s @endif class="space-y-3 text-sm">
    @if ($crawl->isActive())
        <p>Önce site taranıyor; aynı alan adındaki her sayfa bir kez listeleniyor. Tarama bitince hız ölçümleri kendiliğinden başlar.</p>
    @elseif ($crawl->status !== 'completed')
        <p class="opacity-70">Tarama tamamlanmadığı için ölçüm yapılmadı.</p>
    @elseif ($summary['total'] === 0)
        <p class="opacity-70">Ölçülecek HTML sayfası bulunamadı.</p>
    @else
        <div>
            <div class="flex justify-between mb-1">
                <span>{{ $summary['pending'] > 0 ? 'Ölçülüyor' : 'Ölçümler tamamlandı' }}: {{ number_format($summary['total'] - $summary['pending'], 0, ',', '.') }} / {{ number_format($summary['total'], 0, ',', '.') }}</span>
                @if ($summary['pending'] > 0)
                    <span class="opacity-70">Kalan süre yaklaşık {{ $summary['minutes_left'] >= 60 ? floor($summary['minutes_left'] / 60).' sa '.($summary['minutes_left'] % 60).' dk' : $summary['minutes_left'].' dk' }}</span>
                @endif
            </div>
            <div class="h-2 rounded bg-gray-200 dark:bg-gray-700 overflow-hidden"><div class="h-2 bg-primary-500" style="width: {{ $summary['percent'] }}%"></div></div>
        </div>
        @if ($summary['paused_until'])
            <p class="text-warning-600">Google kotası doldu; ölçümler {{ \Illuminate\Support\Carbon::parse($summary['paused_until'])->timezone(config('screamingrabbit.alerts.timezone'))->format('H:i') }} civarı kendiliğinden devam eder.
                @if (blank(\App\Models\Setting::get('pagespeed_api_key'))) Google ayarlarına PageSpeed API anahtarı ekleyin; kota 25.000/gün olur. @endif</p>
        @endif
        @if ($summary['failed'] > 0)
            <p class="opacity-70">{{ $summary['failed'] }} ölçüm iki denemede de alınamadı (sayfa çok yavaş ya da Google erişemedi).</p>
        @endif
        @if ($summary['strategies'] !== [])
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tr class="text-left opacity-70"><th>Cihaz</th><th>Ölçülen sayfa</th><th>Ortalama puan</th><th>CWV iyi</th><th>İyileştirilmeli</th><th>Kötü</th><th>Puanı 50 altı</th></tr>
                    @foreach ($summary['strategies'] as $strategy => $row)
                        <tr>
                            <td class="pe-3">{{ \App\Models\SpeedResult::STRATEGIES[$strategy] }}</td>
                            <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                            <td @class(['text-success-600' => $row['average'] >= 90, 'text-warning-600' => $row['average'] < 90 && $row['average'] >= 50, 'text-danger-600 font-semibold' => $row['average'] !== null && $row['average'] < 50])>{{ $row['average'] ?? '—' }}</td>
                            <td class="text-success-600">{{ $row['good'] }}</td>
                            <td class="text-warning-600">{{ $row['needs'] }}</td>
                            <td @class(['text-danger-600 font-semibold' => $row['poor'] > 0])>{{ $row['poor'] }}</td>
                            <td @class(['text-danger-600 font-semibold' => $row['slow'] > 0])>{{ $row['slow'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <p class="text-xs opacity-70">Core Web Vitals: gerçek kullanıcı verisi (Chrome, 28 gün) varsa o, yoksa laboratuvar ölçümü (LCP, CLS, TBT) kullanılır. "İyi" sınırları: LCP ≤ 2,5 sn, INP ≤ 200 ms, CLS ≤ 0,1.</p>
        @endif
    @endif
</div>
