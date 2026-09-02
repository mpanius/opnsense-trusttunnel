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

for package in os-trusttunnel-2.1.0.pkg \
  os-trusttunnel-client-2.1.0.pkg; do
  manifest=$(pkg info -l -F "./${package}") || exit 1
  if printf '%s\n' "$manifest" | \
    grep -E '/\._|__MACOSX|__pycache__|\.pyc$'; then
    exit 1
  fi
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
- `mtu_size`: 576–9000, default модели plugin `1350`; снижайте его только при
  воспроизводимом провале end-to-end PMTUD;
- `bound_if`: обязательный физический исходящий интерфейс именно этого узла;
- `change_system_dns`: только `false`; DNS управляется OPNsense;
- `allowed_destinations`/`excluded_destinations`: только IPv4-сети.

Не копируйте `bound_if` между узлами без проверки. При создании собственного
TUN (`use_existing = false`) штатный stop удаляет интерфейс и свои маршруты.
При `use_existing = true` backend сохраняет адреса, MTU и жизненный цикл
чужого интерфейса, переключает packet-header mode через `TUNSIFHEAD=0` и
снимает только добавленные им managed routes.

## Сертификат и WAN-правило Endpoint

Plugin не импортирует сертификаты и не создаёт firewall rules самостоятельно.
До включения Endpoint отдельными API-транзакциями:

1. найдите либо импортируйте сертификат через `/api/trust/cert/search` и
   `/api/trust/cert/add`, затем выберите его `refid` в поле TLS certificate;
2. найдите exact WAN rule через `/api/firewall/filter/searchRule`; при его
   отсутствии добавьте минимальный TCP rule на фактические address/port через
   `/api/firewall/filter/addRule` и выполните `/api/firewall/filter/apply`;
3. сохраните UUID правила для отдельного API-only rollback.

Перед каждым POST обязательны fresh backup, read-back, exact redacted diff и
явное подтверждение. Не редактируйте `/conf/config.xml` и не считайте package
uninstall способом удаления сертификата или firewall rule.

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
   BOUND_IF=vtnet0  # замените на фактический исходящий интерфейс узла
   python3 -m unittest discover -s tests -v
   sh /path/to/opnsense-trusttunnel/tests/freebsd_client_tun_smoke.sh \
     /usr/local/sbin/trusttunnel_client "$BOUND_IF"
   ```

После включения сервиса проверьте boot lifecycle на тестовой OPNsense:
штатный reboot должен восстановить Endpoint/Client, Client supervisor и его
child должны иметь разные PID, а созданный `tun<N>` и настроенные managed routes —
появиться без ручного Apply. Для Client отдельно выполните
supervision smoke на тестовой OPNsense с установленным plugin: завершение child
не должно менять PID supervisor и должно создать новый child через 5 секунд.

```sh
sh /path/to/opnsense-trusttunnel/tests/freebsd_client_supervision_smoke.sh \
  /usr/local/etc/rc.d/trusttunnel_client \
  /usr/local/sbin/trusttunnel_client
```

Этот smoke использует детерминированный worker вместо сетевого запуска Client:
он проверяет supervisor PID, красный status без child, повторный запуск child и
чистый stop. Реальный бинарник и TUN отдельно проверяет предыдущий TUN smoke.

Package lifecycle Endpoint проверяйте только на изолированной OPNsense VM.
Smoke удаляет и повторно устанавливает plugin, сравнивает полный SHA256
`/conf/config.xml` и проверяет cleanup derived runtime:

```sh
TT_SSH_TARGET=root@192.0.2.10 \
TT_SSH_KEY=/secure/test_ed25519 \
TT_KNOWN_HOSTS=/secure/test_known_hosts \
TT_ENDPOINT_PLUGIN_PKG=/artifacts/os-trusttunnel-2.1.0.pkg \
  sh tests/smoke_endpoint_package_lifecycle.sh
```

## Откат и удаление

Откат не равен удалению. При неудачном обновлении остановите новый service,
переустановите заранее сохранённые пакеты предыдущей версии. Восстановление
конфигурации выполняйте только штатным
`POST /api/core/backup/revertBackup/<exact-pre-change-id>` после отдельного
подтверждения; затем проверьте configd, Web UI, правила и маршруты. Не
используйте `pkg delete` как способ rollback persistent-конфигурации.

Для окончательного удаления сначала отключите и остановите service, сохраните
`/conf/config.xml`, затем удалите только выбранный plugin и его бинарник:

```sh
# endpoint
pkg delete os-trusttunnel trusttunnel

# либо client
pkg delete os-trusttunnel-client trusttunnel-client
```

После удаления отдельно удалите созданный deployment workflow сертификат или
WAN rule только если у них нет других consumers, используя их native API.
Убедитесь, что пользовательские правила, интерфейсы и другие плагины не
затронуты.
