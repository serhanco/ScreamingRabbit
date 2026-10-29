@php($labels = ['contains' => 'geçen sayfa', 'not_contains' => 'geçmeyen sayfa', 'regex' => 'değer bulunan sayfa', 'xpath' => 'değer bulunan sayfa'])
<div class="space-y-4 text-sm">
    @foreach (\App\Crawler\CustomExtraction::summary($getRecord()) as $rule)
        <div>
            <div class="font-semibold">{{ $rule['name'] }} <span class="font-normal opacity-60">· {{ \App\Crawler\CustomExtraction::TYPES[$rule['type']] }}: <code>{{ $rule['value'] }}</code></span></div>
            <div>{{ number_format($rule['pages'], 0, ',', '.') }} {{ $labels[$rule['type']] }}</div>
            @if ($rule['examples'])
                <ul class="list-disc ps-5 break-all opacity-80">@foreach ($rule['examples'] as $example)<li>{{ $example }}</li>@endforeach</ul>
            @endif
        </div>
    @endforeach
</div>
