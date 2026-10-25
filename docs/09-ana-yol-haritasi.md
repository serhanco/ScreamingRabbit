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
| 1 | Temel teknik denetim (MVP) | Teknik SEO denetim raporu | ◐ sürüyor |
| 2 | Hızlı kazançlar: WordPress dış kontroller + uptime izleyici | WordPress güvenlik/SEO kontrolü, 7/24 erişilebilirlik takibi | ○ |
| 3 | GSC ve iş listesi | Trafiğe göre öncelikli aksiyon planı, kannibalizasyon/zombi sayfa analizi | ○ |
| 4 | Otomasyon, değişiklik takibi, raporlama | Aylık otomatik rapor, "neyi düzelttik, ne bozuldu" | ○ |
| 5 | Derin teknik denetim (Screaming Frog eşitliği) | Yapısal veri, hreflang, Core Web Vitals, AI arama hazırlığı denetimi | ○ |
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
- ○ Liste modu (URL listesi yapıştır/yükle) ve yalnızca sitemap modu
- ○ Include/exclude (regex), user-agent seçimi (Googlebot, Bingbot, AI botları, özel)
- ○ Tarama ilerleme göstergesi, durdur/devam et, eski taramaları otomatik silme (retention)
- ○ 50.000 URL hedefi için performans testi

**Kurallar** (✓ mevcut 44 kural: yanıt, yönlendirme, indekslenebilirlik, sayfa içi, URL, güvenlik, `wp_raw_url`)
- ✓ Zaman aşımı/yanıt vermeyen ayrımı, meta refresh, X-Robots-Tag noindex, canonical hedefi non-200/noindex
- ○ Dış kırık link ve kırık iç linkin kaynak sayfa listesi, sitemapte olup taranmayan URL
- ✓ Görseller: alt eksik
- ○ Görseller: büyük dosya (>200 KB), kırık görsel, boyut özniteliği eksik
- ✓ Mimari: tek iç linkli sayfa
- ✓ Güvenlik: mixed content, HTTPS sayfadan HTTP'ye iç link
- ○ Güvensiz form
- ✓ WordPress temel: ek sayfaları, ince arşivler, birden fazla SEO eklentisi

**Ekranlar ve rapor**
- ◐ Tarama sonuçları: URL tablosu ve sorunlar ekranı
- ✓ Sağlık skoru (0-100): her tetiklenen kural önem ağırlığının %30'unu, kalanını etkilediği sayfa oranıyla orantılı düşürür
- ○ Her URL'nin ayrıntı sayfası: başlıklar, gelen/giden linkler, bulgular
- ○ CSV export (done) (tablo ve sorun bazında)
- ○ Müşteri raporu: paylaşılabilir link (iptal edilebilir token) + PDF

## 3. Faz 2 · Hızlı kazançlar

**WordPress dış kontroller (WP-1, [07 §2](07-wordpress-modulu.md))**
- ○ Çekirdek sürümü ifşası ve güncellik (wordpress.org API)
- ○ Eklenti/tema envanteri (HTML izlerinden) ve güncellik
- ○ Kullanıcı adı ifşası (`?author=`, REST users), `xmlrpc.php`, varsayılan dosyalar, `debug.log`, dizin listeleme, yapılandırma yedekleri, HTTP giriş sayfası
- ○ "Arama motorlarını engelle" açık, SEO eklentisi tespiti, çift site haritası, iç arama indekslenebilir, `?replytocom`, sayfalama canonical'ı, varsayılan içerik, "Kategorilenmemiş", staging sızıntısı
- ○ Raporda "WordPress" bölümü

**Erişilebilirlik izleyici (M-1, [08 §2](08-izleme-ve-bildirimler.md))**
- ○ Dakikada bir kontrol: durum kodu, zaman aşımı, DNS, SSL, beklenen kelime, yanıt süresi
- ○ Yanlış alarm koruması (tekrar deneme + kanarya), kesinti kaydı ve süresi
- ○ E-posta + Telegram: "düştü", "düzeldi (süre)", saatlik hatırlatma
- ○ SSL bitiş uyarısı, dış kalp atışı servisi (uygulamanın kendisi için)
- ○ Panelde 90 günlük uptime şeridi

## 4. Faz 3 · GSC ve iş listesi (ana farklılaştırıcı)

**GSC bağlantısı ([04 §4.1](04-mvp-kapsami-ve-yol-haritasi.md))**
- ○ Google OAuth, proje başına property seçimi
- ○ Search Analytics günlük senkron (sayfa × sorgu × gün, 16 aya kadar geçmiş)
- ○ URL Inspection: öncelikli sayfalar için sırayla (günlük 2.000 kota)
- ○ Sayfa tablosuna GSC sütunları: tıklama, gösterim, CTR, pozisyon

**GSC'den türeyen analizler ([04 §4.2](04-mvp-kapsami-ve-yol-haritasi.md))**
- ○ Trafik alan sayfada teknik sorun (öncelik yükseltme)
- ○ Yüksek gösterim / düşük CTR
- ○ Fırsat sorguları (pozisyon 8-20)
- ○ Kannibalizasyon
- ○ Düşen sayfalar, zombi sayfalar, yetim sayfalar (GSC'de var, iç linki yok)
- ○ İndeks tutarsızlığı (Google canonical ≠ bizim, "taranmış, indekslenmemiş")

**İş listesi ([04 §4.3](04-mvp-kapsami-ve-yol-haritasi.md))**
- ○ Bulgulardan ve GSC analizlerinden otomatik aksiyon, tekrar etmeyen güncelleme
- ○ Öncelik skoru (önem × etkilenen sayfa × trafik), "neden önce?" tek cümle açıklama
- ○ Durumlar: açık → yapılıyor → tamamlandı → doğrulandı (otomatik)
- ○ Not, son tarih, "müşteri raporunda göster"
- ○ Etki ölçümü: tamamlanınca GSC anlık görüntüsü, 28 gün sonra önce/sonra

**Segmentler (Oncrawl/JetOctopus fikri)**
- ○ URL desenine göre (`/blog/`, `/urun/`) ve WordPress post type/kategoriye göre segment
- ○ Segment bazında sağlık skoru, trafik, sorun dağılımı
- ○ Çapraz grafikler: derinlik × tıklama, iç link sayısı × pozisyon

## 5. Faz 4 · Otomasyon, değişiklik takibi, raporlama

**Zamanlama ve karşılaştırma**
- ○ Proje başına zamanlanmış tarama (günlük/haftalık/aylık)
- ○ Otomatik tarama karşılaştırma: yeni/kaybolan sayfalar, yeni/düzelen sorunlar, title/meta/canonical/durum değişimleri
- ○ Kritik sayfa izleme (Lumar Protect fikri): seçili 5-50 URL saatlik/günlük (durum, noindex, canonical, title)
- ○ Sağlık skoru trendi

**Bildirim merkezi (M-2, [08 §3](08-izleme-ve-bildirimler.md))**
- ○ Tüm olay türleri tek yerde: uptime, SSL, kritik sayfa değişimi, yeni kritik bulgu, GSC düşüşü, WordPress açığı, sistem hataları
- ○ Önem bazlı yönlendirme, tekrar bastırma, toplu kesinti birleştirme, sessiz saatler, haftalık özet

**Raporlama**
- ○ Markalı müşteri raporu (Serhan'ın logosu ve renkleri)
- ○ Aylık rapor otomatik hazırlanır, Serhan onaylar ve gönderir
- ○ Rapor bölümleri: sağlık skoru ve trend, yapılan işler ve etkisi, açık öncelikli aksiyonlar, GSC özeti, erişilebilirlik, WordPress güvenliği
- ○ Rapor arşivi (müşteri geçmiş raporları linkten görür)

## 6. Faz 5 · Derin teknik denetim (Screaming Frog eşitliği)

Her madde bağımsız bir pakettir; talebe göre öne çekilir.

**Tarama yetenekleri**
- ○ Özel HTTP başlıkları ve çerezler, basic auth ve form girişiyle staging tarama
- ○ Özel robots.txt ile simülasyon, robots.txt test aracı
- ○ JS render (sayfa bazlı, opsiyonel; render öncesi/sonrası farkı) **[VPS]**
- ○ JS yönlendirmelerini yakalama **[VPS]**

**İçerik**
- ○ Yakın kopya (minhash, benzerlik %)
- ○ Kaynak kodda metin/regex arama, custom extraction (XPath, CSS, regex)
- ○ H2 ve başlık hiyerarşisi (sırasız başlıklar)
- ○ Türkçe uyarlanmış okunabilirlik

**İndekslenebilirlik ve uluslararası**
- ○ hreflang: dönüş etiketi, dil/bölge kodu, non-200 hedef, x-default
- ○ Canonical zincirleri, HTTP header canonical
- ○ Sayfalama (rel next/prev bilgilendirme)

**Linkleme ve mimari**
- ○ Anchor text analizi (tekil ve toplu, "buraya tıklayın" türü zayıf metinler)
- ○ İç Link Score (PageRank benzeri)
- ○ Taranamayan linkler (`onclick`, `javascript:`, `href`'siz)
- ○ Dizin bazlı derinlik ve dağılım

**Yapısal veri**
- ○ JSON-LD / Microdata / RDFa çıkarma
- ○ Schema.org doğrulama ve Google zengin sonuç gereksinimleri

**Performans**
- ○ PageSpeed Insights API + CrUX: LCP, INP, CLS (ücretsiz API, şablon başına örnek sayfa)
- ○ CWV trendi ve şablon bazlı sorunlar

**Güvenlik**
- ○ Güvenlik başlıkları (HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy)

**AI arama (GEO) hazırlığı**
- ○ robots.txt'de GPTBot, ClaudeBot, PerplexityBot, Google-Extended vb. durumu
- ○ `llms.txt` varlığı ve geçerliliği
- ○ İçeriğin JS olmadan erişilebilirliği
- ○ Organization / Article / FAQ / Product şema kapsamı, yazar ve tarih sinyalleri

**Araçlar**
- ○ XML ve görsel sitemap üretme
- ○ Staging ↔ production URL eşleme ve karşılaştırma
- ○ Site göçü denetimi: eski URL listesi → yeni sitede yönlendirme kontrolü

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

## 8. Faz 7 · AI ve ileri analiz

**AI destekli aksiyonlar (Claude API, uygulamada çalışır)**
- ○ Title/meta yeniden yazım önerisi (GSC sorgularıyla), toplu inceleme ekranı
- ○ Eksik alt metni önerisi (görsel + bağlam)
- ○ İç link önerici (zayıf sayfalara, GSC fırsat sorgularıyla eşleşen kaynaklardan)
- ○ WordPress'te onaylı uygulama (WP-4) ve GSC ile etki ölçümü
- ○ Bulgu açıklamalarını müşteri diline çeviren rapor özeti

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
| 3.8 Yapısal veri | Faz 5 |
| 3.9 Performans, mobil, erişilebilirlik | Faz 5 (PSI/CrUX), Faz 7 (axe) |
| 3.10 Özel arama, extraction | Faz 5 |
| 3.11 Entegrasyonlar | Faz 3 (GSC), Faz 5 (PSI), Faz 7 (GA4, AI, MCP) |
| 3.12 Sitemap ve robots araçları | Faz 1 (analiz), Faz 5 (üretim, test) |
| 3.13 Otomasyon, karşılaştırma, raporlama | Faz 4 |

**Screaming Frog'da hiç olmayanlar:** GSC trafiğiyle öncelik ve iş listesi (Faz 3), önce/sonra etki ölçümü (Faz 3), bilgisayardan bağımsız 7/24 zamanlama (Faz 4), dakikalık uptime izleme ve Telegram uyarısı (Faz 2), WordPress güvenlik denetimi ve site içi eklenti (Faz 2, 6), AI ile onaylı düzeltme uygulama (Faz 7), Türkçe markalı müşteri raporu (Faz 1, 4).
