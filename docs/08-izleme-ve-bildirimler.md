# 08 · Erişilebilirlik İzleme ve Bildirimler

> Durum tarihi: 25 Ekim 2026 · Durum: **plan** (kod yok)
> Bağlam: [04 §4.4 Otomasyon](04-mvp-kapsami-ve-yol-haritasi.md) (kritik sayfa izleme, e-posta bildirimi) ve [07 WordPress modülü](07-wordpress-modulu.md). Bu doküman, sitenin erişilemez olduğunu anında yakalayan bir izleyiciyi ve tüm uyarıların tek yerden yönetildiği bildirim merkezini tanımlar.

## 1. Özet

| Parça | Ne yapar |
|---|---|
| **Erişilebilirlik izleyici (uptime)** | Her projenin seçili adreslerini dakikada bir kontrol eder. Site düşünce haber verir, düzelince tekrar haber verir ve kesintinin ne kadar sürdüğünü yazar. |
| **Olay (incident) kaydı** | Her kesinti başlangıç, bitiş, süre ve neden ile saklanır. |
| **Bildirim merkezi** | Uptime dahil tüm uyarıları (SSL, kritik sayfa değişimi, yeni kritik bulgu, GSC düşüşü, WordPress açığı) kanallara yönlendirir, tekrarları bastırır, haftalık özet üretir. |
| **Erişilebilirlik raporu** | Müşteri bazında aylık uptime yüzdesi, kesinti listesi, ortalama yanıt süresi. Müşteri raporuna bölüm olarak girer. |

## 2. Erişilebilirlik izleyici

### 2.1 Ne kontrol edilir

Proje başına bir veya birkaç **izleme hedefi** (varsayılan: ana sayfa). Her kontrolde:

| Kontrol | Başarısız sayılır |
|---|---|
| HTTP yanıtı | 5xx, 4xx (hedef 200 bekliyorsa), bağlantı hatası |
| Zaman aşımı | 10 saniyede yanıt yok |
| DNS | Alan adı çözülemiyor |
| SSL | Sertifika geçersiz veya süresi dolmuş |
| İçerik (isteğe bağlı) | Sayfada beklenen bir kelime yok (örn. "beyaz ekran" veya hosting'in "hesap askıya alındı" sayfası 200 dönse bile yakalanır) |
| Yanıt süresi | Kaydedilir; 3 ardışık kontrolde eşiği (varsayılan 5 sn) aşarsa "yavaş" uyarısı |

WordPress projelerinde ek hedef önerisi: `/wp-login.php` yerine `/wp-json/` (veritabanı bağlantı hatasını da yakalar, giriş sayfasını yormaz).

### 2.2 Yanlış alarmı önleme

- **Doğrulama:** İlk başarısızlıkta alarm yok. Aynı çalışma içinde 5 sn sonra bir kez daha denenir; o da başarısızsa **kesinti açılır** ve bildirim gider.
- **Kanarya kontrolü:** Her çalışmada önce bilinen sağlam iki adrese (örn. `https://www.google.com`, `https://1.1.1.1`) istek atılır. Kanaryalar da başarısızsa sorun bizim sunucumuzun internetindedir: o dakika için tüm alarmlar bastırılır, yalnızca iç kayıt düşülür.
- **Düzelme:** Kesinti, ilk başarılı kontrolde kapanır; süre = ilk başarısız kontrol → ilk başarılı kontrol.

### 2.3 Paylaşımlı hosting (Hostinger) kısıtları

- Cron dakikada bir çalışıyor ve kuyruk işçisi zamanlayıcının içinde (PR #7). Bu yüzden izleyici **kuyruğa girmez**, zamanlayıcıda tarama işlerinden **önce** çalışan ayrı, hızlı bir komuttur (`monitor:run`, üst üste binme kilidiyle). Uzun bir tarama parçası izlemeyi geciktirmez.
- Süre bütçesi: çalışma başına en fazla ~20 sn. İstekler eşzamanlı (havuz: 10) gönderilir, zaman aşımı 10 sn. Bu bütçe ~50 hedefi rahat taşır; daha fazlası GoDaddy VPS taşınmasıyla gelir.
- Kontrol sıklığı hedef başına seçilir: 1, 5 veya 15 dakika. Varsayılan **1 dakika**.
- **Kendi kendini izleme sorunu:** Hostinger düşerse uygulama da düşer ve kimse haber vermez. Çözüm: ücretsiz bir dış "kalp atışı" servisi (Healthchecks.io ücretsiz plan). Zamanlayıcı her dakika oraya ping atar; ping 5 dakika gelmezse servis Serhan'a e-posta/Telegram ile haber verir. Kurulumu tek seferlik: Serhan hesap açar, ping adresini uygulama ayarına yapıştırır.

### 2.4 Veri ve saklama

| Tablo | İçerik | Saklama |
|---|---|---|
| `monitors` | Proje, URL, sıklık, beklenen durum/kelime, açık/kapalı | kalıcı |
| `monitor_checks` | Her kontrol: zaman, durum kodu, süre, hata türü | 30 gün (ham) |
| `monitor_daily` | Gün başına uptime %, ortalama/p95 yanıt süresi | kalıcı |
| `incidents` | Başlangıç, bitiş, süre, neden (DNS / zaman aşımı / 5xx / SSL / içerik), bildirim kayıtları | kalıcı |

## 3. Bildirim merkezi

### 3.1 Olay türleri

| Olay | Kaynak | Varsayılan önem |
|---|---|---|
| Site erişilemez / tekrar erişilebilir (süreyle) | Uptime | kritik |
| Site yavaş | Uptime | yüksek |
| SSL sertifikası 14 gün içinde bitiyor | Uptime (her gün bir kez) | yüksek |
| Alan adı 30 gün içinde bitiyor (RDAP) | Günlük kontrol | yüksek |
| Kritik sayfada değişim (durum kodu, noindex, canonical, title) | Kritik sayfa izleme (04 §4.4) | kritik |
| Taramada yeni kritik bulgu | Tarama karşılaştırma | yüksek |
| GSC'de anlamlı tıklama düşüşü | GSC senkronu | yüksek |
| Açığı bilinen eklenti/çekirdek tespit edildi | [07 WP-2](07-wordpress-modulu.md) | kritik |
| WordPress eklenti bağlantısı koptu | 07 WP-2 | orta |
| Uygulamanın kendisi: tarama hatası, cron durdu | Sistem | yüksek |

### 3.2 Kanallar

| Kanal | Karar | Neden |
|---|---|---|
| **E-posta** | Faz M-1 | Hostinger SMTP hazır, ek maliyet yok |
| **Telegram** | Faz M-1, **anlık uyarılar için önerilen kanal** | Ücretsiz bot, kurulum 2 dakika (bot linkine tıkla → "Başlat"), anlık telefon bildirimi |
| WhatsApp | Faz M-3 | Meta Business doğrulaması ve onaylı şablon mesaj zorunlu, mesaj başı ücretli; şimdilik değmez |
| Slack/Discord webhook | Faz M-3, istenirse | Tek ayar alanı, kolay |
| SMS | Kapsam dışı | Ücretli, Telegram yeterli |

### 3.3 Kurallar

- **Yönlendirme:** Kritik → anında Telegram + e-posta. Yüksek → anında Telegram. Orta/düşük → yalnızca haftalık özet e-postası.
- **Tekrar bastırma:** Açık bir kesinti için yalnızca "düştü" ve "düzeldi" mesajları gider; arada 1 saatte bir "hâlâ erişilemez (2 sa 15 dk)" hatırlatması.
- **Toplu kesinti:** Aynı dakikada 3+ proje düşerse tek mesajda toplanır (genelde ortak hosting sorunu).
- **Sessiz saatler:** İsteğe bağlı; kritik olaylar sessiz saatte de gider.
- **Bakım modu:** Proje için "bakımdayım, X saat alarm verme" düğmesi; bu süredeki kesintiler raporda "planlı bakım" olarak ayrılır, uptime yüzdesini düşürmez.
- **Müşteriye bildirim:** Varsayılan **kapalı**. Proje bazında açılabilir (M-3); açılınca yalnızca uptime "düştü/düzeldi" mesajları müşterinin e-postasına gider. Diğer her şey Serhan'dan geçer (04 §4.4 ilkesi).

### 3.4 Mesaj örneği (Telegram)

```
🔴 hekimdijital.com ERİŞİLEMEZ
Neden: zaman aşımı (10 sn)
Başlangıç: 25.10.2026 21:14

🟢 hekimdijital.com TEKRAR ERİŞİLEBİLİR
Kesinti süresi: 12 dk (21:14 → 21:26)
Bu ayki uptime: %99,72
```

## 4. Raporlama

- Proje panelinde: son 90 günün günlük uptime şeridi, kesinti listesi, yanıt süresi grafiği.
- Müşteri raporuna (paylaşılabilir link + PDF) "Erişilebilirlik" bölümü: dönem uptime %, kesinti sayısı ve toplam süresi, en uzun kesinti, ortalama yanıt süresi, planlı bakımlar ayrı.
- Aylık özet, aylık müşteri raporuyla birlikte üretilir (Serhan onaylar).

## 5. Faz planı

| Faz | Kapsam | Yol haritasındaki yeri |
|---|---|---|
| **M-1** | Uptime izleyici (§2), kesinti kaydı, e-posta + Telegram, SSL bitiş uyarısı, dış kalp atışı, panelde uptime ekranı | 4. adımın **ilk** işi (bağımsız ve hızlı değer; crawler MVP'si bitince) |
| **M-2** | Bildirim merkezi (§3): tüm olay türleri, yönlendirme, bastırma, haftalık özet; müşteri raporuna erişilebilirlik bölümü | 4. adım, GSC ve kritik sayfa izlemeyle birlikte |
| **M-3** | Müşteriye uptime bildirimi, bakım modu, alan adı bitiş (RDAP), WhatsApp/Slack | P2 |

## 6. Kararlar

- **Karar:** Varsayılan kontrol sıklığı 1 dakika; alarm iki ardışık başarısızlık ve kanarya kontrolünden sonra.
- **Karar:** İlk kanallar e-posta ve Telegram. WhatsApp sonraya.
- **Karar:** Müşteriye otomatik bildirim varsayılan kapalı.
- **Karar:** Uygulamanın kendisi ücretsiz bir dış kalp atışı servisiyle izlenir.
