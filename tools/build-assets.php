<?php
/**
 * Сборка общих CSS/JS-бандлов для публичной страницы.
 *
 * Запуск:
 *   php tools/build-assets.php
 *
 * Нужно запускать после изменений в:
 *   assets/css/schedule.css
 *   assets/css/schedule-template.css
 *   assets/js/schedule.js
 */

if (PHP_SAPI !== 'cli') {
    exit("Скрипт запускается только из командной строки.\n");
}

$root = dirname(__DIR__);
$dist = $root . '/assets/dist';

if (!is_dir($dist) && !mkdir($dist, 0775, true) && !is_dir($dist)) {
    fwrite(STDERR, "Не удалось создать каталог: {$dist}\n");
    exit(1);
}

$jsFiles = [
    'assets/vendor/jquery-3.6.0.min.js',
    'assets/vendor/jquery-ui/jquery-ui.min.js',
    'assets/js/schedule.js',
    'assets/js/free-rooms.js',
];

$cssFiles = [
    'assets/vendor/jquery-ui/jquery-ui.min.css',
    'assets/css/schedule.css',
    'assets/css/schedule-template.css',
    'assets/css/free-rooms.css',
];

$js = '';
foreach ($jsFiles as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Не найден файл: {$file}\n");
        exit(1);
    }
    $js .= "/* ==== {$file} ==== */\n" . file_get_contents($path) . "\n";
}

$css = '';
foreach ($cssFiles as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Не найден файл: {$file}\n");
        exit(1);
    }
    $css .= "/* ==== {$file} ==== */\n" . file_get_contents($path) . "\n";
}

// CSS-бандл лежит в assets/dist, поэтому пути к иконкам jQuery UI
// должны вести в assets/vendor/jquery-ui/images.
$css = str_replace('url("images/', 'url("../vendor/jquery-ui/images/', $css);

$jsPath = $dist . '/app.min.js';
$cssPath = $dist . '/app.min.css';

if (file_put_contents($jsPath, $js) === false) {
    fwrite(STDERR, "Не удалось записать {$jsPath}\n");
    exit(1);
}
if (file_put_contents($cssPath, $css) === false) {
    fwrite(STDERR, "Не удалось записать {$cssPath}\n");
    exit(1);
}

printf("JS:  %s (%d байт)\n", $jsPath, filesize($jsPath));
printf("CSS: %s (%d байт)\n", $cssPath, filesize($cssPath));
