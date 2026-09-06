# Выпуск пакетов

Этот runbook описывает подготовку релиза для OPNsense 26.7 / FreeBSD 15.1
amd64. Публикация запрещена, пока не пройдены проверки артефактов и E2E-gates
ниже. Релиз не заменяет site-specific firewall/HA validation и не подтверждает
24-часовое окно стабильности либо удаление предыдущего rollback path.

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
- `trusttunnel-client-1.1.5.r.6_1.pkg`;
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
  trusttunnel-client-1.1.5.r.6_1.pkg \
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
pkg add ./trusttunnel-client-1.1.5.r.6_1.pkg ./os-trusttunnel-client-2.1.0.pkg
BOUND_IF=vtnet0  # замените на фактический исходящий интерфейс узла
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
  /usr/local/sbin/trusttunnel_client "$BOUND_IF"
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_supervision_smoke.sh \
  /usr/local/etc/rc.d/trusttunnel_client \
  /usr/local/sbin/trusttunnel_client
```

На чистой Client VM установка plugin должна успешно мигрировать пустую модель
`0.0.0 -> 2.1.0` без `ValidationException` до настройки `bound_if`. Затем
negative `Apply`/`reconfigure` с пустым `bound_if` обязан вернуть
`bound_if is required on FreeBSD/OPNsense`; после выбора существующего
физического интерфейса тот же action должен пройти. Model migration и runtime
validation — два отдельных release-gate.

Обязательный E2E-gate — реальный трафик через endpoint и `tun(4)`: валидный
TLS/SNI и аутентификация, маршрут через TUN, TCP и UDP DNS, рост счётчиков без
ошибок, штатный restart и cleanup интерфейса/маршрута после остановки. Одни
`--version`, установка пакета или SOCKS/CONNECT smoke этот gate не закрывают.

Изолированные прогоны на OPNsense 26.7 (FreeBSD ABI `1501000`) подтвердили
clean migration, TLS/SNI/auth positive и negative cases, `VPN_SS_CONNECTED`,
маршрут через `tun0` с MTU 1350, TCP, UDP DNS, штатный restart и cleanup.
Full-duplex harness передал ровно 10 GiB в каждом направлении за 1906,5
секунды с совпавшими SHA256 и нулевыми `Ierrs/Oerrs/Drop` при boot-time
`net.link.ifqmaxlen=1024`. Controlled HA failover подтвердил recovery и
выбор secondary, но data plane остановился на TLS trust. После исправления
certificate ownership повторный failover не проводился и не является
доказательством релиза. Production cutover подтвердил прикладную TCP/UDP
матрицу и return path через TrustTunnel. Предыдущий маршрут остаётся rollback
path до отдельного 24-часового stability gate.

Отличающийся от certificate identity разрешённый `custom_sni` подтверждён
endpoint-side capture; публично доверенный сертификат с подходящими SAN прошёл
проверку, через ту же сессию прошли TCP и UDP DNS.
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

При переносе overlay с macOS отключайте resource forks (`COPYFILE_DISABLE=1`)
и перед публикацией проверяйте каждый plugin manifest:

```sh
for package in os-trusttunnel-2.1.0.pkg \
  os-trusttunnel-client-2.1.0.pkg; do
  manifest=$(pkg info -l -F "${package}") || exit 1
  if printf '%s\n' "$manifest" | \
    grep -E '/\._|__MACOSX|__pycache__|\.pyc$'; then
    exit 1
  fi
done
```

Наличие AppleDouble `._*` в package считается release blocker даже если
`pkg add -f` способен проигнорировать конфликтующие записи.

## 5. Опубликовать и проверить release

Создавайте annotated tag только из commit, прошедшего повторный аудит и уже
совпадающего с `origin/master`. В каталоге артефактов должны находиться только
четыре ожидаемых `.pkg` и `SHA256SUMS`:

```sh
release_commit=$(git rev-parse HEAD)
test "$release_commit" = "$(git rev-parse origin/master)" || exit 1
test -z "$(git status --porcelain --untracked-files=no)" || exit 1

git tag -a v2.1.0 -m 'TrustTunnel OPNsense plugins v2.1.0' "$release_commit"
git push origin refs/tags/v2.1.0

gh release create v2.1.0 \
  trusttunnel-1.1.0.pkg \
  trusttunnel-client-1.1.5.r.6_1.pkg \
  os-trusttunnel-2.1.0.pkg \
  os-trusttunnel-client-2.1.0.pkg \
  SHA256SUMS \
  --verify-tag \
  --title 'TrustTunnel OPNsense plugins v2.1.0' \
  --notes-file RELEASE-NOTES.md
```

Release notes должны фиксировать source commit, OPNsense/FreeBSD ABI,
upstream tags, E2E-результаты, IPv4-only Client и незавершённый 24-часовой
stability gate. Подписанный pkg-репозиторий документируется только после
появления реального публичного ключа и доступного HTTPS endpoint.

После публикации проверьте release без GitHub-аутентификации в чистом
каталоге, повторно скачайте все assets и сверьте checksum:

```sh
release_dir=$(mktemp -d)
cd "$release_dir" || exit 1
base=https://github.com/mpanius/opnsense-trusttunnel/releases/download/v2.1.0
for asset in \
  trusttunnel-1.1.0.pkg \
  trusttunnel-client-1.1.5.r.6_1.pkg \
  os-trusttunnel-2.1.0.pkg \
  os-trusttunnel-client-2.1.0.pkg \
  SHA256SUMS
do
  curl -fLO "$base/$asset" || exit 1
done
sha256sum -c SHA256SUMS

curl -fsSL \
  https://api.github.com/repos/mpanius/opnsense-trusttunnel/releases/tags/v2.1.0 |
  jq -e '
    .tag_name == "v2.1.0" and
    ([.assets[].name] | sort) ==
    (["SHA256SUMS", "os-trusttunnel-2.1.0.pkg",
      "os-trusttunnel-client-2.1.0.pkg", "trusttunnel-1.1.0.pkg",
      "trusttunnel-client-1.1.5.r.6_1.pkg"] | sort)
  '
```

API `target_commitish` не является достаточной проверкой annotated tag:
сравните peeled commit удалённого tag с локальным `release_commit`:

```sh
remote_commit=$(git ls-remote origin 'refs/tags/v2.1.0^{}' | awk '{print $1}')
test "$remote_commit" = "$release_commit" || exit 1
```
