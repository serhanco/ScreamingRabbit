<?php

namespace App\Audit;

/**
 * Single source for every audit rule and threshold (docs/04, section 3.2).
 * Thresholds follow current common standards; each one records its source and last review date
 * so they can be revisited when the standards change.
 */
class RuleCatalog
{
    public const CATEGORIES = [
        'response' => 'Yanıt ve yönlendirme',
        'indexability' => 'İndekslenebilirlik',
        'onpage' => 'Sayfa içi',
        'url' => 'URL ve mimari',
        'security' => 'Güvenlik',
        'wordpress' => 'WordPress',
    ];

    public const SEVERITIES = [
        'high' => 'Yüksek',
        'medium' => 'Orta',
        'low' => 'Düşük',
        'info' => 'Bilgi',
    ];

    /** @var array<string, array{value: int, source: string, reviewed: string}> */
    public const THRESHOLDS = [
        'title_max' => ['value' => 60, 'source' => 'Google arama sonucu başlık kırpma sınırı, sektör araçları (Screaming Frog, Sitebulb) varsayılanı', 'reviewed' => '2026-10-25'],
        'title_min' => ['value' => 30, 'source' => 'Screaming Frog varsayılanı', 'reviewed' => '2026-10-25'],
        'description_max' => ['value' => 155, 'source' => 'Google arama sonucu açıklama kırpma sınırı, sektör araçları varsayılanı', 'reviewed' => '2026-10-25'],
        'description_min' => ['value' => 70, 'source' => 'Screaming Frog varsayılanı', 'reviewed' => '2026-10-25'],
        'low_word_count' => ['value' => 200, 'source' => 'Screaming Frog "low content" varsayılanı', 'reviewed' => '2026-10-25'],
        'url_max_length' => ['value' => 115, 'source' => 'Screaming Frog varsayılanı', 'reviewed' => '2026-10-25'],
        'max_depth' => ['value' => 4, 'source' => 'Sektörde yaygın "ana sayfadan en fazla 4 tık" kuralı', 'reviewed' => '2026-10-25'],
    ];

    public static function threshold(string $key): int
    {
        return self::THRESHOLDS[$key]['value'];
    }

    /**
     * @return array<string, array{category: string, severity: string, title: string, description: string, fix: string, platform?: string}>
     */
    public static function all(): array
    {
        return [
            // Yanıt ve yönlendirme
            'http_4xx' => ['category' => 'response', 'severity' => 'high', 'title' => '4xx hata veren sayfa', 'description' => 'Sayfa 404/410 gibi bir hata döndürüyor. İç linkler boşa gidiyor, kullanıcı ve arama motoru çıkmaz sokağa düşüyor.', 'fix' => 'Sayfayı geri getirin ya da yeni adrese 301 yönlendirin ve bu sayfaya giden iç linkleri güncelleyin.'],
            'http_5xx' => ['category' => 'response', 'severity' => 'high', 'title' => '5xx sunucu hatası veren sayfa', 'description' => 'Sunucu bu sayfa için hata döndürüyor. Sık görülürse tarama bütçesi ve sıralamalar zarar görür.', 'fix' => 'Sunucu/WordPress hata günlüklerine bakın; eklenti ya da kaynak yetersizliği olup olmadığını kontrol edin.'],
            'fetch_failed' => ['category' => 'response', 'severity' => 'high', 'title' => 'Yanıt vermeyen sayfa', 'description' => 'Sayfa zaman aşımına uğradı veya bağlantı kurulamadı.', 'fix' => 'Sunucu hızını ve güvenlik duvarı/bot engelleme ayarlarını kontrol edin.'],
            'timeout' => ['category' => 'response', 'severity' => 'high', 'title' => 'Zaman aşımına uğrayan sayfa', 'description' => 'Sayfa 15 saniye içinde yanıt vermedi. Kullanıcılar ve arama motoru botları sayfaya ulaşamıyor olabilir.', 'fix' => 'Sunucu yanıt süresini ve ağır eklentileri kontrol edin; önbellek kullanın.'],
            'meta_refresh' => ['category' => 'response', 'severity' => 'medium', 'title' => 'Meta refresh yönlendirmesi', 'description' => 'Sayfa, HTML içindeki meta refresh ile başka adrese gönderiyor; sunucu yönlendirmesi kadar güvenilir değildir.', 'fix' => 'Meta refresh yerine 301 sunucu yönlendirmesi kullanın.'],
            'redirect_temporary' => ['category' => 'response', 'severity' => 'medium', 'title' => 'Geçici (302/307) yönlendirme', 'description' => 'Kalıcı olması gereken bir taşıma geçici yönlendirmeyle yapılmış olabilir; sinyaller yeni adrese tam aktarılmaz.', 'fix' => 'Taşıma kalıcıysa 301 kullanın.'],
            'redirect_chain' => ['category' => 'response', 'severity' => 'medium', 'title' => 'Yönlendirme zinciri', 'description' => 'Bir yönlendirme, başka bir yönlendirmeye gidiyor. Her ek adım hız ve sinyal kaybıdır.', 'fix' => 'Eski adresi doğrudan son hedefe yönlendirin.'],
            'redirect_loop' => ['category' => 'response', 'severity' => 'high', 'title' => 'Yönlendirme döngüsü', 'description' => 'İki adres birbirine yönlendiriyor; sayfa hiç açılmaz.', 'fix' => 'Yönlendirme kurallarından birini kaldırın.'],
            'link_to_redirect' => ['category' => 'response', 'severity' => 'low', 'title' => 'Yönlendirilen adrese iç link', 'description' => 'Sitedeki linkler doğrudan hedefe değil, yönlendirilen eski adrese gidiyor.', 'fix' => 'İç linkleri son adrese güncelleyin.'],
            'blocked_by_robots' => ['category' => 'response', 'severity' => 'low', 'title' => 'robots.txt ile engellenen iç URL', 'description' => 'Sitede link verilen bu adres robots.txt tarafından engelleniyor; taranamadı.', 'fix' => 'Engel bilinçli değilse robots.txt kuralını düzeltin; bilinçliyse linki kaldırmayı düşünün.'],

            // İndekslenebilirlik
            'noindex' => ['category' => 'indexability', 'severity' => 'medium', 'title' => 'noindex sayfa', 'description' => 'Sayfa arama sonuçlarından hariç tutuluyor (meta robots ya da X-Robots-Tag).', 'fix' => 'Bilinçli değilse noindex etiketini kaldırın.'],
            'canonical_missing' => ['category' => 'indexability', 'severity' => 'low', 'title' => 'Canonical etiketi eksik', 'description' => 'Sayfa kendi kanonik adresini belirtmiyor; parametreli kopyalar sorun çıkarabilir.', 'fix' => 'SEO eklentinizde kendine işaret eden canonical etiketini açın.'],
            'canonical_other' => ['category' => 'indexability', 'severity' => 'medium', 'title' => 'Canonical başka adrese işaret ediyor', 'description' => 'Sayfa kendi yerine başka bir adresin indekslenmesini istiyor.', 'fix' => 'Bilinçli değilse canonical adresini sayfanın kendisine çevirin.'],
            'canonical_target_bad' => ['category' => 'indexability', 'severity' => 'high', 'title' => 'Canonical hedefi çalışmıyor veya noindex', 'description' => 'Sayfanın canonical olarak gösterdiği adres hata veriyor, yönlendiriliyor ya da noindex; canonical sinyali yok sayılır.', 'fix' => 'Canonical\'ı çalışan, indekslenebilir bir adrese çevirin.'],
            'sitemap_non_indexable' => ['category' => 'indexability', 'severity' => 'medium', 'title' => 'Sitemap\'te olup indekslenemeyen URL', 'description' => 'Sitemap yalnızca indekslenebilir (200, noindex olmayan) adresleri içermelidir.', 'fix' => 'Adresi sitemap\'ten çıkarın ya da sorunu düzeltin.'],
            'sitemap_missing_indexable' => ['category' => 'indexability', 'severity' => 'low', 'title' => 'Taranıp sitemap\'te olmayan indekslenebilir URL', 'description' => 'Bu sayfa indekslenebilir ama sitemap\'te yok.', 'fix' => 'Sitemap\'e ekleyin (SEO eklentisi ayarlarını kontrol edin).'],

            // Sayfa içi
            'title_missing' => ['category' => 'onpage', 'severity' => 'high', 'title' => 'Title eksik', 'description' => 'Sayfanın title etiketi yok ya da boş.', 'fix' => 'Sayfaya özgü, anahtar kelimeyi içeren bir title yazın.'],
            'title_duplicate' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'Title kopya', 'description' => 'Aynı title birden fazla sayfada kullanılıyor.', 'fix' => 'Her sayfa için benzersiz title yazın.'],
            'title_too_long' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Title çok uzun', 'description' => 'Title arama sonuçlarında kırpılabilir.', 'fix' => 'Title\'ı kısaltın ve önemli kelimeleri başa alın.'],
            'title_too_short' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Title çok kısa', 'description' => 'Title, sayfayı anlatmak için yeterince alan kullanmıyor.', 'fix' => 'Title\'ı genişletin.'],
            'meta_description_missing' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'Meta description eksik', 'description' => 'Açıklama yoksa Google kendi seçtiği bir metni gösterir.', 'fix' => 'Sayfayı özetleyen çekici bir açıklama yazın.'],
            'meta_description_duplicate' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Meta description kopya', 'description' => 'Aynı açıklama birden fazla sayfada kullanılıyor.', 'fix' => 'Sayfaya özgü açıklamalar yazın.'],
            'meta_description_too_long' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Meta description çok uzun', 'description' => 'Açıklama arama sonuçlarında kırpılabilir.', 'fix' => 'Açıklamayı kısaltın.'],
            'meta_description_too_short' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Meta description çok kısa', 'description' => 'Açıklama alanı verimli kullanılmıyor.', 'fix' => 'Açıklamayı genişletin.'],
            'h1_missing' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'H1 eksik', 'description' => 'Sayfanın ana başlığı (H1) yok.', 'fix' => 'Sayfa konusunu anlatan tek bir H1 ekleyin.'],
            'h1_multiple' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Birden fazla H1', 'description' => 'Sayfada birden çok H1 var; konu odağı bulanıklaşır.', 'fix' => 'Tek H1 bırakın, diğerlerini H2/H3 yapın.'],
            'h1_duplicate' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'H1 kopya', 'description' => 'Aynı H1 birden fazla sayfada kullanılıyor.', 'fix' => 'Her sayfaya özgü H1 yazın.'],
            'images_missing_alt' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Alt özniteliği olmayan görsel', 'description' => 'Alt metni olmayan görseller erişilebilirliği ve görsel aramayı olumsuz etkiler.', 'fix' => 'Görsellere içeriği anlatan alt metin ekleyin (süs görsellerine boş alt="").'],
            'low_content' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Düşük kelime sayısı', 'description' => 'Sayfada çok az metin var; "ince içerik" sayılabilir.', 'fix' => 'İçeriği zenginleştirin ya da sayfayı birleştirin/noindex yapın.'],
            'duplicate_content' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'Birebir kopya içerik', 'description' => 'Bu sayfanın metni başka bir sayfayla birebir aynı.', 'fix' => 'Sayfaları birleştirin veya canonical ile asıl sayfayı gösterin.'],

            // URL ve mimari
            'url_uppercase' => ['category' => 'url', 'severity' => 'low', 'title' => 'URL\'de büyük harf', 'description' => 'Büyük/küçük harf farkı kopya adres üretebilir.', 'fix' => 'Adresleri küçük harfe çevirip eskiyi yönlendirin.'],
            'url_underscore' => ['category' => 'url', 'severity' => 'low', 'title' => 'URL\'de alt çizgi', 'description' => 'Google sözcük ayırıcı olarak tire önerir.', 'fix' => 'Alt çizgi yerine tire kullanın.'],
            'url_too_long' => ['category' => 'url', 'severity' => 'low', 'title' => 'URL çok uzun', 'description' => 'Çok uzun adresler paylaşması ve okunması zor olur.', 'fix' => 'Adresi kısaltın.'],
            'url_parameters' => ['category' => 'url', 'severity' => 'low', 'title' => 'Parametreli URL', 'description' => 'Sorgu parametreleri kopya içerik üretebilir.', 'fix' => 'Gereksiz parametreleri engelleyin ya da canonical kullanın.'],
            'url_non_ascii' => ['category' => 'url', 'severity' => 'low', 'title' => 'URL\'de Türkçe/özel karakter veya boşluk', 'description' => 'Özel karakterler kodlanarak okunmaz hale gelir ve linklerde hata çıkarabilir.', 'fix' => 'Adreste yalnızca a-z, 0-9 ve tire kullanın.'],
            'crawl_depth_high' => ['category' => 'url', 'severity' => 'low', 'title' => 'Ana sayfadan çok uzak sayfa', 'description' => 'Sayfaya ana sayfadan çok sayıda tıklamayla ulaşılıyor; önem sinyali düşer.', 'fix' => 'Sayfayı daha üst menü/kategori linkleriyle bağlayın.'],

            'single_inlink' => ['category' => 'url', 'severity' => 'low', 'title' => 'Tek iç linkle bağlı sayfa', 'description' => 'Sayfaya sitede yalnızca bir yerden link veriliyor; önem sinyali ve keşfedilebilirlik zayıf.', 'fix' => 'İlgili sayfalardan ve menü/kategori yapısından ek iç linkler verin.'],

            // Güvenlik
            'mixed_content' => ['category' => 'security', 'severity' => 'high', 'title' => 'Karışık içerik (HTTPS sayfada HTTP kaynak)', 'description' => 'Güvenli sayfa, güvenli olmayan HTTP kaynaklar yüklüyor; tarayıcı uyarısı ve engelleme riski var.', 'fix' => 'Görsel, script ve stil adreslerini https:// olarak güncelleyin.'],
            'https_to_http_link' => ['category' => 'security', 'severity' => 'medium', 'title' => 'HTTPS sayfadan HTTP iç linke', 'description' => 'Sayfa, kendi sitenizin güvenli olmayan HTTP adreslerine link veriyor.', 'fix' => 'İç linkleri https:// adresine güncelleyin.'],
            'http_page' => ['category' => 'security', 'severity' => 'high', 'title' => 'HTTPS olmayan sayfa', 'description' => 'Sayfa güvenli olmayan HTTP üzerinden açılıyor.', 'fix' => 'Tüm sayfaları HTTPS\'e yönlendirin.'],

            // WordPress
            'wp_attachment_page' => ['category' => 'wordpress', 'severity' => 'medium', 'title' => 'İndekslenebilir ek (attachment) sayfası', 'description' => 'WordPress her görsel için ayrı bir sayfa üretir; bunlar ince içerik olarak indekslenir.', 'fix' => 'SEO eklentisinde ek sayfalarını ana dosyaya yönlendirin ya da noindex yapın.', 'platform' => 'wordpress'],
            'wp_thin_archive' => ['category' => 'wordpress', 'severity' => 'low', 'title' => 'İnce içerikli indekslenebilir arşiv sayfası', 'description' => 'Etiket/yazar/tarih arşivi az metinle indeksleniyor; kopya ve ince içerik riski taşır.', 'fix' => 'Gereksiz arşivleri noindex yapın veya içerik ekleyin.', 'platform' => 'wordpress'],
            'wp_multiple_seo_plugins' => ['category' => 'wordpress', 'severity' => 'medium', 'title' => 'Birden fazla SEO eklentisi izi', 'description' => 'Sitede birden çok SEO eklentisinin (Yoast, Rank Math, AIOSEO) çıktısı var; çift etiket ve çakışma yaratabilir.', 'fix' => 'Yalnızca birini etkin bırakın.', 'platform' => 'wordpress'],
            'wp_raw_url' => ['category' => 'wordpress', 'severity' => 'medium', 'title' => 'Ham WordPress adresi (?p=, ?page_id=)', 'description' => 'Sayfa güzel adresinin yanında ham adresle de erişilebilir ve canonical ile korunmuyor.', 'fix' => 'Kalıcı bağlantı ayarını ve canonical etiketini kontrol edin.', 'platform' => 'wordpress'],
        ];
    }
}
