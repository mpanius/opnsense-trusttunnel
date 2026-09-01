# Repository Guidelines

## Структура проекта

Репозиторий содержит два независимых плагина OPNsense: endpoint в
`net/os-trusttunnel/` и client в `net/os-trusttunnel-client/`. Внутри `src/`
соблюдается штатная структура OPNsense: MVC-код и XML-формы находятся в
`opnsense/mvc/`, служебные скрипты — в `opnsense/scripts/`, действия
`configd` — в `opnsense/service/conf/actions.d/`. Overlay-порты бинарников
лежат в `freebsd-port/security/`, Conan profiles и recipe patches — в
`freebsd-port/conan/`, документация — в `docs/`. Артефакты `dist/` не
добавляйте в Git.

## Сборка и локальные проверки

Бинарные пакеты собираются на FreeBSD 15.1 с зафиксированным ports tree:

```sh
export PORTSDIR="$HOME/ports"
cd freebsd-port/security/trusttunnel
make -m /usr/share/mk -m "$PORTSDIR/Mk" fetch checksum package
cd ../trusttunnel-client
PATH="$HOME/.venv/bin:$PATH" make -m /usr/share/mk -m "$PORTSDIR/Mk" fetch checksum package
```

Перед client build подготовьте Conan cache через
`tools/bootstrap-client-conan-freebsd.sh`. Плагины собирайте в совместимом
дереве `opnsense/plugins` командой `make package`. Проверки исходников:

```sh
fd -e php net -x php -l
fd -e py net -x python3 -m py_compile
fd -e xml net -x xmllint --noout
```

## Стиль и именование

Сохраняйте стиль окружающего кода: четыре пробела в PHP и Python,
`snake_case` для Python-функций и суффикс `Action` у OPNsense API-методов.
Namespace, модель и XML-узел согласуйте по роли: `TrustTunnel`/
`trusttunnel` для endpoint, `TrustTunnelClient`/`trusttunnelclient` для
client. Не смешивайте их конфигурации.

## Тестирование

Для render-скриптов проверяйте валидные и ошибочные входы, права файлов и
атомарную замену. UI, service и network changes требуют smoke-теста на чистой
OPNsense 26.7 VM. `trusttunnel_client --version` не доказывает VPN: upstream
`v1.1.5-rc.6` пока не реализует FreeBSD TUN. Заявляйте поддержку только после
E2E с реальным трафиком, маршрутами и DNS.

## Коммиты и pull request

Следуйте истории Conventional Commits: `feat(client): ...`,
`fix(services): ...`, `docs: ...`. Делайте узкие коммиты. В PR укажите
мотивацию, компонент, команды и результаты проверок; для UI приложите
скриншоты, для data plane — доказательство трафика. Никогда не добавляйте
ключи, секреты, внутренние адреса, caches или build logs.
