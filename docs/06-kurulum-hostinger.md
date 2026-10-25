# 06 · Kurulum Kılavuzu (Hostinger paylaşımlı hosting)

> Durum: **Taslak.** Hostinger'a özgü ekran adları ilk kurulumda doğrulanacak ve bu doküman o zaman kesinleştirilecek.
> İki yol var. **Yol A** terminal gerektirmez ve önerilen yoldur. **Yol B** SSH'ye alışkın olanlar içindir.

## Ortak ön koşullar

- [x] hekimdijital.com boş PHP sitesi olarak hazır, SSL aktif
- [ ] hPanel → **Databases → Management**: bir veritabanı, kullanıcı ve şifre oluşturulmuş (şifre sohbette paylaşılmaz)
- [ ] hPanel → **Advanced → PHP Configuration**: PHP 8.3 veya üstü seçili

## Yol A: Terminalsiz kurulum (ZIP + tarayıcı)

Hazır paket `screamingrabbit-release.zip` içinde iki klasör var: `screamingrabbit` (uygulama) ve `public_html` (web'e açık kısım). Paketi, kod deposundaki `tools/build-release.sh` betiği hazırlar.

1. hPanel → **Files → File Manager** aç. `domains/hekimdijital.com/` klasörüne gir (`public_html`'in bir üstü).
2. ZIP'i bu klasöre **yükle** (Upload), sonra ZIP'e sağ tıklayıp **Extract** ile aç. Sonuçta yan yana `public_html` ve `screamingrabbit` klasörleri olmalı. Sorarsa mevcut dosyaların üzerine yazmaya izin ver.
3. `public_html` içinde Hostinger'ın bıraktığı bir `default.php` varsa sil.
4. `screamingrabbit` klasöründeki **`install.key`** dosyasını aç (çift tıklama), içindeki metni kopyala.
5. Tarayıcıda `https://hekimdijital.com/install.php` adresini aç. Formu doldur:
   - Kurulum anahtarı: az önce kopyaladığın metin
   - Veritabanı: sunucu `localhost`, hPanel'de oluşturduğun ad, kullanıcı ve şifre
   - Yönetici hesabı: adın, e-postan ve en az 12 karakterlik bir şifre
6. **Kurulumu başlat**'a bas. Başarılı olunca sayfa sana cron komutunu gösterir ve kurulum sayfası kendini siler.
7. hPanel → **Advanced → Cron Jobs**: yeni görev, **her dakika**, komut olarak o sayfada gösterilen satır.
8. `https://hekimdijital.com/admin` adresinde giriş yap. İlk girişte telefondaki doğrulama uygulamasını (Google Authenticator, Authy vb.) kurmanı ister: QR kodu okut ve **kurtarma kodlarını güvenli bir yere kaydet.**

Güvenlik: Kurulum sayfası, `install.key` içindeki metni bilmeyen kimsenin kurulum yapmasına izin vermez ve kurulum bitince kendini kilitler.

### Güncelleme (Yol A)

Yeni sürüm çıktığında yeni bir ZIP hazırlanır. `screamingrabbit` ve `public_html` içindeki dosyaların üzerine yazılır, ancak **`screamingrabbit/.env`** dosyasına ve **`screamingrabbit/storage`** klasörüne dokunulmaz. Ardından veritabanı değişiklikleri için panelde **Ayarlar → Güncelle** düğmesi eklenecek (henüz yok). O zamana kadar güncellemeyi birlikte yaparız.

## Yol B: SSH ile kurulum

```bash
cd ~
git clone https://github.com/serhanco/ScreamingRabbit.git screamingrabbit
cd screamingrabbit
cp .env.example .env
nano .env        # APP_ENV=production, APP_DEBUG=false, APP_URL, DB_* değerlerini doldur
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan filament:assets
php artisan make:filament-user
```

Web adresini `~/screamingrabbit/public` klasörüne bağlamak için (klasör yolu hesaba göre değişebilir):

```bash
mv ~/domains/hekimdijital.com/public_html ~/domains/hekimdijital.com/public_html.eski
ln -s ~/screamingrabbit/public ~/domains/hekimdijital.com/public_html
```

Cron (her dakika): `php /home/KULLANICI/screamingrabbit/artisan schedule:run >> /dev/null 2>&1`

Güncelleme: `cd ~/screamingrabbit && bash deploy.sh` (gerekirse `PHP_BIN=/usr/bin/php8.3 bash deploy.sh`).

## Başka bir domaine veya VPS'e taşıma

1. Kodu yeni sunucuya aynı yollardan kur.
2. Eski veritabanını dışa aktarıp yenisine yükle (phpMyAdmin).
3. `.env` içinde `APP_URL` ve veritabanı bilgilerini güncelle.
4. DNS'i yeni sunucuya çevir, SSL'in aktif olduğunu kontrol et.
5. Eski `.env` içindeki `APP_KEY` değerini yeni `.env`'e **aynen** kopyala. Aksi halde şifrelenmiş veriler (2FA anahtarları) okunamaz.
