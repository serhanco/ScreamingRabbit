@php
    $color = fn (?int $s) => $s === null ? '#6b7280' : ($s >= 80 ? '#15803d' : ($s >= 50 ? '#b45309' : '#b91c1c'));
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>SEO raporları · {{ $project->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; margin: 0; padding: 24px 16px; background: #f3f4f6; }
        .page { max-width: 720px; margin: 0 auto; background: #fff; padding: 28px; border-radius: 6px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        td, th { border-bottom: 1px solid #e5e7eb; padding: 10px 6px; text-align: left; }
        a { color: #c2410c; }
        .footer { margin-top: 28px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
<div class="page">
    <h1>SEO raporları</h1>
    <div class="muted">{{ $project->client?->name }} · {{ $project->base_url }}</div>

    @if ($reports->isEmpty())
        <p>Henüz yayınlanmış bir rapor yok.</p>
    @else
        <table>
            <tr class="muted"><th>Dönem</th><th>Sağlık skoru</th><th></th></tr>
            @foreach ($reports as $report)
                <tr>
                    <td>{{ $report->periodLabel() }}</td>
                    <td style="font-weight:bold;color:{{ $color($report->health_score) }}">{{ $report->health_score ?? '-' }} / 100</td>
                    <td style="text-align:right"><a href="{{ $report->url() }}">Raporu aç</a> · <a href="{{ route('monthly-report.pdf', $report->token) }}">PDF</a></td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        {{ $brand['product'] }} ile hazırlanmıştır · <a href="{{ $brand['url'] }}">Bir {{ $brand['name'] }} ürünü</a>
    </div>
</div>
</body>
</html>
