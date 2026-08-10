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

For an Android emulator, install Android Studio first (`flutter doctor` currently
flags it as missing), then:

```bash
flutter run --dart-define=HASH_BUDDY_API=http://10.0.2.2:8000/api/v1
```

`10.0.2.2` is how the Android emulator reaches your host. Use `127.0.0.1` for the
iOS simulator, or your machine's LAN IP for a physical device.
`android:usesCleartextTraffic="true"` is already set in the manifest so debug
builds can reach a local `http://` API — remove it before shipping a release.

```bash
flutter analyze   # clean
flutter test      # 8 tests
```

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
