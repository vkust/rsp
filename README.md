# Расписание занятий — автономное приложение

Веб-приложение для отображения расписания занятий школы/колледжа с Telegram-ботом,
подписками на уведомления и административной панелью.

Ранее проект распространялся как плагин WordPress. В текущей версии **зависимость от
WordPress полностью устранена**: приложение работает автономно (собственный роутер,
слой совместимости с WP-функциями, база данных через PDO — SQLite или MySQL).
Весь исходный функционал плагина сохранён.

## Возможности

- Импорт расписания из XML-файлов (выгрузка из 1С/электронного журнала) и его активация;
- Публичные страницы расписания: по классам, по преподавателям, по кабинетам («панели»);
- Telegram-бот: команды `/start`, `/help`, подписка на изменения расписания,
  уведомления о ближайшем занятии и о расписании на завтра;
- Приём сообщений бота через Telegram Webhook (`POST /telegram-webhook`);
- Планировщик задач (аналог WP-Cron) с запуском через системный crontab;
- Административная панель: управление расписанием, настройки бота, статистика,
  общие настройки;
- REST-эндпоинты и AJAX-обработчики для фронтенда.

## Требования

- PHP **8.0+** с расширениями `pdo`, `pdo_sqlite` (для SQLite) или `pdo_mysql` (для MySQL),
  `curl`, `xml`, `mbstring`, `json`;
- Для продакшена — любой веб-сервер (nginx/Apache) с PHP-FPM либо встроенный сервер PHP.

## Быстрый старт

```bash
git clone https://github.com/vkust/rsp.git schedule && cd schedule   # либо скачайте ZIP и распакуйте
chmod +x setup.sh                                     # если флаг исполнения не сохранился

./setup.sh                 # интерактивная установка
./setup.sh --yes           # неинтерактивно (параметры по умолчанию, пароль — автогенерация)
./setup.sh --help          # справка
```

Мастер установки проходит по шагам:

1. **Проверка окружения** — наличие PHP ≥ 8.0 и обязательных расширений (при отсутствии
   выводятся команды установки PHP для Debian/Fedora).
2. **Конфигурация** — пошаговый опрос: URL приложения, тип базы (SQLite/MySQL), путь к базе
   или параметры MySQL, часовой пояс, логин и пароль администратора (вводится скрыто,
   в `config.php` сохраняется только bcrypt-хеш). Существующий `config.php` не портится:
   его текущие значения становятся значениями по умолчанию.
3. **Инициализация базы данных** — подключение, создание таблиц, регистрация задач
   планировщика.
4. **Итог** — готовые команды запуска dev-сервера, строка для crontab и инструкция
   по настройке Telegram-бота; сгенерированный пароль печатается один раз.

Дополнительные команды мастера:

```bash
./setup.sh hash "пароль"   # только сгенерировать bcrypt-хеш пароля
./setup.sh deactivate      # снять задачи планировщика
./setup.sh uninstall       # удалить таблицы приложения
```

### Запуск

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

Откройте:

- `http://localhost:8080/` — публичная страница расписания;
- `http://localhost:8080/admin` — вход в админку (логин `admin`, пароль — ваш).

## Конфигурация

Файл `config.php` создаётся мастером установки (или при первом запуске `install.php`).
Все параметры можно задавать через переменные окружения:

| Ключ | Переменная окружения | По умолчанию | Описание |
|---|---|---|---|
| `site_url` | `SCHEDULE_SITE_URL` | `http://localhost:8080` | Полный URL приложения (нужен для Telegram webhook и ссылок) |
| `db.type` | `SCHEDULE_DB_TYPE` | `sqlite` | `sqlite` или `mysql` |
| `db.sqlite.path` | — | `data/schedule.sqlite` | Путь к файлу базы SQLite |
| `db.mysql.*` | `SCHEDULE_DB_HOST/PORT/NAME/USER/PASS` | localhost:3306 / schedule | Параметры MySQL |
| `uploads_dir` | — | `data/uploads` | Каталог загружаемых XML-файлов |
| `timezone` | `SCHEDULE_TZ` | `Europe/Moscow` | Часовой пояс приложения |
| `admin.username` | `SCHEDULE_ADMIN_USER` | `admin` | Логин администратора |
| `admin.password_hash` | `SCHEDULE_ADMIN_HASH` | — | Хеш пароля (`./setup.sh hash "пароль"`) |
| `admin.session_secret` | `SCHEDULE_SESSION_SECRET` | — | Секрет подписи сессий (генерируется мастером) |
| `debug.*` | — | выключено | Аналог `WP_DEBUG`: вывод и лог (`logs/app.log`) |

Низкоуровневая установка без мастера (альтернатива):

```bash
php install.php                        # создать config.php + таблицы БД + задачи планировщика
php install.php --hash-password "п"    # сгенерировать хеш пароля администратора
php install.php --deactivate           # снять cron-задачи
php install.php --uninstall            # удалить таблицы и настройки
```

## Планировщик (cron)

Вместо WP-Cron используется системный crontab. Пример записей:

```cron
*/5 * * * * /usr/bin/php /path/to/app/cron.php next_lesson >> /path/to/app/logs/cron.log 2>&1
0 20 * * *  /usr/bin/php /path/to/app/cron.php next_day    >> /path/to/app/logs/cron.log 2>&1
```

Задачи регистрируются в таблице `schedule_cron` и исполняются при наступлении срока
во время любого HTTP-запроса (ленивый запуск, как wp-cron) либо через `cron.php`.
Доступна обёртка с логированием `cron-task.sh {next_lesson|next_day}`.

## Настройка Telegram-бота

1. Создайте бота у [@BotFather](https://t.me/BotFather), получите токен.
2. В админке (`/admin/bot`) сохраните токен.
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
├── setup.sh                  — мастер установки (shell-обёртка) — рекомендуется
├── setup.php                 — ядро мастера (конфиг, хеш пароля, БД)
├── install.php               — низкоуровневая CLI-установка/удаление
├── cron.php                  — запуск задач планировщика из crontab
├── cron-task.sh              — обёртка для crontab с логированием
├── school-schedule.php       — основной модуль приложения (бывший файл плагина)
├── page-schedule.php         — рендеринг публичной страницы расписания
├── includes/
│   ├── core/                 — ядро: конфиг, App_Database (PDO-обёртка с API $wpdb
│   │                           и транслятором MySQL-SQL → SQLite), роутер, схема БД
│   ├── compat/wp-shim.php    — слой совместимости: get_option/update_option, хуки,
│   │                           трансиенты, nonce, esc_* и другие WP-функции
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
| `/panels` | GET | Панели (классы/преподаватели/кабинеты) |
| `/telegram` | GET | Страница подписки бота |
| `/admin`, `/admin/...` | GET | Админка (требует сессию) |
| `/login`, `/logout` | GET/POST | Вход и выход администратора |
| `/options.php` | POST | Сохранение настроек |
| `/telegram-webhook` | POST | Webhook Telegram-бота |
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

## Лицензия

MIT (наследует лицензию исходного плагина).
