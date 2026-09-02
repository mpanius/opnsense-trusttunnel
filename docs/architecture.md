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
  trusttunnel-client    → client + FreeBSD TUN overlay
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

Plugin не управляет firewall, NAT или System Trust. Сертификат импортируется
через штатный `/api/trust/cert/*`, а WAN-правило создаётся и удаляется отдельной
транзакцией `/api/firewall/filter/*` с собственным UUID, backup и rollback.
Отключение или удаление package не изменяет эти объекты автоматически.

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

Renderer переводит модель OPNsense в контракт `v1.1.5-rc.6`: top-level
`vpn_mode`/`exclusions`, секции `[endpoint]` и `[listener.tun]`. Для FreeBSD
поддерживается только IPv4; `device_name` пуст или соответствует `tun<N>`,
MTU находится в диапазоне 576–9000 (default модели plugin 1350), а `bound_if` обязателен
и задаётся отдельно для каждого узла как его физический исходящий интерфейс.
Изменение системного DNS запрещено:
`change_system_dns = false`. При `use_existing = false` имя должно быть пустым
или указывать на свободный `tun<N>`; backend владеет созданным интерфейсом и
удаляет его вместе со своими маршрутами при stop. При `true` backend сохраняет
адреса, MTU и жизненный цикл существующего TUN, переключает packet-header mode
через `TUNSIFHEAD=0` и снимает при stop только добавленные managed routes.

Client запускается через `daemon -r -R 5`: rc.d отслеживает supervisor и
отдельный child pidfile, поэтому аварийное завершение бинарника приводит к
перезапуску через 5 секунд. Start syshook получает `enabled` через `pluginctl`,
затем вызывает штатный `configctl reconfigure`; stop syshook безусловно вызывает
`configctl stop`. Прямого чтения или записи `/conf/config.xml` в boot path нет.
Status-actions используют стандартный для
OPNsense `errors:no`, поскольку `rc.d onestatus` штатно возвращает exit `1` для
остановленного сервиса.

## Текущая граница поддержки

Upstream `TrustTunnelClient v1.1.5-rc.6` не создаёт FreeBSD TUN; backend
добавлен только overlay-патчами этого репозитория. Локальный E2E на OPNsense
26.7.3_8 / ABI `1501000` подтвердил `Certificate verified`,
`VPN_SS_CONNECTED`, `Successfully connected`, маршрут через `tun0` с MTU
1350, TCP HTTP 301, UDP DNS A, рост счётчиков с 0/0 до 929/690 байт без
ошибок, удаление `tun0` и восстановление маршрута через `vtnet0` после stop.

Endpoint-side capture подтвердил certificate identity и отличный от него
разрешённый SNI; публично доверенный сертификат с подходящими SAN прошёл
проверку, через ту же сессию прошли TCP HTTP 301 и UDP DNS.
Alias не должен иметь вид `<label>.<main-host>`: upstream резервирует этот
формат под SNI-аутентификацию `<credentials>.<main-host>`.

Эти результаты фиксируют работоспособность тестового data plane. Production
deployment, отказоустойчивость и production support пока не подтверждены.

## Смежные документы

- [`install.md`](install.md) — безопасная установка и smoke-test.
- [`release.md`](release.md) — release gates.
- [`freebsd-port-patches.md`](freebsd-port-patches.md) — текущий overlay
  `v1.1.5-rc.6` и архив портирования `v1.1.4`.
- [`troubleshooting.md`](troubleshooting.md) — диагностика и исторические
  проблемы.
