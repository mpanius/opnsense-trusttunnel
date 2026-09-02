# TrustTunnel для OPNsense

[![License: BSD-2-Clause](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)

Репозиторий содержит два независимых плагина OPNsense и overlay-порты
FreeBSD для их бинарных зависимостей:

| Компонент | Назначение | Upstream |
| --- | --- | --- |
| `net/os-trusttunnel` | управление endpoint через OPNsense Web UI | `TrustTunnel v1.1.0` |
| `net/os-trusttunnel-client` | конфигурация клиентского процесса | `TrustTunnelClient v1.1.5-rc.6` |
| `freebsd-port/security/` | сборка пакетов `trusttunnel*` | FreeBSD 15.1 / OPNsense 26.7 |

## Статус

Пакеты endpoint и клиента воспроизводимо собираются под ABI
`FreeBSD:15:amd64`. Upstream `TrustTunnelClient v1.1.5-rc.6` не содержит
FreeBSD TUN backend, поэтому overlay этого репозитория добавляет IPv4 TUN и
управление маршрутами. Overlay также исправляет зависание HTTP/2-клиента,
когда все установленные upstream-сессии исчезли, а в пуле остались только
открывающиеся замены. Текущая версия обоих plugin packages — 2.1.0.

Локальный E2E на двух чистых OPNsense 26.7.3_8 VM (ABI `1501000`)
подтвердил TLS-сессию, маршрут через `tun0` с MTU 1350, TCP и UDP DNS-трафик,
рост счётчиков без ошибок и очистку интерфейса/маршрута после остановки.
Это доказательство тестового стенда, а не подтверждение production deployment
или production support; базовый E2E не проверяет обрыв всего upstream pool —
recovery принимается отдельным regression/runtime smoke. Клиентская версия
остаётся prerelease.

## Возможности плагинов

- Endpoint: выбор сертификата OPNsense, управление пользователями, экспорт
  deeplink/QR, конфигурация `configd` и управляемое WAN-правило.
- Client: импорт deeplink с предварительным просмотром, генерация
  конфигурации и IPv4 FreeBSD TUN backend с owned cleanup.
- Раздельные пакеты: endpoint не тянет клиентскую зависимость и наоборот.

## Сборка и установка

Процедура создания FreeBSD builder и сборки обоих портов описана в
[`freebsd-port/README.md`](freebsd-port/README.md). Проверяемая установка на
OPNsense приведена в [`docs/install.md`](docs/install.md), выпуск — в
[`docs/release.md`](docs/release.md).

Собранные `.pkg` не хранятся в Git. До появления проверенного GitHub Release
пакеты следует собирать самостоятельно и сверять SHA-256 перед установкой.

## Связанные проекты

- [TrustTunnel](https://github.com/TrustTunnel/TrustTunnel)
- [TrustTunnelClient](https://github.com/TrustTunnel/TrustTunnelClient)
- [TrustTunnel-Keenetic](https://github.com/artemevsevev/TrustTunnel-Keenetic)

Код интеграции OPNsense распространяется по BSD-2-Clause. Upstream-исходники
TrustTunnel и TrustTunnelClient сохраняют свои лицензии Apache-2.0.
