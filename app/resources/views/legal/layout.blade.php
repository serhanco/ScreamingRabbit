{{-- Public pages (home, privacy, terms) that Google's OAuth consent screen links to. Standalone: no login, no panel assets. --}}
@php($brand = config('screamingrabbit.brand'))
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>@yield('title') · {{ $brand['product'] }}</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; padding: 16px; }
        .card { max-width: 760px; margin: 32px auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        header img { width: 36px; height: 36px; }
        header a { color: inherit; text-decoration: none; font-weight: 700; font-size: 18px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 17px; margin: 24px 0 8px; }
        p, li { line-height: 1.6; }
        hr { border: 0; border-top: 1px solid #e5e7eb; margin: 32px 0; }
        a { color: #c2410c; }
        .btn { display: inline-block; margin-top: 8px; padding: 10px 18px; border-radius: 8px; background: #ea580c; color: #fff; font-weight: 600; text-decoration: none; }
        .muted { color: #6b7280; font-size: 13px; }
        footer { text-align: center; font-size: 13px; color: #6b7280; margin-bottom: 24px; }
        footer a { color: inherit; }
    </style>
</head>
<body>
<div class="card">
    <header>
        <img src="{{ asset('images/logo-mark.svg') }}" alt="">
        <a href="{{ route('home') }}">{{ $brand['product'] }}</a>
    </header>
    @yield('content')
</div>
<footer>
    <a href="{{ route('legal.privacy') }}">Gizlilik / Privacy</a> &middot;
    <a href="{{ route('legal.terms') }}">Koşullar / Terms</a> &middot;
    {{ $brand['product'] }} &middot; Bir <a href="{{ $brand['url'] }}" rel="noopener">{{ $brand['name'] }}</a> ürünü / a {{ $brand['name'] }} product
</footer>
</body>
</html>
