#!/usr/bin/env php
<?php
/**
 * Фоновая задача обновления/отката для админки.
 *
 * Запускается через systemd-run (или nohup) из App_Updater::startWorker().
 * Пишет прогресс в .updates/status.json и logs/updater.log.
 */

if (PHP_SAPI !== 'cli') {
    exit("Скрипт запускается только из командной строки.\n");
}

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/includes/core/config.php';
App_Config::load();

$lib = APP_ROOT . '/.updates/lib/updater.php';
require_once is_file($lib) ? $lib : APP_ROOT . '/includes/core/updater.php';

$action = $argv[1] ?? '';
$force = in_array('--force', $argv, true);
$noStop = in_array('--no-stop', $argv, true);
$restoreDb = !in_array('--no-restore-db', $argv, true);

$id = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--id' && isset($argv[$i + 1])) {
        $id = $argv[$i + 1];
    }
    if ($arg === '--restore-db') {
        $restoreDb = true;
    }
}

$updater = new App_Updater(APP_ROOT);

try {
    if ($action === 'update') {
        $updater->update([
            'force' => $force,
            'stop_service' => !$noStop,
        ]);
    } elseif ($action === 'rollback') {
        $updater->rollback($id, $restoreDb, [
            'stop_service' => !$noStop,
        ]);
    } else {
        throw new RuntimeException('Неизвестная задача: ' . $action);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
