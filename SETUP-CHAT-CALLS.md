# Finishing chat and calls

The code is complete, deployed, and running. Three things need your accounts or
your AWS console, and until they are done the feature degrades rather than
breaks:

| | Without it | Effect |
|---|---|---|
| Security group ports | coturn runs but is unreachable | Calls fail between mobile networks |
| Firebase project | Push disabled, app still runs | No banner when a message or a match arrives |
| `POST_NOTIFICATIONS` prompt | Declined | Same as above, per handset |

---

## 1. Security group — 5 minutes, do this first

coturn is installed and running on the EC2 box, listening on 3478 with the
public address correctly mapped (`13.200.74.8/172.31.11.193`). It cannot be
reached until these are open on `sg-0d48e0b05f2b80c7b`:

| Type | Protocol | Port range | Source | Why |
|---|---|---|---|---|
| Custom UDP | UDP | `3478` | `0.0.0.0/0` | TURN control channel |
| Custom TCP | TCP | `3478` | `0.0.0.0/0` | TURN over TCP, for wifi that blocks UDP |
| Custom UDP | UDP | `49160-49200` | `0.0.0.0/0` | The media relay itself |

The relay range is deliberately narrow — 40 ports is ample for a test group and
keeps the rule list readable, rather than opening the whole ephemeral range.

**Why this is not optional.** Indian mobile carriers put subscribers behind
carrier-grade NAT. Neither handset can open a path to the other, and STUN has
nothing to discover. Two testers on the same wifi will connect without any of
this and everything will look fine; the same two on 4G will not. The app says so
on screen rather than leaving you guessing.

Verify afterwards at [icetest.info](https://icetest.info) with
`turn:api.remoteshala.com:3478` and credentials from
`GET /api/v1/calls/ice-servers`. A **relay** candidate means it works.

---

## 2. Firebase — 20 minutes

Chat without push is close to useless: you send "I'm at gate 4" and the other
person is never told, while you believe you made contact. It also carries call
invites, which is why signalling needs no websocket.

### In the Firebase console

1. [console.firebase.google.com](https://console.firebase.google.com) → **Add project** → name it `hash-buddy`. Analytics is not needed.
2. **Add app → Android**. Package name must be exactly:
   ```
   com.agilemania.hashbuddy
   ```
3. Download **`google-services.json`** → put it at:
   ```
   app/android/app/google-services.json
   ```
   It is already gitignored.
4. **Project settings → Service accounts → Generate new private key**. This JSON
   can send push to every install you have — treat it like the release keystore.

### In the app

Add the plugin, which reads `google-services.json` at build time:

`app/android/settings.gradle.kts` — in the `plugins` block:
```kotlin
id("com.google.gms.google-services") version "4.4.2" apply false
```

`app/android/app/build.gradle.kts` — in its `plugins` block:
```kotlin
id("com.google.gms.google-services")
```

Until you add these, `Firebase.initializeApp()` fails, `PushService` disables
itself, and the app runs normally on polling alone. That is deliberate: a
checkout without your credentials still has to work.

### On the server

Copy the service-account JSON somewhere outside the repo, then:

```bash
scp -i ~/.ssh/test-server-mumbai.pem service-account.json ubuntu@13.200.74.8:/tmp/
ssh -i ~/.ssh/test-server-mumbai.pem ubuntu@13.200.74.8 '
  sudo mkdir -p /etc/hash-buddy
  sudo mv /tmp/service-account.json /etc/hash-buddy/fcm.json
  sudo chown www-data:www-data /etc/hash-buddy/fcm.json
  sudo chmod 600 /etc/hash-buddy/fcm.json'
```

Then in `/var/www/hash-buddy/api/.env`:

```env
HASHBUDDY_PUSH_DRIVER=fcm
FCM_PROJECT_ID=hash-buddy-xxxxx
FCM_CREDENTIALS_PATH=/etc/hash-buddy/fcm.json
```

```bash
cd /var/www/hash-buddy/api && sudo -u www-data php artisan config:cache
```

Leave `HASHBUDDY_PUSH_DRIVER=log` and the whole feature still works — it just
writes what it would have sent to `storage/logs/laravel.log`, which is a
perfectly good way to confirm the right people would have been notified.

---

## 3. Rebuild and reinstall

```bash
cd app
flutter build apk --release --split-per-abi \
  --dart-define=HASH_BUDDY_API=https://api.remoteshala.com/api/v1
```

Both phones get `app-arm64-v8a-release.apk`. It installs over the previous one —
the release keystore has not changed.

---

## What to test, in order

1. **Chat.** Both sign in, one opens a ride, the other joins. Ride → **Chat &
   call** → send a message. It should appear on the other phone within about
   four seconds.
2. **The join notification.** With push configured, the person already on the
   ride gets *"You have a ride mate"* without opening the app. This is the one
   that makes the product work.
3. **A call on the same wifi.** Tap the handset icon. Grant the microphone when
   asked — it is requested at that moment, with the reason on screen, not on
   launch.
4. **A call on two different mobile networks.** This is the real test, and the
   one that fails without step 1 above.

---

## Known gaps

- **A call only rings while the app is running.** Waking a killed app for an
  incoming call needs a foreground service and a full-screen intent, which is a
  separate piece of work and its own Play Console declaration.
- **No call history.** Only missed calls leave a trace, as a line in the chat.
- **Group calls are not supported** — calling is one-to-one, which covers the
  default two-seat ride. A ride of three or four can chat but only call
  individually.
- **No blocking or reporting.** Chat gives strangers a channel to each other,
  which makes this materially more urgent than it was yesterday.
