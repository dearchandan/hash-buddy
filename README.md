# hash-buddy

Your ride companion finder personal buddy.

Hash Buddy matches travellers leaving the same airport terminal for the same part
of the city at the same time, so they can share one cab. It does **not** book
cabs, hold money, or talk to Uber and Ola — one traveller books, the rest settle
up directly.

```
hash-buddy/
├── api/    Laravel 13 REST API (PHP 8.3, MySQL, Sanctum)
└── app/    Flutter client
```

Deploying to EC2 behind your own domain: **[DEPLOY.md](DEPLOY.md)**.

---

## What works today

**Find mates.** A traveller says which terminal they are leaving, which zone they
are heading to, and the window they can depart in. The matcher returns everyone
compatible — groups with a free seat and lone travellers to pair with — ranked.

**Join a ride.** Taking a seat is transactional and race-safe: two travellers
tapping "join" on the last seat cannot both get it. The group narrows its
departure window to the intersection of its members' windows and locks when full.
Leaving frees the seat, reopens the request, and hands the host role on.

| | |
|---|---|
| Auth | Phone + OTP, Sanctum bearer tokens |
| Matching | Same airport + terminal + zone, windows overlapping ≥ 10 min |
| Ranking | Window overlap (50), vehicle fit (20), rating (15), shared flight (15) |
| Fares | Estimated per head, upgrading sedan → SUV when the group outgrows a sedan |
| Safety | Women-only filter, self-declared and **not** verified |

### Three design decisions worth knowing

**Groups default to two.** A pair fits a sedan with airport luggage, halves the
fare cleanly, and needs only one other traveller to exist. Threes need an SUV and
two other travellers. Capacity is configurable up to four.

**Fares price the right vehicle.** Dividing a solo sedan fare three ways implies
a ~67% saving that does not exist, because three people with bags need an SUV.
`FareEstimator` upgrades the vehicle class, so the same trip reports ~47%. The
honest number is the one travellers will actually experience at the kerb.

**Flight numbers are first-class.** A flight number tells you exactly when 180
people land together, which turns real-time matching into scheduled matching and
lets intent be declared days ahead. Shared flights rank higher.

---

## Running it

### API

Requires PHP 8.3+, Composer, MySQL. On Laragon everything is already bundled.

```bash
cd api
composer install
cp .env.example .env          # already points at the hash_buddy database
php artisan key:generate
mysql -u root -e "CREATE DATABASE IF NOT EXISTS hash_buddy"
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

`--host=0.0.0.0` matters: an emulator or phone cannot reach a server bound to
127.0.0.1.

The seeder creates Bengaluru zones and a small cast of travellers on the
Koramangala and HSR corridors. Sign in as **+919800000001**.

While `HASHBUDDY_OTP_DEBUG=true`, `POST /auth/otp` returns the code in its
response instead of sending an SMS. **Turn this off before anyone real uses it**
and wire `OtpService::deliver()` to an SMS provider.

```bash
php artisan test    # 37 tests
./vendor/bin/pint   # formatting
```

### App

Flutter 3.44 lives at `D:\laragon\www\flutter` but is **not on PATH**. Either add
it permanently:

```powershell
setx PATH "$env:PATH;D:\laragon\www\flutter\bin"    # new terminals only
```

…or prefix it per session:

```bash
export PATH="/d/laragon/www/flutter/bin:$PATH"
```

Platform folders (`web/`, `android/`, `ios/`) are already generated — you do not
need `flutter create` again.

The quickest way to see the app is Chrome, which needs no Android SDK:

```bash
cd app
flutter run -d chrome --dart-define=HASH_BUDDY_API=http://127.0.0.1:8000/api/v1
```

For the Android emulator, an AVD named **hashbuddy_pixel** (Pixel 7, API 36) is
already set up:

```bash
flutter emulators --launch hashbuddy_pixel
flutter run -d emulator-5554 --dart-define=HASH_BUDDY_API=http://10.0.2.2:8000/api/v1
```

`android/gradle.properties` sets `kotlin.incremental=false`. This project sits on
`D:` while the pub cache is on `C:`, and Kotlin's incremental compiler throws
`this and base files have different roots` when it tries to relativise plugin
sources across Windows drives. Moving `PUB_CACHE` onto `D:` would fix it too, at
the cost of re-downloading packages.

`10.0.2.2` is how the Android emulator reaches your host. Use `127.0.0.1` for the
iOS simulator, or your machine's LAN IP for a physical device.
`android:usesCleartextTraffic="true"` is already set in the manifest so debug
builds can reach a local `http://` API — remove it before shipping a release.

```bash
flutter analyze   # clean
flutter test      # 8 tests
```

---

## Shipping an APK

```bash
cd app
flutter build apk --release --split-per-abi \
  --dart-define=HASH_BUDDY_API=https://your-api.example.com/api/v1
```

Outputs land in `build/app/outputs/flutter-apk/`. Split per ABI gives ~15-19 MB
each instead of one ~45 MB fat APK; for Play use `flutter build appbundle`
instead and let Google do the splitting.

**Signing.** Release falls back to the debug key so the build works locally, but
a debug-signed APK cannot go to Play, and a regenerated key produces an APK that
will not install over the previous one. Generate a keystore once, keep it
somewhere you will not lose it, and never commit it:

```bash
keytool -genkey -v -keystore hashbuddy-release.jks -keyalg RSA \
        -keysize 2048 -validity 10000 -alias hashbuddy
```

Then create `app/android/key.properties` (gitignored):

```properties
storePassword=...
keyPassword=...
keyAlias=hashbuddy
storeFile=D:/keys/hashbuddy-release.jks
```

**Release builds cannot talk to `http://`.** Cleartext is permitted only in the
debug manifest, so `--dart-define` must point at an HTTPS host. For a quick test
on your own wifi before you have one, build `--debug` and use your LAN IP.

## Permissions

The release APK declares exactly one permission: `INTERNET`. Nothing in the app
uses location or Bluetooth — travellers pick a drop zone from a list — and an
unused permission costs install conversion and a Play Console declaration for
no benefit.

If that changes, the order of need is:

1. **`POST_NOTIFICATIONS`** (Android 13+). This is the one you will actually
   want first: without push, two lone travellers only pair when one opens a ride
   and the other happens to look again.
2. **`ACCESS_COARSE_LOCATION`**, foreground only, if you add live location
   between already-matched travellers to solve the kerb rendezvous, or a geofence
   that notices someone has landed at BLR. Ask at the point of use with a
   rationale, not on launch. Prefer coarse — a zone-level product does not need
   `ACCESS_FINE_LOCATION`. Never request background location: it triggers a
   special Play review this use case will not survive.
3. **Bluetooth** — no justified use. BLE proximity at the kerb is worse than a
   map plus a message, and `BLUETOOTH_SCAN` invites scrutiny you gain nothing from.

Any of these requires a Play Console **Data safety** declaration and a published
privacy policy. Collecting phone numbers already puts you in scope of India's
DPDP Act 2023, so that policy is needed regardless.

---

## API reference

All routes are prefixed `/api/v1`. Single resources come back wrapped in `data`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/zones` | Drop zones (public) |
| `POST` | `/auth/otp` | Request a login code |
| `POST` | `/auth/verify` | Exchange the code for a token |
| `GET` `PATCH` | `/me` | Read / update your profile |
| `POST` | `/auth/logout` | Revoke the current token |
| `GET` `POST` | `/ride-requests` | List your requests / create one |
| `GET` `DELETE` | `/ride-requests/{id}` | Read / cancel a request |
| `GET` | `/ride-requests/{id}/matches` | **Find mates** |
| `POST` | `/ride-requests/{id}/auto-match` | Take the best seat, or open a ride |
| `GET` `POST` | `/groups` | Your rides / open one |
| `GET` | `/groups/{id}` | Ride detail |
| `POST` | `/groups/{id}/join` | **Join a ride** |
| `POST` | `/groups/{id}/leave` | Give up your seat |

Domain failures return a stable `error` code alongside the message —
`group_full`, `already_member`, `window_mismatch`, `gender_policy`,
`destination_mismatch`, `request_not_open` — so the client can react to the
reason rather than parse prose.

Tuning lives in `api/config/hashbuddy.php`: overlap floor, ranking weights,
group capacity, and the sedan/SUV thresholds.

---

## Not built yet

Deliberately out of scope for this slice, roughly in the order they will matter:

- **Notifications.** Two lone travellers currently only pair when one opens a
  ride and the other checks their matches again. Push closes that loop and is the
  single biggest functional gap.
- **In-app chat**, so travellers coordinate the kerb rendezvous without swapping
  phone numbers.
- **Ratings after a ride.** The columns exist and are read by the ranker; nothing
  writes to them yet.
- **Trust and safety operations** — reporting, blocking, and a human on call.
  A product that puts strangers in a car together at 11pm cannot treat this as a
  later phase.
- **Real identity.** Phone-OTP alone means a banned user buys a new SIM. The
  women-only filter in particular is only as good as the gender field behind it,
  which nobody checks.
- **Expiring stale requests.** `requests.ttl_hours` is configured but no
  scheduled job acts on it yet.
- **Payments.** Out of scope by design, and the moment money moves through the
  platform, RBI payment-aggregator rules apply.

Fares in `ZoneSeeder` are rough planning figures, not quotes. Verify them before
showing them to real travellers.
