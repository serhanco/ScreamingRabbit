@php
    $rows = \App\Audit\HealthScore::deductions($getRecord())->take(10);
@endphp
@if ($rows->isEmpty())
    <div class="text-sm opacity-70">Puan düşüren sorun yok.</div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70"><th>Puanı en çok düşüren sorunlar</th><th>Önem</th><th>Etkilenen sayfa</th><th>Düşülen puan</th></tr>
            @foreach ($rows as $row)
                <tr>
                    <td class="pe-3">{{ $row['title'] }}</td>
                    <td>{{ \App\Audit\RuleCatalog::SEVERITIES[$row['severity']] ?? $row['severity'] }}</td>
                    <td>{{ number_format($row['pages'], 0, ',', '.') }}</td>
                    <td class="font-semibold">-{{ number_format($row['points'], 1, ',', '.') }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif
