<?php
/**
 * CLI-установка автономного приложения «Расписание занятий».
 *
 * Использование:
 *   php install.php                        — создать config.php и таблицы БД
 *   php install.php --hash-password "пароль" — сгенерировать хеш пароля администратора
 *   php install.php --deactivate           — снять cron-задачи (аналог деактивации плагина)
 *   php install.php --uninstall            — удалить таблицы и настройки (аналог удаления плагина)
 */

if (PHP_SAPI !== 'cli') {
    exit("Скрипт запускается только из командной строки: php install.php\n");
}

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/core/config.php';
require_once APP_ROOT . '/includes/core/database.php';

$options = array_slice($argv, 1);

/* ---- Генерация хеша пароля ------------------------------------------- */
if (in_array('--hash-password', $options, true)) {
    $idx = array_search('--hash-password', $options, true);
    $pass = $options[$idx + 1] ?? null;
    if (!$pass) {
        fwrite(STDERR, "Укажите пароль: php install.php --hash-password \"секрет\"\n");
        exit(1);
    }
    echo password_hash($pass, PASSWORD_DEFAULT) . "\n";
    exit(0);
}

/* ---- Подготовка config.php -------------------------------------------- */
$configPath = APP_ROOT . '/config.php';
$isNewConfig = false;
if (!file_exists($configPath)) {
    copy(APP_ROOT . '/config.sample.php', $configPath);
    $isNewConfig = true;
    echo "Создан файл конфигурации: config.php\n";
}

App_Config::load($configPath);
echo "Подключение к базе данных (" . App_Config::get('db.type') . ")...\n";

try {
    $db = App_Database::instance();
} catch (Throwable $e) {
    fwrite(STDERR, "Ошибка подключения к БД: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Проверьте параметры db в config.php\n");
    exit(1);
}

require_once APP_ROOT . '/includes/core/schema.php';

/* ---- Деактивация -------------------------------------------------------- */
if (in_array('--deactivate', $options, true)) {
    define('WP_DEBUG', false);
    require_once APP_ROOT . '/includes/compat/wp-shim.php';
    wp_clear_scheduled_hook('schedule_next_lesson_notification');
    wp_clear_scheduled_hook('schedule_next_day_notification');
    wp_clear_scheduled_hook('schedule_check_current_period');
    echo "Cron-задачи сняты. (Не забудьте удалить crontab: * * * * * php " . APP_ROOT . "/cron.php)\n";
    exit(0);
}

/* ---- Полное удаление ---------------------------------------------------- */
if (in_array('--uninstall', $options, true)) {
    define('WP_DEBUG', false);
    require_once APP_ROOT . '/includes/compat/wp-shim.php';
    app_uninstall_schema();
    echo "Таблицы приложения удалены.\n";
    echo "Для удаления файлов расписаний очистите каталог " . App_Config::get('uploads_dir') . " вручную.\n";
    exit(0);
}

/* ---- Установка ------------------------------------------------------------ */
$created = app_install_schema();
if ($created) {
    echo "Созданы таблицы:\n  - " . implode("\n  - ", $created) . "\n";
} else {
    echo "Все таблицы уже существуют.\n";
}

// Первичная активация: регистрируем задачи планировщика как в activate() плагина
require_once APP_ROOT . '/includes/compat/wp-shim.php';
if (!wp_next_scheduled('schedule_next_lesson_notification')) {
    wp_schedule_event(time(), 'every_minute', 'schedule_next_lesson_notification');
}
if (!wp_next_scheduled('schedule_next_day_notification')) {
    wp_schedule_event(time(), 'hourly', 'schedule_next_day_notification');
}
echo "Зарегистрированы задачи планировщика (выполняются через cron.php).\n";

// Устанавливаем категории предметов по умолчанию (аналог активации плагина)
$default_categories = [
    'main' => ['name' => 'Основные предметы', 'visible' => true],
    'extracurricular' => ['name' => 'Внеурочная деятельность', 'visible' => true],
    'elective' => ['name' => 'Элективный курс', 'visible' => true],
    'additional' => ['name' => 'Дополнительные занятия', 'visible' => true],
    'consultation' => ['name' => 'Консультации', 'visible' => true],
];
if (!get_option('schedule_categories')) {
    update_option('schedule_categories', $default_categories);
    echo "Установлены категории предметов по умолчанию.\n";
}

// Проверка пароля администратора
$hash = (string) App_Config::get('admin.password_hash', '');
if ($hash === '') {
    echo "\n⚠ Пароль администратора не задан. Выполните:\n";
    echo "   1) php install.php --hash-password \"ваш_пароль\"\n";
    echo "   2) скопируйте полученный хеш в config.php => admin.password_hash\n";
}

$updatesDir = APP_ROOT . '/.updates';
if (!is_dir($updatesDir)) {
    @mkdir($updatesDir, 0775, true);
}
$updatesCurrent = $updatesDir . '/current.json';
if (is_dir($updatesDir) && !is_file($updatesCurrent)) {
    $versionFile = APP_ROOT . '/VERSION';
    $appVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';
    @file_put_contents($updatesCurrent, json_encode([
        'version' => $appVersion,
        'identifier' => null,
        'source' => 'install',
        'installed_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

echo "\nУстановка завершена.\n";
if ($isNewConfig) {
    echo "Отредактируйте config.php (site_url, доступы к БД, токен бота задаётся в админке),\n";
}
echo "затем запустите веб-сервер, например:\n";
echo "   php -S 0.0.0.0:8080 -t public public/index.php\n";
echo "и добавьте в crontab:\n";
echo "   * * * * * php " . APP_ROOT . "/cron.php > /dev/null 2>&1\n";
