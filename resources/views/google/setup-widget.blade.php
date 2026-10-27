<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-rocket-launch" heading="Başlarken: Google Search Console bağlantısı" description="Bir kereye mahsus kurulum. Bağlantı kurulunca bu kutu kendiliğinden kaybolur.">
        @include('google.setup-guide', ['withForm' => true])
    </x-filament::section>
</x-filament-widgets::widget>
