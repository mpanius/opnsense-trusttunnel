#!/usr/bin/env bash
set -euo pipefail

# Создаёт отдельную FreeBSD VM в Proxmox и настраивает проверенный toolchain.
# Все параметры сети и доступа передаются снаружи: внутренних адресов и ключей
# в репозитории нет.

FREEBSD_IMAGE_URL="https://download.freebsd.org/releases/VM-IMAGES/15.1-RELEASE/amd64/Latest/FreeBSD-15.1-RELEASE-amd64-BASIC-CLOUDINIT-ufs.qcow2.xz"
FREEBSD_IMAGE_SHA256="e4ca4db889f8559c9b9dfcacc70405c038476f4b6d41649b152d3809a2ed9e1f"
PORTS_COMMIT="0d6b099b1aaaf982802e835757e6bc5b35c2b40b"

usage() {
    cat <<'EOF'
Usage:
  provision-freebsd-builder.sh \
    --pve-host HOST --vmid ID --address CIDR --gateway IP \
    --bootstrap-address IP --ssh-public-key FILE \
    [--storage local-zfs] [--bridge vmbr0] [--identity FILE]

bootstrap-address — временный DHCP-адрес первого запуска. Скрипт запускается
с машины, которая может подключиться по SSH к Proxmox от root и к временному
адресу от пользователя freebsd из cloud image.
EOF
}

PVE_HOST=""
VMID=""
ADDRESS=""
GATEWAY=""
BOOTSTRAP_ADDRESS=""
SSH_PUBLIC_KEY=""
STORAGE="local-zfs"
BRIDGE="vmbr0"
IDENTITY=""

while (($#)); do
    case "$1" in
        --pve-host) PVE_HOST=${2:?}; shift 2 ;;
        --vmid) VMID=${2:?}; shift 2 ;;
        --address) ADDRESS=${2:?}; shift 2 ;;
        --gateway) GATEWAY=${2:?}; shift 2 ;;
        --bootstrap-address) BOOTSTRAP_ADDRESS=${2:?}; shift 2 ;;
        --ssh-public-key) SSH_PUBLIC_KEY=${2:?}; shift 2 ;;
        --storage) STORAGE=${2:?}; shift 2 ;;
        --bridge) BRIDGE=${2:?}; shift 2 ;;
        --identity) IDENTITY=${2:?}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

for value in PVE_HOST VMID ADDRESS GATEWAY BOOTSTRAP_ADDRESS SSH_PUBLIC_KEY; do
    [[ -n ${!value} ]] || { echo "Missing required argument: $value" >&2; exit 2; }
done
[[ $VMID =~ ^[0-9]+$ ]] || { echo "VMID must be numeric" >&2; exit 2; }
[[ $ADDRESS =~ ^[0-9.]+/[0-9]+$ ]] || { echo "Invalid CIDR address" >&2; exit 2; }
[[ $GATEWAY =~ ^[0-9.]+$ && $BOOTSTRAP_ADDRESS =~ ^[0-9.]+$ ]] || {
    echo "Invalid IPv4 address" >&2
    exit 2
}
[[ -r $SSH_PUBLIC_KEY ]] || { echo "Cannot read SSH public key" >&2; exit 2; }

SSH=(ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
[[ -z $IDENTITY ]] || SSH+=(-i "$IDENTITY")

if "${SSH[@]}" "root@$PVE_HOST" "qm status '$VMID'" >/dev/null 2>&1; then
    echo "VMID $VMID already exists; refusing to modify it" >&2
    exit 1
fi

remote_tmp="/var/tmp/freebsd-builder-${VMID}"
"${SSH[@]}" "root@$PVE_HOST" bash -s -- \
    "$VMID" "$STORAGE" "$BRIDGE" "$ADDRESS" "$GATEWAY" \
    "$remote_tmp" "$FREEBSD_IMAGE_URL" "$FREEBSD_IMAGE_SHA256" <<'PVE'
set -euo pipefail
vmid=$1 storage=$2 bridge=$3 address=$4 gateway=$5
tmp=$6 image_url=$7 image_sha256=$8
mkdir -p "$tmp"
trap 'rm -f "$tmp/image.qcow2.xz" "$tmp/image.qcow2"' EXIT
curl -fL --retry 3 -o "$tmp/image.qcow2.xz" "$image_url"
actual=$(sha256sum "$tmp/image.qcow2.xz" | awk '{print $1}')
[[ $actual == "$image_sha256" ]] || {
    echo "FreeBSD image checksum mismatch: $actual" >&2
    exit 1
}
xz -dc "$tmp/image.qcow2.xz" >"$tmp/image.qcow2"
qm create "$vmid" \
    --name trusttunnel-builder --description 'FreeBSD 15.1 build VM for OPNsense 26.7' \
    --cores 4 --memory 8192 --cpu host --ostype other --onboot 0 \
    --scsihw virtio-scsi-single --net0 "virtio,bridge=$bridge" \
    --serial0 socket --vga serial0 --agent enabled=1
qm importdisk "$vmid" "$tmp/image.qcow2" "$storage"
volume=$(qm config "$vmid" | awk '/^unused0:/ {print $2}')
[[ -n $volume ]]
qm set "$vmid" --scsi0 "$volume,discard=on,iothread=1,ssd=1" --boot order=scsi0
qm resize "$vmid" scsi0 40G
qm set "$vmid" --ide2 "$storage:cloudinit" --ciuser freebsd
qm set "$vmid" --ipconfig0 "ip=$address,gw=$gateway"
PVE

scp_args=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
[[ -z $IDENTITY ]] || scp_args+=(-i "$IDENTITY")
scp "${scp_args[@]}" "$SSH_PUBLIC_KEY" "root@$PVE_HOST:$remote_tmp/authorized_key"
"${SSH[@]}" "root@$PVE_HOST" \
    "qm set '$VMID' --sshkeys '$remote_tmp/authorized_key' && qm start '$VMID'"

echo "Waiting for bootstrap SSH at $BOOTSTRAP_ADDRESS ..."
for _ in {1..60}; do
    if "${SSH[@]}" "freebsd@$BOOTSTRAP_ADDRESS" true 2>/dev/null; then
        break
    fi
    sleep 5
done
"${SSH[@]}" "freebsd@$BOOTSTRAP_ADDRESS" true

static_ip=${ADDRESS%/*}
prefix=${ADDRESS#*/}
"${SSH[@]}" "freebsd@$BOOTSTRAP_ADDRESS" sh -s -- \
    "$static_ip" "$prefix" "$GATEWAY" <<'GUEST_NET'
set -eu
static_ip=$1 prefix=$2 gateway=$3
iface=$(route -n get default | awk '/interface:/ {print $2}')
case "$prefix" in
    24) netmask=255.255.255.0 ;;
    *) echo "Only a /24 network is currently supported" >&2; exit 2 ;;
esac
su -m root -c "sysrc hostname=trusttunnel-builder.local ifconfig_${iface}='inet ${static_ip} netmask ${netmask}' defaultrouter=${gateway}"
su -m root -c 'shutdown -r now' || true
GUEST_NET

echo "Waiting for static SSH at $static_ip ..."
for _ in {1..60}; do
    if "${SSH[@]}" "freebsd@$static_ip" true 2>/dev/null; then
        break
    fi
    sleep 5
done
"${SSH[@]}" "freebsd@$static_ip" sh -s -- "$PORTS_COMMIT" <<'GUEST_BUILD'
set -euo pipefail
ports_commit=$1
su -m root -c 'pkg bootstrap -f -y'
su -m root -c 'pkg install -y bash cmake git gmake llvm19 ninja perl5 pkgconf portfmt py313-sqlite3 python313 qemu-guest-agent rust'
su -m root -c 'sysrc qemu_guest_agent_enable=YES && service qemu-guest-agent start'
if [[ ! -d "$HOME/ports/.git" ]]; then
    git clone --filter=blob:none --branch 2026Q3 https://git.FreeBSD.org/ports.git "$HOME/ports"
fi
git -C "$HOME/ports" fetch origin "$ports_commit"
git -C "$HOME/ports" checkout --detach "$ports_commit"
python3.13 -m venv "$HOME/.venv"
"$HOME/.venv/bin/pip" install --disable-pip-version-check 'conan==2.32.0'

expected=(
    bash-5.3.15 cmake-3.31.12 git-2.54.0 gmake-4.4.1 llvm19-19.1.7_4
    'ninja-1.13.2,4' perl5-5.42.3 'pkgconf-2.4.3_1,1' portfmt-1.1.6
    py313-sqlite3-3.13.15_10 python313-3.13.15
    qemu-guest-agent-11.0.2 rust-1.96.1
)
for package in "${expected[@]}"; do
    pkg info -e "$package" || {
        echo "Unexpected toolchain version; missing $package" >&2
        exit 1
    }
done
freebsd-version
git -C "$HOME/ports" rev-parse HEAD
"$HOME/.venv/bin/conan" --version
GUEST_BUILD

"${SSH[@]}" "root@$PVE_HOST" "qm config '$VMID'; qm status '$VMID'"
