@php($r = $this->result)
<x-filament::section heading="Sonuç">
    <div class="grid grid-cols-3 gap-4 text-sm mb-4">
        <div><div class="opacity-70">Her iki sitede</div><div class="text-xl font-semibold">{{ number_format($r['common'], 0, ',', '.') }}</div></div>
        <div><div class="opacity-70">Yalnızca canlıda (staging'de yok)</div><div @class(['text-xl font-semibold', 'text-danger-600' => $r['only_live']['count'] > 0])>{{ number_format($r['only_live']['count'], 0, ',', '.') }}</div></div>
        <div><div class="opacity-70">Yalnızca staging'de</div><div class="text-xl font-semibold">{{ number_format($r['only_staging']['count'], 0, ',', '.') }}</div></div>
    </div>

    @if ($r['only_live']['count'] > 0)
        <h3 class="font-semibold text-sm mt-2">Staging'de olmayan canlı sayfalar</h3>
        <p class="text-xs opacity-70 mb-1">Yayına alınınca bu adresler 404 verir; yönlendirme planlayın.</p>
        <ul class="text-sm list-disc ps-5 break-all">@foreach ($r['only_live']['sample'] as $path)<li>{{ $path }}</li>@endforeach</ul>
    @endif

    @foreach ($r['fields'] as $field => $diff)
        <h3 class="font-semibold text-sm mt-4">{{ \App\Crawler\SiteComparison::FIELDS[$field] }} farklı: {{ number_format($diff['count'], 0, ',', '.') }} sayfa</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tr class="text-left opacity-70"><th class="pe-3">Yol</th><th class="pe-3">Canlı</th><th>Staging</th></tr>
                @foreach ($diff['sample'] as $row)
                    <tr class="border-t border-gray-200 dark:border-white/10 align-top"><td class="pe-3 break-all">{{ $row['path'] }}</td><td class="pe-3">{{ $row['live'] }}</td><td>{{ $row['staging'] }}</td></tr>
                @endforeach
            </table>
        </div>
    @endforeach

    @if ($r['fields'] === [] && $r['only_live']['count'] === 0)
        <p class="text-sm">Ortak sayfalarda fark yok.</p>
    @endif
    <p class="text-xs opacity-70 mt-3">Her listede en fazla {{ \App\Crawler\SiteComparison::SAMPLE }} örnek gösterilir.</p>
</x-filament::section>
