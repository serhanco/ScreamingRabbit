# 06 · Kurulum Kılavuzu (Hostinger paylaşımlı hosting)

> Durum: **Taslak.** Hostinger ekran adları ilk kurulumda doğrulanacak, kılavuz o zaman kesinleştirilecek.

## Nasıl çalışıyor

Kod GitHub'da. `main` dalına her değişiklik geldiğinde GitHub, kütüphaneler ve arayüz dosyaları hazır olarak **`deploy`** adlı bir dal üretir (`tools/build-deploy.sh`, `.github/workflows/deploy-branch.yml`). Hostinger'ın **Git** ekranı bu dalı doğrudan web klasörüne çeker. Terminal ve ZIP gerekmez. Güncelleme, Git ekranında tek tıkla yapılır.

`deploy` dalının düzeni (web klasörünün kendisi olur):

```
index.php  install.php  .htaccess  css/  js/  fonts/   ← web'e açık dosyalar
app/                                                    ← uygulama (web'den engelli: app/.htaccess)
```

## İlk kurulum

Ön koşullar:

- [ ] hPanel → **Databases → Management**: bir veritabanı, kullanıcı ve şifre oluşturulmuş (şifre sohbette paylaşılmaz)
- [ ] hPanel → **Advanced → PHP Configuration**: PHP 8.3 veya üstü

Adımlar:

1. hPanel → **File Manager** → `public_html` içindeki her şeyi sil (Hostinger'ın bıraktığı `default.php` gibi). Git, klasörün boş olmasını ister.
2. hPanel → **Advanced → Git** → yeni depo ekle:
   - Depo adresi: `https://github.com/serhanco/ScreamingRabbit.git`
   - Dal: `deploy`
   - Klasör: boş bırak (`public_html`'e kurulur)
   Oluştur'a, sonra **Deploy**'a bas.
3. Tarayıcıda `https://hekimdijital.com` aç. Seni kurulum sayfasına yönlendirir.
4. Kurulum sayfasındaki **kurulum anahtarı** için File Manager'da `domains/hekimdijital.com/screamingrabbit-data/storage/app/install.key` dosyasını aç, içindeki metni kopyala ve forma yapıştır.
5. Formu doldur: veritabanı sunucusu `localhost`, hPanel'de oluşturduğun veritabanı adı, kullanıcı ve şifre, kendi e-postan ve en az 12 karakterlik bir şifre. **Kurulumu başlat.**
6. Sayfa sana bir **cron komutu** gösterir. hPanel → **Advanced → Cron Jobs** → her dakika çalışan yeni bir görev olarak ekle.
7. `https://hekimdijital.com/admin` adresinde giriş yap. İlk girişte telefondaki doğrulama uygulamasını (Google Authenticator, Authy vb.) kurmanı ister: QR kodu okut ve **kurtarma kodlarını güvenli bir yere kaydet.**

Güvenlik: Kurulum sayfası, yalnızca hosting hesabına erişimi olanın okuyabileceği anahtarı sorar. Kurulum bitince kendini kilitler ve siler. `app/` klasörü ve `.env` dosyası web'den erişilemez.

## Güncelleme

1. Değişiklikler `main` dalına birleşince GitHub `deploy` dalını kendisi yeniler (birkaç dakika).
2. hPanel → **Advanced → Git** → ilgili depoda **Deploy** (güncellemeleri çek).

Veritabanı değişikliği içeren bir sürüm olursa, ayrıca belirtilecektir.

## Başka bir domaine veya VPS'e taşıma

1. Kodu yeni sunucuya aynı yoldan kur (`deploy` dalı).
2. Eski veritabanını dışa aktarıp yenisine yükle (phpMyAdmin).
3. `app/.env` içinde `APP_URL` ve veritabanı bilgilerini güncelle.
4. DNS'i yeni sunucuya çevir, SSL'in aktif olduğunu kontrol et.
5. Eski `.env` içindeki `APP_KEY` değerini yeni `.env`'e **aynen** kopyala. Aksi halde şifrelenmiş veriler (2FA anahtarları) okunamaz.

## Alternatif: SSH ile kurulum (ileri düzey)

SSH erişimi olanlar `git clone` ve `composer install` ile de kurabilir. Ayrıntılar için `deploy.sh` dosyasına bakın.
