@php
    $record = $getRecord();
    $order = array_flip(array_merge(\App\Models\RedirectCheck::PROBLEMS, ['same', 'ok']));
    $rows = collect($record->rows)->sortBy(fn ($row) => $row['state'] === 'done' ? ($order[$row['verdict']] ?? 99) : 100);
    $problem = fn ($row) => in_array($row['verdict'] ?? null, \App\Models\RedirectCheck::PROBLEMS, true);
@endphp
<div @if ($record->status === 'pending') wire:poll.10s @endif>
    @if ($summary = $record->summary())
        <div class="flex flex-wrap gap-3 text-sm mb-3">
            @foreach ($summary as $verdict => $count)
                <span @class(['font-semibold text-danger-600' => in_array($verdict, \App\Models\RedirectCheck::PROBLEMS, true)])>{{ \App\Models\RedirectCheck::VERDICTS[$verdict] ?? $verdict }}: {{ $count }}</span>
            @endforeach
        </div>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70"><th>Eski adres</th><th>Sonuç</th><th>Vardığı adres</th><th>Yol</th></tr>
            @foreach ($rows as $row)
                <tr class="align-top border-t border-gray-100 dark:border-white/5">
                    <td class="break-all pe-3 py-1">{{ $row['url'] }}@if ($row['expected'])<div class="text-xs opacity-60">Beklenen: {{ $row['expected'] }}</div>@endif</td>
                    @if ($row['state'] !== 'done')
                        <td colspan="3" class="opacity-60 py-1">Sırada</td>
                    @else
                        <td @class(['py-1 pe-3', 'text-danger-600 font-semibold' => $problem($row), 'text-success-600' => $row['verdict'] === 'ok'])>{{ \App\Models\RedirectCheck::VERDICTS[$row['verdict']] ?? $row['verdict'] }}</td>
                        <td class="break-all pe-3 py-1">{{ $row['final_url'] }} @if ($row['final_status'])<span class="opacity-60">({{ $row['final_status'] }})</span>@endif</td>
                        <td class="py-1 text-xs opacity-70">{{ collect($row['hops'])->pluck('status')->implode(' → ') ?: '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
</div>
