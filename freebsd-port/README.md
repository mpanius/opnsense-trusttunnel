# FreeBSD-порты TrustTunnel

Overlay содержит два пакета для OPNsense 26.7 / FreeBSD 15.1 amd64:

| Порт | Upstream tag | Бинарники |
| --- | --- | --- |
| `security/trusttunnel` | `TrustTunnel v1.1.0` | `trusttunnel_endpoint`, `trusttunnel_setup_wizard` |
| `security/trusttunnel-client` | `TrustTunnelClient v1.1.5-rc.6` | `trusttunnel_client` |

Client tag является prerelease. Upstream `v1.1.5-rc.6` не содержит FreeBSD
TUN backend; overlay добавляет его локальным patch и проверяет отдельно от
обычного CLI smoke.

## Создание builder

[`tools/provision-freebsd-builder.sh`](../tools/provision-freebsd-builder.sh)
создаёт новую VM FreeBSD 15.1: 4 vCPU, 8 GiB RAM, диск 40 GiB. Скрипт
проверяет SHA256 официального image, фиксирует commit ports tree и версии
toolchain. Локально нужны Bash, SSH и SCP; на PVE — root SSH-доступ, `qm`,
`curl`, `xz`, `sha256sum`, существующий bridge и storage, поддерживающий
`qm importdisk` и cloud-init volumes. Зарезервируйте DHCP адрес bootstrap за
MAC гостя и проверьте свободные VMID и оба адреса до запуска.
Скрипт отказывается от существующего QEMU VMID, но не проверяет LXC с тем же
VMID и коллизии IP/MAC — их обязан исключить оператор.

Используйте только сеть `/24`; `--bootstrap-address` — зарезервированный
DHCP-адрес для указанного MAC, а `--address` — итоговый static IP. MAC должен
быть уникальным. Один SSH identity должен подходить и для PVE, и для гостя:
соответствующий `--identity` ключ должен быть разрешён для root на PVE, а
`--ssh-public-key` должен быть его публичной частью для гостя.

```sh
tools/provision-freebsd-builder.sh \
  --pve-host PVE_HOST --vmid VMID \
  --address STATIC_IP/24 --gateway GATEWAY \
  --bootstrap-address DHCP_IP --mac-address 02:00:00:15:01:01 \
  --storage local-zfs --bridge vmbr0 \
  --identity /path/to/id_ed25519 \
  --ssh-public-key /path/to/id_ed25519.pub
```

После создания на PVE проверьте `qm config VMID` и `qm status VMID`. В госте
проверьте `freebsd-version`, `pkg info`, версии Rust/CMake/LLVM/Ninja/Python,
а также фиксированные значения:

```sh
git -C "$HOME/ports" rev-parse HEAD
# 0d6b099b1aaaf982802e835757e6bc5b35c2b40b
/home/freebsd/.venv/bin/conan --version
# Conan version 2.32.0
```

При ошибке импорта или таймауте ZFS используйте только read-only диагностику
на PVE:

```sh
vmid=VMID
qm status "$vmid"; qm config "$vmid"
zfs list -o name | grep "vm-${vmid}-"
zpool status -x
```

Во время проверки финальной версии скрипта один запуск завершился сообщением
`zfs create ... got timeout`. Это не классифицировано как «известный» сбой:
cleanup удалил созданные ресурсы, `zpool status -x` показал healthy, а повтор
того же commit без изменений прошёл полностью.
При обычной ошибке provisioning автоматически очищает VM, созданную этим
запуском, после проверки имени `trusttunnel-builder`; после ошибки проверьте
отсутствие её ресурсов. Не удаляйте zvol по одному совпадению `grep`: сначала
сверьте точные VMID и имя в конфигурации PVE. Повторяйте provisioning только с
подтверждённо свободным VMID. Принудительное убийство может обойти cleanup и
оставить диск или lock. Каждый SSH wait может занимать около пяти минут; host
key гостя при переходе DHCP → static закреплён временным trust store скрипта.

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

## FreeBSD TUN backend клиента

Patch
`security/trusttunnel-client/files/patch-net_src_os__tunnel__freebsd.cpp`
добавляет отсутствующий в upstream backend. Он открывает `/dev/tun` либо явно
заданный `tun<N>`, отключает 4-byte address header через `TUNSIFHEAD=0`,
получает имя интерфейса вызовом `TUNGIFNAME` с полным `struct ifreq` и
настраивает IPv4 POINTOPOINT-адрес, маршруты и MTU. В create-mode имя должно
быть пустым или свободным `tun<N>`; созданный клиентом интерфейс удаляется при
остановке. При `use_existing=true` backend сохраняет адреса, MTU и жизненный
цикл чужого интерфейса, переключает packet-header mode через `TUNSIFHEAD=0`,
но снимает добавленные им managed routes.

Для OPNsense используйте пустое `device_name` или имя вида `tun<N>`; типовое
значение модели plugin — `mtu_size = 1350`. Обязательный `bound_if` должен
совпадать с реальным физическим исходящим интерфейсом этого узла. IPv6 backend пока
недоступен. `change_system_dns` должен оставаться `false`: DNS настраивает
OPNsense, а не клиентский процесс.

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
OPNsense 26.7 VM через `pkg add`, проверьте `pkg info`, ожидаемые файлы и
безопасные `--version`/`--help`. Затем из root-shell запустите smoke backend:

```sh
BOUND_IF=vtnet0  # замените на фактический исходящий интерфейс узла
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
  /usr/local/sbin/trusttunnel_client "$BOUND_IF"
```

Smoke проверяет создание `tun<N>` с POINTOPOINT и MTU 1350, удаление
принадлежащего клиенту интерфейса, отказ занять existing TUN без attach-mode,
а также сохранение externally owned TUN с удалением только добавленного
клиентом маршрута. Он не проверяет endpoint,
TLS, маршрутизацию прикладного трафика или DNS и поэтому не заменяет E2E.

Свежий локальный E2E на двух изолированных VM, OPNsense 26.7.3_8 и
FreeBSD ABI 1501000, подтвердил маркеры `VPN_SS_CONNECTED` и успешного
подключения к endpoint, маршрут `1.1.1.1 -> tun0`, TCP-ответ HTTP 301, UDP DNS,
рост счётчиков TUN без ошибок и удаление маршрута и интерфейса после остановки.
Это доказательство локальной совместимости сборки, но не production
validation.

## Атрибуция

Порты ссылаются на upstream Apache-2.0 и используют `LICENSE_FILE` из
исходников. Обоснование pin/patch хранится в `UPSTREAM-NOTES.md` и
`conan/patches/`.
