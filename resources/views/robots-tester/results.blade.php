<x-filament::section heading="Sonuç">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <tr class="text-left opacity-70">
                <th class="pe-3">Adres</th>
                @foreach ($this->testedBots as $name)
                    <th class="pe-3">{{ \Illuminate\Support\Str::before($name, ' (') }}</th>
                @endforeach
            </tr>
            @foreach ($this->results as $row)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="pe-3 py-1 break-all">{{ $row['url'] }}</td>
                    @foreach ($row['bots'] as $verdict)
                        <td class="pe-3 py-1">
                            <span @class(['font-semibold', 'text-success-600' => $verdict['allowed'], 'text-danger-600' => ! $verdict['allowed']])>{{ $verdict['allowed'] ? 'İzinli' : 'Engelli' }}</span>
                            @if ($verdict['rule'])
                                <div class="text-xs opacity-70 font-mono">{{ $verdict['rule'] }}</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
    <p class="text-xs opacity-70 mt-2">Kural: en uzun eşleşen satır geçerlidir, eşitlikte Allow kazanır (Google ve RFC 9309). Bir bota özel grup varsa yalnızca o grup okunur, yoksa "User-agent: *" grubu.</p>
</x-filament::section>
