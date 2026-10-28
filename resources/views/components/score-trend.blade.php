@props(['points'])
{{-- Health score per crawl as plain bars in a table, so the PDF renderer draws it the same as the browser. --}}
@php
    $barColor = fn (int $s) => $s >= 80 ? '#15803d' : ($s >= 50 ? '#b45309' : '#b91c1c');
@endphp
<table style="border-collapse: collapse; margin-top: 8px;">
    <tr>
        @foreach ($points as $point)
            <td style="vertical-align: bottom; text-align: center; padding: 0 4px; height: 92px; font-size: 10px;">
                <div style="font-weight: bold; color: {{ $barColor($point['score']) }}">{{ $point['score'] }}</div>
                <div style="width: 22px; margin: 2px auto 0; height: {{ max(2, (int) round($point['score'] * 0.7)) }}px; background: {{ $barColor($point['score']) }}; border-radius: 3px 3px 0 0;"></div>
            </td>
        @endforeach
    </tr>
    <tr>
        @foreach ($points as $point)
            <td style="text-align: center; padding: 3px 4px 0; font-size: 10px; color: #6b7280; border-top: 1px solid #e5e7eb;">{{ $point['date'] }}</td>
        @endforeach
    </tr>
</table>
