#!/bin/sh

set -eu

rc_script=${1:-/usr/local/etc/rc.d/trusttunnel_client}
client_bin=${2:-/usr/local/sbin/trusttunnel_client}
supervisor_pidfile=/var/run/trusttunnel_client.pid
child_pidfile=/var/run/trusttunnel_client.child.pid

if [ "$(uname -s)" != "FreeBSD" ]; then
    echo "SKIP: FreeBSD is required" >&2
    exit 77
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "FAIL: root is required" >&2
    exit 1
fi
if [ ! -x "$rc_script" ] || [ ! -x "$client_bin" ]; then
    echo "FAIL: rc script and client binary must be executable" >&2
    exit 1
fi
if "$rc_script" onestatus >/dev/null 2>&1; then
    echo "FAIL: an existing TrustTunnel Client service may be active" >&2
    exit 1
fi
rm -f "$supervisor_pidfile" "$child_pidfile"

work_dir=$(mktemp -d /tmp/trusttunnel-supervision-smoke.XXXXXX)
chmod 700 "$work_dir"
config_path="$work_dir/client.toml"
worker_bin="$work_dir/trusttunnel_client"
cp /bin/sleep "$worker_bin"
chmod 700 "$worker_bin"
: >"$config_path"
chmod 600 "$config_path"
before_interfaces=$(ifconfig -l | tr ' ' '\n' | grep '^tun[0-9][0-9]*$' || true)

cleanup()
{
    trusttunnel_client_config="$config_path" \
        trusttunnel_client_binary="$worker_bin" \
        trusttunnel_client_args=3600 "$rc_script" onestop \
        >/dev/null 2>&1 || true
    rm -rf "$work_dir"
}
trap cleanup EXIT HUP INT TERM

is_client_pid()
{
    candidate_pid=$1
    candidate_comm=$(ps -p "$candidate_pid" -o comm= 2>/dev/null |
        tr -d '[:space:]')
    kill -0 "$candidate_pid" 2>/dev/null &&
        [ "$candidate_comm" = "trusttunnel_client" ]
}

direct_client_child()
{
    pgrep -P "$supervisor_pid" -x trusttunnel_client 2>/dev/null || true
}

trusttunnel_client_config="$config_path" \
    trusttunnel_client_binary="$worker_bin" \
    trusttunnel_client_args=3600 "$rc_script" onestart

attempt=0
first_child_pid=
while [ "$attempt" -lt 40 ]; do
    if [ -s "$supervisor_pidfile" ] && [ -s "$child_pidfile" ]; then
        candidate=$(cat "$child_pidfile")
        if is_client_pid "$candidate"; then
            first_child_pid=$candidate
            break
        fi
    fi
    sleep 0.25
    attempt=$((attempt + 1))
done
[ -s "$supervisor_pidfile" ] && [ -n "$first_child_pid" ] || {
    echo "FAIL: supervisor or live client child was not created" >&2
    exit 1
}

supervisor_pid=$(cat "$supervisor_pidfile")
kill -0 "$supervisor_pid"
is_client_pid "$first_child_pid"
# Terminate the child directly while keeping the supervisor alive. The real
# descriptor-exhaustion incident also completed the client's orderly teardown
# before the process exited; the supervisor must create a fresh child after it.
kill -TERM "$first_child_pid"

attempt=0
while [ "$attempt" -lt 20 ] && is_client_pid "$first_child_pid"; do
    sleep 0.1
    attempt=$((attempt + 1))
done
if is_client_pid "$first_child_pid"; then
    echo "FAIL: terminated client child remained alive" >&2
    exit 1
fi
if trusttunnel_client_config="$config_path" \
    trusttunnel_client_binary="$worker_bin" \
    trusttunnel_client_args=3600 "$rc_script" onestatus \
    >/dev/null 2>&1; then
    echo "FAIL: status stayed green while supervisor had no live child" >&2
    exit 1
fi

attempt=0
second_child_pid=
while [ "$attempt" -lt 40 ]; do
    candidate=$(direct_client_child)
    if [ -n "$candidate" ] && [ "$candidate" != "$first_child_pid" ] &&
        is_client_pid "$candidate"; then
        second_child_pid=$candidate
        break
    fi
    sleep 0.25
    attempt=$((attempt + 1))
done
[ -n "$second_child_pid" ] || {
    echo "FAIL: client was not restarted after child termination" >&2
    printf 'supervisor_pidfile=' >&2
    cat "$supervisor_pidfile" >&2 2>/dev/null || echo missing >&2
    printf 'child_pidfile=' >&2
    cat "$child_pidfile" >&2 2>/dev/null || echo missing >&2
    ps -ax -o pid,ppid,state,command | grep -E \
        '[d]aemon: .*trusttunnel_client|[/]usr/local/sbin/trusttunnel_client' \
        >&2 || true
    exit 1
}
kill -0 "$supervisor_pid"
is_client_pid "$second_child_pid"
[ "$(ps -p "$second_child_pid" -o ppid= | tr -d '[:space:]')" = \
    "$supervisor_pid" ] || {
    echo "FAIL: restarted client child is not owned by supervisor" >&2
    exit 1
}
trusttunnel_client_config="$config_path" \
    trusttunnel_client_binary="$worker_bin" \
    trusttunnel_client_args=3600 "$rc_script" onestatus \
    >/dev/null

trusttunnel_client_config="$config_path" \
    trusttunnel_client_binary="$worker_bin" \
    trusttunnel_client_args=3600 "$rc_script" onestop
sleep 1
if kill -0 "$supervisor_pid" 2>/dev/null; then
    echo "FAIL: supervised service remained alive after stop" >&2
    exit 1
fi

after_interfaces=$(ifconfig -l | tr ' ' '\n' | grep '^tun[0-9][0-9]*$' || true)
[ "$after_interfaces" = "$before_interfaces" ] || {
    echo "FAIL: supervised lifecycle left a TUN interface behind" >&2
    exit 1
}

trap - EXIT HUP INT TERM
rm -rf "$work_dir"
echo "PASS: client child restarted under the same supervisor and stopped cleanly"
