# FreeBSD-порты TrustTunnel

Overlay содержит два пакета для OPNsense 26.7 / FreeBSD 15.1 amd64:

| Порт | Upstream tag | Бинарники |
| --- | --- | --- |
| `security/trusttunnel` | `TrustTunnel v1.1.0` | `trusttunnel_endpoint`, `trusttunnel_setup_wizard` |
| `security/trusttunnel-client` | `TrustTunnelClient v1.1.5-rc.6` | `trusttunnel_client` |

Client tag является prerelease. Сборка и CLI smoke подтверждены, системный
TUN на FreeBSD — нет.

## Создание builder

[`tools/provision-freebsd-builder.sh`](../tools/provision-freebsd-builder.sh)
создаёт новую VM FreeBSD 15.1: 4 vCPU, 8 GiB RAM, диск 40 GiB. Скрипт
проверяет SHA256 официального image, фиксирует commit ports tree и версии
toolchain. Все адреса и ключи передаются аргументами; существующий VMID не
изменяется. Первый запуск использует DHCP-адрес, после чего скрипт закрепляет
указанный static IP внутри FreeBSD. Для повторного использования известной
DHCP-аренды можно дополнительно передать `--mac-address MAC`.

```sh
tools/provision-freebsd-builder.sh \
  --pve-host PVE_HOST --vmid VMID \
  --address STATIC_IP/24 --gateway GATEWAY \
  --bootstrap-address DHCP_IP \
  --ssh-public-key /path/to/id_ed25519.pub
```

## Conan overlay клиента

Checkout должен точно соответствовать `v1.1.5-rc.6`. Скрипт устанавливает
зафиксированный FreeBSD 15 profile как `default` в выбранный `CONAN_HOME`,
экспортирует NativeLibsCommon/DnsLibs recipes и накладывает проверяемые
FreeBSD-патчи. Если существующий default profile отличается, скрипт
останавливается, не перезаписывая его:

```sh
git clone --branch v1.1.5-rc.6 --depth 1 \
  https://github.com/TrustTunnel/TrustTunnelClient.git ~/TrustTunnelClient
PATH="$HOME/.venv/bin:$PATH" \
  tools/bootstrap-client-conan-freebsd.sh ~/TrustTunnelClient \
  "$HOME/.venv/bin/conan"
```

## Сборка

```sh
export PORTSDIR="$HOME/ports"
export PORTS_MK="-m /usr/share/mk -m $PORTSDIR/Mk"

cd freebsd-port/security/trusttunnel
make $PORTS_MK fetch checksum package

cd ../trusttunnel-client
PATH="$HOME/.venv/bin:$PATH" make $PORTS_MK fetch checksum package
```

Пакеты находятся в `work/pkg/`. До публикации установите каждый на чистую
OPNsense 26.7 VM через `pkg add -f`, проверьте `pkg info`, ожидаемые файлы и
безопасные `--version`/`--help`. Для клиента это только smoke, не E2E.

## Атрибуция

Порты ссылаются на upstream Apache-2.0 и используют `LICENSE_FILE` из
исходников. Обоснование pin/patch хранится в `UPSTREAM-NOTES.md` и
`conan/patches/`.
