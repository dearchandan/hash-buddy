#!/usr/bin/env bash
#
# Installs coturn, the TURN relay that WebRTC calls fall back to when the two
# handsets cannot reach each other directly.
#
#   sudo ./turn.sh api.yourdomain.com
#
# This is not optional infrastructure. Indian mobile carriers put subscribers
# behind carrier-grade NAT, where neither peer can open a path to the other and
# STUN has nothing to discover. Two testers on the same wifi will connect
# without it and everything will look fine; the same two on 4G usually will not.
#
# Prints the security-group rules to open at the end. It deliberately does not
# touch AWS itself — what is exposed to the internet should be visible in the
# console, not buried in a shell script.

set -euo pipefail

REALM="${1:-}"

if [[ -z "$REALM" ]]; then
    echo "usage: sudo $0 <realm, e.g. api.yourdomain.com>" >&2
    exit 64
fi

if [[ $EUID -ne 0 ]]; then
    echo "run me with sudo" >&2
    exit 1
fi

API_ENV=/var/www/hash-buddy/api/.env
RELAY_MIN=49160
RELAY_MAX=49200

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$1"; }

log "Installing coturn"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq coturn

# Reuse the secret already in .env so re-running does not invalidate the
# credentials handed to clients that are mid-call.
if [[ -f "$API_ENV" ]] && grep -q '^HASHBUDDY_TURN_SECRET=.\+' "$API_ENV"; then
    SECRET="$(grep '^HASHBUDDY_TURN_SECRET=' "$API_ENV" | cut -d= -f2-)"
    log "Reusing the TURN secret already in .env"
else
    SECRET="$(openssl rand -hex 32)"
    log "Generated a new TURN secret"
fi

# On EC2 the interface only ever sees the private address; the public one is
# NAT'd in front of it. Without this mapping coturn advertises the private IP
# as its relay address and every call quietly fails to connect.
PRIVATE_IP="$(hostname -I | awk '{print $1}')"
PUBLIC_IP="$(curl -s --max-time 5 http://169.254.169.254/latest/meta-data/public-ipv4 || true)"

if [[ -z "$PUBLIC_IP" ]]; then
    # IMDSv2 only: fetch a token first.
    TOKEN="$(curl -s --max-time 5 -X PUT http://169.254.169.254/latest/api/token \
        -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' || true)"
    PUBLIC_IP="$(curl -s --max-time 5 -H "X-aws-ec2-metadata-token: $TOKEN" \
        http://169.254.169.254/latest/meta-data/public-ipv4 || true)"
fi

if [[ -z "$PUBLIC_IP" ]]; then
    echo "Could not determine the public IP from instance metadata." >&2
    echo "Set external-ip by hand in /etc/turnserver.conf or calls will not connect." >&2
    exit 1
fi

log "Relay address ${PUBLIC_IP}/${PRIVATE_IP}"

cat > /etc/turnserver.conf <<CONF
# Managed by deploy/turn.sh

listening-port=3478
fingerprint

# Credentials are derived from a shared secret rather than stored per user:
# the app is handed a username that is an expiry timestamp and a password that
# is its HMAC, so credentials pulled out of an APK stop working within the hour.
use-auth-secret
static-auth-secret=${SECRET}
realm=${REALM}

# The public address to advertise, mapped from the private one the NIC sees.
external-ip=${PUBLIC_IP}/${PRIVATE_IP}

# Narrow relay range so the security group stays a short, readable list rather
# than the whole ephemeral range.
min-port=${RELAY_MIN}
max-port=${RELAY_MAX}

# A relay that will forward anywhere is an open proxy. These keep it pointed at
# the public internet and away from the machine's own services and the VPC.
no-multicast-peers
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255

# Audio is ~50 kbps each way. These caps stop one misbehaving client turning a
# small instance into somebody's free bandwidth.
user-quota=12
total-quota=100
bps-capacity=0

no-cli
syslog
CONF

chmod 640 /etc/turnserver.conf
chown root:turnserver /etc/turnserver.conf 2>/dev/null || true

# The package ships disabled until configured.
sed -i 's/^#*TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn 2>/dev/null \
    || echo 'TURNSERVER_ENABLED=1' > /etc/default/coturn

systemctl enable coturn >/dev/null 2>&1 || true
systemctl restart coturn
sleep 2

if ! systemctl is-active --quiet coturn; then
    echo "coturn failed to start:" >&2
    journalctl -u coturn -n 20 --no-pager >&2
    exit 1
fi

log "coturn is running"

# ------------------------------------------------------------------- app env ---
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$API_ENV"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$API_ENV"
    else
        printf '%s=%s\n' "$key" "$value" >> "$API_ENV"
    fi
}

if [[ -f "$API_ENV" ]]; then
    log "Pointing the API at the relay"
    set_env HASHBUDDY_TURN_URLS "turn:${REALM}:3478?transport=udp,turn:${REALM}:3478?transport=tcp"
    set_env HASHBUDDY_TURN_SECRET "$SECRET"
    cd /var/www/hash-buddy/api && sudo -u www-data php artisan config:cache >/dev/null
fi

cat <<DONE

  coturn is listening on 3478.

  Open these in the EC2 security group, or calls will never reach the relay:

      UDP  3478                 0.0.0.0/0    TURN control
      TCP  3478                 0.0.0.0/0    TURN over TCP, for restrictive wifi
      UDP  ${RELAY_MIN}-${RELAY_MAX}        0.0.0.0/0    media relay

  Check it end to end at https://icetest.info — paste:

      turn:${REALM}:3478
      username/credential from GET /api/v1/calls/ice-servers

  A "relay" candidate in the results means it works.

DONE
