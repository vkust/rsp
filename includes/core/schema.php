<?php
/**
 * Схема базы данных приложения «Расписание занятий».
 * Идемпотентное создание таблиц (аналог dbDelta для SQLite и MySQL).
 */

if (!defined('APP_ROOT')) {
    exit;
}

/**
 * Создать таблицы, если они отсутствуют. Возвращает список созданных таблиц.
 */
function app_install_schema()
{
    $db = App_Database::instance();
    // Полное имя таблицы = префикс соединения + 'schedule_...'
    // (например schedule_users). Все запросы в приложении используют
    // тот же формат: "{$wpdb->prefix}schedule_users".
    $p = $db->prefix;
    $created = [];

    // Опции (замена wp-options)
    if (!$db->table_exists("{$p}schedule_options")) {
        $db->query("CREATE TABLE `{$p}schedule_options` (
            id INTEGER PRIMARY KEY " . ($db->is_mysql() ? 'BIGINT(20) NOT NULL AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
            option_name VARCHAR(191) NOT NULL,
            option_value TEXT NOT NULL,
            UNIQUE KEY option_name (option_name)
        )" . $db->get_charset_collate());
        $created[] = "{$p}schedule_options";
    }

    // Планировщик (замена wp-cron)
    if (!$db->table_exists("{$p}schedule_cron")) {
        $db->query("CREATE TABLE `{$p}schedule_cron` (
            id INTEGER PRIMARY KEY " . ($db->is_mysql() ? 'BIGINT(20) NOT NULL AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
            timestamp BIGINT(20) NOT NULL DEFAULT 0,
            interval BIGINT(20) NOT NULL DEFAULT 0,
            recurrence VARCHAR(32) NOT NULL DEFAULT '',
            hook VARCHAR(191) NOT NULL,
            args TEXT,
            KEY ts (timestamp)
        )" . $db->get_charset_collate());
        $created[] = "{$p}schedule_cron";
    }

    // Пользователи Telegram
    if (!$db->table_exists("{$p}schedule_users")) {
        $db->query("CREATE TABLE `{$p}schedule_users` (
            id INTEGER PRIMARY KEY " . ($db->is_mysql() ? 'BIGINT(20) NOT NULL AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
            chat_id BIGINT(20) NOT NULL,
            username VARCHAR(255),
            first_name VARCHAR(255),
            last_name VARCHAR(255),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY chat_id (chat_id)
        )" . $db->get_charset_collate());
        $created[] = "{$p}schedule_users";
    }

    // Подписки
    if (!$db->table_exists("{$p}schedule_subscriptions")) {
        $db->query("CREATE TABLE `{$p}schedule_subscriptions` (
            id INTEGER PRIMARY KEY " . ($db->is_mysql() ? 'BIGINT(20) NOT NULL AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
            chat_id BIGINT(20) NOT NULL,
            subscription_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(50) NOT NULL,
            username VARCHAR(255) DEFAULT NULL,
            notify_next_lesson TINYINT(1) DEFAULT 0,
            notify_next_day TINYINT(1) DEFAULT 0,
            notify_changes TINYINT(1) DEFAULT 0,
            notify_period_start TINYINT(1) DEFAULT 0,
            notify_period_end TINYINT(1) DEFAULT 0,
            next_lesson_type VARCHAR(50) DEFAULT NULL,
            next_lesson_time INT DEFAULT 15,
            next_day_time VARCHAR(8) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY chat_id (chat_id),
            KEY username (username),
            UNIQUE KEY unique_subscription (chat_id, subscription_type, entity_id)
        )" . $db->get_charset_collate());
        $created[] = "{$p}schedule_subscriptions";
    }

    // Журнал уведомлений
    if (!$db->table_exists("{$p}schedule_notifications")) {
        $db->query("CREATE TABLE `{$p}schedule_notifications` (
            id INTEGER PRIMARY KEY " . ($db->is_mysql() ? 'BIGINT(20) NOT NULL AUTO_INCREMENT' : 'AUTOINCREMENT') . ",
            chat_id BIGINT(20) NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            source VARCHAR(50) DEFAULT 'auto',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY chat_id (chat_id),
            KEY notification_type (notification_type),
            KEY created_at (created_at)
        )" . $db->get_charset_collate());
        $created[] = "{$p}schedule_notifications";
    }

    return $created;
}

/**
 * Удалить все таблицы приложения (используется install.php --uninstall).
 */
function app_uninstall_schema()
{
    $db = App_Database::instance();
    foreach (['schedule_subscriptions', 'schedule_users', 'schedule_notifications', 'schedule_cron', 'schedule_options'] as $t) {
        $db->query("DROP TABLE IF EXISTS `" . App_Database::instance()->prefix . "{$t}`");
    }
}
