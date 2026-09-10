# Авторизация WordPress 0.2.0

За основу взяты задача Codex `01a08b55-5ca1-76e2-ad7f-e5eae6e72135` и текущие
реализованные обработчики Dzen Chat. Код основного проекта не изменялся.

## Что изменено

Переход на `/auth/add` передаёт `type=wordpress`, HTTPS `site_url`, callback
в пределах сайта, `state`, S256 `code_challenge`. После возврата WordPress
проверяет state, сессию администратора, срок попытки, сайт и origin сервиса.
Одноразовый code обменивается через JSON POST `/auth/exchange/`.

Принимаются реальные поля `client_id`, `client_secret`, `site_source_id`.
Ключи шифруются прежним sodium-хранилищем WordPress с `autoload=false`.
Придуманные project/integration IDs больше не требуются для подключения.
Код удаляется из видимого URL, повторный callback не отправляет второй exchange.

Успех exchange подтверждает авторизацию, без запроса будущего `/api/v1/integration`.
Виджеты, история, индексация и статусы источников не адаптировались к серверу.
Их подготовленный код сохранён; новое подключение не включает эти возможности
и не отправляет события индексации. Локальное удаление ключей явно отличается
от отзыва клиента в Dzen Chat.

## Результаты

- PHP lint и 101 проверка: прежние 48 контрактных + 17 виджетных,
  36 новых в `tests/registration-contracts.php`.
- Новый контракт проверяет точный endpoint/JSON, PKCE, отсутствующие поля ответа,
  HTTP 302/400, ошибку сети, неверный JSON, state/сессию/origin/TTL, повтор кода,
  nonce, HTTPS/path-prefix, шифрование и отсутствие вызовов будущих API.
- Во встроенном браузере выполнен настоящий цикл: WordPress → локальный
  Dzen Chat → создание отдельного проекта → callback → exchange → успешная
  авторизация WordPress. Перезагрузка сохранила подключение.
- Повторное подключение к тому же проекту выдало нового клиента и сохранило
  `site_source_id`. Проверка в WP показала: секрет зашифрован, autoload выключен,
  синтетический API не загружен, очередь индексирования пуста.

Живой тест использовал `https://local.dzenchat.com` и
`https://local.dzenchat.com:8869`, а не production. Ни ключи, ни код exchange
в отчёт или Git не включены. Создан отдельный тестовый проект `local.dzenchat.com`
с источником `https://local.dzenchat.com:8869/`; подключение оставлено для проверки.

## Повторение живой проверки

Отдельный Compose project сохраняет реальную регистрацию независимо от
синтетических `make test` / `make setup`. Оба WordPress используют порт 8868,
поэтому одновременно запускается только один стенд.

На этой машине локальный Caddy уже имеет доверенный сертификат
`local.dzenchat.com`. Публичный CA копируется с правами чтения для контейнера;
проверка сертификатов остаётся включённой.

```sh
docker compose stop
mkdir -p output/caddy-registration
install -m 644 /opt/homebrew/var/lib/caddy/pki/authorities/local/root.crt output/caddy-registration/dzen-local-ca.crt
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml up -d db wordpress
caddy run --config tests/Caddyfile.registration --adapter caddyfile
```

Последняя команда запускается в отдельном терминале. Тестовый proxy использует
существующий сертификат; после его обновления proxy нужно перезапустить.
Хранилище proxy изолировано в `output/caddy-registration`, автосохранение
основной конфигурации Caddy отключено. При первом запуске до этой изоляции Caddy
автоматически очистил три набора давно просроченных сертификатов в стандартном
пользовательском каталоге `~/Library/Application Support/Caddy`.

При первом запуске нового volume:

```sh
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml run --rm cli core install --url=https://local.dzenchat.com:8869 --title='WordPress Dzen integration' --admin_user=dzen_test --admin_password=local-dzen-test-8868 --admin_email=wordpress-registration@example.org --skip-email
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml run --rm cli plugin activate dzen-chat
```

Это отдельная тестовая учётная запись WordPress. Затем открыть
`https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat` во встроенном
браузере, войти и нажать «Подключить Dzen Chat». Для production по умолчанию
используется `https://chat.dzen.dev`; локальный origin задаёт только dev overlay.

Архив `dist/dzen-chat-0.2.0.zip` содержит плагин без fixture, Compose и локальных
сертификатов. Production-деплой и API управления не проверялись в этой итерации.
