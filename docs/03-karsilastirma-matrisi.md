# 03 · Karşılaştırma Matrisi

> Durum tarihi: 24 Ekim 2026
> ✅ var · 🟡 kısmi / sınırlı · ❌ yok · ❔ doğrulanamadı
> "ScreamingRabbit (hedef)" sütunu planlanan durumu ve hangi aşamada geleceğini gösterir (P0 = MVP, P1 = GSC/otomasyon aşaması, P2 = sonrası).

Rakip hücreleri [01](01-screaming-frog-ozellik-envanteri.md) ve [02](02-rakip-analizi.md) dokümanlarındaki kaynaklara dayanır. Üretici beyanları bağımsız test edilmemiştir.

## 1. Çalışma modeli ve maliyet

| | Screaming Frog | Sitebulb | JetOctopus | Lumar | Oncrawl | Ahrefs/Semrush Audit | **ScreamingRabbit (hedef)** |
|---|---|---|---|---|---|---|---|
| Çalışma yeri | Masaüstü | Masaüstü + Bulut | Bulut | Bulut | Bulut | Bulut | **Kendi VPS'in (web)** |
| Bilgisayar kapalıyken tarama | ❌ | ✅ (Cloud) | ✅ | ✅ | ✅ | ✅ | **✅** |
| Fiyat | £199/yıl | £95+/ay (Cloud) | Teklif | Teklif (enterprise) | Teklif | Paket aboneliği | **Lisans yok, yalnızca VPS** |
| Türkçe arayüz/rapor | ❌ | ❌ | ❌ | ❌ | ❌ | 🟡 | **✅** |

## 2. Tarama ve teknik denetim

| | SF | Sitebulb | JetOctopus | Lumar | Oncrawl | Ahrefs/Semrush | **SR** |
|---|---|---|---|---|---|---|---|
| Durum kodları, redirect zinciri | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P0** |
| Title / meta / heading | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P0** |
| Canonical, robots, indekslenebilirlik | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P0** |
| Birebir kopya içerik | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P0** |
| Yakın kopya | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | **P1** |
| Semantik benzerlik (embedding) | ✅ | ❔ | ❔ | ❔ | ❔ | ❔ | **P2** |
| hreflang | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P1** |
| Yapısal veri doğrulama | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | **P1** |
| JS render | ✅ (ücretli) | ✅ | ✅ | ✅ | ✅ | ✅ | **P1 (opsiyonel)** |
| Custom extraction | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | **P1** |
| Taranamayan link tespiti | ✅ (24.0) | ❔ | ❔ | ❔ | ❔ | ❔ | **P1** |
| Sitemap analizi | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P0** |
| Erişilebilirlik (axe) | ✅ | ✅ | ❔ | ✅ | ❔ | ❌ | **P2** |
| PSI / Lighthouse / CrUX | ✅ | ✅ | ✅ | ✅ | ✅ | 🟡 | **P1** |
| WordPress'e özel denetimler | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | **P0 (temel), P1 (gelişmiş)** |
| AI arama / GEO denetimleri | 🟡 (AI UA) | ❔ | ✅ (AI bot log) | ✅ | ❔ | 🟡 | **P1** |

## 3. Veri entegrasyonu ve bağlam

| | SF | Sitebulb | JetOctopus | Lumar | Oncrawl | Ahrefs/Semrush | **SR** |
|---|---|---|---|---|---|---|---|
| GSC performans verisi | ✅ | ✅ | ✅ (16+ ay) | ✅ | ✅ | 🟡 | **P1** |
| GSC URL Inspection | ✅ | ❔ | ❔ | ❔ | ❔ | ❌ | **P1** |
| Kannibalizasyon tespiti | ❌ | ❔ | ✅ | ❔ | 🟡 | 🟡 | **P1** |
| Zombi / düşen sayfa tespiti | ❌ | ❔ | ✅ | ❔ | 🟡 | ❔ | **P1** |
| GA4 | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | **P2** |
| Log dosyası analizi | Ayrı ürün | ❌ | ✅ | ✅ | ✅ | ❌ | **P2** |
| Metrik tabanlı segmentasyon | 🟡 (Segments) | 🟡 | ✅ | ✅ | ✅ | ❌ | **P1** |
| Backlink / keyword hacmi | ✅ (ücretli API) | ❌ | ❌ | ❌ | 🟡 | ✅ | **— (kapsam dışı)** |

## 4. Aksiyon, otomasyon ve raporlama

| | SF | Sitebulb | JetOctopus | Lumar | Oncrawl | Ahrefs/Semrush | **SR** |
|---|---|---|---|---|---|---|---|
| Sorun açıklaması + çözüm önerisi | 🟡 | ✅ (Hints) | 🟡 | ✅ | 🟡 | ✅ | **P0** |
| Önceliklendirme (önem derecesi) | 🟡 | ✅ | 🟡 | ✅ | ✅ | ✅ | **P0** |
| **GSC trafiğiyle ağırlıklı öncelik** | ❌ | ❌ | 🟡 | 🟡 | 🟡 | ❌ | **P1 (ana farklılaştırıcı)** |
| **İş listesi (atanabilir, durumlu aksiyonlar)** | ❌ | ❌ | ❌ | 🟡 | ❌ | 🟡 | **P1 (ana farklılaştırıcı)** |
| Zamanlanmış tarama | 🟡 (PC açık) | ✅ | ✅ | ✅ | ✅ | ✅ | **P1** |
| Zamanlanmış özel kontroller (QA testleri) | ❌ | ❌ | 🟡 | ✅ (Protect) | ❌ | ❌ | **P1** |
| Otomatik tarama karşılaştırma | ✅ (24.0) | ✅ | ✅ | ✅ | ✅ | ✅ | **P1** |
| E-posta uyarısı | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | **P1** |
| Müşteriye markalı rapor | ❌ (Data Studio) | ✅ | 🟡 | ✅ | 🟡 | ✅ | **P0 (basit), P1 (markalı)** |
| Önce/sonra etki ölçümü | ❌ | ❌ | 🟡 | ✅ (Impact) | 🟡 | ❌ | **P1** |
| AI asistandan kullanım (MCP) | ✅ (24.0) | ❔ | ❔ | ❔ | ❔ | ❔ | **P2** |

## 5. Özet

- **Masa tabanı (P0):** Klasik teknik denetimlerin hepsi her araçta var. Bunlar ScreamingRabbit'in "olmazsa olmaz" temeli. Kendi başına farklılaştırıcı değil.
- **Kazanacağımız yer (P1):** GSC trafiğiyle ağırlıklandırılmış öncelik + atanabilir iş listesi + bilgisayardan bağımsız zamanlanmış kontroller + önce/sonra etki ölçümü. Bu dördünü bir arada uygun fiyatla sunan bir araç yok. Enterprise araçlar parçalarını yapıyor ama danışman ölçeği için pahalı.
- **Niş avantaj:** WordPress'e özel denetimler ve Türkçe müşteri raporu.
- **Bilinçli olarak dışarıda:** Lisanslı backlink/keyword verisi, AMP, CLI, Data Studio.
