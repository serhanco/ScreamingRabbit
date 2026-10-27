@extends('legal.layout')
@section('title', 'Gizlilik Politikası / Privacy Policy')
@section('content')
    @php($brand = config('screamingrabbit.brand'))
    <h1>Gizlilik Politikası</h1>
    <p class="muted">Son güncelleme: 27 Ekim 2026</p>
    <p>{{ $brand['product'] }}, {{ $brand['name'] }} (<a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a>) tarafından işletilen, yalnızca davetle kullanılan bir SEO denetim aracıdır.</p>
    <h2>Google ile hangi verilere erişiyoruz</h2>
    <p>Google hesabınızı bağladığınızda yalnızca şu izinleri isteriz: e-posta adresiniz (hangi hesabın bağlandığını göstermek için) ve Google Search Console verilerine <b>salt okunur</b> erişim (<code>webmasters.readonly</code>). Search Console'da hiçbir şeyi değiştirmeyiz, silmeyiz ya da göndermeyiz.</p>
    <h2>Verileri nasıl kullanıyoruz</h2>
    <p>Search Console verileri (sorgular, tıklamalar, gösterimler, konumlar, URL denetim sonuçları) yalnızca size ve projenize erişim verdiğiniz kişilere SEO raporu, fırsat listesi ve iş listesi göstermek için kullanılır. Bu veriler satılmaz, reklam için kullanılmaz, üçüncü taraflarla paylaşılmaz ve yapay zekâ modellerini eğitmek için kullanılmaz.</p>
    <p>{{ $brand['product'] }}'in Google API'lerinden aldığı bilgileri kullanması ve başka uygulamalara aktarması, Sınırlı Kullanım (Limited Use) koşulları dahil <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Hizmetleri Kullanıcı Verileri Politikası</a>'na uyar.</p>
    <h2>Saklama ve güvenlik</h2>
    <p>Google erişim anahtarı (refresh token) veritabanında şifrelenmiş olarak saklanır. Tüm bağlantılar HTTPS üzerindendir. Panel iki adımlı doğrulama ile korunur.</p>
    <h2>Erişimi geri alma ve silme</h2>
    <p>Bağlantıyı panelden kaldırabilir ya da istediğiniz an <a href="https://myaccount.google.com/permissions">Google hesap izinleri</a> sayfasından {{ $brand['product'] }} erişimini iptal edebilirsiniz. Bağlantı kaldırıldığında saklanan anahtar silinir. Verilerinizin tamamen silinmesini istemek için <a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a> üzerinden bize ulaşın.</p>

    <hr>

    <div lang="en">
        <h1>Privacy Policy</h1>
        <p class="muted">Last updated: 27 October 2026</p>
        <p>{{ $brand['product'] }} is an invitation-only SEO audit tool operated by {{ $brand['name'] }} (<a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a>).</p>
        <h2>What Google data we access</h2>
        <p>When you connect a Google account we request only your email address (to show which account is connected) and <b>read-only</b> access to Google Search Console data (<code>webmasters.readonly</code>). We never change, delete or submit anything in Search Console.</p>
        <h2>How we use it</h2>
        <p>Search Console data (queries, clicks, impressions, positions, URL inspection results) is used only to show SEO reports, opportunity lists and task lists to you and the people you give access to your project. It is not sold, not used for advertising, not shared with third parties and not used to train AI models.</p>
        <p>{{ $brand['product'] }}'s use and transfer of information received from Google APIs adheres to the <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>
        <h2>Storage and security</h2>
        <p>The Google refresh token is stored encrypted in our database. All traffic uses HTTPS. The panel is protected by two-factor authentication.</p>
        <h2>Revoking access and deletion</h2>
        <p>You can disconnect from the panel, or revoke {{ $brand['product'] }}'s access at any time in your <a href="https://myaccount.google.com/permissions">Google account permissions</a>. Disconnecting deletes the stored token. To have all your data deleted, contact us via <a href="{{ $brand['url'] }}">{{ parse_url($brand['url'], PHP_URL_HOST) }}</a>.</p>
    </div>
@endsection
