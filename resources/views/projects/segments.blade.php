<x-filament-panels::page>
    @php
        $n = fn ($v) => number_format($v, 0, ',', '.');
        $tint = fn ($h) => $h >= 80 ? 'text-success-600' : ($h >= 50 ? 'text-warning-600' : 'text-danger-600');
        $tables = [
            'folders' => ['Bölümler (ilk URL klasörü)', 'Sağlık: yüksek veya orta önemde sorunu olmayan sayfaların oranı. En kötü bölümden başla.'],
            'types' => ['WordPress içerik türü', 'Yazı, sayfa, ürün, arşiv gibi türlere göre. Arşivler sorunlu ve trafiksizse noindex düşün.'],
            'categories' => ['WordPress kategorisi', 'Yazı ve ürünlerin ilk kategorisine göre. Zayıf kategoriler içerik veya iç link ister.'],
            'depths' => ['Tıklama derinliği', 'Ana sayfadan kaç tıkta ulaşılıyor. Derindeki sayfalar az tıklama alıyorsa iç link vererek yukarı çek.'],
            'links' => ['İç link sayısı × pozisyon', 'Sayfaya gelen iç link sayısına göre. Az link alan gruplar Google\'da daha gerideyse bu sayfalara iç link ver.'],
        ];
        $first = ['folders' => 'Bölüm', 'types' => 'Tür', 'categories' => 'Kategori', 'depths' => 'Derinlik', 'links' => 'Gelen iç link'];
    @endphp

    @if (! $crawl)
        <x-filament::section>Bu proje için tamamlanmış bir tarama yok. Önce bir tarama başlat.</x-filament::section>
    @else
        @foreach ($tables as $key => [$title, $why])
            @continue(in_array($key, ['types', 'categories'], true) && $$key->isEmpty())
            <x-filament::section :heading="$title" :description="$why">
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tr class="text-left opacity-70">
                        <th>{{ $first[$key] }}</th><th>Sayfa</th><th>Sağlık</th><th>Önemli sorunlu</th><th>Ort. iç link</th>
                        @if ($gsc)<th>Tıklama (28 gün)</th><th>Gösterim</th><th>Sayfa başı tıklama</th><th>Ort. pozisyon</th>@endif
                    </tr>
                    @foreach ($$key as $r)
                        <tr>
                            <td class="pe-3">{{ $key === 'depths' ? ($r['segment'] >= 5 ? '5+' : $r['segment']) : $r['segment'] }}</td>
                            <td>{{ $n($r['pages']) }}</td>
                            <td class="font-semibold {{ $tint($r['health']) }}">%{{ $r['health'] }}</td>
                            <td>{{ $n($r['high']) }}</td>
                            <td>{{ number_format($r['avg_inlinks'], 1, ',', '.') }}</td>
                            @if ($gsc)<td>{{ $n($r['clicks']) }}</td><td>{{ $n($r['impressions']) }}</td><td>{{ number_format($r['avg_clicks'], 1, ',', '.') }}</td><td>{{ $r['position'] === null ? '–' : number_format($r['position'], 1, ',', '.') }}</td>@endif
                        </tr>
                    @endforeach
                </table>
                </div>
            </x-filament::section>
        @endforeach
        <div class="text-xs opacity-70">Son tamamlanan taramaya göre; yalnızca 200 dönen HTML sayfaları sayılır.</div>
    @endif
</x-filament-panels::page>
