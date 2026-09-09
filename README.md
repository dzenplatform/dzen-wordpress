# Dzen Chat for WordPress

Плагин для подключения WordPress к [Dzen Chat](https://chat.dzen.dev): виджеты,
триггеры страниц, синхронизация индекса, история диалогов и состояние источников
в административной панели сайта.

**Состояние: клиент WordPress 0.1.1 для контракта API.** Код плагина реализован;
серверная готовность API и production-интеграция пока не подтверждены.
Серверная задача скрытия истории: [Dzen Chat #14](https://github.com/xen/dzen.chat/issues/14).

- [Спецификация плагина](docs/specs/2026-09-09-wordpress-plugin.md)
- [Требования к API Dzen Chat](docs/contracts/dzen-chat-api-v1.md)

Репозиторий: `git@github.com:dzenplatform/dzen-wordpress.git`.
Локальный путь: `/Users/xen/Dev/dzen/wordpress`.

Реализация разделена на проверяемые итерации. Серверный контракт согласуется
в отдельной задаче проекта `dzen.chat`; этот репозиторий отвечает за WordPress.

История: просмотр, поиск, фильтры, скрытие и восстановление; источник можно
прочитать внутри админки или открыть по ссылке на оригинал. Чаты, сообщения,
цитаты и биллинговые данные не копируются в БД WordPress. Удаления чатов нет.

Базовые разделы: подключение через `/auth/add` с PKCE, зашифрованные credentials,
статус проекта, виджеты, очередь публичных страниц/записей, триггеры и источники.
Полный объём и границы приёмки описаны в спецификации.

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

ZIP: `dist/dzen-chat-0.1.1.zip`. Для установки нужны HTTPS, PHP sodium и сильные
ключи WordPress в `wp-config.php`. На стенде credentials создаёт fixture, поэтому
эта проверка не доказывает реальный вход и выдачу кода на chat.dzen.dev.

Исправление показа виджета и результаты проверки:
[проверка публичной страницы](docs/verification/2026-09-09-widget-display.md).

`make down` останавливает только контейнеры этого стенда. Внешние источники,
производственный сайт и репозитории Dzen Chat не изменяются.
