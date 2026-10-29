@php($rows = \App\Crawler\FolderStats::for($getRecord()))
@if ($rows === [])
    <div class="text-sm opacity-70">Taranan sayfa yok.</div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70"><th>Dizin</th><th>Sayfa</th><th>İndekslenebilir</th><th>Hatalı (4xx/5xx)</th><th>Ort. derinlik</th><th>En derin</th><th>Ort. link puanı</th><th>Bulgu</th></tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="pe-3 break-all">{{ $row['folder'] }}</td>
                    <td>{{ number_format($row['pages'], 0, ',', '.') }}</td>
                    <td>{{ number_format($row['indexable'], 0, ',', '.') }}</td>
                    <td @class(['text-danger-600 font-semibold' => $row['errors'] > 0])>{{ $row['errors'] ?: '—' }}</td>
                    <td @class(['text-warning-600 font-semibold' => $row['avg_depth'] > 3])>{{ number_format($row['avg_depth'], 1, ',', '.') }}</td>
                    <td @class(['text-warning-600' => $row['max_depth'] > 3])>{{ $row['max_depth'] }}</td>
                    <td>{{ $row['avg_link_score'] !== null ? number_format($row['avg_link_score'], 1, ',', '.') : '—' }}</td>
                    <td>{{ number_format($row['issues'], 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </table>
    </div>
    <p class="text-xs opacity-70 mt-2">Derinlik: ana sayfadan kaç tıkla ulaşıldığı; 3'ün üstü turuncu. En kalabalık {{ \App\Crawler\FolderStats::MAX_ROWS }} dizin gösterilir.</p>
@endif
