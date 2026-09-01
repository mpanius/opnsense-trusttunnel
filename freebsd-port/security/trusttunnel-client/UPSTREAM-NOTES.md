# `security/trusttunnel-client` — upstream notes

## Источник

- Repo: <https://github.com/TrustTunnel/TrustTunnelClient>
- Tag: `v1.1.5-rc.6`
- Commit: `9c6d5104e79af0ed230d2d30a083a090c933fd48`
- Build system: CMake 3.24+, Conan 2 и Ninja; C++/C/Rust

Tag является prerelease. FreeBSD 15.1 package устанавливается и проходит
CLI smoke, но это не функциональная поддержка VPN.

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

Port patches в `files/` минимально адаптируют platform guards, socket/ping
code, CMake и network monitor для компиляции FreeBSD. Они не добавляют
полноценный FreeBSD TUN backend. В upstream `net/src/os_tunnel.cpp`
`make_vpn_tunnel()` поддерживает Windows, macOS и Linux; ветка FreeBSD
возвращает `nullptr`.

Следствие: пакет нельзя описывать как готовый system-wide VPN client на
OPNsense. Для объявления поддержки нужны upstream/backend change и E2E на
чистой OPNsense 26.7 VM с интерфейсом, маршрутами, DNS и реальным трафиком.

## Устанавливаемый файл

Port устанавливает только `/usr/local/sbin/trusttunnel_client`. Wizard не
нужен OPNsense plugin, который генерирует конфигурацию самостоятельно.
