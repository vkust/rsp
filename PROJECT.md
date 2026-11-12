# RSP — карта проекта для будущих разработчиков и моделей

Этот файл описывает текущее устройство проекта, ключевые решения и правила
работы. Он предназначен для быстрого входа в контекст без чтения всей истории
изменений.

> Не храните здесь пароли, SSH-ключи, IP-адреса и другие секреты.

## 1. Что это

Автономное PHP-приложение для показа школьного/вузовского расписания.

Раньше проект был WordPress-плагином. Сейчас WordPress **не используется**:
есть собственный роутер, слой совместимости с WP-функциями и база через PDO
(SQLite или MySQL).

Основные возможности:

- импорт XML-расписания (aSc Timetables);
- публичная страница расписания и режим панелей;
- фильтры по учителю, предмету, классу, дню и кабинету;
- кнопка «Сегодня»;
- отсчёт до начала занятий за час до первого урока в блоке периода;
- кнопка «Свободные кабинеты»: модальное окно с выбором дня и урока,
  по умолчанию — сегодня и текущий урок (на перемене — следующий);
- сетка занятости кабинетов в модальном окне: строки — кабинеты,
  столбцы — уроки, свободные и занятые ячейки, переключение дней;
- категории предметов и выбор отображаемых групп классов;
- бегущая строка, баннеры, QR-код и заголовок;
- Telegram-бот и уведомления;
- админка;
- система обновления и отката версий;
- публичный XML-endpoint для внешних приложений;
- управление фавиконом.

Текущая версия — `2.18.2` (см. `VERSION`).

## 2. Быстрый старт

Установка на чистый Linux-сервер:

```bash
sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
```

или:

```bash
sh <(curl -fsSL https://raw.githubusercontent.com/vkust/rsp/main/setup.sh)
```

`setup.sh`:

1. скачивает репозиторий в `./rsp`;
2. проверяет/устанавливает PHP и расширения;
3. запускает `setup.php`;
4. запускает встроенный PHP-сервер через systemd (`rsp-schedule.service`) или
   `nohup`.

Неинтерактивный режим:

```bash
sh <(wget -qO- https://raw.githubusercontent.com/vkust/rsp/main/setup.sh) --yes
```

## 3. Поток запроса

```text
HTTP
  → public/index.php
      → gzip-обёртка (ob_gzhandler)
      → статика для cli-server (/assets, /uploads)
      → bootstrap.php
          → App_Config
          → App_Database
          → wp-shim.php
          → schema.php
          → school-schedule.php (хуки и класс SchoolSchedule)
      → includes/core/router.php
          → App_Router::dispatch()
              → /, /panels, /telegram, /admin, /ajax, /schedule.xml, ...
```

Точка входа всегда `public/index.php`. Корень приложения — `APP_ROOT`.

## 4. Ключевые файлы

```text
public/index.php              — фронт-контроллер, gzip, статика cli-server
bootstrap.php                 — загрузка ядра
config.php                    — генерируется установщиком, не в git
config.sample.php             — шаблон конфигурации
VERSION                       — версия приложения
setup.sh / setup.php          — установка
install.php                   — низкоуровневая установка/миграции
update.php                    — CLI обновления/отката
update-worker.php             — фоновая задача обновления для админки
cron.php                      — задачи уведомлений

includes/core/config.php      — App_Config (точечные ключи: db.type)
includes/core/database.php    — App_Database (PDO + API $wpdb)
includes/core/router.php      — App_Router (маршруты, админ-оболочка)
includes/core/schema.php      — таблицы БД
includes/core/updater.php     — App_Updater (обновления, снимки, откат)

includes/compat/wp-shim.php   — get_option/update_option, хуки, nonce, esc_*,
                                wp_upload_dir, favicon-хелперы и т.д.
includes/parser.php           — парсер XML aSc
includes/scheduler.php        — уведомления
includes/telegram-bot.php     — Telegram-бот
includes/rest-api.php         — webhook/REST

school-schedule.php           — основной модуль: хуки, админка, рендер,
                                загрузка XML, настройки
templates/                    — HTML-шаблоны публичных страниц и админки
assets/                       — CSS/JS
assets/vendor/                — локальные jQuery и jQuery UI
assets/dist/                  — собранные app.min.css / app.min.js
tools/build-assets.php        — сборка фронтенд-бандлов
data/                         — SQLite и uploads (не в git)
logs/                         — логи (не в git)
.updates/                     — снимки и состояние обновлений (не в git)
```

## 5. Админка

Вход:

```text
/admin
```

Разделы:

| Раздел | URL |
|---|---|
| Основные настройки | `/admin?page=school-schedule` |
| Настройки сайта | `/admin?page=schedule-site-settings` |
| Обновления | `/admin?page=schedule-updates` |
| Telegram-бот | `/admin?page=schedule-telegram-settings` |
| Статистика бота | `/admin?page=schedule-telegram-stats` |

### Основные настройки

- загрузка и активация XML;
- список файлов;
- категории предметов и их видимость;
- показ групп классов:
  - мастер-переключатель;
  - выбор конкретных групп по классам;
  - глобальное скрытие группы по названию;
- бегущая строка;
- баннеры, QR и заголовок;
- свободные кабинеты: исключение отдельных кабинетов из списка и сетки;
- расположение кнопок отдельно для основной страницы и панелей,
  для компьютеров и мобильных;
- смешанный режим: кнопки в шапке, при прокрутке — в выбранном месте;
- «Управление страницами»:
  - `/`;
  - `/panels`;
  - `/schedule.xml`.

### Настройки сайта

- URL приложения;
- HTTPS и пути к сертификатам;
- порт встроенного сервера;
- смена пароля администратора;
- фавикон.

### Обновления

- текущая версия/коммит;
- проверка обновлений;
- обновление;
- список снимков;
- откат кода и/или БД;
- журнал.

## 6. Расписание: парсинг и рендеринг

### Парсер

`includes/parser.php` читает aSc XML:

- `periods`;
- `subjects`;
- `teachers`;
- `classrooms`;
- `classes`;
- `groups` (если есть);
- `lessons`;
- `cards`.

Особенности:

- парсер имеет guard `if (!defined('ABSPATH')) exit;` — запускать его после
  `bootstrap.php` или определить `ABSPATH`;
- группы класса хранят `entireclass`; группы «Весь класс» не показываются как
  подгруппы;
- парсер фильтрует уроки по видимым категориям, но если категории ещё не
  настроены — показывает всё.

### Активный файл

Активный XML хранится в опции:

```text
schedule_active_file
```

Если файл недоступен, берётся самый свежий XML из `data/uploads/schedules/`.
Путь обязательно должен находиться внутри каталога загрузок.

### Рендеринг

`templates/schedule-template.php`:

- фильтры (jQuery UI selectmenu на десктопе, native select на мобильных);
- кнопка «Сегодня»;
- кнопка «Свободные кабинеты»;
- переключение горизонтального/вертикального вида;
- экспорт в Excel (ExcelJS подгружается лениво);
- группа/класс/кабинет в карточке урока;
- текущее время и период;
- таблица минифицируется через `ob_start()` + удаление пробелов между тегами.

### Свободные кабинеты

Данные передаются в шаблон JSON-скриптом `#freeRoomsData`:

- `rooms` — названия кабинетов;
- `roomShorts` — короткие названия для узких экранов;
- `days` — дни недели с короткими названиями;
- `periods` — уроки с временем начала/конца;
- `usedPeriods` — уроки, которые встречаются в расписании;
- `occupied` — занятость `[день][урок][кабинет]` с классами, предметом и
  учителями.

Занятость считается по `lessons_all`, который парсер заполняет **до**
фильтрации по видимости категорий. Поэтому уроки скрытых категорий всё равно
занимают кабинет. Несколько кабинетов в одном уроке разбираются через
запятую.

По умолчанию окно открывается на сегодня и на текущий урок; во время
перемены — на следующий. Если сегодня выходной или на сегодня нет уроков,
выбирается ближайший учебный день. Ручной выбор дня или урока отключает
автоподсказку.

Кабинеты можно исключить из списка и сетки. Опция
`schedule_free_rooms_excluded` содержит массив ID исключённых кабинетов.
Управление: `/admin?page=school-schedule` → карточка «Свободные кабинеты».

### Сетка свободных кабинетов

Открывается кнопкой «Открыть сетку» в модальном окне «Свободные кабинеты».
Это второе модальное окно, отдельной страницы нет:

- строки — кабинеты, столбцы — уроки, которые встречаются в расписании;
- свободная ячейка — зелёная с `✓`, занятая — красная с классом;
- в заголовке урока показывается количество свободных кабинетов;
- день по умолчанию — тот, что выбран в списке свободных кабинетов;
- текущий урок подсвечивается, если открыт сегодняшний день;
- при нажатии на занятую ячейку открывается карточка с днём, уроком,
  предметом, классом и учителем;
- дни переключаются без перезагрузки;
- занятость считается по `lessons_all`.

На экранах до `1024px` используются короткие названия кабинетов (`short` из
XML), на мобильных сетка открывается на весь экран. Разметка сетки собирается
в `assets/js/schedule.js`, стили — в `assets/css/schedule.css`.

## 7. Публичные страницы и XML API

Маршруты:

```text
/                    — обычное расписание
/schedule             — алиас
/raspisanie           — алиас
/panels               — расписание для панелей
/telegram             — страница подписки Telegram
/login, /logout       — вход/выход
/admin...             — админка
/ajax                 — AJAX
/schedule.xml         — активный XML
/api/schedule.xml     — алиас
/raspisanie.xml       — алиас
/assets/*, /uploads/* — статика и файлы
```

`/schedule.xml`:

- всегда отдаёт текущий активный XML;
- `Content-Type: application/xml`;
- CORS `*`;
- `ETag`, `Last-Modified`;
- `304 Not Modified`;
- `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`;
- заголовки `X-Schedule-File`, `X-Schedule-Md5`;
- gzip;
- поддерживает `GET`, `HEAD`, `OPTIONS`.

## 8. Обновления и откат

Система: `App_Updater` + `.updates/`.

### CLI

```bash
php update.php version
php update.php check
php update.php update
php update.php update --force
php update.php list
php update.php rollback
php update.php rollback <id>
php update.php rollback <id> --no-restore-db
php update.php status
```

### Админка

`/admin?page=schedule-updates`.

Задачи запускаются в фоне через `systemd-run` (или `nohup`), чтобы пережить
перезапуск сервиса.

### Снимки

`.updates/backups/<id>/`:

- `code/` — код приложения;
- `config.php.snapshot` — копия конфига;
- `database.sqlite` или `database.sql` — БД;
- `manifest.json`.

Хранятся последние `updates.keep_backups` (по умолчанию 10).

### Источник обновлений

1. последний GitHub Release, если опубликован;
2. иначе `main` по конкретному SHA коммита.

`VERSION` читается по этому же SHA, чтобы версия и архив были согласованы.

### Прокси

Если GitHub доступен только через прокси:

```php
'updates' => [
    'proxy' => 'http://proxy:3128',
],
```

или `SCHEDULE_UPDATE_PROXY`.

### Важно

- обновление и откат могут перезапускать `rsp-schedule.service`;
- откат по умолчанию восстанавливает и код, и БД;
- `--no-restore-db` — только код;
- управляющая копия updater лежит в `.updates/lib/updater.php` и переживает
  откат к версии без этого класса.

## 9. Фронтенд-ассеты и производительность

- jQuery и jQuery UI лежат локально в `assets/vendor/`.
- Общие бандлы:

```text
assets/dist/app.min.css
assets/dist/app.min.js
```

- После изменений в `assets/css/schedule.css`,
  `assets/css/schedule-template.css` или `assets/js/schedule.js` нужно выполнить:

```bash
php tools/build-assets.php
```

- ExcelJS не входит в начальную загрузку: подключается при нажатии «Экспорт».
- В `public/index.php` включён gzip через `ob_gzhandler`.
- Бинарные файлы (PNG/JPG/WEBP/ICO/WOFF2) не сжимаются.
- Статика отдаётся с длинным кешем, версия в URL меняется через `filemtime`.
- Таблица расписания минифицируется.
- Шапка использует CSS Grid: часы/период по центру, кнопки справа.
- На экранах до `1024px` кнопки показываются только иконками; QR скрывается.
- Таблица прокручивается вместе со страницей; при достижении шапкой верха
  экрана её копия закрепляется fixed-слоем (`schedule-sticky-header`) с лёгкой
  анимацией появления. Горизонтальная прокрутка синхронизируется сразу.
- Текст первого столбца закрепляется под шапкой при вертикальной прокрутке;
  при горизонтальной прокрутке он уходит вместе с остальными колонками.
  Текущий урок сохраняет подсветку, следующий блок выталкивает предыдущий.
- На мобильных фильтры в одну колонку, период и часы не перекрываются.

### Расположение кнопок

Компоновка страницы одна — классическая: часы по центру, фильтры под шапкой.

Расположение кнопок настраивается отдельно:

```text
schedule_main_buttons_desktop
schedule_main_buttons_mobile
schedule_panels_buttons_desktop
schedule_panels_buttons_mobile
```

Значения:

- `header` — кнопки в шапке;
- `right` / `left` — постоянно фиксированная вертикальная панель;
- `bottom` — постоянно фиксированная панель снизу;
- `scroll-right` / `scroll-left` / `scroll-bottom` — смешанный режим:
  кнопки остаются в шапке, а после прокрутки страницы переносятся
  в выбранное фиксированное место.

Переопределение для мобильных действует до `768px`, для компьютеров — от `769px`.
Классы `buttons-desktop-*` и `buttons-mobile-*` добавляются к
`.schedule-container`. В смешанном режиме JS добавляет класс
`buttons-floating` при прокрутке (`scrollY > 120`) и убирает его при
возврате наверх (`scrollY < 80`).

Управление: `/admin?page=school-schedule` → «Расположение кнопок».

## 10. Фавикон

- По умолчанию: `assets/img/favicon.png`.
- Если опция `schedule_favicon` не задана (`null`) — используется стандартный.
- Если `''` — фавикон отключён.
- Если строка `favicon/<file>` — используется файл из `data/uploads/favicon/`.
- Управление: `/admin?page=schedule-site-settings` → «Фавикон».
- Форматы: ICO, PNG, SVG, JPG/JPEG, WEBP, GIF; до 2 МБ.
- Для SVG проверяется корректность XML; для растровых — MIME.
- Ссылка на стандартный фавикон содержит `?ver=<filemtime>`, чтобы обойти
  агрессивный кеш браузера.

## 11. База данных

По умолчанию SQLite:

```text
data/schedule.sqlite
```

Таблицы:

- `schedule_options`;
- `schedule_cron`;
- `schedule_users`;
- `schedule_subscriptions`;
- `schedule_notifications`.

SQLite работает в WAL-режиме. При обновлении/откате БД делается консистентный
снимок (`VACUUM INTO`) или `mysqldump` для MySQL.

## 12. Конфигурация

`config.php` генерируется `setup.php` и **не хранится в git**.

Основные ключи:

```php
'site_url' => '',
'server' => [
    'bind' => '0.0.0.0',
    'port' => 8080,
],
'updates' => [
    'repo' => 'vkust/rsp',
    'branch' => 'main',
    'keep_backups' => 10,
    'proxy' => '',
],
'db' => [...],
'uploads_dir' => '/path/to/data/uploads',
'timezone' => 'Europe/Moscow',
'admin' => [
    'username' => 'admin',
    'password_hash' => '...',
    'session_secret' => '...',
],
'debug' => [...],
```

Доступ к значениям:

```php
App_Config::get('db.type');
App_Config::site_url('admin');
```

## 13. Правила разработки

1. **Не добавлять WordPress-зависимости.** Использовать только `wp-shim.php`.
2. **Проверять PHP-синтаксис** после изменений:

   ```bash
   php -l <file>
   ```

3. **Пересобирать бандлы** после изменений CSS/JS:

   ```bash
   php tools/build-assets.php
   ```

4. **Поднимать версию** при изменениях кода:
   - `VERSION`;
   - `SCHOOL_SCHEDULE_VERSION` в `school-schedule.php`.

5. **Не коммитить:**
   - `config.php`;
   - `data/`;
   - `logs/`;
   - `.updates/`.

6. **Формы админки** — через `admin-post.php`, с nonce и проверкой
   `current_user_can('manage_options')`.

7. **Вывод** — экранировать (`esc_html`, `esc_attr`, `esc_url`).
8. **Ввод** — санитизировать.
9. **Пути/URL** — через `APP_ROOT`, `App_Config::site_url()`,
   `app_asset_url()`.
10. **Не менять публичный VPS без явной просьбы.** Проверять изменения на
    тестовом контейнере/локально.

## 14. Диагностика

Проверка приложения:

```bash
curl -I http://localhost:8080/
curl -s http://localhost:8080/schedule.xml | md5sum
php update.php version
php update.php status
systemctl status rsp-schedule.service
```

Логи:

```text
logs/app.log
logs/cron.log
logs/telegram-bot.log
logs/updater.log
logs/server.log      — при запуске через nohup
```

Если XML не меняется:

- проверить `schedule_active_file`;
- проверить заголовки `X-Schedule-File`, `X-Schedule-Md5`;
- помнить, что `/schedule.xml` отдаёт виртуальный endpoint, а не файл на диске.

## 15. Известные нюансы

- `wp_enqueue_script` в shim не разрешает зависимости автоматически: порядок
  подключения задаётся вручную.
- `app_print_enqueue_scripts()` выводит localized-скрипт после тега скрипта.
- `wp_upload_dir()` использует `uploads_dir` из конфига.
- Парсер нельзя подключать дважды без `require_once` — будет redeclare класса.
- `App_Config::get()` поддерживает точечные ключи.
- `config.php` может переопределять пароль через опцию
  `schedule_admin_password_hash` (fallback, если файл недоступен для записи).
- Встроенный PHP-сервер однопоточный: локальные ассеты могут загружаться
  последовательно. Поэтому CSS/JS собраны в два бандла.
- Favicon кешируется браузерами особенно агрессивно; для стандартного файла
  используется `?ver=<filemtime>`.
- `lessons` — отфильтрованный по категориям список для отображения,
  `lessons_all` — полный список для служебных расчётов (свободные кабинеты).
  Если `lessons_all` нет (старый кеш), шаблон откатывается на `lessons`.

## 16. Публичный VPS и тестовый контейнер

В рамках текущей рабочей сессии изменения проверялись на отдельном тестовом
контейнере. Публичный VPS намеренно не обновлялся без отдельной просьбы.

Если публичный VPS нужно обновить:

1. сделать резервную копию;
2. обновить код;
3. выполнить `php update.php update --force` или обновление из админки;
4. проверить `/`, `/admin`, `/schedule.xml`;
5. при ошибке — `php update.php rollback`.

## 17. Полезные команды

```bash
# Проверка версии
php update.php version

# Проверить обновления
php update.php check

# Список снимков
php update.php list

# Откат
php update.php rollback

# Пересборка фронтенда
php tools/build-assets.php

# Синтаксис
php -l school-schedule.php
php -l includes/core/router.php
php -l includes/core/updater.php
```

## 18. Принцип

Главный источник истины — код в `main` и `VERSION`. Если поведение в этом
файле расходится с кодом, доверяйте коду и обновляйте этот документ.
