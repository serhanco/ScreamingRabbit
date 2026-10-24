# 01 · Screaming Frog SEO Spider — Güncel Özellik Envanteri

> Durum tarihi: 24 Ekim 2026 · Güncel sürüm: **24.3** (29 Haziran 2026)
> Kaynaklar: [Ürün sayfası](https://www.screamingfrog.co.uk/seo-spider/), [Sürüm geçmişi](https://www.screamingfrog.co.uk/seo-spider/release-history/), [24.0 duyurusu](https://www.screamingfrog.co.uk/blog/seo-spider-24/)

Bu doküman, ScreamingRabbit'in "en az bunu yapmalı" referans listesidir. Her özelliğin yanında bizim için öncelik notu vardır:

- **P0**: MVP'de olmalı (3. adım)
- **P1**: GSC ve otomasyon aşamasında (4. adım)
- **P2**: Sonraki sürümler
- **—**: Bilinçli olarak yapmayacağız ya da başka yolla çözeceğiz

## 1. Ürün özeti

| Konu | Değer |
|---|---|
| Model | Masaüstü uygulaması (Windows, macOS, Linux). Java tabanlı. |
| Fiyat | Yıllık **£199** / lisans |
| Ücretsiz sürüm | Tarama başına **500 URL**. Konfigürasyon, kaydetme, JS render, custom extraction ve API entegrasyonları kapalı. |
| Ücretli limit | "Sınırsız" (pratikte makinenin RAM ve disk kapasitesine bağlı) |
| Kardeş ürün | Log File Analyser 7.1 (ayrı lisans) |

**Yapısal zayıflık (bizim fırsatımız):** Masaüstü uygulaması olduğu için zamanlama bilgisayarın açık olmasına bağlı. Bulutta çalıştırmak "kendin kur" işi. Çıktı ham URL listeleri şeklinde, önceliklendirme ve "ne yapmalıyım" açıklaması zayıf. Müşteriye rapor vermek için ek emek gerekiyor.

## 2. Sürüm bazlı evrim (son 4 yıl)

| Sürüm | Tarih | Öne çıkanlar |
|---|---|---|
| 18.0 | Ara 2022 | GA4 entegrasyonu, PDF parse, Validation sekmesi, zamanlama/CLI için kimlik doğrulama, Flesch okunabilirlik |
| 19.0 | Tem 2023 | Yeni arayüz, birleşik konfigürasyon, **Segments**, görsel custom extraction, 3D görselleştirme |
| 20.0 | May 2024 | Custom JavaScript snippet'leri, mobil kullanılabilirlik, N-gram analizi, toplu anchor text, yerel Lighthouse, karbon ayak izi |
| 21.0 | Kas 2024 | **Doğrudan AI API entegrasyonu** (OpenAI, Gemini, Ollama), erişilebilirlik (axe), e-posta bildirimleri |
| 22.0 | Haz 2025 | **Semantik benzerlik** (embedding), semantik içerik kümeleri, semantik arama, çoklu export, Google Sheets'ten indirme |
| 23.0 | Eki 2025 | Lighthouse "Insight Audits", Ahrefs v3 API, otomatik tarama silme (retention), embedding kuralları |
| 24.0 | May 2026 | **MCP sunucusu** (Claude vb. ile doğal dilde kullanım), **otomatik tarama karşılaştırma**, e-postada değişiklik tablosu, export'ları e-postayla gönderme, **taranamayan link tespiti** |

Çıkarım: Screaming Frog son iki yılda üç yöne gidiyor: (1) AI/semantik analiz, (2) zamanlanmış tarama ve değişiklik takibi, (3) AI asistanlarla entegrasyon. Bizim "aksiyon listesi + otomasyon" fikrimiz bu yönle aynı, fakat onlar bunu masaüstü kısıtıyla yapıyor.

## 3. Özellik envanteri (kategori bazlı)

### 3.1 Tarama motoru ve konfigürasyon

| Özellik | Açıklama | Öncelik |
|---|---|---|
| Spider modu | Başlangıç URL'sinden link takip ederek tarama | P0 |
| Liste modu | Verilen URL listesini tarama (göç denetimi için kritik) | P0 |
| Sitemap'ten tarama | XML sitemap URL'lerini kaynak olarak kullanma | P0 |
| Include / Exclude (regex) | Tarama kapsamını desenle daraltma | P0 |
| Hız ve eşzamanlılık limiti | Max thread, URL/saniye | P0 |
| User-agent seçimi | Googlebot, Bingbot, AI botları, özel UA | P0 |
| Özel HTTP başlıkları ve çerez | Accept-Language, cookie vb. | P1 |
| robots.txt'ye uyma / yok sayma, özel robots.txt | Test ve simülasyon dahil | P0 (uyma), P1 (özel) |
| Form tabanlı kimlik doğrulama, basic auth | Staging siteleri için | P1 |
| JavaScript render | Gömülü Chromium ile | P1 (bkz. mimari) |
| Crawl depth, URL uzunluğu, parametre limitleri | Sonsuz döngü koruması | P0 |
| Geçersiz (malformed) linkleri tarama | 24.0 ile geldi | P2 |
| Tarama kaydetme / açma, retention | Veritabanında kalıcı tutulacak | P0 (bizde doğal) |

### 3.2 Yanıt kodları ve yönlendirmeler

| Özellik | Öncelik |
|---|---|
| 2xx / 3xx / 4xx / 5xx / yanıt yok sınıflandırması | P0 |
| 301 / 302 / 307 / 308, meta refresh, JS redirect, HTTP refresh | P0 (JS redirect: P1) |
| Yönlendirme zincirleri ve döngüleri | P0 |
| Kırık link + kaynak sayfa (inlink) listesi | P0 |
| Dış link durum kodları | P0 |

### 3.3 Sayfa içi (on-page) öğeler

| Özellik | Öncelik |
|---|---|
| Title: eksik, kopya, uzun/kısa (karakter + piksel genişliği) | P0 |
| Meta description: eksik, kopya, uzun/kısa | P0 |
| H1/H2: eksik, kopya, çoklu, sırasız | P0 |
| Kelime sayısı, düşük içerik | P0 |
| Okunabilirlik (Flesch) | P2 (Türkçe için uyarlama gerek) |
| Görseller: alt eksik, büyük boyut, boyut özniteliği eksik | P0 |
| Yazım ve dilbilgisi (25+ dil) | P2 |

### 3.4 İndekslenebilirlik ve direktifler

| Özellik | Öncelik |
|---|---|
| Meta robots ve X-Robots-Tag (noindex, nofollow, none, nosnippet…) | P0 |
| Canonical (link + HTTP header): eksik, kendine değil, kanonik zinciri, non-200 hedef | P0 |
| robots.txt ile engellenen URL'ler | P0 |
| İndekslenebilir / indekslenemez durumu ve nedeni | P0 |
| rel next/prev sayfalama kontrolleri | P2 |
| hreflang: dönüş etiketi eksik, hatalı dil kodu, non-200 | P1 |

### 3.5 Site mimarisi ve linkleme

| Özellik | Öncelik |
|---|---|
| Crawl depth (tıklama derinliği) | P0 |
| Inlink / outlink sayıları, iç Link Score (PageRank benzeri) | P0 (sayılar), P1 (skor) |
| Anchor text (tekil ve toplu) | P1 |
| Yetim (orphan) sayfalar: sitemap / GSC / GA'da olup linklenmeyen | P1 (GSC ile güçlü) |
| Taranamayan linkler (onclick, javascript:, span href) | P1 |
| Görselleştirme: force-directed, ağaç, 3D | P2 |
| Dizin bazlı derinlik ve dağılım | P1 |

### 3.6 Kopya ve benzer içerik

| Özellik | Öncelik |
|---|---|
| Birebir kopya (MD5 hash) | P0 |
| Yakın kopya (minhash / benzerlik oranı) | P1 |
| Semantik benzerlik (embedding) ve içerik kümeleri | P2 |
| Düşük alakalı içerik (sitenin ana odağından sapan) | P2 |

### 3.7 Güvenlik ve teknik

| Özellik | Öncelik |
|---|---|
| HTTP sayfalar, mixed content, güvensiz formlar | P0 |
| Güvenlik başlıkları (HSTS, CSP, X-Frame-Options…) | P1 |
| URL sorunları: büyük harf, alt çizgi, non-ASCII, parametre, uzun URL, tekrarlayan path | P0 |
| HTML validation (botların parse'ını bozan hatalar) | P2 |
| AMP doğrulama | — (AMP büyük ölçüde terk edildi) |

### 3.8 Yapısal veri

| Özellik | Öncelik |
|---|---|
| JSON-LD / Microdata / RDFa çıkarma | P1 |
| Schema.org ve Google rich result doğrulaması | P1 |

### 3.9 Performans, mobil, erişilebilirlik

| Özellik | Öncelik |
|---|---|
| PageSpeed Insights / Lighthouse ve CrUX verisi | P1 (PSI API ücretsiz) |
| Mobil kullanılabilirlik | P2 |
| Erişilebilirlik (axe, WCAG) | P2 |
| Karbon ayak izi | — |

### 3.10 Özel arama, extraction, JS

| Özellik | Öncelik |
|---|---|
| Kaynak kodda metin/regex arama | P1 |
| Custom extraction (XPath, CSS, regex) | P1 |
| Tarama sırasında custom JavaScript | P2 |

### 3.11 Entegrasyonlar

| Özellik | Öncelik |
|---|---|
| Google Search Console (performans + URL Inspection) | P1, **ana farklılaştırıcımız** |
| Google Analytics 4 | P2 |
| PageSpeed Insights | P1 |
| Ahrefs / Majestic / Moz link metrikleri | — (lisanslı API, kullanıcı tercihine aykırı) |
| AI sağlayıcıları (OpenAI, Gemini, Ollama, Anthropic) ile özel prompt | P2 |
| MCP sunucusu | P2 (ilginç: kendi aracımızı Claude'dan sürmek) |

### 3.12 Sitemap ve robots araçları

| Özellik | Öncelik |
|---|---|
| XML ve görsel sitemap üretme | P2 |
| Sitemap analizi: sitemapte olup taranmayan, indekslenemez URL'ler sitemapte, yetimler | P0 |
| robots.txt indirme, düzenleme, test | P1 |

### 3.13 Otomasyon, karşılaştırma, raporlama

| Özellik | Öncelik |
|---|---|
| Zamanlanmış tarama | P1 (bizde sunucu tarafında, bilgisayar açık olmak zorunda değil) |
| CLI | — (web paneli ve API yeterli) |
| Tarama karşılaştırma (iki tarama arası fark) | P1 |
| Otomatik karşılaştırma + e-posta ile değişiklik tablosu | P1 |
| Staging vs production URL eşleme | P2 |
| Google Sheets'e otomatik export | P2 |
| Data Studio (Looker) raporları | — (kendi müşteri raporumuz olacak) |
| Çoklu export, sekme başına export | P1 (CSV/XLSX) |

## 4. Bizim için kritik notlar

1. **Masaüstü kısıtı:** Screaming Frog'un zamanlaması, karşılaştırması ve e-posta bildirimleri bilgisayar açıkken çalışıyor. ScreamingRabbit bunları VPS'te 7/24 yapacak. Bu tek başına önemli bir değer.
2. **"Ne yapmalıyım" eksikliği:** Screaming Frog sorunları filtre olarak listeler. Önceliklendirme ve açıklama zayıf. Sitebulb bu boşluğu "Hints" ile doldurdu (bkz. [02](02-rakip-analizi.md)).
3. **Lisanslı veri kaynakları:** Ahrefs, Majestic ve Moz entegrasyonları ücretli API ister. Serhan'ın tercihi lisanslı dünyadan uzak durmak olduğu için kapsam dışı. GSC ücretsiz ve çok daha bağlamsal.
4. **JS render maliyeti:** Hedef siteler çoğunlukla WordPress, yani sunucu tarafında render ediliyor. JS render'ı varsayılan değil, opsiyonel ve sayfa bazlı yaparak sunucu yükünü düşük tutabiliriz.
