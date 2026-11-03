# Расписание занятий — автономное приложение

Веб-приложение для отображения расписания занятий школы/колледжа с Telegram-ботом,
подписками на уведомления и административной панелью.

Ранее проект распространялся как плагин WordPress. В текущей версии **зависимость от
WordPress полностью устранена**: приложение работает автономно, используя собственный
 роутер, слой совместимости с WP-функциями и базу данных через PDO (SQLite или MySQL).
Весь исходный функционал плагина сохранён.

## Возможности

- Импорт расписания из XML-файлов (выгрузка из 1С/электронного журнала) и его активация;
- Публичные страницы расписания: по классам, по преподавателям, по кабинетам («панели»);
- Telegram-бот: команды `/start`, `/help`, подписка на изменения расписания,
  уведомления о ближайшем занятии и о расписании на завтра;
- Приём сообщений бота через Telegram Webhook (`POST /telegram-webhook`);
- Планировщик задач (аналог WP-Cron) с запуском через системный crontab;
- Административная панель: управление расписанием, настройки бота, статистика,
  общие настройки (аналог `options.php`);
- REST-эндпоинты и AJAX-обработчики для фронтенда.

## Требования

- PHP **8.0+** (проверено на 8.2) с расширениями `pdo`, `pdo_sqlite` (для SQLite),
  `pdo_mysql` (для MySQL), `curl`, `xml`/`simplexml`, `mbstring`, `json`;
- Для продакшена — любой веб-сервер (nginx/Apache) с PHP-FPM или встроенный сервер PHP.

## Быстрый старт

### Вариант 1 — мастер установки (рекомендуется)

```bash
php setup.php
```

Мастер по шагам: проверит PHP и расширения, спросит URL приложения, тип базы
(SQLite/MySQL), пути каталогов, часовой пояс; предложит задать логин и пароль
администратора (пароль запрашивается скрыто, в `config.php` сохраняется только
хеш, а секрет сессий генерируется автоматически); затем создаст таблицы БД,
зарегистрирует задачи планировщика и выведет итог с готовыми командами запуска.
Существующий `config.php` не портится: его значения становятся значениями по
умолчанию, а при пересоздании делается резервная копия `config.php.backup-*`.

Неинтерактивная установка (все параметры по умолчанию, пароль генерируется
автоматически и печатается один раз в конце):

```bash
php setup.php --yes
```

### Вариант 2 — ручной запуск установщика

```bash
# 1. Установка зависимостей нет — достаточно PHP. Создаём конфиг и базу:
php install.php

# 2. Задаём пароль администратора:
php install.php --hash-password "ВАШ_ПАРОЛЬ"
#    скопируйте полученный хеш в config.php => ['admin']['password_hash']
```

### Запуск

```bash
# Локальный сервер (для разработки):
php -S 0.0.0.0:8080 -t public public/index.php
```

Откройте:

- `http://localhost:8080/` — публичная страница расписания;
- `http://localhost:8080/admin` — вход в админку (логин `admin`, пароль — ваш).

## Конфигурация

Файл `config.php` создаётся из `config.sample.php` при первом запуске `install.php`.
Все параметры можно задавать и через переменные окружения:

| Ключ | Переменная окружения | По умолчанию | Описание |
|---|---|---|---|
| `site_url` | `SCHEDULE_SITE_URL` | `http://localhost:8080` | Полный URL приложения (нужен для Telegram webhook и ссылок) |
| `db.type` | `SCHEDULE_DB_TYPE` | `sqlite` | `sqlite` или `mysql` |
| `db.sqlite.path` | — | `data/schedule.sqlite` | Путь к файлу базы SQLite |
| `db.mysql.*` | `SCHEDULE_DB_HOST/PORT/NAME/USER/PASS` | localhost:3306 / schedule | Параметры MySQL |
| `db.prefix` | — | `` (пусто) | Префикс таблиц (как `$wpdb->prefix`) |
| `uploads_dir` | — | `data/uploads` | Каталог загружаемых XML-файлов |
| `timezone` | `SCHEDULE_TZ` | `Europe/Moscow` | Часовой пояс приложения |
| `admin.username` | `SCHEDULE_ADMIN_USER` | `admin` | Логин администратора |
| `admin.password_hash` | `SCHEDULE_ADMIN_HASH` | — | Хеш пароля (`php install.php --hash-password "пароль"`) |
| `admin.session_secret` | `SCHEDULE_SESSION_SECRET` | `change-me...` | Секрет подписи сессий — **обязательно измените** |
| `debug.*` | — | выключено | Аналог `WP_DEBUG`: вывод и лог (`logs/app.log`) |

## Команды установки

```bash
php install.php                        # создать config.php + таблицы БД + задачи планировщика
php install.php --hash-password "п"    # сгенерировать хеш пароля администратора
php install.php --deactivate           # снять cron-задачи (аналог деактивации плагина)
php install.php --uninstall            # удалить таблицы и настройки (аналог удаления плагина)
```

## Планировщик (cron)

Приложение не имеет внутреннего планировщика WordPress — вместо него используется
системный crontab. Пример записи:

```cron
*/5 * * * * /usr/bin/php /path/to/app/cron.php next_lesson >> /path/to/app/logs/cron.log 2>&1
0 20 * * *  /usr/bin/php /path/to/app/cron.php next_day    >> /path/to/app/logs/cron.log 2>&1
```

Также доступен готовый скрипт-обёртка `cron-task.sh {next_lesson|next_day}`
(логирует запуски в `logs/cron.log`).

Задачи регистрируются в таблице `schedule_cron` и исполняются при наступлении срока
во время любого HTTP-запроса (ленивый запуск, как wp-cron) либо через `cron.php`.

## Настройка Telegram-бота

1. Создайте бота у [@BotFather](https://t.me/BotFather), получите токен.
2. В админке (`/admin/bot`) сохраните токен — настройки пишутся в таблицу `schedule_options`.
3. Укажите webhook на ваш публичный URL:

```bash
curl -F "url=https://your-domain.example/telegram-webhook" \
     "https://api.telegram.org/bot<TOKEN>/setWebhook"
```

4. Проверьте работу: отправьте боту `/start`.

## Структура проекта

```
├── public/index.php          — точка входа (весь трафик идёт через неё)
├── bootstrap.php             — загрузка ядра (аналог wp-load.php)
├── config.php / config.sample.php — конфигурация
├── install.php               — CLI-установка/удаление
├── cron.php                  — запуск задач планировщика из crontab
├── cron-task.sh              — обёртка для crontab с логированием
├── school-schedule.php       — основной модуль приложения (бывший файл плагина)
├── page-schedule.php         — рендеринг публичной страницы расписания
├── includes/
│   ├── core/
│   │   ├── config.php        — загрузчик конфигурации
│   │   ├── database.php      — App_Database: PDO-обёртка с API $wpdb
│   │   │                     (prepare/get_results/get_var/insert/update/replace и
│   │   │                      транслятор MySQL-SQL → SQLite: SHOW TABLES/COLUMNS,
│   │   │                      CURDATE(), NOW(), DATE_SUB/DATE_ADD и т.д.)
│   │   ├── router.php        — маршрутизация: фронтенд, /admin, AJAX, webhook,
│   │   │                     /login /logout /options.php, раздача /assets и /uploads
│   │   └── schema.php        — создание/удаление таблиц (аналог dbDelta)
│   ├── compat/wp-shim.php    — слой совместимости: get_option/update_option,
│   │                         add_action/do_action, трансиенты, хуки REST/AJAX,
│   │                         esc_*__/*, nonce, dbDelta и сотни других WP-функций
│   ├── parser.php            — парсер XML-расписания
│   ├── scheduler.php         — логика уведомлений (next lesson / next day)
│   ├── telegram-bot.php      — Telegram-бот (webhook, команды, подписки)
│   └── rest-api.php          — эндпоинты JSON для фронтенда
├── templates/                — HTML-шаблоны страниц и админки
├── assets/                   — CSS/JS/img (раздаются по /assets/*)
├── data/                     — база SQLite и uploads/ (создаётся автоматически)
└── logs/                     — app.log, cron.log
```

## Маршруты приложения

| Путь | Метод | Назначение |
|---|---|---|
| `/`, `/schedule`, `/raspisanie` | GET | Публичная страница расписания |
| `/panels` | GET | Панели (классы/преподаватели/кабинеты) — аналог шорткода `school_schedule_panels` |
| `/telegram` | GET | Страница подписки бота — аналог шорткода `telegram_schedule` |
| `/admin`, `/admin/...` | GET | Админка (требует сессию) |
| `/login`, `/logout` | GET/POST | Вход и выход администратора |
| `/options.php` | POST | Сохранение настроек (аналог `wp-admin/options.php`) |
| `/telegram-webhook`, `/wp-json/school-schedule/v1/telegram-webhook` | POST | Webhook Telegram-бота |
| `/ajax?action=...` | GET/POST | AJAX-обработчики (аналог `admin-ajax.php`) |
| `/assets/*`, `/uploads/*` | GET | Статика и загруженные XML-файлы |

## Деплой в продакшене (nginx пример)

```nginx
server {
    listen 443 ssl;
    server_name schedule.example.com;
    root /var/www/schedule/public;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Укажите реальный домен в `site_url` (`SCHEDULE_SITE_URL`) — от него зависит webhook
Telegram и ссылки в уведомлениях. Директории `data/` и `logs/` должны быть доступны
для записи пользователю PHP; наружу они не публикуются (root — только `public/`).

## Отличие от прежней WP-версии

- Нет требований к WordPress, темам, плагинам и авторизации WP;
- `$wpdb` заменён на `App_Database` (PDO) с сохранением интерфейса и SQL-совместимости;
- Хуки `add_action/add_filter/do_action` работают через собственный реестр
  (`includes/compat/wp-shim.php`) — код модулей не переписывался;
- WP-Cron заменён на таблицу `schedule_cron` + ленивый запуск + `cron.php` для crontab;
- REST API WordPress заменён собственными маршрутами `/rest-api/*` (URL совместимы);
- Опции WordPress хранятся в таблице `schedule_options` с теми же именами ключей;
- Авторизация в админку — одна учётная запись из `config.php` (bcrypt-хеш) + сессии;
- Функционал (парсер, бот, подписки, уведомления, статистика, панели) — без изменений.

## Лицензия

MIT (наследует лицензию исходного плагина).
