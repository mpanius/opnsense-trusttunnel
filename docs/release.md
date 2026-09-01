# Выпуск пакетов

Этот runbook описывает подготовку артефактов для OPNsense 26.7 / FreeBSD 15.1.
Публичный релиз запрещён, пока не пройдены все проверки ниже.

## 1. Собрать бинарные пакеты

На чистом builder выполните команды из
[`../freebsd-port/README.md`](../freebsd-port/README.md). Сохраните версии,
upstream tags/commit SHA, вывод `pkg info -F` и SHA256 каждого `.pkg`.

## 2. Собрать плагины

Подключите `net/os-trusttunnel` и `net/os-trusttunnel-client` к совместимому
дереву `opnsense/plugins`; корень этого репозитория сам по себе не содержит
`Mk/plugins.mk`.

```sh
cd /path/to/opnsense/plugins/net/os-trusttunnel
make package
cd ../os-trusttunnel-client
make package
```

## 3. Проверить артефакты

На чистой OPNsense 26.7 VM:

```sh
pkg add -f ./trusttunnel-1.1.0.pkg
pkg add -f ./trusttunnel-client-1.1.5.r.6.pkg
trusttunnel_endpoint --version
trusttunnel_client --version
```

После установки плагинов проверьте Web UI, генерацию конфигурации, права
файлов, запуск служб и удаление пакетов. Клиентский релиз остаётся
экспериментальным до реализации FreeBSD TUN и E2E-теста реального трафика.

## 4. Проверить публичную готовность

Перед tag/push обязательны:

```sh
gitleaks dir . --redact
gitleaks git . --redact
git diff --check
git status --short
```

Дополнительно проверьте всю историю на приватные адреса/имена хостов, blobs
более 50 MiB, generated files и лицензионную атрибуцию. Приватный ключ подписи
никогда не хранится в Git или на builder.

## 5. Опубликовать

Создавайте GitHub Release только из проверенного commit. Прикладывайте `.pkg`,
файл `SHA256SUMS` и release notes с точными платформой, upstream SHA и
известными ограничениями. Подписанный pkg-репозиторий документируется только
после появления реального публичного ключа и доступного HTTPS endpoint.
