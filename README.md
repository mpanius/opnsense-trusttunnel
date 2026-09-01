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
`FreeBSD:15:amd64`, устанавливаются на FreeBSD 15.1 и проходят CLI-проверки
`--version`/`--help`. Клиентская версия является prerelease.

Полноценная работа клиента как системного VPN на FreeBSD пока **не
подтверждена**: в upstream `v1.1.5-rc.6` нет реализации FreeBSD TUN backend.
Не используйте этот репозиторий как готовое production-решение до успешного
OPNsense E2E-теста с реальным трафиком. Исторические результаты для v1.0.x в
`docs/` не являются подтверждением текущей версии.

## Возможности плагинов

- Endpoint: выбор сертификата OPNsense, управление пользователями, экспорт
  deeplink/QR, конфигурация `configd` и управляемое WAN-правило.
- Client: импорт deeplink с предварительным просмотром, выбор активного
  сервера и генерация конфигурации клиента.
- Раздельные пакеты: endpoint не тянет клиентскую зависимость и наоборот.

## Сборка и установка

Процедура создания FreeBSD builder и сборки обоих портов описана в
[`freebsd-port/README.md`](freebsd-port/README.md). Проверяемая установка на
OPNsense приведена в [`docs/install.md`](docs/install.md), выпуск — в
[`docs/release.md`](docs/release.md).

Собранные `.pkg` не хранятся в Git. До появления проверенного GitHub Release
пакеты следует собирать самостоятельно; команды загрузки несуществующих
артефактов намеренно не публикуются.

## Связанные проекты

- [TrustTunnel](https://github.com/TrustTunnel/TrustTunnel)
- [TrustTunnelClient](https://github.com/TrustTunnel/TrustTunnelClient)
- [TrustTunnel-Keenetic](https://github.com/artemevsevev/TrustTunnel-Keenetic)

Код интеграции OPNsense распространяется по BSD-2-Clause. Upstream-исходники
TrustTunnel и TrustTunnelClient сохраняют свои лицензии Apache-2.0.
