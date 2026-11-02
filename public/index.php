<?php
/**
 * Точка входа автономного приложения «Расписание занятий».
 * Веб-сервер (Apache/Nginx/PHP built-in) должен направлять все запросы сюда.
 */

// Раздача статики для встроенного сервера PHP (php -S localhost:8080 -t public)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // assets/ находятся в корне проекта (вне public/) — отдаём их напрямую
    if (preg_match('#^/assets/#', $path) && is_file(dirname(__DIR__) . $path)) {
        $file = dirname(__DIR__) . $path;
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
                  'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
}

require __DIR__ . '/../bootstrap.php';
require APP_ROOT . '/includes/core/router.php';

// Soft-cron: выполняем просроченные задачи планировщика (аналог запуска WP-Cron при визите)
app_run_cron();

App_Router::dispatch();
