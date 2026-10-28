<x-filament-widgets::widget>
    <x-filament::section heading="Sağlık skoru trendi" description="Son taramaların sağlık skoru, en eskisi solda.">
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
