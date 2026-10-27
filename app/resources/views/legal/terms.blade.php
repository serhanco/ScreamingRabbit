@extends('legal.layout')
@section('title', 'Kullanım Koşulları / Terms of Service')
@section('content')
    @php($brand = config('screamingrabbit.brand'))
    <h1>Kullanım Koşulları</h1>
    <p class="muted">Son güncelleme: 27 Ekim 2026</p>
    <p>{{ $brand['product'] }}, {{ $brand['name'] }} (<a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a>) tarafından sunulan bir SEO denetim aracıdır ve yalnızca davet edilen kullanıcılar içindir.</p>
    <ul>
        <li>Yalnızca yetkili olduğunuz siteleri ve Search Console mülklerini ekleyin.</li>
        <li>Hesabınızı ve iki adımlı doğrulamanızı başkasıyla paylaşmayın.</li>
        <li>Aracı siteleri aşırı yükleyecek ya da yasa dışı amaçlarla kullanmayın.</li>
        <li>Raporlar ve öneriler bilgi amaçlıdır; arama sıralamaları için garanti verilmez. Hizmet "olduğu gibi" sunulur.</li>
        <li>Kurallara uyulmadığında erişim kapatılabilir. Google verilerinin kullanımı <a href="{{ route('legal.privacy') }}">Gizlilik Politikası</a>'na tabidir.</li>
        <li>Koşullar güncellenebilir; güncel metin her zaman bu sayfadadır.</li>
    </ul>

    <hr>

    <div lang="en">
        <h1>Terms of Service</h1>
        <p class="muted">Last updated: 27 October 2026</p>
        <p>{{ $brand['product'] }} is an SEO audit tool provided by {{ $brand['name'] }} (<a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a>) for invited users only.</p>
        <ul>
            <li>Only add sites and Search Console properties you are authorised to manage.</li>
            <li>Do not share your account or two-factor authentication with others.</li>
            <li>Do not use the tool to overload websites or for unlawful purposes.</li>
            <li>Reports and recommendations are informational; no search ranking is guaranteed. The service is provided "as is".</li>
            <li>Access may be removed if these terms are broken. Use of Google data is governed by the <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</li>
            <li>These terms may change; the current text is always on this page.</li>
        </ul>
    </div>
@endsection
