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
        'image_max_kb' => ['value' => 100, 'source' => 'Screaming Frog "Over 100 KB" görsel varsayılanı', 'reviewed' => '2026-10-25'],
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
            'image_broken' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'Bozuk görsel', 'description' => 'Sayfadaki görsel açılmıyor (4xx/5xx ya da yanıt yok); ziyaretçi boş kutu görür.', 'fix' => 'Görseli yeniden yükleyin ya da sayfadan kaldırın, adresini düzeltin.'],
            'image_too_large' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Büyük görsel', 'description' => 'Görsel 100 KB\'tan büyük; sayfanın yüklenmesini yavaşlatır.', 'fix' => 'Görseli sıkıştırın, WebP/AVIF\'e çevirin ve gösterildiği boyuta küçültün.'],
            'search_bot_blocked' => ['category' => 'indexability', 'severity' => 'high', 'title' => 'Arama motoru robots.txt ile engellenmiş', 'description' => 'robots.txt bir veya daha fazla arama motorunun siteyi taramasını engelliyor; bu motorlarda site görünmez veya bilgisi eskir.', 'fix' => 'robots.txt\'de ilgili User-agent grubundaki Disallow kuralını kaldırın.'],
            'ai_search_bot_blocked' => ['category' => 'indexability', 'severity' => 'medium', 'title' => 'AI arama botu robots.txt ile engellenmiş', 'description' => 'ChatGPT, Claude veya Perplexity gibi AI aramalarının botları siteyi okuyamıyor; bu cevaplarda site kaynak olarak gösterilmez.', 'fix' => 'AI aramalarında görünmek istiyorsanız robots.txt\'de bu botlara izin verin (eğitim botlarını ayrıca engelleyebilirsiniz).'],
            'ai_training_bot_blocked' => ['category' => 'indexability', 'severity' => 'info', 'title' => 'AI eğitim botu engellenmiş', 'description' => 'İçeriğin AI modeli eğitiminde kullanılması robots.txt ile reddedilmiş. Bu bir tercihtir, arama görünürlüğünü etkilemez.', 'fix' => 'Bilinçli bir tercihse işlem gerekmez.'],
            'bot_fetch_refused' => ['category' => 'indexability', 'severity' => 'low', 'title' => 'Bot kimliğiyle erişim reddedildi', 'description' => 'Ana sayfa normal tarayıcıya açılıyor ama bazı bot kimlikleriyle 401/403/429/503 dönüyor. Cloudflare/WAF bot engeli olabilir; sahte bot koruması da sebep olabilir, gerçek botlar etkilenmeyebilir.', 'fix' => 'Cloudflare\'de "AI botlarını engelle" / Bot Fight Mode ve güvenlik eklentisi kurallarını kontrol edin, istenen botları izinli listeye alın.'],
            'schema_invalid_json' => ['category' => 'onpage', 'severity' => 'medium', 'title' => 'Bozuk yapısal veri (JSON-LD)', 'description' => 'Sayfadaki JSON-LD bloğu geçerli JSON değil; Google ve AI sistemleri bu şemayı tamamen yok sayar.', 'fix' => 'Şemayı üreten eklentiyi/temayı kontrol edin, Google Zengin Sonuç Testi ile doğrulayın.'],
            'home_missing_organization_schema' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Ana sayfada kurum şeması yok', 'description' => 'Ana sayfada Organization, LocalBusiness veya Person şeması bulunamadı; arama motorları ve AI sistemleri markayı bir varlık olarak tanımakta zorlanır.', 'fix' => 'Ana sayfaya ad, logo, adres/iletişim ve sameAs (sosyal profiller) içeren Organization veya LocalBusiness JSON-LD ekleyin (Yoast/Rank Math ayarlarından yapılabilir).'],
            'content_requires_js' => ['category' => 'indexability', 'severity' => 'medium', 'title' => 'İçerik yalnızca JavaScript ile görünüyor', 'description' => 'Sayfanın ham HTML\'inde neredeyse hiç metin yok; içerik tarayıcıda JavaScript ile çiziliyor. ChatGPT, Claude, Perplexity gibi AI botlarının çoğu JavaScript çalıştırmaz ve sayfayı boş görür; Google da geç ve eksik işleyebilir.', 'fix' => 'Sunucu tarafı oluşturma (SSR) veya statik ön oluşturma kullanın; WordPress\'te içeriği JavaScript ile yükleyen sayfa oluşturucu/eklenti ayarlarını kontrol edin.'],
            'llms_txt_missing' => ['category' => 'indexability', 'severity' => 'info', 'title' => 'llms.txt yok', 'description' => 'Site kökünde llms.txt dosyası yok. Bu dosya AI sistemlerine sitenin özetini ve önemli sayfalarını sunan yeni bir standarttır (llmstxt.org); henüz zorunlu değildir.', 'fix' => 'Başlık, kısa özet ve önemli sayfa linklerini içeren bir /llms.txt ekleyin.'],
            'llms_txt_invalid' => ['category' => 'indexability', 'severity' => 'low', 'title' => 'llms.txt biçimi hatalı', 'description' => 'llms.txt var ama "# Başlık" ile başlamıyor ya da hiç link içermiyor.', 'fix' => 'Dosyayı llmstxt.org biçimine getirin: ilk satır "# Site adı", ardından "> özet" ve "## Bölüm" altında Markdown linkler.'],
            'images_missing_size' => ['category' => 'onpage', 'severity' => 'low', 'title' => 'Boyutu belirtilmemiş görsel', 'description' => 'Görselde width ve height öznitelikleri yok; tarayıcı yer ayıramaz, görsel yüklenince sayfa kayar (CLS, Core Web Vitals).', 'fix' => 'Görsellere gerçek width ve height değerlerini ekleyin (WordPress bunu medya kitaplığındaki görsellere kendisi ekler; tema/sayfa oluşturucu kaldırıyorsa düzeltin).'],
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
            'wp_search_engines_discouraged' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'Arama motorları engellenmiş (WordPress ayarı)', 'description' => 'Ana sayfa noindex; büyük ihtimalle Ayarlar → Okuma\'daki "Arama motorlarının bu siteyi dizine eklemesini engelle" kutusu işaretli. Site Google\'dan düşer.', 'fix' => 'Ayarlar → Okuma\'da kutunun işaretini kaldırın, SEO eklentisinde ana sayfa noindex değil mi kontrol edin.'],
            'wp_core_outdated' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'WordPress çekirdeği güncel değil', 'description' => 'Sitedeki WordPress sürümü en son sürümün gerisinde; güvenlik yamaları eksik olabilir.', 'fix' => 'Yedek alıp Pano → Güncellemeler\'den WordPress\'i güncelleyin.'],
            'wp_plugin_outdated' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'medium', 'title' => 'Güncel olmayan eklenti', 'description' => 'Sayfa kaynağındaki sürüm bilgisine göre eklentinin daha yeni bir sürümü var. Eski eklentiler en sık hack sebebidir.', 'fix' => 'Eklentileri güncelleyin; kullanılmayanları silin. (Sürüm, varlık dosyalarındaki ?ver= değerinden tahmin edilir.)'],
            'wp_version_exposed' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'low', 'title' => 'WordPress sürümü dışarıdan görünüyor', 'description' => 'Sürüm bilgisi RSS, readme.html veya meta etiketiyle herkese açık; saldırganlar açık arayan siteleri böyle seçer.', 'fix' => 'Güvenlik/SEO eklentisinde sürüm bilgisini gizleyin, readme.html\'i silin.'],
            'wp_inventory' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'info', 'title' => 'Tespit edilen eklenti ve temalar', 'description' => 'Sayfa kaynağından görünen eklenti ve temalar (tam liste değildir).', 'fix' => 'Bilgi amaçlıdır; tam envanter için ileride bağlantı eklentisi kullanılacak.'],
            'wp_user_enumeration' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'Kullanıcı adları ifşa ediliyor', 'description' => 'Yönetici kullanıcı adları ?author=1 yönlendirmesi veya REST API üzerinden görülebiliyor; kaba kuvvet saldırılarının yarısını kolaylaştırır.', 'fix' => 'Güvenlik eklentisiyle yazar taramasını ve REST users uç noktasını kapatın; yazar görünen adını kullanıcı adından farklı yapın.'],
            'wp_xmlrpc_enabled' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'medium', 'title' => 'xmlrpc.php açık', 'description' => 'XML-RPC kaba kuvvet ve DDoS amplifikasyonunda kullanılır; çoğu sitede gerekmez.', 'fix' => 'Jetpack veya mobil uygulama kullanmıyorsanız güvenlik eklentisiyle ya da .htaccess ile kapatın.'],
            'wp_default_files' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'low', 'title' => 'Varsayılan WordPress dosyaları açık', 'description' => 'readme.html / license.txt herkese açık; sürüm ve platform bilgisi verir.', 'fix' => 'Bu dosyaları silin ya da erişimi engelleyin.'],
            'wp_debug_log_public' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'debug.log herkese açık', 'description' => '/wp-content/debug.log indirilebiliyor; dosya yolları, hata ayrıntıları ve bazen kişisel veri içerir.', 'fix' => 'WP_DEBUG_LOG\'u kapatın, dosyayı silin ve /wp-content/ altında .log erişimini engelleyin.'],
            'wp_directory_listing' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'medium', 'title' => 'Dizin listeleme açık', 'description' => 'Klasör içeriği (yüklemeler/eklentiler) tarayıcıdan listelenebiliyor.', 'fix' => 'Sunucuda dizin listelemeyi kapatın (.htaccess: Options -Indexes).'],
            'wp_config_backup_exposed' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'wp-config yedeği açıkta', 'description' => 'wp-config yedek dosyası indirilebiliyor; veritabanı şifresi ve gizli anahtarlar içerir.', 'fix' => 'Dosyayı hemen silin, veritabanı şifresini ve güvenlik anahtarlarını değiştirin.'],
            'wp_login_http' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'high', 'title' => 'Giriş sayfası şifresiz (HTTP) açılıyor', 'description' => 'wp-login.php HTTP üzerinden açılıyor; şifre ağda düz metin gidebilir.', 'fix' => 'HTTP\'den HTTPS\'e kalıcı yönlendirme ekleyin (FORCE_SSL_ADMIN).'],
            'wp_search_indexable' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'medium', 'title' => 'İç arama sonuçları indekslenebilir', 'description' => '?s= arama sonuç sayfaları noindex değil; spam ve sonsuz URL üretilebilir.', 'fix' => 'SEO eklentisinde arama sayfalarını noindex yapın.'],
            'wp_double_sitemap' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'medium', 'title' => 'İki site haritası birden aktif', 'description' => 'Hem WordPress\'in kendi /wp-sitemap.xml\'i hem eklenti site haritası açık; arama motorlarına çelişen liste verir.', 'fix' => 'SEO eklentisinin site haritasını bırakıp çekirdek site haritasını kapatın.'],
            'wp_default_content' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'low', 'title' => 'Varsayılan WordPress içeriği duruyor', 'description' => '"Merhaba dünya!", "Örnek sayfa" veya "Bir başka WordPress sitesi" gibi kurulum içerikleri yayında.', 'fix' => 'Bu içerikleri silin, slogan alanını doldurun.'],
            'wp_uncategorized' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'low', 'title' => '"Kategorilenmemiş" kategorisi kullanımda', 'description' => 'Yazılar varsayılan kategoride duruyor; zayıf site mimarisi işaretidir.', 'fix' => 'Kategoriyi yeniden adlandırın ya da yazıları anlamlı kategorilere taşıyın.'],
            'wp_replytocom' => ['category' => 'wordpress', 'platform' => 'wordpress', 'severity' => 'low', 'title' => '?replytocom= linkleri', 'description' => 'Yorum yanıtı linkleri kopya URL üretir.', 'fix' => 'SEO eklentisinde replytocom\'u kaldırın ya da yorum yanıtlarını JS ile yapın.'],
            'wp_raw_url' => ['category' => 'wordpress', 'severity' => 'medium', 'title' => 'Ham WordPress adresi (?p=, ?page_id=)', 'description' => 'Sayfa güzel adresinin yanında ham adresle de erişilebilir ve canonical ile korunmuyor.', 'fix' => 'Kalıcı bağlantı ayarını ve canonical etiketini kontrol edin.', 'platform' => 'wordpress'],
        ];
    }
}
