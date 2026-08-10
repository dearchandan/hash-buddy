# Deploying Hash Buddy

Getting the Laravel API onto an EC2 instance behind your own domain, and
building an APK that talks to it.

Throughout, replace `api.yourdomain.com` with the host you actually use.

---

## 0. Read this first

**The API URL is baked into the APK at build time.** There is no in-app setting
to change it. Point DNS at the server and get HTTPS working *before* you build
the APK you hand out, or you will be rebuilding and reinstalling on every phone.

**A release APK cannot talk to `http://`.** Cleartext is permitted only in the
debug manifest. That is deliberate — login codes and bearer tokens must not
travel in the clear — and it means TLS is not optional.

---

## 1. The EC2 instance

| | |
|---|---|
| AMI | Ubuntu Server 24.04 LTS |
| Type | `t3.small` (2 vCPU / 2 GB). `t4g.small` is ARM and cheaper — pick the matching ARM AMI if you use it |
| Storage | 20 GB gp3 |
| Elastic IP | **Allocate and associate one.** Without it the public IP changes on stop/start and your DNS record goes stale |

PHP-FPM plus MySQL on one 2 GB box is fine for a test group and well beyond.
`t3.micro` (1 GB) will run, but MySQL and Composer together will make it swap.

### Security group

| Port | Source | Why |
|---|---|---|
| 22 | **Your IP only** | SSH. Never `0.0.0.0/0` |
| 80 | `0.0.0.0/0` | HTTP, needed for the Let's Encrypt challenge and the HTTPS redirect |
| 443 | `0.0.0.0/0` | The API itself |

Do **not** open 3306. MySQL stays on localhost. Do not open 8000 — nothing
should ever reach `php artisan serve`, which is a single-threaded dev server and
has no place here.

### DNS

An `A` record for `api.yourdomain.com` pointing at the Elastic IP. Confirm it
resolves before running certbot, or the certificate request will fail:

```bash
dig +short api.yourdomain.com
```

---

## 2. Server setup

> **Shortcut:** [`deploy/provision.sh`](deploy/provision.sh) does everything in
> sections 2 to 4 in one go, and is safe to re-run:
>
> ```bash
> sudo ./deploy/provision.sh api.yourdomain.com you@yourdomain.com <git-repo-url>
> ```
>
> The manual steps below are what it does, kept here so you can follow along or
> diverge from it. It deliberately does not touch firewall rules — that is the
> security group's job, and hiding it here would obscure what is exposed.

```bash
sudo apt update && sudo apt upgrade -y

# PHP version depends on the release: 8.3 on Ubuntu 24.04, 8.5 on 26.04.
PHP_VER=$(apt-cache search --names-only '^php[0-9]\.[0-9]+-fpm$' \
  | sed -E 's/^php([0-9]+\.[0-9]+)-fpm.*/\1/' | sort -V | tail -1)

sudo apt install -y nginx mysql-server git unzip \
  php${PHP_VER}-fpm php${PHP_VER}-mysql php${PHP_VER}-mbstring \
  php${PHP_VER}-xml php${PHP_VER}-curl php${PHP_VER}-zip \
  php${PHP_VER}-bcmath php${PHP_VER}-intl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Ubuntu carries PHP in its default repositories, so no third-party PPA is needed.
`composer.json` accepts PHP 8.3 and up, so whatever the release ships is fine.

### Swap, on a 1 GB instance

MySQL and `composer install` together will exhaust a 1 GB box and get composer
OOM-killed mid-install. If you are on `t2.micro`/`t3.micro`, add swap first:

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

`provision.sh` does this automatically when it sees less than 2 GB of RAM.

### Database

```bash
sudo mysql_secure_installation

sudo mysql -e "
CREATE DATABASE hash_buddy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hashbuddy'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON hash_buddy.* TO 'hashbuddy'@'localhost';
FLUSH PRIVILEGES;"
```

Use a generated password, not one you invent. It only ever lives in `.env`.

---

## 3. Deploy the code

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <your-repo-url> hash-buddy
sudo chown -R $USER:www-data /var/www/hash-buddy
cd /var/www/hash-buddy/api

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### `.env` for production

```env
APP_NAME="Hash Buddy"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hash_buddy
DB_USERNAME=hashbuddy
DB_PASSWORD=the-password-you-generated

# Login: see section 5
HASHBUDDY_OTP_DEBUG=false
HASHBUDDY_OTP_TEST_NUMBERS=+919800000001,+919800000002
HASHBUDDY_SMS_DRIVER=log
```

`APP_DEBUG=false` matters: with it on, any error response ships a full stack
trace including your database credentials.

### Permissions, migrate, cache

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

php artisan migrate --force          # --force: no prompt in production
php artisan db:seed --class=ZoneSeeder --force

php artisan config:cache
php artisan route:cache
```

Seed **only** `ZoneSeeder`. `DatabaseSeeder` also runs `DemoSeeder`, which
creates fake travellers — it refuses to run when `APP_ENV=production`, but be
explicit rather than relying on that.

After any later `.env` change, re-run `php artisan config:cache`. A cached
config ignores the file until you do.

---

## 4. nginx and HTTPS

`/etc/nginx/sites-available/hash-buddy`:

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/hash-buddy/api/public;

    index index.php;
    charset utf-8;

    client_max_body_size 8M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Note `root` points at `api/public`, not the repo root. Anything else exposes
`.env` to the internet.

```bash
sudo ln -s /etc/nginx/sites-available/hash-buddy /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.yourdomain.com
```

Certbot rewrites the server block for TLS and installs a renewal timer. Check it:

```bash
sudo certbot renew --dry-run
```

### Verify

```bash
curl -s https://api.yourdomain.com/api/v1/zones | head -c 200
curl -s https://api.yourdomain.com/up
```

Zones should return JSON. If you get a 500, look in
`/var/www/hash-buddy/api/storage/logs/laravel.log` — with `APP_DEBUG=false` the
response deliberately tells you nothing.

---

## 5. Login on a public server

`HASHBUDDY_OTP_DEBUG` is **ignored when `APP_ENV=production`**. That is a
deliberate guard: if it were honoured, anyone who found your domain could
request a code for any phone number, read it from the response, and sign in as
that person.

Two ways forward:

**For a small test group (now).** List the numbers in
`HASHBUDDY_OTP_TEST_NUMBERS`. Those numbers, and only those, get their code back
in the API response. Everyone else receives a `503 sms_unavailable`. Empty this
list the day you go beyond testing.

**For real users (before launch).** Implement `OtpService::deliver()` against
MSG91, Gupshup, or Twilio, set `HASHBUDDY_SMS_DRIVER` to that provider, and
clear the test numbers. Until then the service refuses to issue a code it cannot
deliver, rather than leaving a login screen spinning forever.

---

## 6. Build the APK

Once `https://api.yourdomain.com/api/v1/zones` returns JSON:

```bash
cd app
flutter build apk --release --split-per-abi \
  --dart-define=HASH_BUDDY_API=https://api.yourdomain.com/api/v1
```

Give both phones `app-arm64-v8a-release.apk` — every Android handset from the
last several years is arm64. The `armeabi-v7a` build is for older 32-bit
devices; `x86_64` is emulator-only.

Installing: transfer the APK and open it. Android will ask permission to install
from that source once. Sideloading works fine for a handful of testers; past
that, Play Console **internal testing** is worth the setup because it handles
updates, and it does not train people to dismiss security prompts.

**Sign it properly before you distribute.** Without `android/key.properties` the
build falls back to the debug key, and when you later add a real key the update
will refuse to install over it — every tester has to uninstall first, losing
their session. See the *Shipping an APK* section of the README.

---

## 7. Deploying an update

```bash
ssh -i ~/.ssh/key.pem ubuntu@YOUR_IP 'sudo bash /var/www/hash-buddy/deploy/release.sh'
```

[`deploy/release.sh`](deploy/release.sh) pulls `origin/main`, reinstalls
dependencies, migrates, rebuilds the caches as `www-data`, reloads php-fpm, and
smoke-tests the result — exiting non-zero with the previous commit SHA if the
smoke test fails, so a bad deploy announces itself instead of sitting there
returning 500s.

Two things it gets right that are easy to get wrong by hand:

**Cache only at the final path.** Laravel bakes absolute paths into
`bootstrap/cache/config.php`. Build a tree at one path, cache it, then move it,
and the app looks for its log directory where it used to be. Requests that write
a log 500; read-only ones keep working. A half-broken API is much harder to
diagnose than a dead one, so the script never caches anywhere but in place.

**Chown after composer, not before.** `composer install` triggers
`package:discover`, which writes `bootstrap/cache` as the deploy user. Fix
permissions first and composer immediately undoes it.

The APK only needs rebuilding when the Flutter code changes — or when the API
URL does, since that value is compiled in.

### Rolling back

Every run prints the commit it started from:

```bash
cd /var/www/hash-buddy
sudo -u ubuntu git checkout <previous-sha>
sudo bash deploy/release.sh
```

Migrations are the exception — `git checkout` does not un-migrate. If a release
included a destructive migration, roll that back deliberately with
`php artisan migrate:rollback` before reverting the code.

---

## Still missing before real users

Deploying does not make these go away, and the first two are the ones that will
bite during a live test:

- **No push notifications.** Two lone travellers only pair when one opens a ride
  and the other happens to look again. With two testers you can work around it
  by coordinating out of band; with strangers it is the product's main gap.
- **No in-app chat**, so testers will need each other's numbers to actually meet
  at the kerb.
- **Fares are seeded estimates**, not quotes. Check them against real BLR fares.
- **No reporting or blocking**, and no rate limit on anything but the OTP
  endpoints.
- **Backups.** Nothing backs up MySQL. `mysqldump` on a cron to S3 is twenty
  minutes of work and worth doing before you have data you care about.
- **A privacy policy.** You collect phone numbers, which puts you in scope of
  India's DPDP Act 2023 regardless of app-store requirements.
