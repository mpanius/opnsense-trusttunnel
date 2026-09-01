# Архитектура интеграции

Репозиторий разделяет endpoint и client на два OPNsense plugin package. Они
могут устанавливаться независимо и не должны владеть чужой конфигурацией.

## Компоненты

```text
net/os-trusttunnel/
  MVC + configd + rc.d  → /usr/local/sbin/trusttunnel_endpoint

net/os-trusttunnel-client/
  MVC + configd + rc.d  → /usr/local/sbin/trusttunnel_client

freebsd-port/security/
  trusttunnel           → endpoint package
  trusttunnel-client    → experimental client package
```

Endpoint хранит данные в `<OPNsense><trusttunnel>`, client — в
`<OPNsense><trusttunnelclient>`. Производные TOML-файлы создаются в
`/usr/local/etc/trusttunnel/{server,client}/`; источником истины остаётся
`/conf/config.xml`. Файлы с credentials и private key должны иметь режим
`0600` и записываться атомарно.

## Endpoint apply path

```text
Web UI/API
  → ServerController validation
  → config.xml
  → materialize_certs.php
  → render_server_config.py
  → configd action
  → trusttunnel_endpoint rc.d service
```

Plugin управляет только своим WAN-правилом, помеченным
`<plugin_managed>os-trusttunnel</plugin_managed>`. При disable/uninstall оно
должно удаляться без изменения пользовательских правил.

## Client apply path

```text
deeplink preview + explicit confirmation
  → ClientController validation
  → <trusttunnelclient> config
  → render_client_config.py
  → configd action
  → trusttunnel_client rc.d service
```

Импорт deeplink повторно разбирается серверной стороной; UI preview не
считается доверенным вводом. Пароли исключаются из HA sync через `nosync`.

## Текущая граница поддержки

Пакет `trusttunnel-client v1.1.5-rc.6` собирается и запускает CLI на FreeBSD
15.1, но upstream factory не создаёт FreeBSD TUN backend. Поэтому схема выше
описывает control plane плагина, а не доказанный data plane. Регистрация
паттерна интерфейса OPNsense и успешный статус процесса сами по себе не
означают, что tunnel существует.

Критерий функциональной поддержки: чистая OPNsense 26.7 VM, успешное создание
интерфейса, корректные маршруты/DNS, установление сессии и подтверждённый
двунаправленный реальный трафик. До этого client остаётся экспериментальным.

## Смежные документы

- [`install.md`](install.md) — безопасная установка и smoke-test.
- [`release.md`](release.md) — release gates.
- [`freebsd-port-patches.md`](freebsd-port-patches.md) — архив прежней
  portation; не источник текущих гарантий.
- [`troubleshooting.md`](troubleshooting.md) — диагностика и исторические
  проблемы.
