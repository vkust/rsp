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

    // Проверяем, запущен ли скрипт из командной строки
    $is_cli = (php_sapi_name() === 'cli');
    if (!$is_cli) {
        throw new Exception('This script can only be run from the command line.');
    }

    // Автономное приложение: загружаем bootstrap вместо WordPress
    define('DOING_CRON', true);
    define('WP_USE_THEMES', false);
    require_once __DIR__ . '/bootstrap.php';

    // Режим без аргумента — системный cron: выполняем все просроченные задачи планировщика
    $type = $argv[1] ?? '';
    if ($type === '') {
        log_cron("Режим запуска: системный cron (обработка очереди задач)");
        app_run_cron();
        log_cron("=== Успешное завершение CRON задачи ===");
        echo "Success\n";
        exit(0);
    }

    // Инициализируем необходимые классы
    require_once __DIR__ . '/includes/telegram-bot.php';
    require_once __DIR__ . '/includes/scheduler.php';

    $bot = new ScheduleTelegramBot();
    $scheduler = new ScheduleNotificationScheduler($bot);

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

        case 'current_period':
            log_cron("Запуск обработчика current_period");
            $bot->check_current_period();
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