# 05 · Mimari ve Sunucu Kararı

> Durum tarihi: 24 Ekim 2026 · Durum: **Öneri, Serhan'ın onayını bekliyor**
> **Barındırma kararı (24.10.2026):** MVP ve ilk testler Hostinger paylaşımlı hostingte (`hekimdijital.com`) yapılacak. Büyük siteler (10.000+ URL) gündeme gelince GoDaddy WHM VPS'e taşınacak. Cron tabanlı kuyruk modeli (§ 5.1) iki ortamda da aynı şekilde çalışır. Taşıma § 5.7'deki adımlarla yapılır.
> Girdiler: [04 § 6 mimari kısıtları](04-mvp-kapsami-ve-yol-haritasi.md#6-mimari-için-girdiler-2-adıma-devredilen-kısıtlar) ve 04 § 7'deki kararlar.

Bu doküman iki okuyucu için yazıldı. **Bölüm 1-3** Serhan için sade bir dille yazıldı: ne seçtik, neden seçtik, senden ne lazım. **Bölüm 4 ve sonrası** geliştirme için teknik ayrıntılar.

---

## 1. Kısaca karar

| Konu | Seçim | Bir cümlelik neden |
|---|---|---|
| Dil | **PHP 8.3+** | Senin tercihin; cPanel'de yerleşik olarak çalışıyor, ek kurulum istemiyor. |
| Çatı (framework) | **Laravel 13** | PHP dünyasının en yaygın, en iyi belgelenmiş çatısı. Kuyruk, zamanlayıcı, e-posta ve kullanıcı girişi hazır geliyor. Mart 2026'da çıktı, güvenlik desteği Mart 2028'e kadar sürüyor. |
| Yönetim paneli | **Filament 5** (Livewire 4 tabanlı) | Filtrelenebilir tablolar, formlar, grafikler ve CSV/XLSX export hazır. Paneli sıfırdan yazmak yerine birkaç hafta kazandırır. Açık kaynak ve ücretsiz. |
| Veritabanı | **MySQL 8 / MariaDB** | cPanel'de zaten var, ayrı bir veritabanı sunucusuna gerek yok. |
| Arka plan işleri | **Laravel kuyruğu (veritabanı sürücüsü)** + cron | Redis gibi ek servis kurmadan çalışır. Taramalar web sayfasından bağımsız olarak arka planda ilerler. |
| Zamanlama | **Tek bir cron satırı** → Laravel zamanlayıcı | cPanel'in "Cron Jobs" ekranından bir kez eklenir, gerisini uygulama yönetir. |
| PDF | **dompdf** (saf PHP) | Sunucuya Chrome kurmadan rapordan PDF üretir. |
| GSC | **Google'ın resmi PHP kütüphanesi** + OAuth | Ücretsiz, resmi kaynak. |
| JS render (sonra) | **Opsiyonel** headless Chrome | WordPress siteleri sunucuda render edildiği için MVP'de gerek yok. Sunucu uygunsa 4. adımda eklenir. |

Hepsi açık kaynak ve ücretsiz. Microsoft ya da lisanslı bir bileşen yok.

## 2. Neden Python veya Node değil?

Python ve Node, crawler yazmak için biraz daha zengin kütüphanelere sahip. Ama cPanel'li bir sunucuda bunları sürekli çalışır halde tutmak, yeni başlayan biri için gereksiz bakım yükü getiriyor (sanal ortamlar, süreç yöneticileri, port ve proxy ayarları). PHP ve Laravel cPanel'in "doğal dili". Güncellemek, yedeklemek ve sorun çıktığında hosting desteğinden yardım almak çok daha kolay.

İhtiyacımız olan ölçekte (proje başına 50.000 URL'ye kadar) PHP'nin eşzamanlı HTTP istemcisi yeterince hızlı. Sınır PHP değil, müşteri sitesine nazik davranmak için kendimizin koyacağı hız limiti olacak (bkz. § 5.3).

## 3. Senden gerekenler: sunucu hazırlık listesi

Aşağıdakileri kontrol etmem gerekiyor. Çoğunu sen WHM/cPanel'den birkaç dakikada görebilirsin. İstersen bana SSH erişimi verirsin, ben kontrol eder ve sonucu yazarım.

### 3.1 Bilmem gerekenler

| # | Soru | Nereden bakılır |
|---|---|---|
| 1 | VPS'in RAM'i, CPU çekirdek sayısı ve boş disk alanı ne kadar? | WHM → Server Information veya GoDaddy paneli |
| 2 | Bu VPS'te başka canlı siteler (müşteri siteleri) de çalışıyor mu? | — |
| 3 | WHM'ye root (tam yetkili) erişimin var mı? | WHM'ye girebiliyorsan büyük ihtimalle evet |
| 4 | SSH erişimi açık mı? | cPanel → SSH Access |
| 5 | Hangi PHP sürümleri kurulu? | WHM → MultiPHP Manager |
| 6 | MySQL mi MariaDB mi, sürümü ne? | WHM → Server Information veya phpMyAdmin |
| 7 | hekimdijital.com bu VPS'e mi bakıyor, DNS kimde? | GoDaddy → DNS |

### 3.2 Minimum gereksinimler

| Kaynak | Minimum | Önerilen | Neden |
|---|---|---|---|
| RAM | 2 GB boş | 4 GB+ | Tarama işçisi + MySQL + web |
| Disk | 10 GB boş | 25 GB+ | 50.000 URL'lik bir tarama, link verisiyle birlikte birkaç yüz MB tutabilir; eski taramalar otomatik silinecek |
| PHP | 8.3 | 8.4 | Laravel 13 şartı |
| PHP eklentileri | curl, mbstring, intl, dom, xml, zip, gd, pdo_mysql, bcmath, fileinfo, openssl | — | WHM → EasyApache 4'ten işaretlenir |
| Composer | Kurulu | — | PHP paket yöneticisi (SSH ile tek komut) |
| Cron | Kullanılabilir | — | cPanel → Cron Jobs |
| SSL | AutoSSL / Let's Encrypt | — | cPanel'de genelde varsayılan açık |
| Node.js | Gerekmez (MVP) | 20+ (JS render için) | Sadece 4. adımda, opsiyonel |

> **Önemli:** VPS'te müşteri siteleri de çalışıyorsa, tarama işçisinin aynı anda kaç istek atacağını düşük tutacağız. Böylece sitelerin yavaşlamasını önleriz. Bu yüzden 2. soru önemli.

## 4. Sistem şeması

```
                ┌──────────────────────────── VPS (cPanel) ────────────────────────────┐
                │                                                                       │
 Serhan ───────►│  Web (Apache/LiteSpeed + PHP-FPM)                                     │
 (tarayıcı)     │   └─ Laravel uygulaması                                               │
                │       ├─ Filament paneli (müşteriler, projeler, taramalar, aksiyonlar) │
 Müşteri ──────►│       └─ Herkese açık rapor sayfası  /r/{token}  (+ PDF indir)         │
 (rapor linki)  │                                                                       │
                │  MySQL ◄──────────────┐                                               │
                │                       │                                               │
                │  cron (her dakika) ───┼─► php artisan schedule:run                    │
                │                       │     ├─ zamanı gelen taramaları kuyruğa koy     │
                │                       │     ├─ GSC senkronu (günlük)                   │
                │                       │     └─ kuyruk işçisini çalıştır (aşağıda)      │
                │                       │                                               │
                │  Kuyruk işçisi ───────┘                                               │
                │   ├─ CrawlBatchJob   → müşteri sitesine HTTP istekleri  ─────────────────► müşteri siteleri
                │   ├─ AnalyzeCrawlJob → kuralları çalıştır, bulgu üret                  │
                │   ├─ GscSyncJob      → Google Search Console API  ─────────────────────► Google
                │   └─ ReportJob / Mail → SMTP                                          │
                └───────────────────────────────────────────────────────────────────────┘
```

## 5. Teknik ayrıntılar

### 5.1 Kuyruk işçisi cPanel'de nasıl çalışacak

cPanel'de genelde "sürekli çalışan süreç" (supervisor) yoktur. İki seçenek var:

1. **Varsayılan (cron tabanlı):** Zamanlayıcı her dakika `queue:work --stop-when-empty --max-time=50` çalıştırır. İşçi işler bitince ya da 50 saniye dolunca kendiliğinden kapanır, bir sonraki dakika yeniden başlar. `withoutOverlapping()` ile aynı anda iki işçi açılmaz. Kurulumu sıfır, bakımı sıfır. Maliyeti: işler arasında en fazla ~1 dakika gecikme olur, bu bizim için önemsiz.
2. **Root varsa (opsiyonel, daha sonra):** WHM'den supervisor kurulup işçi sürekli çalıştırılır. Daha hızlı ama bakım ister. Gerekirse sonra geçeriz.

Kod iki yöntemde de aynı, sadece işçinin nasıl başlatıldığı değişiyor.

### 5.2 Crawler tasarımı

- **Sınır (frontier) veritabanında:** Taranacak URL'ler `crawl_urls` tablosunda `pending / fetching / done / failed` durumuyla tutulur. İşçi kapansa bile tarama kaldığı yerden devam eder. Bu, cron tabanlı işçi modeli için şart.
- **Toplu iş:** Her `CrawlBatchJob` çalıştığında en fazla N URL'yi (örn. 50) çeker, Guzzle'ın eşzamanlı havuzuyla (`curl_multi`) aynı anda K istek atar (varsayılan 3, ayarlanabilir), sonuçları yazar, yeni linkleri sınıra ekler ve kendini yeniden kuyruğa koyar. Bu, PHP'nin uzun süreli bellek sorunlarından da korur.
- **Ayrıştırma:** `DOMDocument` + `DOMXPath` (PHP'de yerleşik). Title, meta, heading, canonical, robots, hreflang, linkler, görseller ve JSON-LD çıkarılır.
- **URL normalizasyonu:** Şema/host küçük harf, fragment atma, varsayılan port atma, opsiyonel parametre sıralama. Aynı URL iki kez taranmaz (`url_hash` benzersiz indeks).
- **robots.txt:** Tarama başında bir kez indirilip ayrıştırılır, varsayılan olarak uyulur.
- **Sitemap:** `robots.txt` içindeki ve `/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml` adreslerindeki sitemap'ler indirilir, sitemap index'leri açılır.
- **Yanıt gövdesi:** HTML gövdesi saklanmaz, sadece çıkarılan alanlar ve içerik hash'i saklanır (disk tasarrufu). İleride "HTML sakla" opsiyonu eklenebilir.
- **Kaynak kontrolü:** Dış linkler ve görseller için yalnızca `HEAD` (gerekirse `GET` + gövde sınırı) atılır.

### 5.3 Nezaket ve güvenlik (müşteri sitelerine zarar vermemek)

- Varsayılan: 3 eşzamanlı istek, istekler arası 200 ms. Proje başına ayarlanabilir.
- 429 / 503 yanıtında otomatik yavaşlama ve `Retry-After`'a uyma.
- User-agent: `ScreamingRabbit/1.0 (+https://{APP_URL}/bot)`. Googlebot taklidi opsiyonel.
- İstek zaman aşımı 15 sn, yanıt gövdesi sınırı 5 MB.
- Sadece projenin domaini (ve opsiyonel alt domainler) taranır. Dış linkler sadece durum için kontrol edilir.

### 5.4 Veri modeli (çekirdek tablolar)

```
clients            id, name, contact_email, notes
projects           id, client_id, name, base_url, platform (auto: wordpress|generic),
                   crawl_settings (json), gsc_property, gsc_token_id, schedule (json)
crawls             id, project_id, status, mode (spider|list|sitemap), started_at, finished_at,
                   stats (json), settings_snapshot (json), health_score
crawl_urls         id, crawl_id, url, url_hash, depth, status (pending|fetching|done|failed),
                   source (link|sitemap|list), found_on_id
pages              id, crawl_id, crawl_url_id, status_code, content_type, response_ms, size_bytes,
                   redirect_to, title, title_px, meta_description, h1_first, h1_count, h2_count,
                   canonical, meta_robots, x_robots, indexable, indexability_reason,
                   word_count, content_hash, inlinks_count, outlinks_count, in_sitemap, extra (json)
links              crawl_id, from_page_id, to_crawl_url_id, anchor, rel, type (a|img|link|script),
                   is_internal
checks             (kod içinde katalog; DB'de yalnızca açma/kapama ve eşik geçersiz kılma)
issues             id, crawl_id, check_key, page_id, details (json)
tasks              id, project_id, source (check|gsc), key, title, severity, priority_score,
                   status (open|doing|done|verified), affected_count, notes, due_at,
                   show_in_report, first_seen_crawl_id, last_seen_crawl_id, resolved_crawl_id
gsc_page_metrics   project_id, date, page, clicks, impressions, ctr, position
gsc_query_metrics  project_id, date, page, query, clicks, impressions, ctr, position
reports            id, project_id, token, period_start, period_end, snapshot (json), revoked_at
```

**Hacim tahmini:** 50.000 sayfalık bir site × sayfa başına ortalama ~80 link ≈ 4 milyon `links` satırı. Bu yüzden:
- `links` tablosu dar tutulur (URL metni değil, ID'ler saklanır).
- Toplu ekleme (`INSERT ... VALUES (...), (...)`, 500'lük paketler) kullanılır.
- **Tarama saklama politikası:** Proje başına son N tarama (varsayılan 5) tam olarak tutulur, daha eskilerin yalnızca özet istatistikleri ve bulgu sayıları kalır.

### 5.5 Kural motoru

- Her kural bir PHP sınıfı: `key`, `category`, `severity`, `platform` (generic | wordpress), Türkçe `title`/`why`/`how_to_fix`, `thresholds` ve `run(Crawl): iterable<Issue>`.
- Çoğu kural tek bir SQL sorgusu (örn. `WHERE title IS NULL AND indexable = 1`). Hızlıdır ve 50.000 satırda rahat çalışır.
- **Eşikler** (04 § 7 kararı): Tek bir `config/checks.php` dosyasında, her biri `source` (kaynak linki) ve `reviewed_at` bilgisiyle tutulur. Panelden proje bazında geçersiz kılınabilir.
- **Platform modülleri** (04 § 7 kararı): `app/Checks/Generic/*` her sitede çalışır, `app/Checks/WordPress/*` yalnızca proje platformu `wordpress` ise. Yeni altyapı = yeni klasör + tespit kuralı.
- Tarama analizinden sonra `TaskSynchronizer` bulguları aksiyonlarla eşler: yeni aksiyon açar, mevcut aksiyonu günceller, sorun kalkmışsa `verified` yapar.

### 5.6 Raporlar

- `/r/{token}`: 32+ karakter rastgele token, giriş gerektirmez, `noindex` başlığı taşır, panelden iptal edilebilir.
- Rapor oluşturulduğu andaki veri `snapshot` olarak dondurulur. Sonraki taramalar paylaşılan raporu değiştirmez.
- Aynı Blade şablonu hem web sayfasını hem dompdf PDF'ini üretir.
- Logo ve renk ayarı (markalama) 4. adımda.

### 5.7 Taşınabilirlik ve yapılandırma

- Tüm ortam ayarları `.env` dosyasında: `APP_URL`, veritabanı, SMTP, Google OAuth anahtarları. Domain hiçbir yerde koda gömülmez (04 § 7 kararı).
- **Taşıma adımları:** `.env` içinde `APP_URL`'i değiştir → yeni domain için DNS ve SSL → Google OAuth'ta yönlendirme adresini güncelle. Bu üç adım `docs/` içinde ayrı bir kılavuz olarak yazılacak.

### 5.8 Kod ve dağıtım akışı

```
GitHub (serhanco/ScreamingRabbit)
   │  main dalına birleştirilen değişiklik
   ▼
GitHub Actions: testler (PHPUnit/Pest) + kod stili
   │  yeşilse
   ▼
VPS'e dağıtım: SSH ile `deploy.sh`
   (git pull → composer install --no-dev → php artisan migrate --force → cache temizle)
```

- Başlangıçta dağıtımı elle tetiklenen tek bir komut yaparız (cPanel → Git Version Control veya SSH). Otomatik dağıtım, sunucuya SSH anahtarı eklendikten sonra açılır.
- Veritabanı yedeği: cPanel'in günlük yedeği + uygulama içinden haftalık `mysqldump` (opsiyonel).

### 5.9 Güvenlik

- Tek kullanıcı (Serhan), güçlü şifre + **2FA** (Filament yerleşik).
- Panel `/admin` altında. Kayıt ekranı kapalı.
- GSC OAuth token'ları veritabanında şifreli (`encrypted` cast).
- Rapor linkleri tahmin edilemez ve iptal edilebilir.

### 5.10 Test ve geliştirme ortamı

- Yerel geliştirme ve CI için PHP 8.4 + SQLite/MySQL.
- Crawler testleri sahte HTTP yanıtlarıyla (Guzzle MockHandler) yapılır. Gerçek siteye istek atılmaz.
- Her kural için en az bir "tetiklenir / tetiklenmez" testi.
- Örnek bir WordPress fixture sitesi (statik HTML) ile uçtan uca tarama testi.

## 6. Riskler ve önlemler

| Risk | Olasılık | Önlem |
|---|---|---|
| VPS'te RAM yetersiz | Orta | Batch boyutu ve eşzamanlılık düşük başlar, ölçülerek artırılır. Gerekirse VPS planı yükseltilir. |
| Tarama müşteri sitelerini yavaşlatır | Düşük | § 5.3 nezaket ayarları, 429/503'te otomatik yavaşlama |
| VPS'teki diğer siteler etkilenir | Orta | İşçi `nice` ile düşük öncelikte çalışır, gece saatleri için zamanlama önerilir |
| GoDaddy IP'si müşteri WAF'ına (Cloudflare, Wordfence) takılır | Orta | Proje başına özel UA ve izin listesi kılavuzu. Wordfence için whitelist talimatı yazılır. |
| Cron tabanlı işçi yavaş kalır | Düşük | Supervisor'a geçiş yolu açık (§ 5.1) |
| `links` tablosu şişer | Orta | Saklama politikası (§ 5.4) ve ID tabanlı dar tablo |

## 7. 3. adımın (MVP) iş kırılımı

Onayından sonra 3. adım şu sırayla, her biri ayrı ve incelenebilir PR olarak ilerler:

1. **İskelet:** Laravel 13 + Filament 5 kurulumu, giriş + 2FA, müşteri/proje ekranları, CI (GitHub Actions), `deploy.sh`.
2. **Crawler çekirdeği:** Sınır tablosu, batch işi, ayrıştırıcı, robots/sitemap, nezaket ayarları, ilerleme göstergesi.
3. **Kural motoru + MVP kural kataloğu:** ~45 kural (04 § 3.2), sorunlar ekranı, sağlık skoru.
4. **URL tablosu ve export:** Filtreler, arama, CSV/XLSX.
5. **Rapor:** `/r/{token}` sayfası + PDF.
6. **Sunucuya ilk kurulum:** hekimdijital.com'da canlı test ve ilk gerçek müşteri sitesiyle deneme taraması.

## 8. Onay bekleyen kararlar

1. Teknoloji seçimi (§ 1): PHP 8.3+ / Laravel 13 / Filament 5 / MySQL / cron tabanlı kuyruk.
2. Sunucu hazırlık listesindeki (§ 3.1) yedi sorunun cevabı. İstersen SSH erişimiyle ben kontrol edebilirim.
3. ~~Panelin adresi~~ **Karar (güncel):** Alt domain yerine ana domain `hekimdijital.com` kullanılacak (Hostinger, boş PHP sitesi olarak hazırlandı), sonra VPS.

## 9. Hostinger kontrol sonucu (24.10.2026)

`tools/sunucu-kontrol.php` hekimdijital.com üzerinde çalıştırıldı:

| Konu | Sonuç | Değerlendirme |
|---|---|---|
| PHP (web) | 8.3.x, LiteSpeed | ✅ Laravel 13 için uygun |
| PHP (CLI) | 8.5.x | ✅ Laravel 13 8.5'i destekliyor. Cron komutlarında PHP yolu açıkça yazılacak. |
| PHP eklentileri | Gerekenlerin hepsi + opcache + redis | ✅ |
| memory_limit / max_execution_time | 512M / 300 sn | ✅ Batch işleri bu sınırın çok altında kalacak |
| Composer / Git | 2.9 / 2.52 | ✅ SSH ile dağıtım mümkün |
| Veritabanı | MariaDB 11.8 | ✅ |
| Dışa HTTPS + curl_multi | Var | ✅ Crawler çalışır |
| crontab komutu | Yok | Normal: Hostinger'da cron hPanel → Advanced → Cron Jobs ekranından eklenir |
| Node.js | Yok | MVP'de gerekmiyor. JS render ileride VPS'te. |
| RAM / CPU | Fiziksel sunucunun tamamı görünüyor (paylaşımlı) | Bize düşen pay plan limitleriyle sınırlı. Eşzamanlılık düşük tutulacak. |

**Sonuç:** Hostinger, MVP için teknik olarak hazır. Ek kurulum gerekmiyor.
