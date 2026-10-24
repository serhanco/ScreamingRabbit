# 04 · Konumlandırma, MVP Kapsamı ve Yol Haritası

> Durum tarihi: 24 Ekim 2026
> Bu doküman [01](01-screaming-frog-ozellik-envanteri.md), [02](02-rakip-analizi.md) ve [03](03-karsilastirma-matrisi.md) dokümanlarının sonucudur. Teknik mimari (dil, veritabanı, kuyruk, sunucu) **2. adımda** ayrı bir dokümanda ele alınacak. Burada yalnızca "ne" yapılacağı var, "nasıl" yapılacağı yok.

## 1. Konumlandırma

**ScreamingRabbit, SEO danışmanının müşteri sitelerini kendi sunucusunda sürekli denetleyen ve bulguları GSC trafiğine göre önceliklendirilmiş bir iş listesine çeviren web uygulamasıdır.**

Kullanıcı: Serhan (tek operatör). Müşteri siteleri: çoğunlukla WordPress, GSC erişimi mevcut. SaaS hedefi yok.

Farklılaştırıcılar (öncelik sırasıyla):

1. **Sorun listesi değil, iş listesi.** Her bulgu bir aksiyona dönüşür: ne, nerede, neden, nasıl, ne kadar önemli. Durumu var (açık → yapılıyor → tamamlandı → doğrulandı).
2. **GSC bağlamı.** Öncelik, sayfanın gerçek trafiğiyle ağırlıklandırılır. GSC'ye özgü fırsatlar (kannibalizasyon, düşen sayfalar, yüksek gösterim / düşük CTR) ayrı aksiyonlar üretir.
3. **Kendiliğinden çalışır.** Tarama ve kontroller VPS'te zamanlanır. Değişiklik olunca e-posta gelir. Bilgisayarın açık olması gerekmez.
4. **Değeri kanıtlar.** Tamamlanan aksiyonun önce/sonra GSC etkisi müşteri raporunda görünür.
5. **WordPress'i tanır** ve **Türkçe konuşur.**

## 2. Temel kavramlar (veri modeli taslağı)

| Kavram | Açıklama |
|---|---|
| **Müşteri** | Serhan'ın hizmet verdiği firma |
| **Proje (Site)** | Bir müşterinin bir web sitesi (domain + GSC property bağlantısı + tarama ayarları) |
| **Tarama (Crawl)** | Bir projenin belirli bir andaki taraması. Sayfalar, linkler ve yanıtlar burada saklanır. |
| **Kural (Check)** | Tek bir denetim. Örn. "title eksik". Kimliği, kategorisi, önem derecesi, Türkçe açıklaması ve çözüm önerisi vardır. |
| **Bulgu (Issue)** | Bir kuralın bir taramada tetiklendiği URL'ler |
| **Aksiyon (Task)** | Bulgulardan ya da GSC analizinden türetilen iş kalemi. Öncelik skoru, durum, not ve gerekirse müşteri görünürlüğü vardır. |
| **Zamanlama (Schedule)** | Tarama veya kontrol paketinin ne sıklıkla çalışacağı |
| **Rapor** | Belirli bir tarih aralığı için müşteriye verilecek çıktı |

### Öncelik skoru (ilk öneri)

```
öncelik = önem_ağırlığı × etkilenen_sayfa_faktörü × trafik_faktörü
```

- `önem_ağırlığı`: kritik 10 · yüksek 5 · orta 2 · düşük 1
- `etkilenen_sayfa_faktörü`: log₂(etkilenen URL sayısı + 1)
- `trafik_faktörü`: GSC bağlı değilse 1. Bağlıysa etkilenen URL'lerin son 28 gün tıklama + gösterim payına göre 1 ile 5 arası.

Basit ve açıklanabilir olsun diye bilinçli olarak sade tutuldu. Müşteriye "bu neden önce?" sorusunun cevabı tek cümle olmalı.

## 3. MVP kapsamı (3. adım)

Hedef: Serhan'ın bir müşteri sitesini tarayıp **Screaming Frog'u açmadan** temel teknik denetimi yapabilmesi ve müşteriye rapor verebilmesi.

### 3.1 Uygulama

- Tek kullanıcılı giriş (Serhan). Müşteri ve proje yönetimi.
- Proje başına tarama ayarları: başlangıç URL'si, include/exclude, max URL, hız (eşzamanlılık + gecikme), user-agent, robots.txt'ye uyma.
- Tarama modları: spider, URL listesi, sitemap.
- **Hedef ölçek:** proje başına 50.000 URL'ye kadar sorunsuz tarama (binlerce sayfalık siteler rahatça). Tarama arka planda çalışır, panel ilerlemeyi gösterir.
- Tarama sonuçları kalıcıdır. Önceki taramalar açılabilir.
- URL tablosu: filtrelenebilir, aranabilir, CSV/XLSX export.
- Sorunlar ekranı: kategoriye ve önem derecesine göre gruplu, her kuralın açıklaması ve çözüm önerisiyle.
- Basit sağlık skoru (0-100).
- Basit müşteri raporu: **paylaşılabilir web linki** (giriş gerektirmeyen, tahmin edilemez token'lı URL; istenirse iptal edilebilir) + aynı sayfadan **PDF export**. İçerik: özet skor, kategori başına sorun sayısı, ilk 10 öncelikli aksiyon.

### 3.2 MVP kural kataloğu (ilk sürüm, ~45 kural)

**Yanıt ve yönlendirme**
- 4xx sayfa · 5xx sayfa · yanıt vermeyen URL · zaman aşımı
- İç kırık link (kaynak sayfayla) · dış kırık link
- 3xx'e işaret eden iç link · yönlendirme zinciri (≥2) · yönlendirme döngüsü
- 302/307 (geçici) yönlendirme · meta refresh

**İndekslenebilirlik**
- noindex sayfa (iç linkli) · X-Robots-Tag noindex
- robots.txt ile engellenen iç URL · canonical eksik
- canonical başka URL'ye işaret ediyor · canonical hedefi non-200 / noindex
- sitemapte olup indekslenemez URL · sitemapte olup taranmayan URL · taranıp sitemapte olmayan indekslenebilir URL

**Sayfa içi**
- title eksik / kopya / çok uzun (>60 karakter veya ~580px) / çok kısa
- meta description eksik / kopya / çok uzun / çok kısa
- H1 eksik / çoklu / kopya
- düşük kelime sayısı (<200, ayarlanabilir)
- birebir kopya içerik (hash)

**Görseller**
- alt eksik · büyük dosya (>200 KB) · kırık görsel

**Mimari ve URL**
- crawl depth > 4 · tek iç linkli sayfa
- URL'de büyük harf / alt çizgi / boşluk / non-ASCII · çok uzun URL · parametreli URL'ler

**Güvenlik**
- HTTP sayfa · mixed content · HTTPS'te HTTP'ye iç link

**WordPress temel**
- WordPress tespiti (generator, `/wp-content/`, `/wp-json/`)
- Ek (attachment) sayfaları indekslenebilir
- `?p=` / `?page_id=` gibi ham URL'ler canonical'sız erişilebilir
- Etiket / yazar / tarih arşivleri indekslenebilir ve ince içerikli
- `/wp-json/` veya `xmlrpc.php` erişim kontrolü (bilgilendirme)
- Birden fazla SEO eklentisi izi (Yoast + RankMath çakışması)

### 3.3 MVP'de olmayanlar (bilinçli)

JS render, GSC, zamanlama, karşılaştırma, hreflang, yapısal veri, PSI. Hepsi 4. adımda veya sonrasında gelecek.

## 4. 4. adım kapsamı: GSC, aksiyon listesi ve otomasyon

### 4.1 GSC entegrasyonu

- Google OAuth ile bağlantı. Proje başına bir GSC property.
- **Search Analytics:** Sayfa ve sorgu bazında tıklama, gösterim, CTR, pozisyon (16 aya kadar). Günlük senkron.
- **URL Inspection:** Kritik sayfalar için indeks durumu, Google'ın seçtiği canonical, son tarama tarihi. Kota site başına günde **2.000** ve dakikada 600 istek ([Google limitleri](https://developers.google.com/webmaster-tools/limits)). Bu yüzden tüm site değil, öncelikli sayfalar sırayla kontrol edilir.
- Search Analytics kotası (site başına dakikada 1.200 sorgu) bizim ölçeğimizde sorun değil.

### 4.2 GSC'den türeyen aksiyon kuralları

| Kural | Mantık |
|---|---|
| Trafik alan sayfada teknik sorun | Bulgu × GSC tıklaması → önceliği yükselt |
| Yüksek gösterim, düşük CTR | Pozisyon ≤ 10, CTR beklenenin altında → title/meta yeniden yazımı |
| Fırsat sorguları | Pozisyon 8-20 arası, yüksek gösterim → içerik güçlendirme / iç link |
| Kannibalizasyon | Aynı sorguda ≥2 URL anlamlı gösterim alıyor → birleştirme / canonical |
| Düşen sayfalar | Son 28 gün vs önceki dönem anlamlı tıklama kaybı → incele |
| Zombi sayfalar | İndekslenebilir, taranmış, 90+ gün sıfır gösterim → birleştir / noindex / sil |
| Yetim sayfalar | GSC'de gösterim alıyor ama taramada iç linki yok → iç link ekle |
| İndeks tutarsızlığı | URL Inspection: Google canonical ≠ bizim canonical, ya da "taranmış, indekslenmemiş" |

### 4.3 İş listesi

- Bulgu ve GSC kurallarından otomatik aksiyon üretimi. Aynı aksiyon tekrar tarandığında kopyalanmaz, güncellenir.
- Durumlar: açık → yapılıyor → tamamlandı → **doğrulandı** (sonraki taramada sorun kalktıysa otomatik).
- Not, son tarih, "müşteri raporunda göster" işareti.
- Etki ölçümü: aksiyon tamamlandığında ilgili URL'lerin GSC metrikleri kaydedilir, 28 gün sonra karşılaştırılır.

### 4.4 Otomasyon

- Proje başına zamanlanmış tarama (günlük / haftalık / aylık).
- **Kritik sayfa izleme** (Lumar Protect fikri): seçili 5-50 URL saatlik veya günlük kontrol edilir (durum kodu, noindex, canonical, title değişimi).
- Otomatik tarama karşılaştırma: yeni / kaybolan sayfalar, yeni sorunlar, düzelen sorunlar.
- E-posta bildirimi: kritik değişiklikte anında, diğerleri için haftalık özet.
- Aylık müşteri raporu otomatik üretilir, Serhan onaylayıp gönderir. **Müşteriye otomatik gönderim yok:** dışarıya giden her şey onaydan geçer.

### 4.5 Ek P1 kontroller

hreflang · yapısal veri çıkarma/doğrulama · PSI/CrUX (ücretsiz API) · yakın kopya · taranamayan linkler · güvenlik başlıkları · custom extraction · AI arama/GEO kontrolleri (AI bot robots.txt durumu, llms.txt, JS'siz içerik erişimi) · opsiyonel JS render.

## 5. P2 havuzu (sonrası)

Log dosyası analizi (cPanel erişim logları) · GA4 · semantik benzerlik ve içerik kümeleri · AI destekli title/meta önerisi · iç link önerici · site mimarisi görselleştirme · erişilebilirlik (axe) · sitemap üretimi · staging/production karşılaştırma · MCP sunucusu (ScreamingRabbit'i Claude'dan sürmek) · REA ile rakip araçların belirli özelliklerinin incelenmesi.

## 6. Mimari için girdiler (2. adıma devredilen kısıtlar)

- Sunucu: GoDaddy VPS, WHM/cPanel kurulu.
- Lisanssız, Microsoft dışı teknolojiler. PHP tercih ediliyor.
- Serhan'ın sunucu yönetimi deneyimi sınırlı → kurulum ve bakım adımları basit ve belgeli olmalı.
- Uzun süren taramalar web isteğinden bağımsız, arka plan işçisinde çalışmalı.
- 50.000 URL × (sayfa + linkler) veri hacmi düşünülerek veritabanı tasarlanmalı.
- JS render opsiyonel. VPS'te headless Chrome kurulabilirliği kontrol edilmeli.
- Zamanlama cron ile tetiklenmeli (cPanel'de mevcut).

## 7. Açık sorular (Serhan'a)

1. ~~MVP'deki müşteri raporu PDF mi olsun, paylaşılabilir bir web linki mi, yoksa ikisi de mi?~~ **Karar (24.10.2026):** Paylaşılabilir web linki + PDF export.
2. Kural kataloğundaki eşikler (title uzunluğu, düşük kelime sayısı vb.) için senin kullandığın standartlar var mı?
3. WordPress dışındaki siteler (Shopify, özel yazılım) için de aynı derinlik gerekiyor mu, yoksa onlar genel kurallarla yetinebilir mi?
4. Uygulama hangi adreste çalışsın? (örn. `seo.senindomainin.com`)
