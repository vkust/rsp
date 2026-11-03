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
    // Базовый URL приложения. Берётся из config ('site_url'); если он не настроен
    // или значение дефолтное — вычисляем из текущего HTTP-запроса (для CLI остаётся пустым).
    $configured = rtrim((string) App_Config::get('site_url', ''), '/');
    if ($configured === '' || (PHP_SAPI === 'cli-server' && strpos($configured, ':8080') !== false)) {
        $configured = '';
    }
    if ($configured === '' && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $configured = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    define('APP_URL', $configured);
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
