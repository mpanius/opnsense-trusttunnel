#!/bin/sh

set -eu

client_bin=${1:-/usr/local/sbin/trusttunnel_client}

if [ "$(uname -s)" != "FreeBSD" ]; then
    echo "SKIP: FreeBSD is required" >&2
    exit 77
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "FAIL: root is required to create tun(4)" >&2
    exit 1
fi
if [ ! -x "$client_bin" ]; then
    echo "FAIL: client binary is not executable: $client_bin" >&2
    exit 1
fi

work_dir=$(mktemp -d /tmp/trusttunnel-tun-smoke.XXXXXX)
chmod 700 "$work_dir"
config_path="$work_dir/client.toml"
log_path="$work_dir/client.log"
pid=
existing_interface=

cleanup() {
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        kill "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
    fi
    if [ -n "$existing_interface" ] && ifconfig "$existing_interface" >/dev/null 2>&1; then
        ifconfig "$existing_interface" destroy 2>/dev/null || true
    fi
}
trap cleanup EXIT HUP INT TERM

cat >"$config_path" <<'EOF'
loglevel = "debug"
vpn_mode = "selective"
killswitch_enabled = false
exclusions = []

[endpoint]
hostname = "red.invalid"
addresses = ["192.0.2.1:443"]
has_ipv6 = false
username = "red"
password = "red"
skip_verification = false
certificate = ""
dns_upstreams = ["1.1.1.1"]
upstream_protocol = "http2"
anti_dpi = false

[listener.tun]
bound_if = "vtnet0"
included_routes = []
excluded_routes = []
mtu_size = 1350
change_system_dns = false
device_name = ""
use_existing = false
EOF
chmod 600 "$config_path"

before=$(ifconfig -l | tr ' ' '\n' | grep '^tun[0-9][0-9]*$' || true)
"$client_bin" --config "$config_path" --loglevel debug >"$log_path" 2>&1 &
pid=$!

new_interface=
attempt=0
while [ "$attempt" -lt 30 ]; do
    for interface in $(ifconfig -l | tr ' ' '\n' | grep '^tun[0-9][0-9]*$' || true); do
        if ! printf '%s\n' "$before" | grep -qx "$interface"; then
            new_interface=$interface
            break
        fi
    done
    [ -n "$new_interface" ] && break
    if ! kill -0 "$pid" 2>/dev/null; then
        break
    fi
    sleep 0.2
    attempt=$((attempt + 1))
done

if [ -z "$new_interface" ]; then
    echo "FAIL: client did not create a FreeBSD tun(4) interface" >&2
    grep -E 'Tunnel create error|OS_TUNNEL_FREEBSD|ERROR|Failed' "$log_path" >&2 || true
    exit 1
fi

ifconfig "$new_interface" | grep -q 'UP,POINTOPOINT'
ifconfig "$new_interface" | grep -q 'mtu 1350'
if grep -q 'Tunnel create error' "$log_path"; then
    echo "FAIL: client logged a tunnel factory error" >&2
    exit 1
fi

echo "PASS: client created $new_interface with UP,POINTOPOINT and MTU 1350"
cleanup
pid=

attempt=0
while [ "$attempt" -lt 20 ] && ifconfig "$new_interface" >/dev/null 2>&1; do
    sleep 0.1
    attempt=$((attempt + 1))
done
if ifconfig "$new_interface" >/dev/null 2>&1; then
    echo "FAIL: client left $new_interface behind after shutdown" >&2
    exit 1
fi

echo "PASS: client removed $new_interface after shutdown"

existing_interface=$(ifconfig tun create)
ifconfig "$existing_interface" inet 192.0.2.2 192.0.2.1 \
    netmask 255.255.255.0 mtu 1400 up
collision_config="$work_dir/client-collision.toml"
sed "s|device_name = \"\"|device_name = \"$existing_interface\"|" \
    "$config_path" >"$collision_config"
chmod 600 "$collision_config"

"$client_bin" --config "$collision_config" --loglevel debug \
    >"$work_dir/client-collision.log" 2>&1 &
pid=$!
attempt=0
while [ "$attempt" -lt 30 ] && kill -0 "$pid" 2>/dev/null; do
    sleep 0.2
    attempt=$((attempt + 1))
done
if kill -0 "$pid" 2>/dev/null; then
    echo "FAIL: client attached to an existing TUN without use_existing=true" >&2
    exit 1
fi
wait "$pid" 2>/dev/null || true
pid=
if ! ifconfig "$existing_interface" | grep -q 'mtu 1400'; then
    echo "FAIL: rejected collision modified externally owned $existing_interface" >&2
    exit 1
fi
grep -q 'already exists; refusing to create' "$work_dir/client-collision.log"
echo "PASS: client refused to claim existing $existing_interface"

existing_config="$work_dir/client-existing.toml"
sed \
    -e 's|included_routes = \[\]|included_routes = ["198.51.100.1/32"]|' \
    -e "s|device_name = \"\"|device_name = \"$existing_interface\"|" \
    -e 's|use_existing = false|use_existing = true|' \
    "$config_path" >"$existing_config"
chmod 600 "$existing_config"

"$client_bin" --config "$existing_config" --loglevel debug \
    >"$work_dir/client-existing.log" 2>&1 &
pid=$!

attempt=0
while [ "$attempt" -lt 30 ]; do
    route_interface=$(route -n get 198.51.100.1 2>/dev/null |
        awk '/interface:/ {print $2}')
    [ "$route_interface" = "$existing_interface" ] && break
    if ! kill -0 "$pid" 2>/dev/null; then
        break
    fi
    sleep 0.2
    attempt=$((attempt + 1))
done

if [ "${route_interface:-}" != "$existing_interface" ]; then
    echo "FAIL: client did not route 198.51.100.1 through existing TUN" >&2
    exit 1
fi
if ! ifconfig "$existing_interface" | grep -q 'mtu 1400'; then
    echo "FAIL: client modified externally owned $existing_interface" >&2
    exit 1
fi

cleanup_pid=$pid
kill "$cleanup_pid" 2>/dev/null || true
wait "$cleanup_pid" 2>/dev/null || true
pid=

if ! ifconfig "$existing_interface" >/dev/null 2>&1; then
    echo "FAIL: client destroyed externally owned $existing_interface" >&2
    exit 1
fi
route_interface=$(route -n get 198.51.100.1 2>/dev/null |
    awk '/interface:/ {print $2}')
if [ "$route_interface" = "$existing_interface" ]; then
    echo "FAIL: client left its managed route on existing $existing_interface" >&2
    exit 1
fi

echo "PASS: client preserved $existing_interface and removed its managed route"
ifconfig "$existing_interface" destroy
existing_interface=
