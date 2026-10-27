@extends('legal.layout')
@section('title', 'SEO denetim aracı')
@section('content')
    <h1>{{ config('screamingrabbit.brand.product') }}</h1>
    <p>Web siteleri için SEO denetim ve takip aracı: siteyi tarar, teknik sorunları bulur, Google Search Console verileriyle (salt okunur) öncelikli bir iş listesi ve müşteri raporu hazırlar. Yalnızca davet edilen kullanıcılar içindir.</p>
    <p lang="en">An SEO audit and monitoring tool for websites: it crawls a site, finds technical issues and, using read-only Google Search Console data, builds a prioritised task list and client reports. For invited users only.</p>
    <a class="btn" href="{{ url('/admin') }}">Giriş / Sign in</a>
    <p class="muted" style="margin-top: 20px;">
        <a href="{{ route('legal.privacy') }}">Gizlilik Politikası / Privacy Policy</a> &middot;
        <a href="{{ route('legal.terms') }}">Kullanım Koşulları / Terms of Service</a>
    </p>
@endsection
