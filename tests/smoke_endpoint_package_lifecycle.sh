#!/bin/sh

set -eu

: "${TT_SSH_TARGET:?set TT_SSH_TARGET to an isolated OPNsense host}"
: "${TT_SSH_KEY:?set TT_SSH_KEY to its SSH private key}"
: "${TT_KNOWN_HOSTS:?set TT_KNOWN_HOSTS to a pinned known_hosts file}"
: "${TT_ENDPOINT_PLUGIN_PKG:?set TT_ENDPOINT_PLUGIN_PKG to os-trusttunnel pkg}"

package_sha="$(sha256sum "$TT_ENDPOINT_PLUGIN_PKG" | awk '{print $1}')"
remote_pkg="/tmp/os-trusttunnel-lifecycle.$$.pkg"

ssh_options="-i $TT_SSH_KEY -o BatchMode=yes -o UserKnownHostsFile=$TT_KNOWN_HOSTS"

# shellcheck disable=SC2086
scp $ssh_options "$TT_ENDPOINT_PLUGIN_PKG" "$TT_SSH_TARGET:$remote_pkg"

# shellcheck disable=SC2086
ssh $ssh_options "$TT_SSH_TARGET" sh -s -- "$remote_pkg" "$package_sha" <<'REMOTE'
set -eu

package_path="$1"
expected_sha="$2"

cleanup()
{
    rm -f "$package_path"
}
trap cleanup EXIT HUP INT TERM

test "$(sha256 -q "$package_path")" = "$expected_sha"
pkg add -f "$package_path" >/dev/null

before_sha="$(sha256 -q /conf/config.xml)"
install -d -m 0700 /usr/local/etc/trusttunnel/server
printf '%s\n' derived > /usr/local/etc/trusttunnel/server/lifecycle-sentinel

pkg delete -y os-trusttunnel >/dev/null
after_delete_sha="$(sha256 -q /conf/config.xml)"
test "$after_delete_sha" = "$before_sha"
test ! -e /usr/local/etc/trusttunnel/server
if pkg info -e os-trusttunnel; then
    echo 'endpoint plugin remained installed after pkg delete' >&2
    exit 1
fi

pkg add -f "$package_path" >/dev/null
after_reinstall_sha="$(sha256 -q /conf/config.xml)"
test "$after_reinstall_sha" = "$before_sha"
pkg info -e os-trusttunnel
configctl configd actions | grep 'trusttunnel server reconfigure' >/dev/null

printf 'config_sha=%s package_sha=%s lifecycle=ok\n' "$before_sha" "$expected_sha"
REMOTE
