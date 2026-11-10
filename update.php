#!/usr/bin/env php
<?php
/**
 * CLI-управление обновлениями и откатом.
 *
 *   php update.php version
 *   php update.php check
 *   php update.php update [--force]
 *   php update.php list
 *   php update.php rollback [id] [--no-restore-db]
 *   php update.php status
 */

if (PHP_SAPI !== 'cli') {
    exit("Скрипт запускается только из командной строки: php update.php\n");
}

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/core/config.php';
App_Config::load();

$lib = APP_ROOT . '/.updates/lib/updater.php';
require_once is_file($lib) ? $lib : APP_ROOT . '/includes/core/updater.php';

$updater = new App_Updater(APP_ROOT);
$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

function updater_print_result($result)
{
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function updater_print_status($status)
{
    echo 'Состояние: ' . $status['state'] . "\n";
    if (!empty($status['action'])) {
        echo 'Задача:    ' . $status['action'] . "\n";
    }
    if (!empty($status['message'])) {
        echo 'Сообщение: ' . $status['message'] . "\n";
    }
    if (!empty($status['log'])) {
        echo "\n--- Лог ---\n";
        foreach ($status['log'] as $line) {
            echo $line . "\n";
        }
    }
}

try {
    switch ($command) {
        case 'version':
            $current = $updater->currentInfo();
            echo 'Версия:     ' . $current['version'] . "\n";
            echo 'Коммит:     ' . ($current['identifier'] ?: 'неизвестен') . "\n";
            echo 'Установлен: ' . ($current['installed_at'] ?: 'неизвестно') . "\n";
            break;

        case 'check':
            $check = $updater->check();
            if (!empty($check['error'])) {
                fwrite(STDERR, 'Ошибка проверки: ' . $check['error'] . "\n");
                exit(1);
            }
            echo 'Установлено: ' . $check['current']['version'] . ' (' . ($check['current']['identifier'] ?: 'коммит неизвестен') . ")\n";
            echo 'Доступно:    ' . $check['remote']['version'] . ' (' . ($check['remote']['identifier'] ?: 'без коммита') . ', ' . $check['remote']['source'] . ")\n";
            echo 'Обновление:  ' . ($check['available'] ? 'доступно' : 'не требуется') . "\n";
            if (!empty($check['remote']['notes'])) {
                echo "\n" . trim($check['remote']['notes']) . "\n";
            }
            break;

        case 'update':
            $result = $updater->update([
                'force' => in_array('--force', $args, true),
                'stop_service' => true,
            ]);
            updater_print_result($result);
            break;

        case 'rollback':
            $id = null;
            $restoreDb = true;
            foreach ($args as $arg) {
                if ($arg === '--no-restore-db') {
                    $restoreDb = false;
                } elseif ($arg !== '' && $arg[0] !== '-') {
                    $id = $arg;
                }
            }
            $result = $updater->rollback($id, $restoreDb, [
                'stop_service' => true,
            ]);
            updater_print_result($result);
            break;

        case 'list':
            $backups = $updater->backups();
            if (!$backups) {
                echo "Снимков пока нет.\n";
                break;
            }
            foreach ($backups as $backup) {
                printf(
                    "%-32s %-12s %-20s %s\n",
                    $backup['id'],
                    $backup['version'],
                    $backup['created_at'] ?? '',
                    $backup['reason'] ?? ''
                );
            }
            break;

        case 'status':
            updater_print_status($updater->status());
            break;

        case 'help':
        default:
            echo "Управление обновлениями «Расписание занятий»\n\n";
            echo "Использование:\n";
            echo "  php update.php version                 текущая версия\n";
            echo "  php update.php check                   проверить обновления\n";
            echo "  php update.php update [--force]        обновить приложение\n";
            echo "  php update.php list                    список снимков\n";
            echo "  php update.php rollback [id]           откатить код и БД\n";
            echo "  php update.php rollback [id] --no-restore-db  только код\n";
            echo "  php update.php status                  статус последней задачи\n\n";
            echo "Снимки хранятся в .updates/backups/, лог — logs/updater.log.\n";
            break;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    $status = $updater->status();
    if (!empty($status['log'])) {
        fwrite(STDERR, "\n--- Лог ---\n");
        foreach ($status['log'] as $line) {
            fwrite(STDERR, $line . "\n");
        }
    }
    exit(1);
}
