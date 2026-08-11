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

**Meet at the kerb.** Sharing a ride opens a group chat and a voice call, so two
strangers can actually find each other. Calls are peer-to-peer, so no phone
number is ever revealed. See [Chat and calls](#chat-and-calls) — push and a TURN
relay both need setting up.

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

## Chat and calls

Once travellers share a ride they get a group chat and a voice call. Both open
on joining and close with the ride — they are not a way to contact someone you
merely matched with, and `PublicUserResource` still withholds phone numbers.

**Chat** polls a cursor (`GET /groups/{id}/messages?after=<id>`) rather than
holding a socket. With push carrying the "you have a message" signal, the only
job left is filling in a thread someone is already looking at, and four seconds
of latency is invisible for *"I'm at gate 4"*. That also keeps a Reverb daemon
off a 908 MB instance. The API shape does not change if you later move to
websockets.

**Calls** are peer-to-peer WebRTC. Audio never touches the server, so a call
costs two API requests and no traveller learns another's number. ICE candidates
are gathered fully before the offer is sent rather than trickled — that costs a
second or two of setup and buys a signalling path that survives push delivery
jitter without a socket anywhere.

### Two things must be set up, and neither can be done from the repo

**1. Firebase, for push.** Chat without it is worse than no chat: the sender
believes they made contact when nobody was told. It also carries call invites.

```
console.firebase.google.com → Add project
  → Add app → Android → package name  com.agilemania.hashbuddy
  → download google-services.json  →  app/android/app/google-services.json
```

Then add the Gradle plugin — `app/android/settings.gradle.kts`:

```kotlin
id("com.google.gms.google-services") version "4.4.2" apply false
```

…and `app/android/app/build.gradle.kts`:

```kotlin
id("com.google.gms.google-services")
```

For the server, Firebase Console → *Project settings* → *Service accounts* →
**Generate new private key**. Put the JSON on the box **outside** the repo, then:

```env
HASHBUDDY_PUSH_DRIVER=fcm
FCM_PROJECT_ID=your-project-id
FCM_CREDENTIALS_PATH=/etc/hash-buddy/fcm.json
```

`chmod 600` it and own it by `www-data` — that file can send push to every
install you have. Until all of this exists the driver stays `log`, which writes
what it would have sent; the app detects the missing config and disables push
rather than crashing.

**The API URL and the Firebase config are both baked in at build time**, so
adding `google-services.json` means rebuilding the APK.

**2. Security group, for the TURN relay.** [`deploy/turn.sh`](deploy/turn.sh)
installs and configures coturn, but deliberately does not touch AWS — what is
exposed to the internet belongs in the console, not buried in a shell script.

| Port | Protocol | Why |
|---|---|---|
| 3478 | UDP | TURN control |
| 3478 | TCP | TURN over TCP, for wifi that blocks UDP |
| 49160-49200 | UDP | media relay |

This is not optional. Indian mobile carriers put subscribers behind
carrier-grade NAT, where neither handset can open a path to the other and STUN
has nothing to discover. Two testers on the same wifi will connect without it
and everything will look fine; the same two on 4G usually will not. The app says
so on the call screen when no relay is configured, because that failure is
otherwise silent and reads as a broken app.

Verify with `GET /api/v1/calls/ice-servers`, then paste the returned URL and
credentials into <https://icetest.info>. A `relay` candidate means it works.

## Permissions

The release APK declares three permissions it actually exercises:

| Permission | Used for | Asked |
|---|---|---|
| `INTERNET` | the API | install time |
| `RECORD_AUDIO` | voice calls | when you tap call, with the reason on screen |
| `POST_NOTIFICATIONS` | chat and call invites (Android 13+) | first launch |

`RECORD_AUDIO` is requested at the point of use rather than on launch, where it
reads as a demand and gets denied. `MODIFY_AUDIO_SETTINGS` and `WAKE_LOCK` come
with it but are not runtime permissions. Still no location and no Bluetooth:
travellers pick a drop zone from a list, and calls go over the data connection.
An unused permission costs install conversion and a Play Console declaration for
no benefit.

If more are ever needed, the order is:

1. **`ACCESS_COARSE_LOCATION`**, foreground only, if you add live location
   between already-matched travellers to solve the kerb rendezvous, or a geofence
   that notices someone has landed at BLR. Ask at the point of use with a
   rationale, not on launch. Prefer coarse — a zone-level product does not need
   `ACCESS_FINE_LOCATION`. Never request background location: it triggers a
   special Play review this use case will not survive.
2. **Bluetooth** — no justified use. BLE proximity at the kerb is worse than a
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
| `GET` `POST` | `/groups/{id}/messages` | Chat: read (`?after=`) / send |
| `POST` | `/groups/{id}/messages/read` | Advance your read cursor |
| `GET` | `/messages/unread` | Unread counts for badges |
| `POST` `DELETE` | `/me/devices` | Register / drop a push token |
| `GET` | `/calls/ice-servers` | STUN + short-lived TURN credentials |
| `POST` | `/groups/{id}/calls` | Ring a ride mate (with your SDP offer) |
| `GET` | `/groups/{id}/calls/current` | Poll for a live call |
| `POST` | `/calls/{id}/accept` | Answer, returning your SDP answer |
| `POST` | `/calls/{id}/decline` `/hang-up` | End it |

Chat and call routes answer **404** to anyone who is not on the ride, rather than
403, so probing group ids cannot distinguish a ride you are barred from from one
that never existed.

Domain failures return a stable `error` code alongside the message —
`group_full`, `already_member`, `window_mismatch`, `gender_policy`,
`destination_mismatch`, `request_not_open` — so the client can react to the
reason rather than parse prose.

Tuning lives in `api/config/hashbuddy.php`: overlap floor, ranking weights,
group capacity, and the sedan/SUV thresholds.

---

## Not built yet

Deliberately out of scope for this slice, roughly in the order they will matter:

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
