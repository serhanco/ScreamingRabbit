<x-filament-panels::page>
    @php
        $pct = fn ($v) => '%'.number_format($v * 100, 1, ',', '.');
        $pos = fn ($v) => number_format((float) $v, 1, ',', '.');
        $sections = [
            'low_ctr' => ['Yüksek gösterim, düşük tıklama oranı', 'İlk sayfada ama bu pozisyonda beklenen tıklamanın yarısını bile almıyor. Title ve meta description yeniden yazılmalı.'],
            'opportunities' => ['Fırsat sorguları (pozisyon 8–20)', 'İlk sayfanın sonunda veya hemen dışında; içeriği güçlendirmek ve iç link vermek hızlı kazanç getirir.'],
            'cannibalization' => ['Aynı sorguda yarışan sayfalar', 'Sitenin birden fazla sayfası aynı sorgu için gösteriliyor; birleştirme veya canonical ile tek sayfaya toplanmalı.'],
            'declining' => ['Düşen sayfalar', 'Son 28 günde, önceki 28 güne göre tıklamaların en az %30\'unu kaybetti.'],
            'orphans' => ['Yetim sayfalar', 'Google\'da gösteriliyor ama taramada hiçbir sayfadan iç link almıyor.'],
            'zombies' => ['Zombi sayfalar', 'İndekslenebilir ama 90 gündür tek bir gösterim almadı; birleştirme, iyileştirme veya noindex düşünülmeli.'],
            'index' => ['İndeks sorunları (URL denetimi)', 'Google bu sayfayı dizine eklememiş ya da sayfanın belirttiğinden farklı bir canonical seçmiş.'],
        ];
    @endphp

    @if (! $project->gsc_property)
        <x-filament::section>Bu projeye henüz Search Console mülkü seçilmedi. Proje ayarlarından seç; veriler birkaç dakika içinde gelmeye başlar.</x-filament::section>
    @endif

    @foreach ($sections as $key => [$title, $why])
        <x-filament::section :heading="$title.' ('.$insights[$key]->count().')'" :description="$why" collapsible :collapsed="$insights[$key]->isEmpty()">
            @if ($insights[$key]->isEmpty())
                <div class="text-sm opacity-70">Şu an bu başlıkta bir şey yok.</div>
            @else
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    @switch($key)
                        @case('low_ctr')
                            <tr class="text-left opacity-70"><th>Sayfa</th><th>Gösterim</th><th>Tıklama</th><th>CTR</th><th>Beklenen</th><th>Pozisyon</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="break-all pe-3">{{ $r->page }}</td><td>{{ $r->gsc_impressions }}</td><td>{{ $r->gsc_clicks }}</td><td>{{ $pct($r->ctr) }}</td><td>{{ $pct($r->expected) }}</td><td>{{ $pos($r->gsc_position) }}</td></tr>@endforeach
                            @break
                        @case('opportunities')
                            <tr class="text-left opacity-70"><th>Sorgu</th><th>Sayfa</th><th>Gösterim</th><th>Tıklama</th><th>Pozisyon</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="pe-3">{{ $r->query }}</td><td class="break-all pe-3">{{ $r->page }}</td><td>{{ $r->impressions }}</td><td>{{ $r->clicks }}</td><td>{{ $pos($r->position) }}</td></tr>@endforeach
                            @break
                        @case('cannibalization')
                            <tr class="text-left opacity-70"><th>Sorgu</th><th>Sayfalar (gösterim · pozisyon)</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="pe-3 align-top">{{ $r->query }}</td><td>@foreach ($r->urls as $u)<div class="break-all">{{ $u->page }} <span class="opacity-70">({{ $u->impressions }} · {{ $pos($u->position) }})</span></div>@endforeach</td></tr>@endforeach
                            @break
                        @case('declining')
                            <tr class="text-left opacity-70"><th>Sayfa</th><th>Önceki 28 gün</th><th>Son 28 gün</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="break-all pe-3">{{ $r->page }}</td><td>{{ $r->before_clicks }}</td><td>{{ $r->now_clicks }}</td></tr>@endforeach
                            @break
                        @case('orphans')
                            <tr class="text-left opacity-70"><th>Sayfa</th><th>Gösterim (28 g)</th><th>Tıklama</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="break-all pe-3">{{ $r->page }}</td><td>{{ $r->gsc_impressions }}</td><td>{{ $r->gsc_clicks }}</td></tr>@endforeach
                            @break
                        @case('zombies')
                            <tr class="text-left opacity-70"><th>Sayfa</th><th>İç link</th><th>Kelime</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="break-all pe-3">{{ $r->url }}</td><td>{{ $r->inlinks }}</td><td>{{ $r->word_count }}</td></tr>@endforeach
                            @break
                        @case('index')
                            <tr class="text-left opacity-70"><th>Sayfa</th><th>Google durumu</th><th>Google'ın canonical'ı</th></tr>
                            @foreach ($insights[$key] as $r)<tr><td class="break-all pe-3">{{ $r->url }}</td><td class="pe-3">{{ $r->coverage_state }}</td><td class="break-all">{{ $r->google_canonical ?? '—' }}</td></tr>@endforeach
                            @break
                    @endswitch
                </table>
                </div>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
