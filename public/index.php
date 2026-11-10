<?php
/**
 * Точка входа автономного приложения «Расписание занятий».
 * Веб-сервер (Apache/Nginx/PHP built-in) должен направлять все запросы сюда.
 */

// Сжимаем текстовые ответы (HTML/CSS/JS/XML/JSON). Бинарные файлы отдаются как есть.
if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && !headers_sent()) {
    ob_start('ob_gzhandler');
}

// Раздача статики для встроенного сервера PHP (php -S localhost:8080 -t public)
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // assets/ и uploads/ находятся в корне проекта (вне public/) — отдаём их напрямую
    $root = dirname(__DIR__);
    $file = null;
    if (preg_match('#^/assets/#', $path) && is_file($root . $path)) {
        $file = $root . $path;
    } elseif (preg_match('#^/uploads/(.+)$#', $path)) {
        $rel  = preg_replace('#\.\.#', '', urldecode($path)); // защита от directory traversal
        $cand = $root . '/data' . $rel; // /uploads/... -> data/uploads/...
        if (is_file($cand)) {
            $file = $cand;
        }
    }
    if ($file !== null) {
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
                  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                  'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'xml' => 'text/xml',
                  'ico' => 'image/x-icon', 'woff2' => 'font/woff2'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        // Не сжимаем уже сжатые изображения и шрифты.
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff2'], true)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        if (preg_match('#^/assets/#', $path)) {
            header('Cache-Control: public, max-age=31536000, immutable');
        }
        readfile($file);
        return true;
    }
}

require __DIR__ . '/../bootstrap.php';
require APP_ROOT . '/includes/core/router.php';

// Soft-cron: выполняем просроченные задачи планировщика (аналог запуска WP-Cron при визите)
app_run_cron();

App_Router::dispatch();
