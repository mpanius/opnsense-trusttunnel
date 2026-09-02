# `security/trusttunnel-client` — upstream notes

## Источник

- Repo: <https://github.com/TrustTunnel/TrustTunnelClient>
- Tag: `v1.1.5-rc.6`
- Commit: `9c6d5104e79af0ed230d2d30a083a090c933fd48`
- Build system: CMake 3.24+, Conan 2 и Ninja; C++/C/Rust

Tag является prerelease. Сам upstream не содержит FreeBSD TUN backend;
функциональность добавляет и проверяет этот port overlay.

## Conan dependencies

Upstream bootstrap сам экспортирует публичные recipes, после чего
[`tools/bootstrap-client-conan-freebsd.sh`](../../../tools/bootstrap-client-conan-freebsd.sh)
фиксирует дополнительные исходники:

- NativeLibsCommon `v8.1.49`, commit
  `fd7405ee27fe040fffa094782fd4e9c5ea35fa34`;
- DnsLibs commit `7748c6a740f80c63d478a87e4eec049984f9d8a3`.

Recipe patches лежат в `freebsd-port/conan/patches/` и применяются только
после `git apply --check`. Target profile —
`freebsd-port/conan/profiles/freebsd15-amd64`.

## Source patches

Port patches в `files/` адаптируют platform guards, socket/ping code, CMake и
network monitor, а также добавляют `net/src/os_tunnel_freebsd.cpp`. В
неизменённом upstream `make_vpn_tunnel()` поддерживает Windows, macOS и Linux;
overlay подключает отдельную реализацию для FreeBSD.

Backend открывает `/dev/tun` или `tun<N>`, устанавливает `TUNSIFHEAD=0`,
получает имя через `TUNGIFNAME` с `struct ifreq`, настраивает IPv4
POINTOPOINT, MTU и маршруты. В create-mode имя должно быть пустым или свободным
`tun<N>`; принадлежащий backend интерфейс удаляется при остановке. В
attach-mode backend сохраняет адреса, MTU и lifecycle existing TUN,
переключает packet-header mode и снимает только собственные managed routes.
IPv6 недоступен. Системный DNS должен
настраиваться через OPNsense, поэтому `change_system_dns=false` является
обязательным ограничением.

[`tests/freebsd_client_tun_smoke.sh`](../../../tests/freebsd_client_tun_smoke.sh)
проверяет create/cleanup owned TUN, отказ от коллизии и attach-mode отдельно
от endpoint.
Локальный E2E на двух изолированных OPNsense 26.7.3_8 VM (FreeBSD ABI 1501000)
подтвердил `VPN_SS_CONNECTED`,
успешное подключение к endpoint, маршрут `1.1.1.1 -> tun0`, TCP HTTP 301, UDP
DNS, двусторонние счётчики без ошибок и cleanup. Это не подтверждает
production deployment.

## HTTP/2 multiplexer recovery

В upstream `UpstreamMultiplexer::do_health_check()` состояние без
`US_SESSION_OPENED` только журналируется. Если закрытые сессии уже заменены на
`US_OPENING_SESSION`, пул остаётся непустым, `SERVER_EVENT_SESSION_CLOSED` не
возникает и Client не переходит к повторному выбору endpoint.

Port overlay добавляет два связанных patch:

- `patch-core_src_upstream__multiplexer.cpp` поднимает
  `SERVER_EVENT_HEALTH_CHECK_ERROR`, когда health-check не находит установленную
  сессию;
- `patch-core_test_test__upstream__multiplexer.cpp` воспроизводит пул только с
  открывающейся заменой и требует этот event.

Patch использует существующий recovery path Client и не меняет HTTP/2,
таймауты или число upstream-соединений. Для приёмки package после конфигурации
build tree соберите и полностью прогоните target `test_upstream_multiplexer`.
Patch не FreeBSD-specific: при переходе на следующий upstream tag проверьте
наличие эквивалентного исправления и удалите либо перебазируйте overlay.

## Устанавливаемый файл

Port устанавливает только `/usr/local/sbin/trusttunnel_client`. Wizard не
нужен OPNsense plugin, который генерирует конфигурацию самостоятельно.
