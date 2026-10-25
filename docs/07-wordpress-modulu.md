# 07 · WordPress Modülü: Dış Kontroller ve Bağlantı Eklentisi

> Durum tarihi: 25 Ekim 2026 · Durum: **plan** (kod yok)
> Bağlam: [04 §3.2 "WordPress temel"](04-mvp-kapsami-ve-yol-haritasi.md) ve [04 §7 karar 3](04-mvp-kapsami-ve-yol-haritasi.md) ("platform modülü"). Bu doküman o modülün tam kapsamını, güvenlik modelini ve fazlarını tanımlar.

## 1. Özet

WordPress modülü iki katmandan oluşur:

| Katman | Ne | Kurulum | Ne zaman çalışır |
|---|---|---|---|
| **A. Dış kontroller** | Siteye dışarıdan, ziyaretçi gibi bakan WordPress'e özel SEO ve güvenlik kontrolleri | Yok | Projenin platformu `wordpress` seçiliyse **veya** tarama WordPress tespit ettiyse, her taramanın sonunda |
| **B. Bağlantı eklentisi** ("ScreamingRabbit Connector") | Müşteri sitesine kurulan küçük eklenti. İçeriden veri toplar, onaylı değişiklikleri uygular | Müşteri sitesine bir kez yüklenir | Uygulama zamanlanmış olarak eklentiden veri çeker; onaylı değişiklikleri gönderir |

Temel ilke: **Dışarıdan görülebilen her şey A ile yapılır, eklenti yalnızca dışarıdan görülemeyen veri ve yazma işlemleri için vardır.** Eklentisi olmayan bir site de modülün değerinin çoğunu alır.

Mevcut kodda zemin hazır: `projects.platform`, `crawls.detected_platform`, `crawl_pages.wordpress_hint` ve `RuleCatalog` içindeki `platform => 'wordpress'` işareti. Yeni kurallar aynı katalogda aynı işaretle eklenir.

## 2. Katman A: Dış kontroller

### 2.1 Çalışma kuralları (nezaket ve yasal sınır)

- Yalnızca Serhan'ın hizmet verdiği, yetkili olduğu sitelerde çalışır (proje = yetki).
- **Pasif ve hafif:** yalnızca GET/HEAD. Giriş denemesi, parola tahmini, form gönderimi, açık istismarı **yok**. XML-RPC'ye yöntem çağrısı **yok**.
- Paket başına sabit ve küçük bir istek listesi (~25-40 istek), projenin tarama hızı ayarına (eşzamanlılık + gecikme) tabidir. Paylaşımlı hosting sınırları için tek bir kısa iş olarak çalışır.
- Aynı user-agent ile kendini tanıtır.
- Sürüm karşılaştırmaları için yalnızca halka açık, ücretsiz kaynaklar: `api.wordpress.org` (çekirdek sürüm, eklenti bilgisi, çekirdek dosya özetleri) ve P1'de bir açık veritabanı akışı (§2.4).

### 2.2 Güvenlik ve maruziyet kontrolleri

| Kural | Nasıl tespit edilir | Önem | Faz |
|---|---|---|---|
| WordPress sürümü ifşa ediliyor | `<meta name="generator">`, RSS `<generator>`, `?ver=` parametreleri, `readme.html` | düşük | WP-1 |
| WordPress çekirdeği güncel değil | Tespit edilen sürüm vs `api.wordpress.org/core/version-check` | yüksek (güvenlik sürümü eksikse kritik) | WP-1 |
| Eklenti/tema envanteri (bilgilendirme) | HTML'deki `/wp-content/plugins/<slug>/` ve `/themes/<slug>/` yolları; sürüm `readme.txt` "Stable tag" veya `?ver=` | bilgi | WP-1 |
| Eklenti güncel değil | Tespit edilen sürüm vs wordpress.org eklenti API'si | orta | WP-1 |
| Bilinen açığı olan eklenti/tema/çekirdek | Envanter × açık veritabanı (§2.4) | kritik/yüksek | WP-2 |
| Kullanıcı adları ifşa ediliyor | `/?author=1` → `/author/<ad>/` yönlendirmesi; `/wp-json/wp/v2/users` herkese açık liste; yazar site haritası | yüksek | WP-1 |
| REST API kullanıcı uç noktası açık | `/wp-json/wp/v2/users` 200 ve kullanıcı içeriyor | yüksek | WP-1 |
| `xmlrpc.php` açık | GET yanıtı "accepts POST requests only" | orta | WP-1 |
| Varsayılan dosyalar erişilebilir | `readme.html`, `license.txt`, `wp-admin/install.php`, `wp-admin/setup-config.php` | düşük/orta | WP-1 |
| `debug.log` herkese açık | `/wp-content/debug.log` 200 ve boş değil | kritik | WP-1 |
| Dizin listeleme açık | `/wp-content/uploads/`, `/wp-content/plugins/` "Index of" | orta | WP-1 |
| Yapılandırma yedeği açıkta | Kısa sabit liste: `wp-config.php.bak`, `.old`, `.save`, `~` (yalnızca HEAD; 200 ve boyut > 0) | kritik | WP-1 |
| Giriş sayfası HTTP üzerinden | `wp-login.php` HTTPS'e yönlenmiyor | yüksek | WP-1 |
| Güvenlik başlıkları eksik | Genel P1 kontrolü ([04 §4.5](04-mvp-kapsami-ve-yol-haritasi.md)); WP raporunda da gösterilir | orta | WP-2 |

Not: `/wp-json/` ve `xmlrpc.php` erişimi MVP'de "bilgilendirme" olarak vardı. WP-1 bunları yukarıdaki ayrıntılı kurallarla değiştirir.

### 2.3 WordPress'e özel SEO kontrolleri

| Kural | Nasıl tespit edilir | Önem | Faz |
|---|---|---|---|
| Arama motorları engellenmiş ("Arama motorlarını engelle" açık) | Sitenin geneline yayılmış `noindex` + WP'nin sanal `robots.txt` imzası | kritik | WP-1 |
| SEO eklentisi tespiti (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework) | HTML yorumları, meta, şema imzaları | bilgi | WP-1 |
| Birden fazla SEO eklentisi | Mevcut MVP kuralı; tespit genişler | yüksek | MVP ✓ |
| İki site haritası birden aktif | `/wp-sitemap.xml` (çekirdek) ve eklenti site haritası aynı anda 200 | orta | WP-1 |
| Ek (attachment) sayfaları indekslenebilir | Mevcut MVP kuralı | orta | MVP ✓ |
| Ham adresler (`?p=`, `?page_id=`) | Mevcut `wp_raw_url` | orta | MVP ✓ |
| Etiket/yazar/tarih arşivleri ince ve indekslenebilir | Mevcut MVP kuralı | orta | MVP ✓ |
| İç arama sonuçları indekslenebilir | `/?s=test` 200, `noindex` yok | orta | WP-1 |
| `?replytocom=` linkleri | Taramada bu parametreli iç linkler | düşük | WP-1 |
| Sayfalama (`/page/N/`) canonical'ı ilk sayfaya | canonical = 1. sayfa | orta | WP-1 |
| Varsayılan içerik duruyor | "Merhaba dünya!" / "Hello world!", "Örnek sayfa", "Just another WordPress site" sloganı | düşük | WP-1 |
| "Kategorilenmemiş" kategorisi kullanımda | `/category/uncategorized/` veya `/kategorilenmemis/` içerik barındırıyor | düşük | WP-1 |
| Staging/geliştirme adresi sızıntısı | İç linklerde veya canonical'da farklı bir (staging) host | yüksek | WP-1 |
| Sayfa önbelleği yok | Önbellek başlıkları (LiteSpeed, WP Rocket, W3TC vb.) hiçbir sayfada yok ve TTFB yüksek | orta | WP-2 |
| Gereksiz baş etiketleri / scriptler | emoji scripti, `jquery-migrate`, oEmbed/RSD/wlwmanifest linkleri | düşük | WP-2 |

### 2.4 Açık veritabanı kaynağı (WP-2)

Envanteri bilinen açıklarla eşleştirmek için **Wordfence Intelligence Vulnerability Data Feed** kullanılır: ücretsiz (ticari kullanım ve anahtar koşulları WP-2 başında doğrulanır), tüm feed günde bir kez indirilip yerelde eşleştirilir (site başına istek yok). Ücretsiz API anahtarı gerekiyorsa Serhan bir kez alır, `.env`'e girilir. WPScan API'nin ücretsiz katmanı (günde 25 istek) bizim ölçeğimize yetmez, kullanılmaz.

## 3. Katman B: Bağlantı eklentisi

### 3.1 Neden eklenti? (dış tarama vs eklenti)

| İhtiyaç | Dış tarama | Eklenti |
|---|---|---|
| Sayfa durumları, title/meta/H1, canonical, noindex | ✓ | — |
| Herkese açık eklenti/tema envanteri | Kısmen (yalnızca HTML'de izi olanlar) | ✓ tam liste, aktif/pasif, sürüm, güncelleme durumu |
| Çekirdek/PHP/MySQL sürümü, Site Sağlığı sonuçları | ✗ | ✓ |
| Ayarlar: kalıcı bağlantı, `blog_public`, SEO eklentisi ayarları (arşiv noindex, ek sayfası yönlendirmesi) | Dolaylı tahmin | ✓ kesin |
| Taslak, zamanlanmış, şifreli, noindex içerikler; içerik değişim tarihleri | ✗ | ✓ |
| Yoast/Rank Math odak anahtar kelimesi, SEO skoru | ✗ | ✓ |
| Çekirdek dosya bütünlüğü (değiştirilmiş/yabancı dosya) | ✗ | ✓ (`api.wordpress.org` checksum'larıyla) |
| Yönetici sayısı, zayıf ayarlar (dosya düzenleyici açık, `WP_DEBUG` açık) | ✗ | ✓ |
| İçerik yayınlanınca/güncellenince anında yeniden kontrol | ✗ | ✓ (olay bildirimi) |
| Değişiklik uygulama (title/meta, noindex, yönlendirme, alt metni) | ✗ | ✓ (onaylı) |

### 3.2 Yetenekler ve fazlar

**WP-2 · Salt okunur (v1)**
- Envanter: çekirdek, eklentiler, temalar (aktif/pasif, sürüm, güncelleme var mı).
- Ortam: PHP, MySQL, bellek limiti, WP Site Sağlığı testlerinin sonuçları.
- Ayarlar: kalıcı bağlantı yapısı, `blog_public`, `WP_DEBUG`, `DISALLOW_FILE_EDIT`, otomatik güncelleme durumu, SEO eklentisinin ilgili ayarları.
- İçerik özeti: yazı/sayfa başına URL, durum, son değişiklik, SEO eklentisinin title/description/canonical/noindex/odak kelime alanları. Büyük sitelerde sayfalı çekilir (sayfa başına 500).
- Güvenlik: yönetici sayısı (ad/e-posta yok), çekirdek dosya bütünlüğü.

**WP-3 · Olaylar ve onaylı yazma**
- Olaylar: içerik yayınlandı/güncellendi/silindi, eklenti etkinleştirildi/güncellendi → uygulamaya bildirim → ilgili URL'ler sıradaki kontrolde öncelikli taranır.
- Yazma işlemleri (yalnızca bu liste, genişletilmesi ayrı karar):
  - SEO title / meta description değiştir (aktif SEO eklentisinin alanına yazar)
  - `noindex` ekle/kaldır
  - 301 yönlendirme ekle (Redirection veya Rank Math yönlendirme modülüne; ikisi de yoksa eklentinin kendi küçük tablosuna)
  - Görsel alt metni yaz
- Her değişiklik: önce/sonra değeri iki tarafta da kayıtlı, **tek tıkla geri alma**.

**WP-4 · AI destekli düzeltmeler**
- Uygulama, iş listesindeki aksiyonlar için öneri üretir: title/meta yeniden yazımı (GSC "yüksek gösterim, düşük CTR"), eksik alt metinleri, iç link önerileri.
- AI **uygulamada** çalışır (Claude API). Eklenti hiçbir AI anahtarı tutmaz, yalnızca onaylanmış somut değişikliği uygular.
- Akış: öneri → Serhan inceler/düzenler → onay → eklenti uygular → sonraki taramada doğrulanır → 28 gün sonra GSC etkisi ([04 §4.3](04-mvp-kapsami-ve-yol-haritasi.md)).
- Toplu onay olabilir ama **otomatik uygulama yok**. Dışarıya giden her değişiklik onaydan geçer (04 §4.4 ilkesinin aynısı).

**Bilinçli olarak hiç yapılmayacaklar:** uzaktan kod/PHP çalıştırma, dosya düzenleme, SQL, eklenti kurma/silme, kullanıcı oluşturma, parola işlemleri. Eklenti ele geçirilirse verebileceği zarar, yukarıdaki dar yazma listesiyle sınırlı kalır.

### 3.3 Güvenlik ve kimlik doğrulama modeli

**Karar: Site başına paylaşılan gizli anahtar + HMAC imzalı istekler.** WordPress "Uygulama Parolaları" kullanılmaz; çünkü bir WP kullanıcısının tüm yetkilerini verir, kapsam daraltılamaz.

1. **Eşleştirme:** Proje sayfasında "WordPress'e bağla" → uygulama 32 baytlık rastgele bir gizli anahtar ve bir **bağlantı kodu** üretir. Serhan (veya müşteri) eklenti ayarlarına kodu yapıştırır. Eklenti kodla uygulamaya bir kez el sıkışır, anahtarı alır; kod tek kullanımlık ve 30 dakika geçerli.
2. **İstek yönü:** Esas olarak **uygulama → site** (çekme). Uygulama cron ile çalıştığı için paylaşımlı hostingde de sorunsuz. Eklenti uç noktası: `/wp-json/screamingrabbit/v1/...`. Olay bildirimleri (WP-3) site → uygulama yönünde aynı imzayla gelir.
3. **İmza:** `HMAC-SHA256(gizli, yöntem + yol + zaman damgası + nonce + gövde-SHA256)`. Zaman damgası ±5 dakika; nonce 10 dakika saklanır, tekrar kullanılırsa red. Yalnızca HTTPS.
4. **Saklama:** Uygulamada anahtar Laravel şifrelemesiyle (`APP_KEY`) veritabanında; eklentide `wp_options` içinde (autoload kapalı). İki tarafta da "bağlantıyı kes" ve "anahtarı yenile" var.
5. **Yazma izni sitede açılır:** Eklenti varsayılan olarak **salt okunurdur**. Yazma işlemleri, site yöneticisi eklenti ayarında "ScreamingRabbit değişiklik uygulayabilir" seçeneğini açtığında çalışır. Yani uygulama ele geçirilse bile yazma izni verilmemiş sitelere dokunamaz.
6. **Çakışma koruması:** Her yazma isteği beklenen "önce" değerini taşır. Sitede değer o arada değişmişse eklenti reddeder, aksiyon "çakışma" durumuna düşer.
7. **Sınırlar:** Dakikada en fazla 60 istek; yazma işlemleri tek tek ve günlüğe yazılarak.
8. **Kişisel veri:** Kullanıcı adı, e-posta, yorum, sipariş vb. **toplanmaz** (yalnızca sayılar). KVKK açısından eklentinin topladığı veri listesi ayarlar ekranında açıkça yazar.

### 3.4 Teknik kısıtlar

- Uyumluluk: WordPress 6.0+, PHP 7.4+ (müşteri sitelerinin eski olabileceği varsayımıyla).
- Ön yüzde sıfır yük: ziyaretçi sayfalarına hiçbir CSS/JS eklemez, ağır WP-cron işi yoktur.
- Dağıtım: önce wordpress.org dışında, uygulamanın proje sayfasından ZIP indirme (WP yönetim panelinde "Eklenti yükle" ile kurulur; bu müşteri sitesi tarafında, Hostinger'daki ZIP sorunumuzla ilgisi yok). Güncellemeler: eklenti uygulamadan yeni sürümü kontrol eder (WP-3).
- Kod yeri: aynı repoda `wordpress-plugin/screamingrabbit-connector/`. `deploy` dalına dahil edilmez, ayrı bir GitHub iş akışı ZIP'i üretir.

## 4. Veri modeli eklemeleri

| Tablo | Amaç |
|---|---|
| `wp_site_connections` | Proje ↔ eklenti bağlantısı: şifreli anahtar, eklenti sürümü, yazma izni durumu, son başarılı senkron |
| `wp_snapshots` | Tarama veya senkron başına WP durumu: çekirdek sürümü, ortam, ayarlar (JSON), kaynak (`external` / `plugin`) |
| `wp_components` | Snapshot başına eklenti/tema: slug, tür, sürüm, son sürüm, aktif mi, tespit kaynağı |
| `wp_vulnerabilities` | Günlük indirilen açık veritabanı (slug, etkilenen sürüm aralığı, CVSS, kaynak linki) |
| `wp_change_requests` | Onaylı değişiklik: hedef (post/URL), alan, önce, sonra, durum (önerildi → onaylandı → uygulandı / çakışma / geri alındı), kimin onayladığı, ilişkili aksiyon |

Bulgular mevcut kural/bulgu/aksiyon akışına girer. Eklentiden gelen veri yeni kural kaynağıdır, ayrı bir ekran değildir.

## 5. Faz planı ve öncelik

| Faz | Kapsam | Yol haritasındaki yeri | Ön koşul |
|---|---|---|---|
| **WP-1** | Dış kontroller: §2.2 ve §2.3'teki WP-1 kuralları, envanter tespiti, wordpress.org sürüm karşılaştırması, WP raporu bölümü | 3. adımın sonu (crawler MVP'si biter bitmez) | Crawler ve kural kataloğu (mevcut) |
| **WP-2** | Açık veritabanı eşleştirmesi; eklenti v1 salt okunur; bağlantı ekranı; §2.3 WP-2 kuralları | 4. adımla birlikte (GSC'ye paralel) | WP-1 |
| **WP-3** | Eklenti olayları, onaylı yazma listesi, değişiklik günlüğü ve geri alma, eklenti güncelleme kontrolü | 4. adımın sonu | WP-2, iş listesi (04 §4.3) |
| **WP-4** | AI önerileri → onay → uygulama → GSC ile etki ölçümü | P2 | WP-3, GSC |

İlk pilot: Serhan'ın kendi kontrolündeki bir WordPress sitesi (müşteri sitesi değil). Eklenti orada en az iki hafta salt okunur çalıştıktan sonra müşteri sitelerine geçilir.

## 6. Kararlar ve varsayımlar

- **Karar:** Dış kontroller eklentisiz her WordPress projesinde varsayılan olarak çalışır. Kapatmak isteyen proje ayarından kapatır.
- **Karar:** Eklenti isteğe bağlıdır, varsayılan salt okunurdur; yazma izni sitede açılır.
- **Karar:** AI yalnızca öneri üretir; uygulama her zaman Serhan'ın onayıyla olur.
- **Karar:** Kimlik doğrulama site başına HMAC anahtarıyla; Uygulama Parolaları kullanılmaz.
- **Varsayım:** Müşteri sitelerine eklenti kurma ve dış güvenlik kontrolleri yapma izni, Serhan'ın müşteriyle sözleşmesinde yer alır. Eklenti kurulumundan önce müşteriye verilecek kısa bir bilgilendirme metni WP-2'de hazırlanır.
