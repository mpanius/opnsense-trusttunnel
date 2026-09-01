#!/usr/bin/env bash
set -euo pipefail

# Экспортирует точные upstream-рецепты TrustTunnelClient и накладывает только
# необходимые FreeBSD-адаптации. Патчи падают при изменении upstream-контекста.

CLIENT_REF="v1.1.5-rc.6"
NLC_REF="v8.1.49"
NLC_COMMIT="fd7405ee27fe040fffa094782fd4e9c5ea35fa34"
DNS_REF="7748c6a7"
DNS_COMMIT="7748c6a740f80c63d478a87e4eec049984f9d8a3"

usage() {
    cat <<'EOF'
Usage: bootstrap-client-conan-freebsd.sh CLIENT_SOURCE [CONAN]

CLIENT_SOURCE — checkout TrustTunnelClient v1.1.5-rc.6.
CONAN         — путь к Conan 2 (по умолчанию: conan из PATH).
EOF
}

[[ $# -ge 1 && $# -le 2 ]] || { usage >&2; exit 2; }
CLIENT_SOURCE=$(cd "$1" && pwd)
CONAN=${2:-conan}
REPO_ROOT=$(cd "$(dirname "$0")/.." && pwd)

[[ $($CONAN --version) == "Conan version 2."* ]] || {
    echo "Требуется Conan 2" >&2
    exit 1
}
[[ $(git -C "$CLIENT_SOURCE" describe --tags --exact-match) == "$CLIENT_REF" ]] || {
    echo "CLIENT_SOURCE должен указывать на точный tag $CLIENT_REF" >&2
    exit 1
}

python3.13 "$CLIENT_SOURCE/scripts/bootstrap_conan_deps.py"

workdir=$(mktemp -d "${TMPDIR:-/tmp}/trusttunnel-conan.XXXXXX")
trap 'rm -rf "$workdir"' EXIT

git clone --quiet --branch "$NLC_REF" --depth 1 \
    https://github.com/AdguardTeam/NativeLibsCommon.git "$workdir/native-libs-common"
[[ $(git -C "$workdir/native-libs-common" rev-parse HEAD) == "$NLC_COMMIT" ]] || {
    echo "Неожиданный commit NativeLibsCommon" >&2
    exit 1
}
git -C "$workdir/native-libs-common" apply --check \
    "$REPO_ROOT/freebsd-port/conan/patches/native-libs-common-v8.1.49-freebsd-recipe.patch"
git -C "$workdir/native-libs-common" apply \
    "$REPO_ROOT/freebsd-port/conan/patches/native-libs-common-v8.1.49-freebsd-recipe.patch"
(cd "$workdir/native-libs-common" && \
    "$CONAN" export . --user adguard --channel oss --version 8.1.49)
for recipe in "$workdir/native-libs-common"/conan/recipes/*/; do
    "$CONAN" export "$recipe" --user adguard --channel oss
done

git clone --quiet https://github.com/AdguardTeam/DnsLibs.git "$workdir/dns-libs"
git -C "$workdir/dns-libs" checkout --quiet "$DNS_REF"
[[ $(git -C "$workdir/dns-libs" rev-parse HEAD) == "$DNS_COMMIT" ]] || {
    echo "Неожиданный commit DnsLibs" >&2
    exit 1
}
git -C "$workdir/dns-libs" apply --check \
    "$REPO_ROOT/freebsd-port/conan/patches/dns-libs-7748c6a7-freebsd-recipe.patch"
git -C "$workdir/dns-libs" apply \
    "$REPO_ROOT/freebsd-port/conan/patches/dns-libs-7748c6a7-freebsd-recipe.patch"
dns_version=$(git -C "$workdir/dns-libs" describe --tags --match 'v*' | sed 's/^v//')
[[ $dns_version == "2.10.1-1-g7748c6a7" ]] || {
    echo "Неожиданная версия DnsLibs: $dns_version" >&2
    exit 1
}
(cd "$workdir/dns-libs" && \
    "$CONAN" export . --user adguard --channel oss --version "$dns_version")
