# Установка на OPNsense

Текущая цель совместимости — OPNsense 26.7 на FreeBSD 15.1 amd64. До
публикации проверенного GitHub Release установка выполняется из локально
собранных пакетов. Публичного подписанного pkg-репозитория пока нет.

## Предварительные условия

- чистая тестовая VM OPNsense 26.7;
- root-доступ к консоли или SSH;
- пакеты `trusttunnel-1.1.0.pkg`,
  `trusttunnel-client-1.1.5.r.6.pkg` и нужный OPNsense plugin package;
- резервная копия конфигурации тестового firewall.

Соберите бинарные пакеты по [`../freebsd-port/README.md`](../freebsd-port/README.md).
OPNsense-плагины собираются в штатном дереве `opnsense/plugins`, куда каталоги
`net/os-trusttunnel*` подключаются как overlay.

## Установка пакетов

Скопируйте артефакты на тестовую VM и сначала проверьте metadata:

```sh
pkg info -F ./trusttunnel-1.1.0.pkg
pkg info -F ./trusttunnel-client-1.1.5.r.6.pkg
pkg add -f ./trusttunnel-1.1.0.pkg
pkg add -f ./trusttunnel-client-1.1.5.r.6.pkg
trusttunnel_endpoint --version
trusttunnel_client --version
```

Затем установите только нужный plugin package и перезапустите Web UI:

```sh
pkg add -f ./os-trusttunnel-2.0.0.pkg
# либо: pkg add -f ./os-trusttunnel-client-2.0.0.pkg
configctl webgui restart
```

Не устанавливайте клиентский плагин в production: наличие бинарника и
успешный `--version` не доказывают работоспособность FreeBSD TUN.

## Безопасная проверка

1. Убедитесь, что страницы **VPN → TrustTunnel** или **TrustTunnel Client**
   открываются без ошибок.
2. Сохраните минимальную конфигурацию и проверьте созданные файлы в
   `/usr/local/etc/trusttunnel/` и журналы `configd`.
3. Для endpoint запустите daemon на отдельном тестовом адресе/порту и
   проверьте `service trusttunnel_endpoint onestatus`.
4. Для клиента ограничьтесь CLI smoke до появления FreeBSD TUN backend.
   Функциональную поддержку можно заявлять только после E2E-теста с реальным
   трафиком и контролем маршрутов/DNS.

## Удаление

```sh
pkg delete os-trusttunnel
pkg delete os-trusttunnel-client
pkg delete trusttunnel trusttunnel-client
```

Перед удалением сохраните `/conf/config.xml`. Проверяйте, что другие плагины и
правила firewall не затронуты.
