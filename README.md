# Расписание занятий — автономное приложение

Веб-приложение для отображения школьного/вузовского расписания с Telegram-ботом,
подписками на уведомления и административной панелью.

Проект вырос из WordPress-плагина, но сейчас **WordPress не нужен**. Приложение
работает автономно: собственный роутер, слой совместимости с WP-функциями,
база данных через PDO (SQLite или MySQL).

## Возможности

- импорт расписания из XML (aSc Timetables, 1С, электронный журнал);
- публичные страницы расписания: обычная и режим «панели» (классы, преподаватели,
  кабинеты);
- управление категориями предметов и их видимостью;
- бегущая строка, баннеры, QR-коды и заголовок страницы;
- Telegram-бот: `/start`, `/help`, подписка на изменения, уведомления о ближайшем
  занятии и расписании на завтра;
- приём сообщений бота через webhook;
- планировщик уведомлений (аналог WP-Cron) с запуском через системный `cron`;
- админ-панель: загрузка XML, настройки сайта, смена пароля, порт сервера,
  настройки Telegram, статистика, обновления;
- смена порта и пароля администратора прямо из админки;
- обновление и откат версий со снимками кода и БД;
- HTTPS через reverse proxy (nginx/Caddy/Traefik).

## Требования

- PHP **8.0+**;
- расширения: `pdo`, `pdo_sqlite` или `pdo_mysql`, `curl`, `xml`, `mbstring`,
  `json`;
- для установки одной командой: `git` + `unzip` либо `wget`/`curl` + `unzip`;
- для обновлений: `unzip` (или расширение PHP `zip`), желательно `rsync`;
- для продакшена: nginx/Apache + PHP-FPM либо встроенный сервер PHP;
- для уведомлений: системный `cron` (необязательно, есть ленивый запуск при
  HTTP-запросах).

Установщик `setup.sh` сам ставит PHP и расширения в Debian/Ubuntu, Fedora/RHEL и
Alpine. На других системах он выведет команды для ручной установки.

## Быстрый старт

### Установка одной командой

```bash
sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
```

Или через `curl`:

```bash
sh <(curl -fsSL https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
```

Неинтерактивный режим:

```bash
sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh) --yes
```

Что делает установщик:

1. скачивает репозиторий в `./rsp` (через `git`, иначе ZIP-архив);
2. проверяет PHP и расширения, при необходимости устанавливает их;
3. запускает `setup.php` (интерактивно или с `--yes`);
4. запускает встроенный PHP-сервер:
   - как systemd-сервис `rsp-schedule.service`, если systemd доступен;
   - иначе через `nohup` с логом `logs/server.log`;
5. печатает адреса приложения, админки и рекомендации по cron/Telegram.

Параметры запуска:

```bash
SCHEDULE_PORT=8080 SCHEDULE_BIND=0.0.0.0 \
  sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)

SCHEDULE_USER=www-data \
  sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
```

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `SCHEDULE_PORT` | `8080` | Порт встроенного сервера |
| `SCHEDULE_BIND` | `0.0.0.0` | Адрес привязки |
| `SCHEDULE_USER` | `root` | Пользователь systemd-сервиса |

### Ручная установка из клонированного репозитория

```bash
git clone https://github.com/vkust/rsp.git rsp
cd rsp
chmod +x setup.sh

./setup.sh            # интерактивная установка
./setup.sh --yes      # неинтерактивно, параметры по умолчанию
./setup.sh --help     # справка
```

`setup.php` можно запускать отдельно:

```bash
php setup.php         # интерактивная настройка
php setup.php --yes   # неинтерактивно
```

## Первый запуск

После установки откройте:

- `http://<host>:8080/` — обычное расписание;
- `http://<host>:8080/panels` — расписание для панелей;
- `http://<host>:8080/admin` — вход в админку.

Логин по умолчанию — `admin`. Пароль:

- задаётся вручную в интерактивном режиме;
- при первой установке в режиме `--yes` генерируется автоматически и печатается
  в конце установки (сохраните его).

Если пароль известен, его можно сменить на странице **«Настройки сайта»** в
админке. Если пароль утерян, сгенерируйте новый хеш и обновите `config.php`:

```bash
php install.php --hash-password "новый_пароль"
# полученный хеш скопируйте в config.php → admin.password_hash
```

## Админ-панель

Главная страница — **«Основные настройки»** (`/admin?page=school-schedule`):

- загрузка XML-файла расписания и активация нужной версии;
- список загруженных файлов;
- настройки категорий предметов (видимость и назначение категорий);
- показ групп классов в карточках уроков, выбор конкретных групп и скрытие группы у всех классов;
- настройки бегущей строки;
- настройки баннеров и заголовка;
- блок **«Управление страницами»** со ссылками на обычное расписание и панели.

Остальные разделы:

| Раздел | Адрес |
|---|---|
| Настройки сайта | `/admin?page=schedule-site-settings` |
| Обновления | `/admin?page=schedule-updates` |
| Telegram-бот | `/admin?page=schedule-telegram-settings` |
| Статистика бота | `/admin?page=schedule-telegram-stats` |

### Настройки сайта

На странице `/admin?page=schedule-site-settings`:

- **Полный URL приложения** — можно оставить пустым, тогда URL определяется из
  текущего HTTP-запроса;
- **HTTPS** — флаг для работы за reverse proxy;
- **Путь к SSL-сертификату и ключу** — для справки и генерации конфига nginx;
- **Порт веб-сервера** — сохраняется в `config.php`, systemd-сервис обновляется
  и перезапускается автоматически;
- **Смена пароля администратора** — текущий пароль, новый пароль и подтверждение.

Если systemd недоступен, после смены порта сервер нужно перезапустить вручную.
Если используется reverse proxy, не забудьте обновить upstream.

### Обновления и откат версий

На странице **«Обновления»** (`/admin?page=schedule-updates`) можно:

- посмотреть установленную версию и коммит;
- проверить наличие новой версии на GitHub;
- обновить приложение;
- откатиться к любому сохранённому снимку.

Перед каждым обновлением и откатом автоматически создаётся снимок в
`.updates/backups/`: код приложения, `config.php` и резервная копия БД
(SQLite или MySQL). Хранятся последние `updates.keep_backups` снимков
(по умолчанию 10).

То же самое доступно из консоли:

```bash
php update.php version
php update.php check
php update.php update
php update.php update --force
php update.php list
php update.php rollback              # последний снимок, с восстановлением БД
php update.php rollback <id>         # конкретный снимок
php update.php rollback <id> --no-restore-db   # только код
php update.php status
```

Обновление берётся из последнего GitHub Release, если он опубликован; иначе —
из ветки `main` по SHA коммита. Версия приложения хранится в файле `VERSION`,
состояние установки — в `.updates/current.json`.

Если сервер выходит в интернет через прокси, укажите его в
`updates.proxy` (например, `http://proxy:3128`) или в переменной
`SCHEDULE_UPDATE_PROXY`. `setup.sh` также пробрасывает `HTTP_PROXY`,
`HTTPS_PROXY` и `NO_PROXY` в systemd-сервис.

## XML-расписание

1. Откройте **«Основные настройки»**.
2. Загрузите XML-файл через форму «Загрузка расписания».
3. Файл сохраняется в `data/uploads/schedules/` и становится активным.
4. При необходимости настройте категории предметов и их видимость.
5. Откройте публичную страницу `/` или `/panels`.

Парсер поддерживает формат aSc Timetables (секции `periods`, `subjects`,
`teachers`, `classrooms`, `classes`, `lessons`, `cards`; группы классов — при
наличии `groups`).

## XML для внешних приложений

Активный XML-файл расписания доступен по постоянной публичной ссылке:

```text
https://ваш-домен/schedule.xml
```

Алиас:

```text
https://ваш-домен/api/schedule.xml
```

Ссылка всегда отдаёт текущий активный файл — при загрузке новой версии
расписания адрес менять не нужно.

Особенности:

- `Content-Type: application/xml`;
- CORS разрешён (`Access-Control-Allow-Origin: *`);
- поддержка `HEAD` и `OPTIONS`;
- `ETag` и `Last-Modified` — клиент может получать `304 Not Modified`;
- `Cache-Control: no-cache, must-revalidate` — данные всегда проверяются на
  актуальность;
- сжатие gzip при поддержке клиентом.

Пример получения:

```bash
curl -O https://ваш-домен/schedule.xml
```

## Telegram-бот

1. Создайте бота у [@BotFather](https://t.me/BotFather) и получите токен.
2. Откройте в админке **«Telegram бот»** и сохраните токен.
3. Укажите webhook:

```bash
curl -F "url=https://your-domain.example/telegram-webhook" \
     "https://api.telegram.org/bot<TOKEN>/setWebhook"
```

4. Отправьте боту `/start` и проверьте подписку.

Для webhook нужен публичный HTTPS-адрес. Если `site_url` не задан, приложение
определяет его из запроса, но для стабильной работы лучше указать домен в
**«Настройках сайта»**.

## Планировщик (cron)

Уведомления можно запускать двумя способами:

- ленивый запуск — просроченные задачи выполняются при любом HTTP-запросе;
- системный `cron` — надёжнее для продакшена.

Пример crontab:

```cron
* * * * * /usr/bin/php /path/to/rsp/cron.php next_lesson >> /path/to/rsp/logs/cron.log 2>&1
0 20 * * * /usr/bin/php /path/to/rsp/cron.php next_day >> /path/to/rsp/logs/cron.log 2>&1
```

Без аргумента `cron.php` обрабатывает все просроченные задачи:

```bash
php cron.php
```

Доступные обработчики: `next_lesson`, `next_day`, `current_period`.
Обёртка `cron-task.sh` добавляет логирование.

## Конфигурация

`config.php` создаётся мастером установки и **не хранится в репозитории**.
Шаблон — `config.sample.php`. Существующий `config.php` при повторном запуске
мастера сохраняется: его значения становятся значениями по умолчанию.

Основные параметры:

| Ключ | Переменная окружения* | По умолчанию | Описание |
|---|---|---|---|
| `site_url` | `SCHEDULE_SITE_URL` | пусто | Полный URL; пусто — автоопределение из запроса |
| `server.bind` | `SCHEDULE_BIND` | `0.0.0.0` | Адрес привязки сервера |
| `server.port` | `SCHEDULE_PORT` | `8080` | Порт сервера |
| `updates.repo` | `SCHEDULE_UPDATE_REPO` | `vkust/rsp` | GitHub-репозиторий обновлений |
| `updates.branch` | `SCHEDULE_UPDATE_BRANCH` | `main` | Ветка для обновлений без релизов |
| `updates.keep_backups` | — | `10` | Сколько снимков хранить |
| `updates.proxy` | `SCHEDULE_UPDATE_PROXY` | пусто | Прокси для запросов к GitHub |
| `db.type` | `SCHEDULE_DB_TYPE` | `sqlite` | `sqlite` или `mysql` |
| `db.sqlite.path` | — | `data/schedule.sqlite` | Путь к SQLite-базе |
| `db.mysql.*` | `SCHEDULE_DB_HOST/PORT/NAME/USER/PASS` | `localhost:3306`, `schedule` | Параметры MySQL |
| `uploads_dir` | — | `data/uploads` | Каталог XML-файлов |
| `timezone` | `SCHEDULE_TZ` | `Europe/Moscow` | Часовой пояс |
| `admin.username` | `SCHEDULE_ADMIN_USER` | `admin` | Логин администратора |
| `admin.password_hash` | `SCHEDULE_ADMIN_HASH` | — | Bcrypt-хеш пароля |
| `admin.session_secret` | `SCHEDULE_SESSION_SECRET` | генерируется | Секрет подписи сессий/nonce |
| `debug.enabled` | — | `false` | Включить отладку (аналог `WP_DEBUG`) |
| `debug.display` | — | `false` | Показывать ошибки в браузере |
| `debug.log` | — | `logs/app.log` | Файл лога ошибок |

\* Переменные окружения из таблицы читает `config.sample.php`; `setup.sh` и
`setup.php` дополнительно учитывают `SCHEDULE_PORT` и `SCHEDULE_BIND`. В
сгенерированном `config.php` значения уже записаны явно — после установки их
удобнее менять в админке или в `config.php`.

Сгенерировать хеш пароля вручную:

```bash
php install.php --hash-password "новый_пароль"
```

## Низкоуровневая установка (`install.php`)

`install.php` — альтернатива мастеру для скриптов и отладки:

```bash
php install.php                        # config.php (из sample) + таблицы + cron
php install.php --hash-password "пароль"
php install.php --deactivate           # снять cron-задачи
php install.php --uninstall            # удалить таблицы приложения
```

Для обычной установки используйте `setup.sh` / `setup.php`: они создают
нормальный `config.php` с паролем и параметрами подключения.

## Структура проекта

```text
├── public/index.php           — точка входа (фронт-контроллер)
├── bootstrap.php              — загрузка ядра (аналог wp-load.php)
├── VERSION                    — текущая версия приложения
├── config.php                 — создаётся установщиком (не в git)
├── config.sample.php          — шаблон конфигурации
├── setup.sh                   — установщик одной командой + запуск сервера
├── setup.php                  — мастер настройки (config, БД, пароль)
├── install.php                — низкоуровневые CLI-команды
├── update.php                 — CLI обновления/отката
├── update-worker.php          — фоновая задача для админки
├── cron.php                   — запуск задач планировщика
├── cron-task.sh               — обёртка cron с логированием
├── school-schedule.php        — основной модуль приложения
├── page-schedule.php          — рендеринг публичной страницы
├── includes/
│   ├── core/
│   │   ├── config.php         — App_Config
│   │   ├── database.php       — PDO-обёртка с API $wpdb
│   │   ├── router.php         — маршрутизация и админ-оболочка
│   │   ├── updater.php        — снимки, обновление, откат
│   │   └── schema.php         — схема БД и миграции
│   ├── compat/wp-shim.php     — совместимость с WP-функциями
│   ├── parser.php             — парсер XML-расписания
│   ├── scheduler.php          — уведомления next_lesson / next_day
│   ├── telegram-bot.php       — Telegram-бот
│   ├── rest-api.php           — REST/webhook
│   └── debug.php              — отладочные хелперы
├── templates/                 — шаблоны страниц и админки
├── assets/                    — CSS/JS и локальные vendor-библиотеки (/assets/*)
├── data/                      — SQLite и uploads/ (создаётся автоматически)
├── .updates/                  — снимки, статус и current.json (создаётся автоматически)
└── logs/                      — app.log, cron.log, telegram-bot.log, updater.log;
                                 server.log — при запуске через nohup
```

## Маршруты

| Путь | Метод | Назначение |
|---|---|---|
| `/`, `/schedule`, `/raspisanie` | GET | Обычное расписание |
| `/panels` | GET | Расписание для панелей |
| `/schedule.xml`, `/api/schedule.xml` | GET/HEAD | XML активного файла расписания |
| `/telegram` | GET | Страница подписки Telegram |
| `/admin`, `/admin/...` | GET | Админка (нужна сессия) |
| `/admin/admin-post.php` | POST | Обработчики форм админки |
| `/login`, `/logout` | GET/POST | Вход и выход |
| `/options`, `/options.php` | POST | Сохранение настроек |
| `/ajax` | GET/POST | AJAX-обработчики |
| `/telegram-webhook` | POST | Webhook Telegram |
| `/wp-json/school-schedule/v1/telegram-webhook` | POST | Алиас webhook |
| `/assets/*`, `/uploads/*` | GET | Статика и XML-файлы |

## Продакшен и HTTPS

Встроенный PHP-сервер (`php -S`) не поддерживает HTTPS. Для HTTPS используйте
reverse proxy (nginx, Caddy, Traefik). Пример nginx:

```nginx
server {
    listen 443 ssl;
    server_name schedule.example.com;
    root /var/www/rsp/public;

    ssl_certificate     /etc/letsencrypt/live/schedule.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/schedule.example.com/privkey.pem;

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

Укажите домен в **«Настройках сайта»** (`site_url`). Директории `data/` и
`logs/` должны быть доступны для записи пользователю PHP; наружу публикуется
только `public/`.

Для небольших установок можно оставить встроенный сервер:

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

## Обновление и откат

### Из админки

Откройте **«Обновления»** (`/admin?page=schedule-updates`), нажмите
«Проверить обновления», затем «Обновить». Задача выполняется в фоне, страница
показывает журнал. Перед обновлением автоматически создаётся снимок.

Для отката выберите нужный снимок в таблице и нажмите «Откатить». По умолчанию
восстанавливаются и код, и база данных; галочку «БД» можно снять.

### Из консоли

```bash
cd /path/to/rsp

php update.php check
php update.php update
php update.php list
php update.php rollback              # последний снимок, с БД
php update.php rollback <id>         # конкретный снимок
php update.php rollback <id> --no-restore-db
php update.php status
```

Снимки лежат в `.updates/backups/`, журнал — в `logs/updater.log`.
`config.php`, `data/` и `logs/` при обновлении не перезаписываются.

### Вручную через git

Если приложение развёрнуто из git-клона:

```bash
cd rsp
git pull
sudo systemctl restart rsp-schedule   # если установлено как systemd-сервис
```

## Резервное копирование

Достаточно сохранить:

- `config.php`;
- `data/schedule.sqlite` (или дамп MySQL);
- `data/uploads/`;
- при необходимости — `logs/`.

## Разработка

После изменений в `assets/css/schedule.css`, `assets/css/schedule-template.css`
или `assets/js/schedule.js` пересоберите фронтенд-бандлы:

```bash
php tools/build-assets.php
```

## Лицензия

MIT (наследует лицензию исходного плагина).
