# 06 · Kurulum Kılavuzu (Hostinger paylaşımlı hosting)

> Durum: **Taslak.** Hostinger'a özgü adımlar (klasör yapısı, PHP sürümü seçimi, cron ekranı) ilk kurulumda Serhan ile birlikte doğrulanacak ve bu doküman o zaman kesinleştirilecek.
> Bu kılavuz kodu çalışır hale getirmek içindir. Kod tarafı hazır olmadan uygulamaya gerek yok.

## Ön koşullar (Serhan)

- [x] hekimdijital.com boş PHP sitesi olarak hazır, SSL aktif
- [ ] hPanel → **Databases → MySQL Databases**: bir veritabanı ve kullanıcı oluşturulmuş (adı, kullanıcı adı ve şifre not edilmiş, **sohbette paylaşılmayacak**)
- [ ] hPanel → **Advanced → SSH Access** açık
- [ ] hPanel → **Advanced → PHP Configuration**: PHP 8.3 veya üstü seçili

## Adımlar (SSH ile)

Aşağıdaki komutları SSH'de çalıştırın. `ALAN` yerine hesabınızın klasör adı gelir, Hostinger bunu SSH girişinde gösterir.

```bash
# 1. Kodu uygulama klasörüne al (public_html'in dışına)
cd ~
git clone https://github.com/serhanco/ScreamingRabbit.git screamingrabbit
cd screamingrabbit

# 2. Ayar dosyasını oluştur
cp .env.example .env
nano .env
```

`.env` içinde şu satırlar düzenlenir:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hekimdijital.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<veritabanı adı>
DB_USERNAME=<kullanıcı adı>
DB_PASSWORD=<şifre>
```

```bash
# 3. İlk kurulum
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan filament:assets

# 4. Yönetici hesabını oluştur (şifreyi komut sorar)
php artisan make:filament-user

# 5. Web adresini uygulamanın public klasörüne bağla
#    (mevcut boş public_html'i yedekleyip yerine bağlantı koyar)
mv ~/domains/hekimdijital.com/public_html ~/domains/hekimdijital.com/public_html.eski
ln -s ~/screamingrabbit/public ~/domains/hekimdijital.com/public_html
```

> Adım 5'teki klasör yolu Hostinger hesabına göre değişebilir. İlk kurulumda `ls ~/domains` ile birlikte doğrularız. Symlink izin verilmezse alternatif: uygulamayı doğrudan `public_html` içine koyup kökteki `.htaccess` ile `public/` klasörüne yönlendirmek.

## Zamanlayıcı (cron)

hPanel → **Advanced → Cron Jobs** → yeni cron, **her dakika** (`* * * * *`):

```
/usr/bin/php /home/KULLANICI/screamingrabbit/artisan schedule:run >> /dev/null 2>&1
```

`/usr/bin/php` yolu Hostinger'da farklıysa SSH'de `which php` çıktısı kullanılır. Bu tek satır, arka plan işlerini (taramalar dahil) çalıştırır.

## İlk giriş

1. `https://hekimdijital.com/admin` adresini aç.
2. E-posta ve şifreyle giriş yap.
3. Ekran, telefondaki doğrulama uygulamasıyla (Google Authenticator, Microsoft Authenticator, Authy vb.) **iki adımlı doğrulamayı** kurmanı ister. QR kodu okut ve **kurtarma kodlarını güvenli bir yere kaydet.**

## Güncelleme

Yeni bir sürüm çıktığında SSH'de:

```bash
cd ~/screamingrabbit
bash deploy.sh
```

Gerekirse PHP sürümü seçilir: `PHP_BIN=/usr/bin/php8.3 bash deploy.sh`

## Başka bir domaine veya VPS'e taşıma

1. Kodu yeni sunucuya aynı adımlarla kur.
2. Eski veritabanını dışa aktarıp yenisine yükle (phpMyAdmin veya `mysqldump`).
3. `.env` içinde `APP_URL` ve veritabanı bilgilerini güncelle.
4. DNS'i yeni sunucuya çevir, SSL'in aktif olduğunu kontrol et.
5. Eski `.env` içindeki `APP_KEY` değerini yeni `.env`'e **aynen** kopyala. Aksi halde şifrelenmiş veriler (2FA anahtarları) okunamaz.
