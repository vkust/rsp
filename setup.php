#!/usr/bin/env php
<?php
/**
 * Мастер установки автономного приложения «Расписание занятий».
 *
 * Интерактивный скрипт первичной настройки: проверяет окружение,
 * создаёт config.php (с сохранением существующего), генерирует хеш
 * пароля администратора и секрет сессии, инициализирует базу данных,
 * создаёт каталоги uploads/logs и регистрирует задачи планировщика.
 *
 * Использование:
 *   php setup.php              — интерактивный режим
 *   php setup.php --yes        — все параметры по умолчанию (пароль — автогенерация)
 *   php setup.php --help       — справка
 */

if (PHP_SAPI !== 'cli') {
    exit("Скрипт запускается только из командной строки: php setup.php\n");
}

define('APP_ROOT', __DIR__);
set_time_limit(0);

$options = array_slice($argv, 1);

if (in_array('--help', $options, true) || in_array('-h', $options, true)) {
    echo "Мастер установки «Расписание занятий» (автономная версия, без WordPress)\n\n";
    echo "Использование:\n";
    echo "  php setup.php            интерактивная настройка\n";
    echo "  php setup.php --yes      неинтерактивно: все параметры по умолчанию\n";
    echo "                           (пароль администратора генерируется автоматически)\n\n";
    echo "Что делает мастер:\n";
    echo "  1. Проверяет версию PHP (>= 8.0) и расширения (pdo_sqlite/pdo_mysql, curl, xml, mbstring).\n";
    echo "  2. Создаёт/обновляет config.php по вашим ответам (существующий файл можно сохранить или забэкапить).\n";
    echo "  3. Генерирует хеш пароля администратора и криптостойкий секрет сессий.\n";
    echo "  4. Создаёт каталоги data/uploads и logs, таблицы БД (sqlite или mysql)\n";
    echo "     и регистрирует задачи планировщика.\n\n";
    echo "Низкоуровневые команды (install.php):\n";
    echo "  php install.php --hash-password \"пароль\"  — только сгенерировать хеш\n";
    echo "  php install.php --deactivate              — снять cron-задачи\n";
    echo "  php install.php --uninstall               — удалить таблицы приложения\n";
    exit(0);
}

$isTty = false;
if (function_exists('posix_isatty')) {
    $isTty = @posix_isatty(STDIN);
}
$NON_INTERACTIVE = in_array('--yes', $options, true) || in_array('-y', $options, true) || !$isTty;

/* ========================================================================
 *  Вспомогательные функции вывода и ввода
 * ====================================================================== */

function c($text, $code) { return "\033[{$code}m{$text}\033[0m"; }
function ok($t)   { echo '  ' . c('[OK]', '32') . " {$t}\n"; }
function bad($t)  { echo '  ' . c('[!!]', '31') . " {$t}\n"; }
function warn($t) { echo '  ' . c('[!!]', '33') . " {$t}\n"; }
function info($t) { echo '  ' . c('-->', '36') . " {$t}\n"; }
function step($n, $title) { echo "\n" . c("Шаг {$n}. {$title}", '1;34') . "\n"; }

function prompt($question, $default = null, $hidden = false)
{
    global $NON_INTERACTIVE;
    $hint = ($default !== null && $default !== '') ? ' [' . ($hidden ? '••••' : $default) . ']' : '';
    while (true) {
        echo '  ' . $question . $hint . ': ';
        if ($NON_INTERACTIVE) {
            echo ($hidden ? '(автоматически)' : ($default !== null && $default !== '' ? $default : '')) . "\n";
            return $default !== null ? (string) $default : '';
        }
        if ($hidden) {
            // Скрываем эхо пароля только если STDIN действительно терминал,
            // иначе stty не сработает и процесс зависнет в ожидании ввода.
            $isTty = function_exists('posix_isatty') ? @posix_isatty(STDIN) : false;
            if (!$isTty) {
                exec('sh -c "test -t 0" 2>/dev/null', $o, $rc);
                $isTty = ($rc === 0);
            }
            if ($isTty) {
                $stty = shell_exec('stty -g 2>/dev/null');
                if ($stty !== null && trim($stty) !== '') {
                    shell_exec('stty -echo');
                    $line = fgets(STDIN);
                    shell_exec('stty ' . escapeshellarg(trim($stty)));
                    echo "\n";
                } else {
                    $line = fgets(STDIN);
                }
            } else {
                $line = fgets(STDIN);
            }
        } else {
            $line = fgets(STDIN);
        }
        if ($line === false) { // EOF — принимаем значение по умолчанию
            return $default !== null ? (string) $default : '';
        }
        $line = trim($line);
        if ($line === '') {
            if ($default !== null) return (string) $default;
            continue;
        }
        return $line;
    }
}

function ask_yes_no($question, $default = true)
{
    global $NON_INTERACTIVE;
    $d = $default ? 'Y' : 'N';
    echo '  ' . $question . ' [Y/n] (по умолчанию ' . $d . '): ';
    if ($NON_INTERACTIVE) {
        echo $d . " (авто)\n";
        return $default;
    }
    $line = strtolower(trim((string) fgets(STDIN)));
    if ($line === '') return $default;
    return in_array($line, ['y', 'д', 'да', 'yes', '1'], true);
}

/* ========================================================================
 *  Шаг 1. Проверка окружения
 * ====================================================================== */

step(1, 'Проверка окружения');

$fatalEnv = false;

$ver = PHP_VERSION;
if (version_compare($ver, '8.0.0', '>=')) {
    ok("PHP версии {$ver} (" . PHP_BINARY . ")");
} else {
    bad("Требуется PHP >= 8.0, обнаружен {$ver}. Обновите PHP и запустите мастер заново.");
    $fatalEnv = true;
}

foreach (['pdo', 'curl', 'xml', 'mbstring', 'json'] as $ext) {
    if (extension_loaded($ext)) {
        ok("Расширение php-{$ext}");
    } else {
        bad("Отсутствует обязательное расширение: {$ext} (установите пакет php-{$ext})");
        $fatalEnv = true;
    }
}

$hasSqlite = extension_loaded('pdo_sqlite');
$hasMysql  = extension_loaded('pdo_mysql');
if ($hasSqlite) ok('Доступен драйвер pdo_sqlite (SQLite)');
if ($hasMysql)  ok('Доступен драйвер pdo_mysql (MySQL/MariaDB)');
if (!$hasSqlite && !$hasMysql) {
    bad('Нет ни pdo_sqlite, ни pdo_mysql — установите хотя бы один (рекомендуется php-sqlite3).');
    $fatalEnv = true;
}

foreach (['data', 'logs'] as $dir) {
    $path = APP_ROOT . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (is_dir($path) && !is_writable($path)) {
        warn("Каталог {$dir} недоступен для записи — проверьте права.");
    }
}

if ($fatalEnv) {
    echo "\n" . c('Окружение не готово. Исправьте ошибки выше и повторите запуск.', '1;31') . "\n";
    exit(1);
}

/* ========================================================================
 *  Шаг 2. Параметры конфигурации
 * ====================================================================== */

step(2, 'Конфигурация');

$configPath = APP_ROOT . '/config.php';
$existing = [];
$resetExisting = false;
if (file_exists($configPath)) {
    $existing = @include $configPath;
    if (!is_array($existing)) $existing = [];
    info('Обнаружен существующий config.php — его значения станут значениями по умолчанию.');
    if (ask_yes_no('Пересоздать config.php с нуля (текущий будет забэкаплен)?', false)) {
        $backup = $configPath . '.backup-' . date('Ymd-His');
        copy($configPath, $backup);
        info('Резервная копия: ' . basename($backup));
        $existing = [];
        $resetExisting = true;
    }
} else {
    info('config.php отсутствует — он будет создан.');
}

$getEx = function ($path, $default = null) use ($existing) {
    $v = $existing;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($v) || !array_key_exists($seg, $v)) return $default;
        $v = $v[$seg];
    }
    return ($v === '' || $v === null) ? $default : $v;
};

/* --- URL приложения --- */
$scheme = 'http';
$defUrl = $getEx('site_url', $scheme . '://localhost:8080');
while (true) {
    $siteUrl = prompt('Полный URL приложения (для Telegram webhook и ссылок)', $defUrl);
    if (preg_match('#^https?://#i', $siteUrl)) break;
    bad('URL должен начинаться с http:// или https://');
    $defUrl = '';
}
$siteUrl = rtrim($siteUrl, '/');

/* --- База данных --- */
$dbTypeDefault = $getEx('db.type', $hasSqlite ? 'sqlite' : 'mysql');
if ($hasSqlite && $hasMysql) {
    $answer = prompt('Тип базы данных (sqlite / mysql)', $dbTypeDefault);
} elseif ($hasSqlite) {
    $answer = 'sqlite';
    info('Выбрана база SQLite (доступен только pdo_sqlite).');
} else {
    $answer = 'mysql';
    info('Выбрана база MySQL/MariaDB (доступен только pdo_mysql).');
}
$dbType = strtolower($answer) === 'mysql' ? 'mysql' : 'sqlite';

$sqlitePath = $getEx('db.sqlite.path', APP_ROOT . '/data/schedule.sqlite');
$mysql = [
    'host' => (string) $getEx('db.mysql.host', 'localhost'),
    'port' => (string) $getEx('db.mysql.port', '3306'),
    'name' => (string) $getEx('db.mysql.name', 'schedule'),
    'user' => (string) $getEx('db.mysql.user', 'root'),
    'pass' => (string) $getEx('db.mysql.pass', ''),
];

if ($dbType === 'sqlite') {
    $sqlitePath = prompt('Путь к файлу базы SQLite', $sqlitePath);
    $dir = dirname($sqlitePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        info("Создан каталог {$dir}");
    }
    if (!is_writable($dir)) {
        bad("Каталог {$dir} недоступен для записи.");
        exit(1);
    }
} else {
    $mysql['host'] = prompt('Хост MySQL', $mysql['host']);
    $mysql['port'] = prompt('Порт MySQL', $mysql['port']);
    $mysql['name'] = prompt('Имя базы данных', $mysql['name']);
    $mysql['user'] = prompt('Пользователь', $mysql['user']);
    $mysql['pass'] = prompt('Пароль пользователя', $mysql['pass'], true);
}

$uploadsDir = prompt('Каталог загрузки XML-расписаний', $getEx('uploads_dir', APP_ROOT . '/data/uploads'));
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0775, true);
    info("Создан каталог {$uploadsDir}");
}

$tzList = timezone_identifiers_list();
$tzDefault = $getEx('timezone', in_array('Europe/Moscow', $tzList, true) ? 'Europe/Moscow' : 'UTC');
while (true) {
    $tz = prompt('Часовой пояс (например Europe/Moscow)', $tzDefault);
    if (in_array($tz, $tzList, true)) break;
    bad("Неизвестный часовой пояс: {$tz}");
    $tzDefault = '';
}

/* --- Администратор --- */
$adminUser = prompt('Логин администратора', $getEx('admin.username', 'admin'));
$oldHash = (string) $getEx('admin.password_hash', '');
$regenPass = ($oldHash === '');
if ($oldHash !== '') {
    $regenPass = ask_yes_no('Пароль администратора уже задан. Сгенерировать новый?', false);
}
if ($NON_INTERACTIVE && $oldHash !== '') {
    $regenPass = false; // при автоматическом прогоне не ломаем существующий доступ
}
$adminHash = $oldHash;
$adminPlainPass = null;
if ($regenPass) {
    if ($NON_INTERACTIVE) {
        $adminPlainPass = bin2hex(random_bytes(6)); // 12 hex-символов
        info('Автоматический режим: пароль администратора сгенерирован (см. итог).');
    } else {
        while (true) {
            $p1 = prompt('Пароль администратора (минимум 8 символов)', null, true);
            if (strlen($p1) < 8) { bad('Пароль слишком короткий.'); continue; }
            $p2 = prompt('Повторите пароль', null, true);
            if ($p1 !== $p2) { bad('Пароли не совпадают.'); continue; }
            $adminPlainPass = $p1;
            break;
        }
    }
    $adminHash = password_hash($adminPlainPass, PASSWORD_DEFAULT);
}

$sessionSecret = (string) $getEx('admin.session_secret', '');
if ($sessionSecret === '' || $sessionSecret === 'change-me-random-string' || $regenPass || $resetExisting) {
    $sessionSecret = bin2hex(random_bytes(32));
    info('Сгенерирован новый секрет сессий (session_secret).');
}

/* --- Отладка --- */
$logFile = $getEx('debug.log', APP_ROOT . '/logs/app.log');
$debugOn = $NON_INTERACTIVE
    ? (bool) $getEx('debug.enabled', false)
    : ask_yes_no('Включить режим отладки (аналог WP_DEBUG)?', (bool) $getEx('debug.enabled', false));

/* ========================================================================
 *  Запись config.php
 * ====================================================================== */

$q = function ($s) { return str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $s); };
$debugFlag = $debugOn ? 'true' : 'false';

$configCode = "<?php\n"
    . "/**\n"
    . " * Конфигурация автономного приложения «Расписание занятий».\n"
    . " * Файл сгенерирован мастером установки setup.php " . date('Y-m-d H:i:s') . " UTC.\n"
    . " */\n\n"
    . "return [\n"
    . "    'site_url' => '" . $q($siteUrl) . "',\n\n"
    . "    'db' => [\n"
    . "        'type'   => '" . $q($dbType) . "',\n"
    . "        'sqlite' => ['path' => '" . $q($sqlitePath) . "'],\n"
    . "        'mysql'  => [\n"
    . "            'host'    => '" . $q($mysql['host']) . "',\n"
    . "            'port'    => '" . $q($mysql['port']) . "',\n"
    . "            'name'    => '" . $q($mysql['name']) . "',\n"
    . "            'user'    => '" . $q($mysql['user']) . "',\n"
    . "            'pass'    => '" . $q($mysql['pass']) . "',\n"
    . "            'charset' => 'utf8mb4',\n"
    . "        ],\n"
    . "        'prefix' => '',\n"
    . "    ],\n\n"
    . "    'uploads_dir' => '" . $q($uploadsDir) . "',\n\n"
    . "    'timezone' => '" . $q($tz) . "',\n\n"
    . "    'admin' => [\n"
    . "        'username'       => '" . $q($adminUser) . "',\n"
    . "        'password_hash'  => '" . $q($adminHash) . "',\n"
    . "        'session_secret' => '" . $q($sessionSecret) . "',\n"
    . "    ],\n\n"
    . "    'debug' => [\n"
    . "        'enabled' => {$debugFlag},\n"
    . "        'display' => {$debugFlag},\n"
    . "        'log'     => '" . $q($logFile) . "',\n"
    . "    ],\n"
    . "];\n";

if (@file_put_contents($configPath, $configCode) === false) {
    bad('Не удалось записать config.php — проверьте права на каталог ' . APP_ROOT);
    exit(1);
}
ok('Записан config.php');

// Перезагружаем конфигурацию в текущем процессе
require_once APP_ROOT . '/includes/core/config.php';
App_Config::reload($configPath);

/* ========================================================================
 *  Шаг 3. Инициализация базы данных
 * ====================================================================== */

step(3, 'Инициализация базы данных');

require_once APP_ROOT . '/includes/core/database.php';
try {
    App_Database::instance();
    ok("Подключение к БД ({$dbType}) установлено");
} catch (Throwable $e) {
    bad('Ошибка подключения к БД: ' . $e->getMessage());
    if ($dbType === 'mysql') {
        info('Проверьте, что сервер MySQL запущен и база существует (CREATE DATABASE ... CHARACTER SET utf8mb4).');
    }
    exit(1);
}

require_once APP_ROOT . '/includes/core/schema.php';
$created = app_install_schema();
if ($created) {
    ok('Созданы таблицы: ' . implode(', ', $created));
} else {
    info('Все таблицы уже существуют — структура проверена и дополнена.');
}

require_once APP_ROOT . '/includes/compat/wp-shim.php';
if (!wp_next_scheduled('schedule_next_lesson_notification')) {
    wp_schedule_event(time(), 'every_minute', 'schedule_next_lesson_notification');
}
if (!wp_next_scheduled('schedule_next_day_notification')) {
    wp_schedule_event(time(), 'hourly', 'schedule_next_day_notification');
}
ok('Зарегистрированы задачи планировщика (исполняются через cron.php)');

/* ========================================================================
 *  Шаг 4. Проверка файлов приложения и итог
 * ====================================================================== */

step(4, 'Проверка файлов приложения');
$missing = [];
foreach (['bootstrap.php', 'public/index.php', 'school-schedule.php', 'cron.php',
          'assets/css/schedule.css', 'assets/js/schedule.js'] as $f) {
    if (file_exists(APP_ROOT . '/' . $f)) ok($f);
    else { bad("Отсутствует {$f} — репозиторий неполный!"); $missing[] = $f; }
}
if ($missing) {
    echo "\n" . c('Внимание: отсутствуют обязательные файлы. Установка продолжена, но приложение может работать некорректно.', '1;33') . "\n";
}

echo "\n" . c('Установка завершена успешно!', '1;32') . "\n\n";
echo "  Приложение:  {$siteUrl}\n";
echo "  База данных: {$dbType}" . ($dbType === 'sqlite' ? " ({$sqlitePath})" : " ({$mysql['host']}:{$mysql['port']}/{$mysql['name']})") . "\n";
echo "  Админка:     {$siteUrl}/admin  (логин: {$adminUser})\n";
if ($adminPlainPass !== null) {
    echo '  ' . c("ПАРОЛЬ АДМИНИСТРАТОРА: {$adminPlainPass}", '1;33')
        . " — сохраните его; в config.php хранится только хеш.\n";
}

echo "\n" . c('Следующие шаги:', '1') . "\n";
echo "  1. Быстрый запуск (разработка):\n";
echo "       php -S 0.0.0.0:8080 -t public public/index.php\n";
echo "     Для продакшена настройте nginx/Apache на public/index.php (см. README.md).\n\n";
echo "  2. Планировщик уведомлений — добавьте в crontab:\n";
echo "       * * * * * cd " . escapeshellarg(APP_ROOT) . " && php cron.php > /dev/null 2>&1\n\n";
echo "  3. Откройте {$siteUrl}/admin, войдите и:\n";
echo "       - загрузите XML-файл расписания (страница «Расписание»);\n";
echo "       - укажите токен Telegram-бота (страница «Настройки бота»);\n";
echo "       - при необходимости включите webhook:\n";
echo "         curl -F \"url={$siteUrl}/telegram-webhook\" https://api.telegram.org/bot<TOKEN>/setWebhook\n\n";
exit(0);
