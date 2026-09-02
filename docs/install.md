# Установка на OPNsense

Текущая цель совместимости — OPNsense 26.7 на FreeBSD 15.1 amd64. До
публикации проверенного GitHub Release установка выполняется из локально
собранных пакетов. Публичного подписанного pkg-репозитория пока нет.

## Предварительные условия

- чистая тестовая VM OPNsense 26.7;
- root-доступ к консоли или SSH;
- endpoint: `png-1.6.58.pkg`, `libqrencode-4.1.1.pkg`,
  `trusttunnel-1.1.0.pkg`, `os-trusttunnel-2.1.0.pkg`;
- client: `trusttunnel-client-1.1.5.r.6.pkg`,
  `os-trusttunnel-client-2.1.0.pkg`;
- резервная копия конфигурации тестового firewall.

Соберите бинарные пакеты по [`../freebsd-port/README.md`](../freebsd-port/README.md).
OPNsense-плагины собираются в штатном дереве `opnsense/plugins`, куда каталоги
`net/os-trusttunnel*` подключаются как overlay.

## Установка пакетов

Скопируйте только нужный набор артефактов на тестовую VM. До `pkg add`
сверьте вывод `sha256` с согласованным манифестом сборки (после release — с
опубликованным манифестом того же выпуска) и проверьте metadata каждого файла:

```sh
sha256 ./png-1.6.58.pkg ./libqrencode-4.1.1.pkg \
  ./trusttunnel-1.1.0.pkg ./os-trusttunnel-2.1.0.pkg
sha256 ./trusttunnel-client-1.1.5.r.6.pkg \
  ./os-trusttunnel-client-2.1.0.pkg
for package in png-1.6.58.pkg libqrencode-4.1.1.pkg \
  trusttunnel-1.1.0.pkg os-trusttunnel-2.1.0.pkg \
  trusttunnel-client-1.1.5.r.6.pkg os-trusttunnel-client-2.1.0.pkg; do
  pkg info -F "./${package}"
done
```

Устанавливайте зависимости, бинарник и соответствующий plugin package именно
в таком порядке:

```sh
# endpoint
pkg add ./png-1.6.58.pkg ./libqrencode-4.1.1.pkg
pkg add ./trusttunnel-1.1.0.pkg ./os-trusttunnel-2.1.0.pkg

# либо client
pkg add ./trusttunnel-client-1.1.5.r.6.pkg \
  ./os-trusttunnel-client-2.1.0.pkg

configctl webgui restart
```

Установка пакета может мигрировать модель OPNsense. Сначала выполняйте её на
тестовой VM; локальный E2E не является разрешением на production deployment.

## Настройка клиента

- `tun_interface`: пустая строка для нового интерфейса или свободный `tun<N>`;
- `use_existing`: требует явно заданный существующий `tun<N>`;
- `mtu_size`: 576–9000, рекомендуемое значение по умолчанию 1350;
- `bound_if`: обязательный физический исходящий интерфейс именно этого узла;
- `change_system_dns`: только `false`; DNS управляется OPNsense;
- `allowed_destinations`/`excluded_destinations`: только IPv4-сети.

Не копируйте `bound_if` между узлами без проверки. При создании собственного
TUN (`use_existing = false`) штатный stop удаляет интерфейс и свои маршруты.
При `use_existing = true` backend сохраняет адреса, MTU и жизненный цикл
чужого интерфейса, переключает packet-header mode через `TUNSIFHEAD=0` и
снимает только добавленные им managed routes.

## Безопасная проверка

1. Убедитесь, что страницы **VPN → TrustTunnel** или **TrustTunnel Client**
   открываются без ошибок.
2. Сохраните минимальную конфигурацию и проверьте созданные файлы в
   `/usr/local/etc/trusttunnel/` и журналы `configd`.
3. Для endpoint запустите daemon на отдельном тестовом адресе/порту и
   проверьте `service trusttunnel_endpoint onestatus`.
4. Для клиента проверьте `Certificate verified`, `VPN_SS_CONNECTED`, маршрут
   через TUN, TCP-запрос, UDP DNS-запрос и рост RX/TX без ошибок.
5. Выполните stop: созданный TUN должен исчезнуть, а исходный маршрут —
   восстановиться. Автоматизированная основа проверки:

   ```sh
   python3 -m unittest discover -s tests -v
   sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
     /usr/local/sbin/trusttunnel_client
   ```

## Откат и удаление

Откат не равен удалению. При неудачном обновлении остановите новый service,
переустановите заранее сохранённые пакеты предыдущей версии и восстановите
резервную копию через **System → Configuration → Backups**. Затем проверьте
configd, Web UI, правила и маршруты. Не используйте `pkg delete` как способ
rollback: deinstall-скрипт может намеренно удалить принадлежащую плагину
конфигурацию.

Для окончательного удаления сначала отключите и остановите service, сохраните
`/conf/config.xml`, затем удалите только выбранный plugin и его бинарник:

```sh
# endpoint
pkg delete os-trusttunnel trusttunnel

# либо client
pkg delete os-trusttunnel-client trusttunnel-client
```

После удаления убедитесь, что пользовательские правила, интерфейсы и другие
плагины не затронуты.
