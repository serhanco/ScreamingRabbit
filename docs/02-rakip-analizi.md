# 02 · Rakip Analizi — Screaming Frog Alternatiflerinin Üstün Yanları

> Durum tarihi: 24 Ekim 2026
> Not: Rakip iddialarının bir kısmı üreticinin kendi pazarlama sayfalarından alınmıştır ve bağımsız olarak doğrulanmamıştır. Bu durum ilgili yerlerde belirtilmiştir.

Amaç her rakibi baştan sona listelemek değil. Her birinin **Screaming Frog'a göre neden tercih edildiğini** bulup ScreamingRabbit'e alınacak fikirleri çıkarmak.

## 1. Sitebulb (Desktop + Cloud)

Kaynak: [Sitebulb vs Screaming Frog](https://sitebulb.com/cloud/compare/sitebulb-vs-screaming-frog/) (üretici sayfası)

| Üstün yan | Açıklama | ScreamingRabbit'e etkisi |
|---|---|---|
| **Hints** | Her sorun "neden önemli, nasıl düzeltilir" açıklamasıyla gelir | Her denetim kuralı Türkçe açıklama ve çözüm önerisi taşıyacak |
| **Önem derecesi ve önceliklendirme** | Sorunlar kategori + önem ile gruplanır, tarama sürerken gösterilir | Kural başına `severity` + etkilenen URL sayısı → öncelik skoru |
| **Otomatik müşteri raporları** | Hazır, markalanabilir raporlar | Müşteriye PDF/HTML rapor, Serhan'ın kendi markasıyla |
| **Denetim karşılaştırma** | İki denetim arasında ne düzeldi, ne bozuldu | Tarama farkı (diff) ekranı |
| **Bulut + masaüstü** | Bulutta zamanlanmış tarama, ekip çalışması | Bizde zaten sunucu tabanlı |
| **JS render ücretsiz** | Screaming Frog'da ücretli lisans gerektiriyor | Bizde ücret yok ama kaynak maliyeti var |

Fiyat: Cloud aylık £95'ten başlıyor (üretici beyanı).

## 2. JetOctopus (Bulut, enterprise)

Kaynak: [JetOctopus Enterprise](https://jetoctopus.com/enterprise/) (üretici sayfası)

| Üstün yan | Açıklama | ScreamingRabbit'e etkisi |
|---|---|---|
| **Log analizi** | Googlebot ve AI botlarının gerçekte neyi taradığı, crawl budget israfı | P2: cPanel erişim loglarını (Apache/Nginx) içe aktarma. cPanel'de loglar zaten mevcut. |
| **GSC ile derin entegrasyon** | 16+ ay GSC verisi, **kannibalizasyon**, **zombi sayfalar**, **sıralama düşüşü** tespiti | P1: Bu üç analiz doğrudan aksiyon listesine dönüşecek |
| **Uyarılar** | Trafik düşüşü, indeksleme, CWV değişiminde bildirim | P1: Zamanlanmış kontroller + e-posta uyarısı |
| **AI Internal Linker** | Zayıf sayfalara iç link fırsatlarını sıralar | P2: GSC sorguları + sayfa içeriği eşleştirmesiyle iç link önerisi |
| **Limitsiz** | URL, log, domain limiti yok | Bizde limit VPS kapasitesi |

## 3. Lumar (eski adıyla Deepcrawl)

Kaynak: [Lumar Protect](https://www.lumar.io/protect/) (üretici sayfası)

| Üstün yan | Açıklama | ScreamingRabbit'e etkisi |
|---|---|---|
| **Protect: otomatik QA testleri** | 350+ hazır test. Şablon veya kritik sayfalar saatlik test edilebilir. | P1: "Kritik sayfa izleme": müşterinin ana sayfası, ürün/hizmet sayfaları sık aralıklarla kontrol edilir |
| **CI/CD entegrasyonu ve eşik** | Başarısız test uyarı verir ya da yayını durdurur | P2: Webhook / API ile "bu site sağlıklı mı?" yanıtı |
| **Monitor** | Çoklu domainde metrik trendleri | P1: Müşteri başına sağlık skoru trendi |
| **Impact** | Optimizasyonun sonucunu gösterme | P1: Aksiyon tamamlandı → önce/sonra GSC metriği. Müşteriye değer kanıtı. |
| **GEO (AI arama) metrikleri** | AI aramada görünürlük | P1/P2: AI bot erişimi, llms.txt kontrolleri (aşağıya bakın) |

## 4. Oncrawl

Kaynak: [Oncrawl segmentasyon makalesi](https://www.oncrawl.com/?p=15165) (üretici sayfası)

| Üstün yan | Açıklama | ScreamingRabbit'e etkisi |
|---|---|---|
| **Metrik tabanlı segmentasyon** | Sayfaları GSC pozisyonu, organik ziyaret, bot hit'i gibi verilerle gruplama | P1: Segment = URL deseni (`/blog/`, `/urun/`) veya GSC metriği |
| **Crawl × log × GSC çapraz analizi** | Örn. derinlik arttıkça tıklama nasıl düşüyor | P1: "Derinlik vs GSC tıklama" ve "inlink sayısı vs pozisyon" grafikleri |
| **Custom field ile segment** | XPath/regex ile sayfa türü çıkarıp gruplama | P1: WordPress'te post type / kategori otomatik segment olabilir |

## 5. Ahrefs Site Audit / Semrush Site Audit

Genel SEO paketlerinin parçası. Güçlü yanları teknik derinlikten çok **ekosistem**: backlink verisi, anahtar kelime hacmi ve sağlık skoru tek panelde. Kullanıcı dostu "Health Score" kavramını popülerleştirdiler.

| Üstün yan | ScreamingRabbit'e etkisi |
|---|---|
| Tek sayıyla site sağlık skoru | P0: Basit, açıklanabilir sağlık skoru (müşteriye anlatması kolay) |
| Bulutta zamanlanmış denetim + e-posta | P1 |
| Sorun başına "nasıl düzeltilir" | P0 (Sitebulb Hints ile aynı fikir) |
| Backlink / keyword hacmi | — (lisanslı veri, kapsam dışı) |

## 6. Yükselen alan: AI arama (GEO) denetimleri

2025-2026'da araçların neredeyse hepsi "AI arama hazırlığı" kontrolleri eklemeye başladı (Screaming Frog AI bot user-agent'ı, Lumar GEO modülü, JetOctopus AI bot log analizi). Bunlar ucuz ama değerli kontroller:

- robots.txt'de GPTBot, ClaudeBot, PerplexityBot, Google-Extended vb. botlara izin/engel durumu
- `llms.txt` varlığı ve geçerliliği
- İçeriğin JS olmadan erişilebilir olması (AI botlarının çoğu JS render etmez)
- Yapısal veri (Organization, Article, FAQ, Product) kapsamı
- Yazar / tarih / E-E-A-T sinyallerinin sayfada görünürlüğü

ScreamingRabbit için **P1**. Müşteriye satılabilecek yeni ve anlaşılır bir hizmet kalemi.

## 7. Boşluk analizi: hiçbirinin tam yapmadığı şey

Rakiplerin hepsi bir parçayı iyi yapıyor ama Serhan'ın iş modeline (ajans/danışman, WordPress ağırlıklı müşteriler, GSC erişimi var) tam uyan bir araç yok:

1. **Aksiyon odaklılık:** Araçlar sorun listesi verir. Kim, neyi, hangi sırayla düzeltecek, ne kadar etki bekleniyor sorusunu yanıtlayan bir **iş listesi** üretmezler (Lumar'ın görev özelliği kısmen yapıyor, enterprise fiyatıyla).
2. **GSC bağlamıyla önceliklendirme:** "Bu sayfa kırık link içeriyor" yerine "bu sayfa ayda 1.200 tıklama alıyor ve kırık link içeriyor, önce bunu düzelt" demek. JetOctopus/Oncrawl buna yakın ama enterprise fiyatlı.
3. **WordPress farkındalığı:** WordPress'e özgü sorunlar (ek sayfası URL'leri, `?p=` parametreleri, etiket/arşiv şişmesi, yazar arşivleri, Yoast/RankMath kanonik çakışmaları, `/wp-json/` sızıntısı) hiçbir genel araçta ayrı bir kategori değil.
4. **Türkçe ve müşteriye hazır rapor:** Açıklamalar, rapor ve aksiyon dili Türkçe.
5. **Uygun maliyet:** Kendi VPS'inde, lisans ücreti olmadan.

Bu beş madde ScreamingRabbit'in **konumlandırması** olarak [04](04-mvp-kapsami-ve-yol-haritasi.md) dokümanına taşındı.
