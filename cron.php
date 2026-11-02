#!/usr/bin/env php
<?php
// Добавляем функцию логирования
function log_cron($message, $type = 'info') {
    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $date = date('Y-m-d H:i:s');
    $log_entry = "[$date][$type] $message\n";
    file_put_contents($log_dir . '/cron.log', $log_entry, FILE_APPEND);
}

try {
    log_cron("=== Начало выполнения CRON задачи ===");
    
    // Устанавливаем корректный путь к WordPress
    define('WP_USE_THEMES', false);

    // Функция для поиска wp-load.php
    function find_wordpress_base_path() {
        $dir = dirname(__FILE__);
        $previous_dir = '';
        
        // Ищем wp-load.php вверх по дереву каталогов
        while ($dir !== $previous_dir) {
            // Проверяем наличие wp-load.php
            if (file_exists($dir . '/wp-load.php')) {
                return $dir . '/wp-load.php';
            }

            // Проверяем наличие wp-config.php (альтернативный вариант)
            if (file_exists($dir . '/wp-config.php')) {
                return $dir . '/wp-load.php';
            }

            $previous_dir = $dir;
            $dir = dirname($dir);
        }

        return false;
    }

    // Пытаемся найти wp-load.php
    $wp_load_path = find_wordpress_base_path();

    if ($wp_load_path && file_exists($wp_load_path)) {
        log_cron("Найден wp-load.php: $wp_load_path");
        require_once $wp_load_path;
    } else {
        // Запасной вариант - проверяем несколько стандартных путей
        $wp_load_paths = array(
            __DIR__ . '/../../../wp-load.php',          // Если плагин в стандартной структуре
            __DIR__ . '/../../../../wp-load.php',       // Если плагин глубже
            dirname(dirname(dirname(__DIR__))) . '/wp-load.php', // Относительный путь
            realpath(__DIR__ . '/../../../../wp-load.php'), // Абсолютный путь
        );

        $wp_load_found = false;
        foreach ($wp_load_paths as $path) {
            if ($path && file_exists($path)) {
                log_cron("Найден wp-load.php (запасной вариант): $path");
                require_once $path;
                $wp_load_found = true;
                break;
            }
        }

        if (!$wp_load_found) {
            throw new Exception("WordPress load file not found. Проверенные пути:\n" . 
                              implode("\n", array_filter($wp_load_paths)));
        }
    }

    // Проверяем, запущен ли скрипт из командной строки
    $is_cli = (php_sapi_name() === 'cli');
    if (!$is_cli) {
        throw new Exception('This script can only be run from the command line.');
    }

    // Инициализируем необходимые классы
    require_once __DIR__ . '/includes/telegram-bot.php';
    require_once __DIR__ . '/includes/scheduler.php';

    $bot = new ScheduleTelegramBot();
    $scheduler = new ScheduleNotificationScheduler($bot);

    // Определяем тип уведомления из аргументов командной строки
    $type = $argv[1] ?? '';
    
    log_cron("Тип уведомления: $type");
    log_cron("Режим запуска: CLI");

    switch ($type) {
        case 'next_lesson':
            log_cron("Запуск обработчика next_lesson");
            $scheduler->handle_next_lesson_notification();
            break;
            
        case 'next_day':
            log_cron("Запуск обработчика next_day");
            $scheduler->handle_next_day_notification();
            break;
            
        default:
            throw new Exception('Invalid notification type: ' . $type);
    }
    
    log_cron("=== Успешное завершение CRON задачи ===");
    echo "Success\n";
    exit(0);

} catch (Exception $e) {
    $error_message = "CRON Error: " . $e->getMessage();
    log_cron($error_message, 'error');
    error_log($error_message);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 