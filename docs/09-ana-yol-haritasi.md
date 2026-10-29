# 09 · Ana Yol Haritası (tüm fazlar, özellik bazında)

> Durum tarihi: 25 Ekim 2026
> Bu doküman projenin **tek ana planıdır**. Her faz, içindeki özellikleri tek tek listeler. Ayrıntılar ilgili dokümanlarda: kural kataloğu ve GSC kuralları [04](04-mvp-kapsami-ve-yol-haritasi.md), mimari [05](05-mimari-ve-sunucu.md), WordPress [07](07-wordpress-modulu.md), izleme [08](08-izleme-ve-bildirimler.md). Screaming Frog özellikleri [01](01-screaming-frog-ozellik-envanteri.md), rakip üstünlükleri [02](02-rakip-analizi.md) ve [03](03-karsilastirma-matrisi.md)'ten alındı.
>
> İşaretler: ✓ yapıldı · ◐ kısmen · ○ planlı · **[VPS]** paylaşımlı hostingde çalışmaz, GoDaddy VPS'e taşınınca gelir.

## 0. Hedef

Fazların sonunda ScreamingRabbit:

1. **Screaming Frog'un yaptığı her anlamlı teknik denetimi** yapar (lisanslı veri ve terk edilmiş teknolojiler hariç, §9).
2. Screaming Frog'un **yapamadıklarını** yapar: bilgisayar kapalıyken 7/24 çalışır, GSC trafiğiyle önceliklendirir, iş listesi üretir, siteyi dakika dakika izler, WordPress'in içine girip onaylı düzeltme uygular, müşteriye Türkçe rapor verir ve yapılan işin etkisini kanıtlar.

## 1. Faz özeti

| Faz | Ad | Müşteriye satılabilir çıktı | Durum |
|---|---|---|---|
| 0 | Araştırma, mimari, kurulum | — | ✓ |
| 1 | Temel teknik denetim (MVP) | Teknik SEO denetim raporu | ✓ (50.000 URL testi kaldı) |
| 2 | Hızlı kazançlar: WordPress dış kontroller + uptime izleyici + AI arama temelleri | WordPress güvenlik/SEO kontrolü, 7/24 erişilebilirlik takibi, "AI botları sitenizi okuyabiliyor mu?" kontrolü | ✓ |
| 3 | GSC ve iş listesi | Trafiğe göre öncelikli aksiyon planı, kannibalizasyon/zombi sayfa analizi | ✓ |
| 4 | Otomasyon, değişiklik takibi, raporlama | Aylık otomatik rapor, "neyi düzelttik, ne bozuldu" | ✓ |
| 5 | Derin teknik denetim (Screaming Frog eşitliği) | Yapısal veri doğrulama, GEO/AEO denetimi, hreflang, Core Web Vitals | ✓ (form girişi ve [VPS] işleri kaldı) |
| 6 | WordPress bağlantı eklentisi | Site içinden tam envanter, açık taraması, tek tıkla düzeltme | ○ |
| 7 | AI ve ileri analiz | AI destekli içerik/meta iyileştirme, log analizi, içerik kümeleri | ○ |

Sıra mantığı: önce Serhan'ın hemen satabileceği ve az emekle gelen işler (Faz 2), sonra ana farklılaştırıcı (Faz 3-4), sonra eşitlik ve derinlik (Faz 5-7). Faz 5'in parçaları talep olursa öne çekilebilir; birbirinden bağımsızdır.

## 2. Faz 1 · Temel teknik denetim (MVP)

**Uygulama altyapısı**
- ✓ Laravel 13 + Filament 5 iskeleti, giriş + 2FA, web kurulum sayfası
- ✓ Müşteri ve proje ekranları, proje başına platform seçimi
- ✓ Hostinger otomatik dağıtım (`deploy` dalı), cron, kalıcı sunucu ayarları
- ✓ Daraltılabilir menü, tam genişlik sayfa

**Tarayıcı (crawler)**
- ✓ Spider modu, robots.txt'ye uyma, sitemap okuma (robots, `sitemap.xml`, `sitemap_index.xml`, `wp-sitemap.xml`)
- ✓ URL normalleştirme, Cloudflare `/cdn-cgi/` atlama, WordPress izi tespiti
- ✓ Kısa parçalar halinde arka planda tarama, muhafazakâr hız varsayılanları (2 eşzamanlı, 300 ms, 5.000 URL)
- ✓ Liste modu (URL listesi yapıştır) ve yalnızca sitemap modu: proje ayarlarında "Tarama türü"; linkler takip edilmez
- ✓ Include/exclude (regex), user-agent seçimi (Googlebot, Bingbot, Chrome, özel)
- ✓ Durdur, takılan taramayı otomatik sürdürme, eski taramaları otomatik silme (proje başına son 10), canlı ilerleme çubuğu ve aşama bilgisi
- ○ 50.000 URL hedefi için performans testi

**Kurallar** (✓ mevcut 44 kural: yanıt, yönlendirme, indekslenebilirlik, sayfa içi, URL, güvenlik, `wp_raw_url`)
- ✓ Zaman aşımı/yanıt vermeyen ayrımı, meta refresh, X-Robots-Tag noindex, canonical hedefi non-200/noindex
- ✓ Dış kırık link (taramada en fazla 500, botları engelleyen siteler hariç), kırık iç linkin kaynak sayfaları (bulgu ayrıntısında ilk 3), yetim sayfa (site haritasında olup iç link almayan)
- ✓ Görseller: alt eksik
- ✓ Görseller: büyük dosya (>100 KB, Screaming Frog varsayılanı), kırık görsel
- ✓ Görseller: boyut özniteliği (width/height) eksik, CLS riski
- ✓ Mimari: tek iç linkli sayfa
- ✓ Güvenlik: mixed content, HTTPS sayfadan HTTP'ye iç link
- ✓ Güvensiz form (http:// adrese gönderilen form, HTTP sayfada şifre alanı)
- ✓ WordPress temel: ek sayfaları, ince arşivler, birden fazla SEO eklentisi

**Ekranlar ve rapor**
- ✓ Tarama sonuçları: URL tablosu ve sorunlar ekranı
- ✓ Sağlık skoru (0-100): her tetiklenen kural önem ağırlığının %30'unu, kalanını etkilediği sayfa oranıyla orantılı düşürür
- ✓ Her URL'nin ayrıntı penceresi: başlıklar, gelen/giden linkler, bulgular
- ✓ CSV export (URL ve sorun bazında)
- ✓ Müşteri raporu: paylaşılabilir link (iptal edilebilir token) + PDF, Serhan Demirel imzası
- ✓ Sağlık skorunun nasıl hesaplandığı panelde (tarama sayfası) ve raporda açıklanır, puanı en çok düşüren sorunlar listelenir; raporda "Botlar tarafından taranabilirlik" bölümü (robots.txt, sitemap, engellenen/noindex/indekslenebilir sayfalar, derinlik, bot bazında erişim)

## 3. Faz 2 · Hızlı kazançlar

**WordPress dış kontroller (WP-1, [07 §2](07-wordpress-modulu.md))**
- ✓ Çekirdek sürümü ifşası ve güncellik (wordpress.org API)
- ✓ Eklenti/tema envanteri (HTML izlerinden) ve güncellik
- ✓ Kullanıcı adı ifşası (`?author=`, REST users), `xmlrpc.php`, varsayılan dosyalar, `debug.log`, dizin listeleme, yapılandırma yedekleri, HTTP giriş sayfası
- ✓ "Arama motorlarını engelle" açık, çift site haritası, iç arama indekslenebilir, `?replytocom`, varsayılan içerik, "Kategorilenmemiş"
- ✓ Sayfalama canonical'ı (Faz 5), SEOPress/The SEO Framework tespiti, staging sızıntısı (test alt alan adlarına veya barındırma firmasının test adresine link/canonical)
- ✓ Raporda "WordPress" bölümü: sürüm, eklenti/tema, güvenlik kontrol listesi

**AI arama ve yapısal veri temelleri (GEO-1)** — her taramada otomatik, ek istek neredeyse yok
- ✓ Arama motoru + AI botları erişim tablosu (19 bot): robots.txt'de GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, Bingbot, CCBot, Bytespider, Meta-ExternalAgent için izinli/engelli; eğitim botu ile arama botu ayrımı ve öneri
- ✓ Cloudflare/WAF "AI botlarını engelle" izi (bot user-agent'ıyla 403/challenge yanıtı)
- ✓ `llms.txt` varlığı ve biçim kontrolü (başlık, özet, bağlantı listesi)
- ✓ `llms-full.txt` varlığı, llms.txt içindeki linklerin durumu (ilk 50 iç link taranır; hata veren veya yönlenenler bulgu olur)
- ✓ JSON-LD çıkarma: sayfa başına şema türleri (URL tablosu ve CSV'de), bozuk JSON bulgusu
- ✓ Ana sayfada Organization/LocalBusiness/Person şeması yok bulgusu
- ✓ İçeriğin JS olmadan erişilebilirliği: ham HTML'deki metin miktarı (JS ile yüklenen içerik AI botlarının çoğunca okunmaz)
- ✓ Raporda "AI arama görünürlüğü" bölümü: bot türü başına erişim, llms.txt, kurum şeması, JSON-LD kapsamı, bot tablosu

**Erişilebilirlik izleyici (M-1, [08 §2](08-izleme-ve-bildirimler.md))**
- ✓ Dakikada bir kontrol (1/5/15 dk): durum kodu, zaman aşımı, DNS, SSL, beklenen kelime, yanıt süresi
- ✓ Yanlış alarm koruması (tekrar deneme + kanarya), kesinti kaydı ve süresi
- ✓ E-posta + Telegram: "düştü", "düzeldi (süre)", yavaş, SSL bitiyor (panelden ayar, test düğmesi)
- ✓ Saatlik "hâlâ kapalı" hatırlatması
- ✓ SSL bitiş uyarısı, dış kalp atışı servisi (uygulamanın kendisi için)
- ✓ Panelde 90 günlük uptime şeridi
- ✓ Kullanıcı başına bildirim: her üyenin kendi uyarı e-postası, kendi Telegram sohbeti (tek ortak bot) ve hangi uyarıları alacağı; gönderen posta kutusu ve bot anahtarı ortak

## 4. Faz 3 · GSC ve iş listesi (ana farklılaştırıcı)

**GSC bağlantısı ([04 §4.1](04-mvp-kapsami-ve-yol-haritasi.md))**
- ✓ Google OAuth (panelden tek hesap bağlama), proje başına property seçimi
- ✓ Search Analytics senkronu: sayfa × gün (10 dakikada bir, 16 aya kadar geriye parça parça) + sayfa × sorgu son 28 gün (günde bir, URL detayında gösterilir)
- ✓ URL Inspection: en çok tıklanan sayfalardan başlayarak sırayla (10 dakikada 20, günde en fazla 500, haftada bir yenilenir); URL listesinde "Google indeksi" sütunu, URL detayında Google canonical ve son tarama
- ✓ Sayfa tablosuna GSC sütunları: tıklama, gösterim, CTR, pozisyon (son 28 gün)

**GSC'den türeyen analizler ([04 §4.2](04-mvp-kapsami-ve-yol-haritasi.md))**
- ✓ Trafik alan sayfada teknik sorun (iş listesinde öncelik skoru etkilenen sayfaların tıklamasıyla yükselir)
- ✓ Yüksek gösterim / düşük CTR (pozisyona göre beklenen CTR'nin yarısının altı)
- ✓ Fırsat sorguları (pozisyon 8-20)
- ✓ Kannibalizasyon
- ✓ Düşen sayfalar, zombi sayfalar, yetim sayfalar (GSC'de var, iç linki yok) · proje başına "Search Console fırsatları" sayfası
- ✓ İndeks tutarsızlığı (Google canonical ≠ bizim, "taranmış, indekslenmemiş")

**İş listesi ([04 §4.3](04-mvp-kapsami-ve-yol-haritasi.md))**
- ✓ Bulgulardan ve GSC analizlerinden otomatik aksiyon, tekrar etmeyen güncelleme (her tarama sonunda ve günlük GSC senkronunda)
- ✓ Öncelik skoru (önem × etkilenen sayfa × trafik), "neden önce?" tek cümle açıklama
- ✓ Durumlar: açık → yapılıyor → tamamlandı → doğrulandı (otomatik); sorun geri gelirse yeniden açılır
- ✓ Not, son tarih, "müşteri raporunda göster" işareti; seçilen işler raporda "Çalışma planı" bölümünde (tamamlanan, devam eden, planlanan)
- ✓ Etki ölçümü: tamamlanınca etkilenen sayfalar kaydedilir, 28 gün sonra bu sayfaların tıklama/gösterimi önceki 28 günle karşılaştırılır (iş listesinde "Etki" sütunu, müşteri raporunda tamamlanan işin yanında)

**Segmentler (Oncrawl/JetOctopus fikri)**
- ✓ URL klasörüne göre otomatik segment (proje listesinde "Segmentler"); WordPress içerik türü (yazı, sayfa, ürün, arşivler, özel türler) ve kategoriye göre (temanın body/post class'larından)
- ✓ Segment bazında sağlık (önemli sorunsuz sayfa oranı), 28 gün tıklama/gösterim, sorunlu sayfa sayısı
- ✓ Derinlik × tıklama tablosu; iç link sayısı × pozisyon (gösterimle ağırlıklı ortalama pozisyon)

## 5. Faz 4 · Otomasyon, değişiklik takibi, raporlama

**Zamanlama ve karşılaştırma**
- ✓ Proje başına zamanlanmış tarama (günlük/haftalık/aylık); gece 01:00–06:00 arası başlar, devam eden tarama varsa başlamaz
- ✓ Otomatik tarama karşılaştırma: tarama sayfasında "Önceki taramaya göre değişenler" (yeni/kaybolan sayfalar, yeni/düzelen sorunlar, durum/title/meta/canonical/robots değişimleri, skor farkı)
- ✓ Kritik sayfa izleme (Lumar Protect fikri): proje başına en fazla 50 URL, saatlik/günlük; durum kodu, yönlendirme hedefi, noindex (meta + X-Robots-Tag), canonical, title değişirse ikinci okumayla doğrulanır, geçmişe yazılır ve e-posta/Telegram uyarısı gider ("Kritik sayfalar" menüsü)
- ✓ Sağlık skoru trendi: her tarama sonrası skor saklanır (eski taramalar silinse de kalır); proje sayfasında son 12 tarama, müşteri raporunda (web + PDF) "Genel sağlık" altında

**Bildirim merkezi (M-2, [08 §3](08-izleme-ve-bildirimler.md))**
- ✓ Tüm olay türleri tek yerde ("Bildirimler" menüsü, 180 gün saklanır): uptime, yavaşlık, SSL, kritik sayfa değişimi, taramada yeni yüksek önemli sorun, GSC tıklama düşüşü (7 gün / önceki 7 gün, %30+, en az 50 tıklama; haftada bir), tarama hatası. WordPress açığı ○ (WP-2 ile)
- ✓ Önem bazlı yönlendirme (kritik → Telegram + e-posta, yüksek → Telegram, yoksa e-posta, orta/düşük → yalnızca haftalık özet), aynı uyarı 6 saat içinde tekrar gönderilmez, aynı kontrolde 3+ site düşerse tek mesaj, kullanıcı başına sessiz saatler (kritikler yine gelir, diğerleri bitişte tek mesaj), pazartesi 09:00 haftalık özet e-postası

**Çoklu kullanıcı ve roller (U-1)** — giriş ve 2FA zaten kullanıcı başına (Faz 1)
- ✓ Panelden üye ekleme ("Ekip" sayfası), hesabı kapatma, 2FA sıfırlama; 2FA panelde zaten herkes için zorunlu
- ✓ Roller: yönetici (her şey), uzman (atandığı projeleri yönetir), salt okunur (atandığı projeleri görür)
- ✓ Proje bazında erişim (müşteri, tarama, iş, izleme listeleri de süzülür); uyarılar yalnızca erişilen projeler için; ortak ayarlar (Google, SMTP, bot) yalnızca yönetici
- ✓ Ana yönetici (ilk yönetici, Serhan) her şeyi görür; diğer yöneticiler yalnızca kendi ekledikleri ve atandıkları projeleri görür. Ekip sayfası ve ortak ayarlar (Google anahtarı, SMTP, bot) yalnızca ana yöneticide
- ✓ Her yönetici kendi Google hesabını bağlar (token kullanıcı başına, şifreli); site listesini yalnızca bağlayan görür; proje, mülkünü seçen yöneticinin bağlantısıyla okunur; günlük istek/URL denetimi bağlantı başına sayılır. Davetli üyeler Google bağlamaz, proje sahibinin bağlantısıyla gelen veriyi görür
- ✓ E-postayla davet (yeni kullanıcıya hesap açma + proje, mevcut kullanıcıya proje daveti; 7 gün geçerli, e-posta gitmezse bağlantı panelde gösterilir). Proje sayfasındaki "Ekip ve yetkiler" listesinden proje sahibi davet eder, yetkileri değiştirir, üyeyi çıkarır. Proje bazında yetkiler: tarama, iş listesi, rapor paylaşma, izleme, proje ayarları; görüntüleme herkese açık
- ✓ İşlem günlüğü ("İşlem günlüğü" menüsü, 1 yıl saklanır): üyelerin proje, müşteri, ekip, ortak ayar, iş, rapor, izleme, kritik sayfa ve tarama değişiklikleri; gizli değerler yazılmaz; arka plan işleri kaydedilmez. Onaylı WP değişiklikleri ○ (Faz 6)

**Raporlama**
- ✓ Markalı müşteri raporu: "Rapor görünümü" sayfasında logo (PNG/JPG/SVG, rapora gömülü), firma adı, vurgu rengi, iletişim satırı ve giriş metni; web, PDF ve aylık raporlarda geçerli. "Bir Serhan Demirel ürünü" satırı her zaman kalır
- ✓ Aylık rapor otomatik hazırlanır (ayın ilk haftası, son 45 günde taranan projeler, Telegram/e-posta ile "onay bekliyor" uyarısı); "Müşteri raporları" sayfasında önizle, onayla, müşteriye e-postayla gönder; istenirse ay içinde elle hazırlanır
- ✓ Rapor bölümleri: sağlık skoru ve trend, çalışma planı (yapılan işler ve 28 gün etkisi, planlananlar), Google arama performansı (28 gün tıklama/gösterim/CTR/sıra ve değişim, en çok tıklanan sayfalar ve aramalar), erişilebilirlik (30 gün çalışma oranı, kesinti sayısı/süresi, yanıt süresi), taranabilirlik, AI görünürlüğü, WordPress güvenliği
- ✓ Rapor arşivi: onaylanan raporun dondurulmuş kopyası saklanır (eski taramalar silinse de açılır); müşteri proje başına tek bağlantıdan tüm geçmiş raporları ve PDF'lerini görür

## 6. Faz 5 · Derin teknik denetim (Screaming Frog eşitliği)

Her madde bağımsız bir pakettir; talebe göre öne çekilir.

**Tarama yetenekleri**
- ◐ Staging tarama: basic auth ve en fazla 5 özel HTTP başlığı (çerez dahil) proje ayarlarında, şifreli saklanır ve yalnızca projenin alan adına gönderilir ✓; form girişi ○
- ✓ robots.txt test aracı ✓ ("robots.txt testi" menüsü: siteden getir veya elle düzenle, adres listesini seçilen botlarla dene, karar veren kuralı gör); tarama sırasında özel robots.txt ile simülasyon ✓ (proje ayarlarında "Deneme robots.txt")
- ○ JS render (sayfa bazlı, opsiyonel; render öncesi/sonrası farkı) **[VPS]**
- ○ JS yönlendirmelerini yakalama **[VPS]**

**İçerik**
- ✓ Yakın kopya: ana metnin (menü, header, footer hariç) SimHash parmak izi, en fazla 3 bit fark (~%95 benzer); binlerce sayfada bant eşleştirme ile hızlı. Birebir kopyalar ayrıca raporlanır
- ✓ Kaynak kodda metin/regex arama, custom extraction (XPath, regex): proje ayarlarında en fazla 5 kural (geçiyor/geçmiyor, regex veya XPath ile değer çıkarma); sonuçlar tarama sayfasında özet ve CSV'de sütun olarak; CSS seçici ✓
- ✓ H2 ve başlık hiyerarşisi: atlanan başlık seviyesi (H2 → H4), uzun sayfada H2 yok
- ✓ Türkçe okunabilirlik: Ateşman formülü (paragraf ve liste metni, en az 100 kelime, yalnızca Türkçe sayfalar); 50 altı "zor" bulgusu, puan sayfa listesinde, sayfa detayında ve CSV'de

**İndekslenebilirlik ve uluslararası**
- ✓ hreflang: karşılıklı (dönüş) etiket, ISO dil/bölge kodu, kendini listeleme, x-default, hatalı/noindex/başka canonical hedef; site içindeki dil sürümleri de taranır
- ✓ Canonical zincirleri, HTTP header canonical (HTML yoksa kullanılır), çelişen canonical (birden fazla etiket veya HTML ↔ başlık)
- ✓ Sayfalama: rel next/prev sayfaları taranır; 2. ve sonraki sayfaların ilk sayfaya canonical vermesi, hatalı sonraki/önceki sayfa ve yalnızca etiketle bildirilip normal linki olmayan sayfalama bulgu olur

**Linkleme ve mimari**
- ✓ Anchor text analizi: metinsiz linkler ve "buraya tıklayın", "devamı" türü anlamsız metinler sayfa bazında bulgu olur (görsel alt metni ve aria-label link metni sayılır)
- ✓ İç Link Score: site içi PageRank (nofollow geçmez, yönlendirme hedefe aktarır), 1-100 logaritmik; sayfa listesinde, sayfa detayında ve CSV'de
- ✓ Taranamayan linkler (`href="javascript:…"`, `onclick` ile açılan `href`'siz linkler; menü açma düğmeleri sayılmaz)
- ✓ Dizin bazlı derinlik ve dağılım: tarama sayfasında "Dizin dağılımı" tablosu (sayfa, indekslenebilir, hatalı, ortalama/en fazla derinlik, ortalama link puanı, bulgu)

**Yapısal veri**
- ✓ JSON-LD, Microdata ve RDFa (`vocab`/`typeof`, `schema:` öneki) türleri, site geneli "Yapısal veri envanteri" tablosu (tür, sayfa sayısı, eksik alanlı sayfalar)
- ✓ Schema.org sözlüğüne göre doğrulama (resmi sürüm 29.3): bilinmeyen tür/özellik, türüne ait olmayan özellik, metin beklenen yerde nesne; eksik zorunlu alanlar Google kurallarıyla ayrıca
- ✓ Google zengin sonuç gereksinimleri: Article (+ BlogPosting, NewsArticle), Product (+ Offer, Review, AggregateRating), BreadcrumbList/ListItem, FAQPage/Question/Answer, Recipe, Event, LocalBusiness (+ Dentist, MedicalClinic vb. alt türler), Organization, VideoObject, JobPosting; zorunlu eksik (orta) ve önerilen eksik (düşük, yalnızca ana şemalarda) ayrı bulgu. HowTo Google'da kaldırıldığı için yok
- ✓ Şema ↔ sayfa tutarlılığı: makale başlığı / ürün adı sayfanın title veya H1'ine benziyor mu ✓, teklif fiyatı sayfada yazıyor mu (1.500,00 ve 1,500.00 biçimleri) ✓, sayfa içi kırık `@id` bağlantıları ✓, tarih karşılaştırması (şemadaki yayın/güncelleme tarihi sayfadaki meta ve `<time>` ile aynı mı, ters veya ileri tarih) ✓
- ✓ Yinelenen ana şemalar (örn. hem Yoast hem tema Organization basıyor); çelişen değerler ○
- ✓ WordPress: SEO eklentisinin şema grafiği (Yoast/Rank Math) kontrolü — ana sayfada WebSite düğümü veya kurum logosu yoksa bulgu

**Performans**
- ✓ PageSpeed Insights API + CrUX: her taramadan sonra 5 örnek sayfa (ana sayfa, her içerik türünün en güçlü sayfası, sonra Link Score), mobil; gerçek kullanıcı LCP/INP/CLS ve Lighthouse puanı bulguya dönüşür. Anahtarsız çalışır, isteğe bağlı API anahtarı Google ayarlarında
- ✓ CWV trendi ve şablon bazlı sorunlar: son 8 taramada ortalama mobil puan, ana sayfa gerçek kullanıcı metrikleri ve kötü sayfa sayısı; örnek sayfalar türleriyle (ana sayfa, yazı, ürün…) gösterilir

**Güvenlik**
- ✓ Güvenlik başlıkları (ana sayfada HSTS ve max-age, CSP, X-Content-Type-Options, X-Frame-Options veya CSP frame-ancestors, Referrer-Policy; Mozilla Observatory ölçütü)

**AI arama görünürlüğü: GEO (generative engine) ve AEO (answer engine) denetimi** — GEO-1'in üzerine
- ◐ Varlık (entity) sinyalleri: ana sayfa kurum şemasında `sameAs` yok ✓; şemadaki kurum adı/telefonun sayfalar arasında tutarlılığı ve telefonun sayfada görünmesi ✓; adres karşılaştırması ○ — Organization/Person şeması, `sameAs` (Wikipedia, Wikidata, LinkedIn, sosyal), tutarlı marka adı/NAP
- ◐ E-E-A-T sinyalleri: yazılarda tarih ve yazar eksikliği ✓, hakkımızda/iletişim/gizlilik-KVKK sayfası yokluğu ✓, yazar sayfasına link yok ✓, yazar sayfasında Person/ProfilePage şeması yok ✓ — yazar kutusu ve yazar sayfası, Person şeması, yayın/güncelleme tarihi (görünür + `datePublished`/`dateModified`), hakkımızda/iletişim/politika sayfaları
- ✓ Alıntılanabilirlik (AEO) — soru başlığının altında 20-60 kelimelik cevap yoksa, 800+ kelimede liste/tablo yoksa bulgu; SSS bölümü ve rakamlı ifadeler sayfa detayında: soru biçimli başlıklar, başlığın hemen altında kısa doğrudan cevap paragrafı, liste/tablo kullanımı, SSS bölümü, tanım cümleleri
- ◐ Bölümleme ve okunabilirlik: başlık sırası ✓, 150 kelimeyi aşan paragraflar ✓, soru biçimli başlıklar sayılıyor (AI hazırlık skoruna girecek) — başlık hiyerarşisi, paragraf uzunluğu, anlamlı alt başlıklarla "parçalanabilir" içerik (LLM'lerin pasaj seçimi için)
- ✓ Kaynak ve veri sinyalleri: 500+ kelimelik yazıda dış kaynak linki yoksa bulgu ✓, rakamlı ifadeler sayılıyor ✓, alıntılar (en az 5 kelimelik blockquote/q, sayfa detayında) ✓ — dış kaynak linkleri, istatistik/rakam içeren cümleler, alıntılar
- ✓ Tazelik: 1 yıldan eski ama son 28 günde tıklama alan yazılar (GSC bağlıysa) — eski tarihli ama trafiği olan sayfalar (GSC ile), "son güncelleme" eksikliği
- ✓ AI botlarının gerçekten aldığı içerik: ana sayfa her bot kimliğiyle istenir, normal ziyaretçinin yarısından az metin alan botlar (doğrulama sayfası, cloaking) bulgu olur — AI bot user-agent'larıyla örnek sayfa çekip içerik farkı (cloaking/engel tespiti)
- ✓ Sayfa başına "AI hazırlık skoru" (0-100: JS'siz okunur metin, şema ve zorunlu alanlar, soru başlıkları/SSS, H2 düzeni, kısa paragraflar, içerik derinliği, yazılarda tarih ve yazar); sayfa listesinde sütun, sayfa detayında madde madde, müşteri raporunun AI görünürlüğü bölümünde site ortalaması

**Araçlar**
- ✓ XML ve görsel sitemap üretme: tarama sayfasında "Dışa aktar → Site haritası (XML)"; yalnızca indekslenebilir sayfalar, lastmod ve çalışan görseller (en fazla 50.000 URL)
- ✓ Staging ↔ production URL eşleme ve karşılaştırma ("Site karşılaştırma" menüsü): iki taramayı URL yoluna göre eşler; staging'de olmayan canlı sayfalar, yalnızca staging'de olanlar ve durum kodu, indekslenebilirlik, title, description, H1, canonical, meta robots farkları
- ✓ Site göçü denetimi: "Taşıma kontrolleri" sayfasına eski URL listesi (isteğe bağlı yanında beklenen yeni adres, Excel'den iki sütun) yapıştırılır; her adres yönlendirmeleriyle izlenir (dakikada 15 adres, en fazla 1.000): doğru, zincir, geçici, yanlış hedef, hata, döngü

## 7. Faz 6 · WordPress bağlantı eklentisi ([07 §3](07-wordpress-modulu.md))

**WP-2 · Salt okunur**
- ○ Eşleştirme ekranı (bağlantı kodu), HMAC imzalı istekler
- ○ Tam envanter: çekirdek, eklentiler, temalar, aktif/pasif, güncelleme durumu
- ○ Açık veritabanı eşleştirmesi (Wordfence Intelligence feed, günlük)
- ○ Ortam ve Site Sağlığı, kritik ayarlar (`blog_public`, `WP_DEBUG`, dosya düzenleyici, otomatik güncelleme)
- ○ Yoast/Rank Math alanları, taslak/zamanlanmış içerik, çekirdek dosya bütünlüğü
- ○ Önbellek eklentisi tespiti, gereksiz baş etiketleri

**WP-3 · Olaylar ve onaylı yazma**
- ○ İçerik yayınlandı/güncellendi → ilgili URL'yi öncelikli yeniden kontrol
- ○ Onaylı değişiklikler: SEO title/meta, noindex, 301 yönlendirme, görsel alt metni
- ○ Değişiklik günlüğü, çakışma koruması, tek tıkla geri alma
- ○ Eklentinin kendi güncelleme kontrolü
- ○ AI önerilerinin uygulayıcısı: Faz 7'de üretilen ve panelde onaylanan title/meta, alt metin, şema, SSS/kısa cevap bloğu, iç link önerilerini siteye yazar (LLM anahtarı eklentide değil, uygulamada durur)

## 8. Faz 7 · AI ve ileri analiz

**AI katmanı: çoklu LLM bağlantısı (AI-0)**
- ○ Ayarlar → AI sağlayıcıları: Anthropic (Claude), OpenAI, Google Gemini, Mistral ve OpenAI uyumlu uç noktalar (OpenRouter, yerel model); anahtarlar şifreli saklanır, istenince değiştirilir
- ○ Görev bazlı model seçimi (ör. toplu alt metin için ucuz model, rapor özeti için güçlü model), varsayılan ve yedek sağlayıcı
- ○ Bütçe ve kota: aylık harcama sınırı, proje başına kullanım, istek önbelleği (aynı sayfaya aynı soruyu tekrar sormaz)
- ○ Tüm AI çıktıları öneridir: panelde onay/ret, düzenleme, kim neyi onayladı kaydı

**AI kalite yorumu (AI-1)** — kontrollerin yalnızca varlığını değil kalitesini değerlendirir
- ○ Title/meta: arama niyetine uygunluk, tıklanabilirlik, marka/anahtar kelime dengesi
- ○ Alt metin: görselle ve bağlamla uyum (görsel modeli)
- ○ Şema: içerikle tutarlılık, eksik ama eklenmesi gereken türler
- ○ GEO/AEO: alıntılanabilirlik, doğrudan cevap kalitesi, E-E-A-T eksikleri, llms.txt içeriğinin yeterliliği
- ○ İçerik: ince/yinelenen içerik, okunabilirlik, güncellik yorumu
- ○ Her bulguya "neden önemli + somut öneri" metni, müşteri raporuna da aktarılabilir

**AI destekli aksiyonlar (AI-0 üzerinden, uygulamada çalışır)**
- ○ Title/meta yeniden yazım önerisi (GSC sorgularıyla), toplu inceleme ekranı
- ○ Eksik alt metni önerisi (görsel + bağlam)
- ○ İç link önerici (zayıf sayfalara, GSC fırsat sorgularıyla eşleşen kaynaklardan)
- ○ WordPress'te onaylı uygulama (WP-4) ve GSC ile etki ölçümü
- ○ Bulgu açıklamalarını müşteri diline çeviren rapor özeti

**AI görünürlük ölçümü (AEO-2)**
- ○ Belirlenen sorgular için ChatGPT, Perplexity, Google AI Overviews / AI Mode, Claude cevaplarında sitenin alıntılanması ve marka geçişi takibi (resmî API'lerle, kullanım şartlarına uygun)
- ○ Rakip karşılaştırması: aynı sorgularda kimin alıntılandığı
- ○ GA4 / sunucu logunda AI kaynaklı ziyaret ve bot trafiği (chatgpt.com, perplexity.ai yönlendirmeleri)
- ○ Claude API ile sayfa bazlı "LLM okunabilirlik" değerlendirmesi ve somut yeniden yazım önerisi

**İleri analiz**
- ○ Semantik benzerlik ve içerik kümeleri (embedding), konu dışı içerik tespiti
- ○ Log dosyası analizi: Googlebot ve AI botlarının gerçek tarama davranışı, crawl budget israfı **[VPS]** (erişim logu gerekir)
- ○ GA4 entegrasyonu (dönüşüm ve etkileşim bağlamı)
- ○ Erişilebilirlik (axe, WCAG) **[VPS]**
- ○ Site mimarisi görselleştirme (ağaç / kuvvet yönlü grafik)
- ○ Google Sheets'e otomatik export
- ○ MCP sunucusu: ScreamingRabbit'i Claude'dan doğal dille kullanma
- ○ Diğer platform modülleri (Shopify vb.), talep olursa

## 9. Bilinçli olarak kapsam dışı

| Özellik | Neden |
|---|---|
| Ahrefs / Majestic / Moz / Semrush backlink ve anahtar kelime hacmi | Lisanslı, ücretli API; GSC daha bağlamsal |
| AMP doğrulama | AMP büyük ölçüde terk edildi |
| CLI | Web paneli ve API yeterli |
| Looker Studio raporları | Kendi müşteri raporumuz var |
| Karbon ayak izi | Müşteri değeri düşük |
| Yazım/dilbilgisi denetimi | AI önerileriyle kısmen karşılanır; ayrı modül değmez |

## 10. Screaming Frog'a karşı kapsama özeti

| Screaming Frog alanı ([01](01-screaming-frog-ozellik-envanteri.md)) | Bizde |
|---|---|
| 3.1 Tarama motoru ve konfigürasyon | Faz 1 (temel), Faz 5 (auth, özel başlık, JS render) |
| 3.2 Yanıt kodları ve yönlendirmeler | Faz 1 |
| 3.3 Sayfa içi öğeler | Faz 1, Faz 5 (başlık hiyerarşisi, okunabilirlik) |
| 3.4 İndekslenebilirlik ve direktifler | Faz 1, Faz 5 (hreflang) |
| 3.5 Site mimarisi ve linkleme | Faz 1 (derinlik), Faz 3 (yetim), Faz 5 (anchor, Link Score) |
| 3.6 Kopya ve benzer içerik | Faz 1 (birebir), Faz 5 (yakın), Faz 7 (semantik) |
| 3.7 Güvenlik ve teknik | Faz 1, Faz 2 (WordPress), Faz 5 (başlıklar) |
| 3.8 Yapısal veri | Faz 2 (çıkarma, temel), Faz 5 (doğrulama, zengin sonuç) |
| 3.9 Performans, mobil, erişilebilirlik | Faz 5 (PSI/CrUX), Faz 7 (axe) |
| 3.10 Özel arama, extraction | Faz 5 |
| 3.11 Entegrasyonlar | Faz 3 (GSC), Faz 5 (PSI), Faz 7 (GA4, AI, MCP) |
| 3.12 Sitemap ve robots araçları | Faz 1 (analiz), Faz 5 (üretim, test) |
| 3.13 Otomasyon, karşılaştırma, raporlama | Faz 4 |

**Screaming Frog'da hiç olmayanlar:** GSC trafiğiyle öncelik ve iş listesi (Faz 3), önce/sonra etki ölçümü (Faz 3), bilgisayardan bağımsız 7/24 zamanlama (Faz 4), dakikalık uptime izleme ve Telegram uyarısı (Faz 2), WordPress güvenlik denetimi ve site içi eklenti (Faz 2, 6), AI ile onaylı düzeltme uygulama (Faz 7), Türkçe markalı müşteri raporu (Faz 1, 4), GEO/AEO denetimi ve AI görünürlük ölçümü (Faz 2, 5, 7).
