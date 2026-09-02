# Выпуск пакетов

Этот runbook описывает подготовку релиза для OPNsense 26.7 / FreeBSD 15.1
amd64. Он не подтверждает production readiness: публикация запрещена, пока не
пройдены все проверки и E2E-gates ниже.

## 1. Собрать пакеты

Бинарные пакеты собирайте по
[`../freebsd-port/README.md`](../freebsd-port/README.md), а плагины — в
совместимом дереве `opnsense/plugins`:

```sh
cd /path/to/opnsense/plugins/net/os-trusttunnel && make package
cd ../os-trusttunnel-client && make package
```

Релиз `v2.1.0` должен содержать ровно четыре `.pkg`-артефакта:

- `trusttunnel-1.1.0.pkg`;
- `trusttunnel-client-1.1.5.r.6.pkg`;
- `os-trusttunnel-2.1.0.pkg`;
- `os-trusttunnel-client-2.1.0.pkg`.

Endpoint plugin зависит от `libqrencode`; зависимость должна разрешаться из
настроенного pkg-репозитория или быть установлена до локального `pkg add`.

## 2. Создать и проверить SHA256SUMS

На FreeBSD создайте стандартный файл, совместимый с GNU `sha256sum`:

```sh
: > SHA256SUMS
for asset in \
  trusttunnel-1.1.0.pkg \
  trusttunnel-client-1.1.5.r.6.pkg \
  os-trusttunnel-2.1.0.pkg \
  os-trusttunnel-client-2.1.0.pkg
do
  printf '%s  %s\n' "$(sha256 -q "$asset")" "$asset" >> SHA256SUMS
done
```

Проверка на FreeBSD:

```sh
while read -r expected asset; do
  actual=$(sha256 -q "$asset") || exit 1
  [ "$actual" = "$expected" ] || {
    echo "SHA256 mismatch: $asset" >&2
    exit 1
  }
done < SHA256SUMS
```

На системе с GNU coreutils выполните `sha256sum -c SHA256SUMS`. Перед
публикацией убедитесь, что файл содержит ровно четыре строки и только
перечисленные выше имена.

## 3. Выполнить проверки

Контрактные тесты запускаются локально:

```sh
python3 -m unittest discover -s tests -v
```

На чистых OPNsense 26.7 VM установите пакеты по ролям. На endpoint VM:

```sh
pkg install -y libqrencode
pkg info -e libqrencode
pkg add ./trusttunnel-1.1.0.pkg ./os-trusttunnel-2.1.0.pkg
```

На отдельной client VM установите client и проверьте lifecycle TUN:

```sh
pkg add ./trusttunnel-client-1.1.5.r.6.pkg ./os-trusttunnel-client-2.1.0.pkg
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
  /usr/local/sbin/trusttunnel_client
```

Обязательный E2E-gate — реальный трафик через endpoint и `tun(4)`: валидный
TLS/SNI и аутентификация, маршрут через TUN, TCP и UDP DNS, рост счётчиков без
ошибок, штатный restart и cleanup интерфейса/маршрута после остановки. Одни
`--version`, установка пакета или SOCKS/CONNECT smoke этот gate не закрывают.

Локальный прогон на OPNsense `26.7.3_8` (FreeBSD ABI `1501000`) подтвердил
проверку TLS-сертификата и состояние `VPN_SS_CONNECTED`; маршрут через `tun0`
с MTU 1350 дал HTTP 301 и ответ UDP DNS. Счётчики выросли с `0/0` до
`929/690` bytes без interface errors, а после штатного stop исчезли созданные
TUN-интерфейс и маршрут. Это evidence тестового стенда, а не подтверждение
production deployment, HA, длительной нагрузки или production-маршрутизации.
Отличающийся `custom_sni=www1.ru` подтверждён endpoint-side capture при
certificate identity `api.www1.ru`; Let’s Encrypt certificate с SAN
`*.www1.ru`/`www1.ru` прошёл проверку, через ту же сессию прошли TCP и UDP DNS.
Не используйте alias вида `<label>.<main-host>`: endpoint трактует его как
SNI-аутентификацию `<credentials>.<main-host>`.

## 4. Проверить публичную готовность

Перед tag/push обязательны:

```sh
gitleaks dir . --redact
gitleaks git . --redact
git fsck --full
git diff --check
git status --short
```

`git fsck` не должен сообщать об ошибках целостности; каждый неожиданный
dangling/unreachable object исследуйте до публикации. Отдельно проверьте все
публикуемые refs и историю на внутреннюю топологию
(RFC1918-адреса, hostname, домены), generated files и package/build logs.
Проверьте список объектов и отсутствие blobs более 50 MiB:

```sh
git for-each-ref --format='%(refname)'
git rev-list --objects --all | \
  git cat-file --batch-check='%(objecttype) %(objectsize) %(rest)' | \
  awk '$1 == "blob" && $2 > 52428800 { print }'
```

Корневой `LICENSE` должен покрывать интеграцию, а оба overlay-порта — указывать
лицензию и `LICENSE_FILE` соответствующего upstream. Приватные ключи, secrets,
внутренние адреса, caches и собранные `.pkg` в Git не добавляются.

## 5. Подготовить публикацию

Создавайте tag и GitHub Release только из commit, прошедшего повторный аудит.
Приложите четыре `.pkg`, `SHA256SUMS` и release notes с точными OPNsense,
FreeBSD ABI, upstream tags/commit SHA, результатами E2E и ограничениями.
Подписанный pkg-репозиторий документируется только после появления реального
публичного ключа и доступного HTTPS endpoint.
