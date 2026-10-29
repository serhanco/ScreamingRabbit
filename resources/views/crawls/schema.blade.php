@php($rows = \App\Audit\SchemaInventory::for($getRecord()))
@if ($rows === [])
    <div class="text-sm opacity-70">Taranan sayfalarda yapısal veri (JSON-LD veya Microdata) bulunamadı.</div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70"><th>Tür</th><th>Sayfa</th><th>Zorunlu alan eksik</th><th>Önerilen alan eksik</th><th>Birden fazla kez</th></tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="pe-3">{{ $row['type'] }}</td>
                    <td>{{ number_format($row['pages'], 0, ',', '.') }}</td>
                    <td @class(['text-danger-600 font-semibold' => $row['required'] > 0])>{{ $row['required'] ?: '—' }}</td>
                    <td @class(['text-warning-600' => $row['recommended'] > 0])>{{ $row['recommended'] ?: '—' }}</td>
                    <td>{{ $row['duplicates'] ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <p class="text-xs opacity-70 mt-2">Google zengin sonuç kurallarına göre. Eksik alanların ayrıntısı "Sorunlar" sekmesinde.</p>
@endif
