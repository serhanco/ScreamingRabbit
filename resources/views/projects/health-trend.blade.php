<x-filament-widgets::widget>
    <x-filament::section heading="Sağlık skoru trendi" description="Son taramaların sağlık skoru, en eskisi solda. Skor 100'den başlar; her sorun türü önemine (yüksek 12, orta 6, düşük 2 puana kadar) ve etkilediği sayfa oranına göre puan düşürür. Ayrıntısı tarama sayfasında.">
        @if ($points->isEmpty())
            <div class="text-sm opacity-70">Henüz tamamlanmış tarama yok. İlk taramadan sonra burada görünecek.</div>
        @else
            @if ($change !== null)
                <div class="text-sm mb-2">
                    {{ $points->count() }} taramada
                    <span class="font-semibold {{ $change > 0 ? 'text-success-600' : ($change < 0 ? 'text-danger-600' : '') }}">{{ $change > 0 ? '+' : '' }}{{ $change }} puan</span>
                </div>
            @endif
            <div class="overflow-x-auto"><x-score-trend :points="$points" /></div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
