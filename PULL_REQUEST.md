# Pull Request: Миграция плагина WordPress в автономное PHP-приложение

## Описание
Полностью переработан плагин «Расписание занятий» для автономной работы **без WordPress**.
Весь функционал сохранён: парсер XML-расписания, Telegram-бот с уведомлениями, планировщик задач,
админ-панель, REST/AJAX-эндпоинты, вебхуки. Добавлен мастер установки (`setup.sh` + `setup.php`).

## Что добавлено

### Ядро приложения (includes/core/)
- **database.php** — `App_Database`: PDO-обёртка (SQLite по умолчанию, MySQL опционально) с API,
  совместимым с `$wpdb` (prepare/get_results/insert/update), кросс-платформенный SQL-транслятор
  (SHOW TABLES/COLUMNS, NOW(), CURDATE, DATE_SUB/ADD, dbDelta), автогенерация DDL под SQLite;
- **router.php** — собственный HTTP-роутер: фронтенд, админка, логин/логут (bcrypt-сессии),
  options.php (сохранение настроек), `/telegram-webhook`, `/ajax`, раздача `/assets` и `/uploads`;
- **schema.php** — определение и установка 5 таблиц (options, cron, users, subscriptions, notifications);
- **config.php** — загрузка `config.php` с приоритетом env-переменных.

### Совместимость
- **includes/compat/wp-shim.php** (~1600 строк) — WP-совместимый слой: хуки add_action/do_action,
  get_option/update_option, трансиенты, esc_*/sanitize_*, nonce, wp_remote_post (cURL), плагины-функции
  админ-страниц, аналог WP-Cron через таблицу schedule_cron.

### Установка
- **setup.sh** — shell-мастер: проверка PHP ≥ 8.0 и расширений, создание data/logs, режимы
  `--yes`, `--help`, команды `hash/deactivate/uninstall`; делегирует в PHP-ядро;
- **setup.php** — интерактивный мастер: генерация config.php (с бэкапом), скрытый ввод пароля,
  bcrypt-хеш, выбор БД, инициализация схемы, итог с готовыми командами запуска;
- **install.php** — низкоуровневая установка без интерактива;
- **bootstrap.php / public/index.php / cron.php** — точки входа.

## Изменённый код плагина
- `school-schedule.php`, `includes/telegram-bot.php` — удалены зависимости от ядра WP ($wpdb →
  App_Database, ABSPATH-пути, dbDelta, SHOW CREATE TABLE), унифицированы имена таблиц
  (исправлен баг двойного префикса `schedule_schedule_*`), исправлены битые пути ассетов.

## Исправленные баги
- пустые URL ассетов (ошибка в shim-реализации `esc_url`);
- 404 на POST /login (не хватало маршрутов GET/POST /login и /logout);
- настройки не сохранялись (реестр register_setting пуст при прямом запросе /options.php);
- транслятор DATE_SUB/DATE_ADD не обрабатывал кавычки и %-плейсхолдеры prepare();
- DDL-транслятор: скобки в типами данных, отсутствующий `;` после CREATE TABLE;
- зависание мастера при pipe-вводе пароля.

## Тестирование
- `php -l` — все файлы чистые; `sh -n setup.sh` — OK;
- чистая переустановка `./setup.sh --yes` → exit 0, 5 таблиц + индексы;
- smoke-тесты dev-сервером: `/`, `/panels`, `/telegram`, `/admin`, `/login` (302→200), все страницы
  админки, сохранение настроек (проверка значения в SQLite), импорт тестового XML-расписания,
  мок webhook `POST /telegram-webhook`, `php cron.php`;
- тесты в `tests/` (import, alter, webhook) проходят.

## Документация
- README.md переписан под автономную версию: быстрый старт через `./setup.sh`, конфигурация,
  cron, настройка Telegram-бота, структура проекта, таблица маршрутов, nginx-конфиг.

## Breaking changes
- Требуется PHP ≥ 8.0 (было: любая версия с WP);
- хранение настроек — в таблице `schedule_options` вместо `wp_options`;
- пароль администратора задаётся в мастере (bcrypt-хеш в config.php).
