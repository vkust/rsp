<?php
/**
 * Bootstrap автономного приложения «Расписание занятий».
 * Заменяет wp-load.php / загрузчик WordPress.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

require_once APP_ROOT . '/includes/core/config.php';
App_Config::load();

if (!defined('APP_URL')) {
    // Базовый URL вычисляется из конфигурации (site_url); константа нужна для plugin_dir_url
    define('APP_URL', rtrim((string) App_Config::get('site_url', ''), '/'));
}

// Отображение ошибок (аналог WP_DEBUG из старого кода плагина)
$debug = App_Config::get('debug', []);
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', !empty($debug['enabled']));
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', !empty($debug['display']) && !empty($debug['enabled']));
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', !empty($debug['enabled']));
}
if (!empty($debug['log'])) {
    $logdir = dirname($debug['log']);
    if (!is_dir($logdir)) {
        @mkdir($logdir, 0775, true);
    }
    ini_set('error_log', $debug['log']);
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', WP_DEBUG_DISPLAY ? '1' : '0');

// Ядро
require_once APP_ROOT . '/includes/core/database.php';
require_once APP_ROOT . '/includes/compat/wp-shim.php';

// Подключение к БД и инициализация глобальной $wpdb
App_Database::instance();

// Схема БД (создание таблиц при первом запуске — идемпотентно)
require_once APP_ROOT . '/includes/core/schema.php';
app_install_schema();

// Основной код приложения (бывший файл плагина)
require_once APP_ROOT . '/school-schedule.php';
