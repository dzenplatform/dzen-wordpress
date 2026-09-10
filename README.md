# Dzen Chat for WordPress

Плагин для подключения WordPress к [Dzen Chat](https://chat.dzen.dev).

**Версия 0.2.1: авторизация через реализованные `/auth/add` и `/auth/exchange/`.**
WordPress получает и шифрует `client_id`, `client_secret`, `site_source_id`.
Живой цикл проверен с локальным Dzen Chat. Production-деплой не выполнялся.
Остальные разделы подготовлены под будущие API; после реальной авторизации
они сообщают об этом и не отправляют запросы к несуществующим методам.
Серверная задача скрытия истории: [Dzen Chat #14](https://github.com/xen/dzen.chat/issues/14).

- [Спецификация плагина](docs/specs/2026-09-09-wordpress-plugin.md)
- [Требования к API Dzen Chat](docs/contracts/dzen-chat-api-v1.md)
- [Реальная авторизация и её проверка](docs/verification/2026-09-11-registration.md)

Репозиторий: `git@github.com:dzenplatform/dzen-wordpress.git`.
Локальный путь: `/Users/xen/Dev/dzen/wordpress`.

Реализация разделена на проверяемые итерации. Серверный контракт согласуется
в отдельной задаче проекта `dzen.chat`; этот репозиторий отвечает за WordPress.

Запланированная история: просмотр, поиск, фильтры, скрытие и восстановление; источник можно
прочитать внутри админки или открыть по ссылке на оригинал. Чаты, сообщения,
цитаты и биллинговые данные не копируются в БД WordPress. Удаления чатов нет.

Подключение использует PKCE S256 и одноразовый код. Успешный exchange не
запускает индексацию и не подтверждает оплату проекта. Эти возможности будут
подключаться отдельно. Кнопка удаления ключей удаляет их только из WordPress;
отзыв клиента выполняется в Dzen Chat.

## Установка и релизы

Готовые пакеты: [GitHub Releases](https://github.com/dzenplatform/dzen-wordpress/releases).
Скачайте вложение `dzen-chat-X.Y.Z.zip` и загрузите через WordPress:
«Плагины → Добавить плагин → Загрузить плагин». Для ручного обновления загрузите
ZIP новой версии и подтвердите замену установленного плагина.
Автоматические архивы GitHub «Source code» содержат весь репозиторий и для установки
не предназначены. Рядом с пакетом публикуется SHA-256.

Релизы наследуют доступ репозитория: пока он закрытый, скачать их могут только
участники. После открытия репозитория те же ссылки станут публичными.
GitHub Releases сами по себе не включают уведомления и автоматические обновления
в WordPress. Для этого позже нужен отдельный updater либо публикация в WordPress.org.
Заголовок `Update URI` сейчас защищает от подмены одноимённым плагином каталога.

При push в `main`, pull request и ручном запуске GitHub Actions выполняет проверки
упаковки, PHP lint и 101 контрактную проверку WordPress, затем сохраняет ZIP
как временный artifact на 14 дней. Push тега `vX.Y.Z` дополнительно создаёт
GitHub Release с этим ZIP и файлом `*.zip.sha256` после успешных проверок.
Существующие релизы не перезаписываются. Дополнительные секреты для workflow не нужны.

Для следующего релиза:

1. Обновите `Version` и `DZEN_CHAT_VERSION` в `dzen-chat.php`, `Stable tag` и changelog
   в `readme.txt`. Версия должна иметь формат `X.Y.Z`.
2. Закоммитьте и отправьте изменения в `main`; дождитесь успешного workflow.
3. Создайте и отправьте конкретный тег, например `git tag v0.2.2` и
   `git push origin v0.2.2`. Версия в теге обязана совпасть с метаданными плагина.
4. Готовый ZIP появится в Releases. Номер версии повышается для каждого нового пакета.

Локальная сборка: `make package-test && make package` (Python 3 и Git).
В ZIP попадают только отслеживаемые Git файлы плагина, `src/` и `assets/`;
тестовые данные, Docker, CI и локальные файлы в пакет не включаются.

Справка: [установка ZIP в WordPress](https://wordpress.org/documentation/article/manage-plugins/),
[внешние обновления](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/),
[каталог WordPress.org](https://developer.wordpress.org/plugins/wordpress-org/).

## Локальная проверка

`compose.yaml` поднимает отдельный WordPress 6.8.2/PHP 8.3 на
`http://127.0.0.1:8868`. Здесь используется тестовый API; он не входит в ZIP.
Все учётные данные стенда синтетические. Обычная установка плагина обращается
к `https://chat.dzen.dev`, без резервного перехода на тестовый API.
На публичной странице стенда отображается кнопка «Dzen Chat · тест»:
она открывает демонстрационную панель без ответов ИИ. Тестовый режим явно
обозначен в админке; его загрузчик и данные не входят в ZIP.

```sh
make up
docker compose run --rm cli core install --url=http://127.0.0.1:8868 --title='Dzen Chat contract tests' --admin_user=dzen_test --admin_password=local-dzen-test-8868 --admin_email=wordpress-fixture@example.org --skip-email
docker compose run --rm cli plugin activate dzen-chat
make setup
make test
make package
```

ZIP: `dist/dzen-chat-0.2.1.zip`. Для установки нужны HTTPS, PHP sodium и сильные
ключи WordPress в `wp-config.php`. На стенде credentials создаёт fixture, поэтому
эта проверка не доказывает реальный вход и выдачу кода на chat.dzen.dev.

Для проверки с настоящим локальным Dzen Chat используются отдельные volumes
проекта `dzen-wordpress-registration` и `compose.registration.yaml`, заменяющий
синтетический API. Порядок запуска описан в
[отчёте авторизации](docs/verification/2026-09-11-registration.md).

`make down` останавливает синтетический стенд. Живой стенд останавливается
командой Compose с отдельным именем проекта `dzen-wordpress-registration`.
