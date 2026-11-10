<?php
/**
 * Система обновления и отката версий приложения.
 *
 * Работает поверх текущего каталога приложения:
 *   - хранит снимки кода и базы в .updates/backups/;
 *   - скачивает свежую версию с GitHub (release, иначе main по SHA коммита);
 *   - применяет миграции через install.php;
 *   - умеет откатываться к любому сохранённому снимку;
 *   - запускает фоновые задачи через systemd-run (чтобы пережить restart сервиса).
 */

if (!defined('APP_ROOT')) {
    exit;
}

class App_Updater
{
    /** @var string */
    private $root;

    /** @var string */
    private $stateDir;

    /** @var string */
    private $backupDir;

    /** @var string */
    private $tmpDir;

    /** @var string */
    private $lockFile;

    /** @var string */
    private $statusFile;

    /** @var string */
    private $currentFile;

    /** @var string */
    private $repo = 'vkust/rsp';

    /** @var string */
    private $branch = 'main';

    /** @var int */
    private $keepBackups = 10;

    /** @var string */
    private $proxy = '';

    /** @var resource|null */
    private $lockHandle = null;

    /** @var array */
    private $status = [];

    /** @var string[] */
    private $logLines = [];

    /** @var string[] */
    private $protected = [
        'config.php',
        'data',
        'logs',
        '.updates',
        '.git',
        '.env',
        'update.php',
        'update-worker.php',
    ];

    public function __construct($root = null)
    {
        $this->root = rtrim($root ?: APP_ROOT, '/\\');
        $config = class_exists('App_Config') ? App_Config::get('updates', []) : [];
        if (is_array($config)) {
            $this->repo = $config['repo'] ?? $this->repo;
            $this->branch = $config['branch'] ?? $this->branch;
            $this->keepBackups = max(1, (int) ($config['keep_backups'] ?? $this->keepBackups));
            $this->proxy = (string) ($config['proxy'] ?? '');
        }

        $this->stateDir = $this->root . '/.updates';
        $this->backupDir = $this->stateDir . '/backups';
        $this->tmpDir = $this->stateDir . '/tmp';
        $this->lockFile = $this->stateDir . '/update.lock';
        $this->statusFile = $this->stateDir . '/status.json';
        $this->currentFile = $this->stateDir . '/current.json';

        $this->ensureDir($this->stateDir);
        $this->ensureDir($this->backupDir);
        $this->ensureDir($this->tmpDir);
    }

    /* ------------------------------------------------------------------ */
    /* Публичные операции                                                  */
    /* ------------------------------------------------------------------ */

    public function version()
    {
        $file = $this->root . '/VERSION';
        if (is_file($file)) {
            $v = trim((string) @file_get_contents($file));
            if ($v !== '') {
                return $v;
            }
        }
        return defined('SCHOOL_SCHEDULE_VERSION') ? SCHOOL_SCHEDULE_VERSION : '0.0.0';
    }

    public function currentInfo()
    {
        $info = [
            'version' => $this->version(),
            'identifier' => null,
            'source' => null,
            'installed_at' => null,
        ];
        if (is_file($this->currentFile)) {
            $data = json_decode((string) @file_get_contents($this->currentFile), true);
            if (is_array($data)) {
                $info = array_merge($info, $data);
            }
        }
        return $info;
    }

    public function status()
    {
        if (is_file($this->statusFile)) {
            $data = json_decode((string) @file_get_contents($this->statusFile), true);
            if (is_array($data)) {
                return array_merge([
                    'state' => 'idle',
                    'action' => null,
                    'message' => '',
                    'started_at' => null,
                    'updated_at' => null,
                    'log' => [],
                ], $data);
            }
        }
        return [
            'state' => 'idle',
            'action' => null,
            'message' => '',
            'started_at' => null,
            'updated_at' => null,
            'log' => [],
        ];
    }

    public function isRunning()
    {
        $status = $this->status();
        return in_array($status['state'], ['starting', 'running'], true);
    }

    public function check()
    {
        $current = $this->currentInfo();
        try {
            $remote = $this->remoteInfo();
        } catch (Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'current' => $current,
                'remote' => null,
                'available' => false,
                'checked_at' => date('c'),
            ];
        }

        $available = false;
        if ($current['identifier'] === null || $remote['identifier'] === null) {
            $available = true;
        } else {
            $available = $current['identifier'] !== $remote['identifier'];
        }

        return [
            'error' => null,
            'current' => $current,
            'remote' => $remote,
            'available' => $available,
            'checked_at' => date('c'),
        ];
    }

    public function backups()
    {
        $result = [];
        foreach ((array) @scandir($this->backupDir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->backupDir . '/' . $name;
            if (!is_dir($path)) {
                continue;
            }
            $manifest = $this->readManifest($path);
            if (!$manifest) {
                continue;
            }
            $manifest['id'] = $name;
            $manifest['path'] = $path;
            $manifest['db_size'] = $this->backupDbSize($path, $manifest);
            $result[] = $manifest;
        }
        usort($result, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        return $result;
    }

    /**
     * Запуск фоновой задачи обновления/отката.
     * Возвращает описание способа запуска.
     */
    public function startWorker($action, array $options = [])
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Другая задача обновления уже выполняется.');
        }
        if (!in_array($action, ['update', 'rollback'], true)) {
            throw new InvalidArgumentException('Неизвестная задача: ' . $action);
        }

        $this->startStatus($action, 'Запуск задачи…');

        $args = [$action];
        if ($action === 'update' && !empty($options['force'])) {
            $args[] = '--force';
        }
        if ($action === 'rollback') {
            if (!empty($options['id'])) {
                $args[] = '--id';
                $args[] = $options['id'];
            }
            $args[] = !empty($options['restore_db']) ? '--restore-db' : '--no-restore-db';
        }

        $php = $this->phpCli();
        $worker = $this->root . '/update-worker.php';
        if (!is_file($worker)) {
            $this->setStatus('error', 'Файл update-worker.php не найден.');
            throw new RuntimeException('Файл update-worker.php не найден.');
        }

        if ($this->commandExists('systemd-run')) {
            $unit = 'rsp-update-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $cmd = 'systemd-run --no-block --collect --unit=' . escapeshellarg($unit)
                . ' --property=Type=oneshot'
                . ' --property=WorkingDirectory=' . escapeshellarg($this->root)
                . ' ' . escapeshellarg($php)
                . ' ' . escapeshellarg($worker)
                . ' ' . implode(' ', array_map('escapeshellarg', $args));

            list($code, $output) = $this->runCommand($cmd);
            if ($code === 0) {
                $this->appendLog('Фоновая задача запущена через systemd-run: ' . $unit);
                return ['mode' => 'systemd-run', 'unit' => $unit];
            }
            $this->appendLog('systemd-run не сработал: ' . implode(' ', $output));
        }

        // Fallback: nohup. В этом режиме нельзя безопасно останавливать сервис,
        // поэтому передаём --no-stop.
        $args[] = '--no-stop';
        $log = $this->stateDir . '/worker.log';
        $cmd = 'nohup ' . escapeshellarg($php)
            . ' ' . escapeshellarg($worker)
            . ' ' . implode(' ', array_map('escapeshellarg', $args))
            . ' > ' . escapeshellarg($log) . ' 2>&1 &';
        list($code, $output) = $this->runCommand($cmd);
        if ($code !== 0) {
            $this->setStatus('error', 'Не удалось запустить фоновую задачу.');
            throw new RuntimeException('Не удалось запустить фоновую задачу.');
        }
        $this->appendLog('Фоновая задача запущена через nohup.');
        return ['mode' => 'nohup'];
    }

    /**
     * Синхронное обновление (CLI).
     */
    public function update(array $options = [])
    {
        $this->acquireLock();
        $backupId = null;
        $serviceStopped = false;

        try {
            $this->startStatus('update', 'Проверка обновлений…');
            $current = $this->currentInfo();
            $this->appendLog('Текущая версия: ' . $current['version'] . ' (' . ($current['identifier'] ?: 'коммит неизвестен') . ')');

            $remote = $this->remoteInfo();
            $this->appendLog('Доступная версия: ' . $remote['version'] . ' (' . ($remote['identifier'] ?: 'без коммита') . ', ' . $remote['source'] . ')');

            if (empty($options['force'])
                && $current['identifier'] !== null
                && $remote['identifier'] !== null
                && $current['identifier'] === $remote['identifier']) {
                throw new RuntimeException('Уже установлена последняя версия.');
            }

            $tmp = $this->tmpDir . '/update-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $this->ensureDir($tmp);
            $zip = $tmp . '/update.zip';
            $extract = $tmp . '/extract';
            $this->ensureDir($extract);

            $this->appendLog('Скачивание: ' . $remote['archive_url']);
            $this->download($remote['archive_url'], $zip);

            $this->appendLog('Распаковка архива…');
            $this->extractZip($zip, $extract);
            $source = $this->findExtractedRoot($extract);
            if ($source === null) {
                throw new RuntimeException('В архиве не найден каталог приложения.');
            }

            foreach (['VERSION', 'public/index.php', 'school-schedule.php', 'includes/core/config.php'] as $required) {
                if (!is_file($source . '/' . $required)) {
                    throw new RuntimeException('В архиве отсутствует обязательный файл: ' . $required);
                }
            }

            $newVersion = trim((string) @file_get_contents($source . '/VERSION'));
            if ($newVersion === '') {
                $newVersion = $remote['version'];
            }
            $this->appendLog('Новая версия: ' . $newVersion);

            $backupId = $this->snapshot('before-update');
            $this->appendLog('Создан снимок: ' . $backupId);

            // Сохраняем актуальный updater в управляющий каталог, чтобы он
            // пережил откат к версии, в которой этого класса ещё не было.
            $newUpdater = $source . '/includes/core/updater.php';
            if (is_file($newUpdater)) {
                $this->ensureDir($this->stateDir . '/lib');
                @copy($newUpdater, $this->stateDir . '/lib/updater.php');
            }

            if (!empty($options['stop_service']) && $this->stopService()) {
                $serviceStopped = true;
                $this->appendLog('Сервис остановлен.');
            }

            $this->appendLog('Копирование файлов приложения…');
            $this->syncCode($source, $this->root);

            $this->appendLog('Применение миграций и обновление схемы БД…');
            $this->runMigrations();

            $this->writeCurrent([
                'version' => $newVersion,
                'identifier' => $remote['identifier'],
                'source' => $remote['source'],
                'installed_at' => date('c'),
                'previous' => [
                    'version' => $current['version'],
                    'identifier' => $current['identifier'],
                ],
            ]);

            $this->appendLog('Запуск сервиса…');
            if ($serviceStopped) {
                $this->startService();
            } else {
                $this->scheduleRestart();
            }
            $serviceStopped = false;

            $this->pruneBackups();
            $this->setStatus('success', 'Обновление завершено: ' . $current['version'] . ' → ' . $newVersion, [
                'from_version' => $current['version'],
                'to_version' => $newVersion,
                'backup_id' => $backupId,
            ]);
            $this->appendLog('Готово.');

            return [
                'ok' => true,
                'from' => $current,
                'to' => [
                    'version' => $newVersion,
                    'identifier' => $remote['identifier'],
                ],
                'backup_id' => $backupId,
            ];
        } catch (Throwable $e) {
            $this->appendLog('ОШИБКА: ' . $e->getMessage());
            if ($backupId !== null) {
                try {
                    $this->appendLog('Автоматический откат к снимку ' . $backupId . '…');
                    $this->restoreFromSnapshot($backupId, true);
                    if ($serviceStopped) {
                        $this->startService();
                    } else {
                        $this->scheduleRestart();
                    }
                    $this->appendLog('Автоматический откат выполнен.');
                } catch (Throwable $re) {
                    $this->appendLog('Ошибка автоматического отката: ' . $re->getMessage());
                }
            } elseif ($serviceStopped) {
                try {
                    $this->startService();
                } catch (Throwable $ignored) {
                }
            }
            $this->setStatus('error', $e->getMessage());
            throw $e;
        } finally {
            $this->cleanupTmp();
            $this->releaseLock();
        }
    }

    /**
     * Синхронный откат (CLI).
     */
    public function rollback($id = null, $restoreDb = true, array $options = [])
    {
        $this->acquireLock();
        $safetyId = null;
        $serviceStopped = false;

        try {
            $this->startStatus('rollback', 'Подготовка отката…');
            if ($id === null || $id === '') {
                $id = $this->latestBackupId();
            }
            if (!$id) {
                throw new RuntimeException('Нет доступных снимков для отката.');
            }

            $backup = $this->getBackup($id);
            $current = $this->currentInfo();
            $this->appendLog('Текущая версия: ' . $current['version']);
            $this->appendLog('Откат к снимку: ' . $id . ' (' . $backup['manifest']['version'] . ')');

            $safetyId = $this->snapshot('before-rollback');
            $this->appendLog('Текущее состояние сохранено: ' . $safetyId);

            $canStop = !empty($options['stop_service']);
            if ($restoreDb && !$canStop) {
                $restoreDb = false;
                $this->appendLog('ВНИМАНИЕ: восстановление БД отключено, потому что сервис нельзя остановить.');
            }

            if ($canStop && $this->stopService()) {
                $serviceStopped = true;
                $this->appendLog('Сервис остановлен.');
            }

            $this->appendLog('Восстановление файлов…');
            $this->syncCode($backup['path'] . '/code', $this->root);

            if ($restoreDb) {
                $this->appendLog('Восстановление базы данных…');
                $this->restoreDatabase($backup);
            }

            $this->writeCurrent([
                'version' => $backup['manifest']['version'],
                'identifier' => $backup['manifest']['identifier'] ?? null,
                'source' => $backup['manifest']['source'] ?? null,
                'installed_at' => date('c'),
                'rolled_back_at' => date('c'),
                'rolled_back_from' => [
                    'version' => $current['version'],
                    'identifier' => $current['identifier'],
                ],
            ]);

            $this->appendLog('Запуск сервиса…');
            if ($serviceStopped) {
                $this->startService();
            } else {
                $this->scheduleRestart();
            }
            $serviceStopped = false;

            $this->pruneBackups();
            $this->setStatus('success', 'Откат завершён: ' . $current['version'] . ' → ' . $backup['manifest']['version'], [
                'from_version' => $current['version'],
                'to_version' => $backup['manifest']['version'],
                'backup_id' => $id,
                'safety_backup_id' => $safetyId,
            ]);
            $this->appendLog('Готово.');

            return [
                'ok' => true,
                'from' => $current,
                'to' => $backup['manifest'],
                'backup_id' => $id,
                'safety_backup_id' => $safetyId,
            ];
        } catch (Throwable $e) {
            $this->appendLog('ОШИБКА: ' . $e->getMessage());
            if ($safetyId !== null) {
                try {
                    $this->appendLog('Восстановление состояния до отката: ' . $safetyId);
                    $this->restoreFromSnapshot($safetyId, true);
                    if ($serviceStopped) {
                        $this->startService();
                    } else {
                        $this->scheduleRestart();
                    }
                    $this->appendLog('Состояние до отката восстановлено.');
                } catch (Throwable $re) {
                    $this->appendLog('Ошибка восстановления: ' . $re->getMessage());
                }
            } elseif ($serviceStopped) {
                try {
                    $this->startService();
                } catch (Throwable $ignored) {
                }
            }
            $this->setStatus('error', $e->getMessage());
            throw $e;
        } finally {
            $this->cleanupTmp();
            $this->releaseLock();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Снимки и БД                                                         */
    /* ------------------------------------------------------------------ */

    private function snapshot($reason)
    {
        $current = $this->currentInfo();
        $id = date('YmdHis') . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $current['version']) . '-' . bin2hex(random_bytes(2));
        $dir = $this->backupDir . '/' . $id;
        $this->ensureDir($dir);

        $this->appendLog('Создание снимка ' . $id . '…');
        $this->copyTree($this->root, $dir . '/code', '');
        @copy($this->root . '/config.php', $dir . '/config.php.snapshot');
        @chmod($dir . '/config.php.snapshot', 0600);

        $dbFile = $this->backupDatabase($dir);

        $manifest = [
            'version' => $current['version'],
            'identifier' => $current['identifier'],
            'source' => $current['source'],
            'created_at' => date('c'),
            'reason' => $reason,
            'db_type' => App_Config::get('db.type', 'sqlite'),
            'db_backup' => $dbFile,
            'app_root' => $this->root,
        ];
        $this->writeJson($dir . '/manifest.json', $manifest);
        return $id;
    }

    private function restoreFromSnapshot($id, $restoreDb)
    {
        $backup = $this->getBackup($id);
        $this->syncCode($backup['path'] . '/code', $this->root);
        if ($restoreDb) {
            $this->restoreDatabase($backup);
        }
        $this->writeCurrent([
            'version' => $backup['manifest']['version'],
            'identifier' => $backup['manifest']['identifier'] ?? null,
            'source' => $backup['manifest']['source'] ?? null,
            'installed_at' => date('c'),
            'restored_at' => date('c'),
        ]);
    }

    private function backupDatabase($dir)
    {
        $type = (string) App_Config::get('db.type', 'sqlite');
        if ($type === 'mysql') {
            return $this->backupMysql($dir);
        }
        return $this->backupSqlite($dir);
    }

    private function backupSqlite($dir)
    {
        $path = (string) App_Config::get('db.sqlite.path', $this->root . '/data/schedule.sqlite');
        if (!is_file($path)) {
            throw new RuntimeException('Файл SQLite не найден: ' . $path);
        }

        $target = $dir . '/database.sqlite';
        @unlink($target);
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
            $pdo = null;
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось сделать резервную копию SQLite: ' . $e->getMessage());
        }
        if (!is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('Резервная копия SQLite не создана.');
        }
        return 'database.sqlite';
    }

    private function backupMysql($dir)
    {
        if (!$this->commandExists('mysqldump')) {
            throw new RuntimeException('Для MySQL нужен mysqldump. Установите пакет mysql-client/mariadb-client.');
        }
        $target = $dir . '/database.sql';
        $cnf = $this->mysqlDefaultsFile($dir);
        $cmd = 'mysqldump --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --single-transaction --quick '
            . escapeshellarg((string) App_Config::get('db.mysql.name', ''))
            . ' > ' . escapeshellarg($target) . ' 2>&1';
        list($code, $output) = $this->runCommand($cmd);
        @unlink($cnf);
        if ($code !== 0 || !is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('Не удалось сделать дамп MySQL: ' . implode(' ', $output));
        }
        return 'database.sql';
    }

    private function restoreDatabase($backup)
    {
        $manifest = $backup['manifest'];
        $file = $backup['path'] . '/' . ($manifest['db_backup'] ?? '');
        if (!is_file($file)) {
            throw new RuntimeException('В снимке нет резервной копии БД.');
        }

        $type = $manifest['db_type'] ?? App_Config::get('db.type', 'sqlite');
        if ($type === 'mysql') {
            if (!$this->commandExists('mysql')) {
                throw new RuntimeException('Для восстановления MySQL нужен клиент mysql.');
            }
            $cnf = $this->mysqlDefaultsFile($this->tmpDir);
            $cmd = 'mysql --defaults-extra-file=' . escapeshellarg($cnf)
                . ' ' . escapeshellarg((string) App_Config::get('db.mysql.name', ''))
                . ' < ' . escapeshellarg($file) . ' 2>&1';
            list($code, $output) = $this->runCommand($cmd);
            @unlink($cnf);
            if ($code !== 0) {
                throw new RuntimeException('Не удалось восстановить MySQL: ' . implode(' ', $output));
            }
            return;
        }

        $path = (string) App_Config::get('db.sqlite.path', $this->root . '/data/schedule.sqlite');
        @unlink($path . '-wal');
        @unlink($path . '-shm');
        if (!@copy($file, $path)) {
            throw new RuntimeException('Не удалось восстановить файл SQLite.');
        }
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    private function mysqlDefaultsFile($dir)
    {
        $cnf = $dir . '/.mysql-' . bin2hex(random_bytes(4)) . '.cnf';
        $content = "[client]\n"
            . 'host=' . App_Config::get('db.mysql.host', 'localhost') . "\n"
            . 'port=' . App_Config::get('db.mysql.port', '3306') . "\n"
            . 'user=' . App_Config::get('db.mysql.user', 'root') . "\n"
            . 'password=' . App_Config::get('db.mysql.pass', '') . "\n";
        if (@file_put_contents($cnf, $content) === false) {
            throw new RuntimeException('Не удалось создать временный mysql-конфиг.');
        }
        @chmod($cnf, 0600);
        return $cnf;
    }

    private function latestBackupId()
    {
        $backups = $this->backups();
        return $backups ? $backups[0]['id'] : null;
    }

    private function getBackup($id)
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string) $id)) {
            throw new RuntimeException('Некорректный идентификатор снимка.');
        }
        $path = $this->backupDir . '/' . $id;
        if (!is_dir($path)) {
            throw new RuntimeException('Снимок не найден: ' . $id);
        }
        $manifest = $this->readManifest($path);
        if (!$manifest) {
            throw new RuntimeException('Повреждён manifest снимка: ' . $id);
        }
        return ['id' => $id, 'path' => $path, 'manifest' => $manifest];
    }

    private function readManifest($dir)
    {
        $file = $dir . '/manifest.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function backupDbSize($dir, $manifest)
    {
        $file = $dir . '/' . ($manifest['db_backup'] ?? '');
        return is_file($file) ? (int) filesize($file) : 0;
    }

    private function pruneBackups()
    {
        $backups = $this->backups();
        foreach (array_slice($backups, $this->keepBackups) as $old) {
            $this->removePath($old['path']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Синхронизация файлов                                                */
    /* ------------------------------------------------------------------ */

    private function syncCode($source, $destination)
    {
        $source = rtrim($source, '/\\');
        $destination = rtrim($destination, '/\\');
        if (!is_dir($source)) {
            throw new RuntimeException('Каталог-источник не найден: ' . $source);
        }
        $this->ensureDir($destination);

        if ($this->commandExists('rsync')) {
            $excludes = '';
            foreach ($this->protected as $item) {
                $excludes .= ' --exclude=' . escapeshellarg($item);
            }
            $cmd = 'rsync -a --delete ' . $excludes . ' '
                . escapeshellarg($source . '/') . ' ' . escapeshellarg($destination . '/') . ' 2>&1';
            list($code, $output) = $this->runCommand($cmd);
            if ($code === 0) {
                return;
            }
            $this->appendLog('rsync завершился ошибкой, используется PHP-копирование: ' . implode(' ', $output));
        }

        $this->removeExtraneous($source, $destination, '');
        $this->copyTree($source, $destination, '');
    }

    private function isProtected($relative)
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return false;
        }
        $first = explode('/', $relative)[0];
        return in_array($first, $this->protected, true);
    }

    private function removeExtraneous($source, $destination, $relative)
    {
        $items = @scandir($destination);
        if (!$items) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $rel = $relative === '' ? $item : $relative . '/' . $item;
            if ($this->isProtected($rel)) {
                continue;
            }
            $src = $source . '/' . $rel;
            $dst = $destination . '/' . $item;
            if (!file_exists($src) && !is_link($src)) {
                $this->removePath($dst);
            } elseif (is_dir($src) && !is_link($src) && is_dir($dst) && !is_link($dst)) {
                $this->removeExtraneous($src, $dst, $rel);
            }
        }
    }

    private function copyTree($source, $destination, $relative)
    {
        $this->ensureDir($destination);
        $items = @scandir($source);
        if (!$items) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $rel = $relative === '' ? $item : $relative . '/' . $item;
            if ($this->isProtected($rel)) {
                continue;
            }
            $src = $source . '/' . $item;
            $dst = $destination . '/' . $item;

            if (is_link($src)) {
                $target = @readlink($src);
                if ($target !== false) {
                    @unlink($dst);
                    @symlink($target, $dst);
                }
            } elseif (is_dir($src)) {
                $this->copyTree($src, $dst, $rel);
            } else {
                $this->ensureDir(dirname($dst));
                if (!@copy($src, $dst)) {
                    throw new RuntimeException('Не удалось скопировать файл: ' . $rel);
                }
                if (is_executable($src)) {
                    @chmod($dst, 0755);
                }
            }
        }
    }

    private function removePath($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) @scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($path . '/' . $item);
        }
        @rmdir($path);
    }

    /* ------------------------------------------------------------------ */
    /* Загрузка и распаковка                                               */
    /* ------------------------------------------------------------------ */

    private function remoteInfo()
    {
        $release = $this->tryRelease();
        if ($release !== null) {
            return $release;
        }

        $base = 'https://raw.githubusercontent.com/' . $this->repo . '/' . $this->branch;
        $version = trim($this->httpGet($base . '/VERSION'));
        if ($version === '') {
            throw new RuntimeException('Не удалось прочитать VERSION из репозитория.');
        }

        $identifier = null;
        $date = null;
        $notes = null;
        try {
            $json = json_decode($this->httpGet(
                'https://api.github.com/repos/' . $this->repo . '/commits/' . rawurlencode($this->branch)
            ), true);
            if (is_array($json)) {
                $identifier = $json['sha'] ?? null;
                $date = $json['commit']['committer']['date'] ?? null;
                $notes = $json['commit']['message'] ?? null;
            }
        } catch (Throwable $e) {
            $this->appendLog('Не удалось получить SHA коммита: ' . $e->getMessage());
        }

        $archive = $identifier !== null
            ? 'https://codeload.github.com/' . $this->repo . '/zip/' . $identifier
            : 'https://codeload.github.com/' . $this->repo . '/zip/refs/heads/' . $this->branch;

        return [
            'version' => $version,
            'identifier' => $identifier,
            'source' => 'main',
            'date' => $date,
            'notes' => $notes,
            'archive_url' => $archive,
        ];
    }

    private function tryRelease()
    {
        try {
            $json = json_decode($this->httpGet(
                'https://api.github.com/repos/' . $this->repo . '/releases/latest'
            ), true);
            if (!is_array($json) || empty($json['tag_name'])) {
                return null;
            }
            $tag = (string) $json['tag_name'];
            $version = ltrim($tag, 'vV');
            $archive = $json['zipball_url'] ?? ('https://codeload.github.com/' . $this->repo . '/zip/refs/tags/' . rawurlencode($tag));
            return [
                'version' => $version,
                'identifier' => 'release:' . $tag,
                'source' => 'release',
                'date' => $json['published_at'] ?? null,
                'notes' => $json['body'] ?? null,
                'archive_url' => $archive,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function applyProxy($ch)
    {
        $proxy = $this->proxyUrl();
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
    }

    private function proxyUrl()
    {
        if ($this->proxy !== '') {
            return $this->proxy;
        }
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function httpGet($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'rsp-updater/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $this->applyProxy($ch);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('HTTP-ошибка: ' . $error);
            }
            if ($code >= 400) {
                throw new RuntimeException('HTTP ' . $code . ' для ' . $url);
            }
            return (string) $body;
        }

        $http = [
            'timeout' => 30,
            'user_agent' => 'rsp-updater/1.0',
            'follow_location' => 1,
        ];
        $proxy = $this->proxyUrl();
        if ($proxy !== '') {
            $http['proxy'] = $proxy;
            $http['request_fulluri'] = true;
        }
        $context = stream_context_create(['http' => $http]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Не удалось загрузить ' . $url);
        }
        return (string) $body;
    }

    private function download($url, $destination)
    {
        $this->ensureDir(dirname($destination));
        if (!function_exists('curl_init')) {
            if (!@copy($url, $destination)) {
                throw new RuntimeException('Не удалось скачать архив обновления.');
            }
            return;
        }

        $fp = @fopen($destination, 'wb');
        if (!$fp) {
            throw new RuntimeException('Не удалось открыть файл для загрузки: ' . $destination);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'rsp-updater/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $this->applyProxy($ch);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $code >= 400) {
            @unlink($destination);
            throw new RuntimeException('Не удалось скачать архив: ' . ($error ?: 'HTTP ' . $code));
        }
        if (!is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('Скачанный архив пуст.');
        }
    }

    private function extractZip($zip, $destination)
    {
        if ($this->commandExists('unzip')) {
            list($code, $output) = $this->runCommand(
                'unzip -q -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg($destination) . ' 2>&1'
            );
            if ($code !== 0) {
                throw new RuntimeException('Не удалось распаковать архив: ' . implode(' ', $output));
            }
            return;
        }

        if (class_exists('ZipArchive')) {
            $archive = new ZipArchive();
            if ($archive->open($zip) !== true) {
                throw new RuntimeException('Не удалось открыть ZIP-архив.');
            }
            $archive->extractTo($destination);
            $archive->close();
            return;
        }

        throw new RuntimeException('Для обновления нужен unzip или расширение PHP zip.');
    }

    private function findExtractedRoot($dir)
    {
        foreach ((array) @scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && is_file($path . '/VERSION') && is_file($path . '/public/index.php')) {
                return $path;
            }
        }
        if (is_file($dir . '/VERSION') && is_file($dir . '/public/index.php')) {
            return $dir;
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Сервис и миграции                                                   */
    /* ------------------------------------------------------------------ */

    private function runMigrations()
    {
        $cmd = 'cd ' . escapeshellarg($this->root) . ' && '
            . escapeshellarg($this->phpCli()) . ' install.php 2>&1';
        list($code, $output) = $this->runCommand($cmd);
        if ($code !== 0) {
            throw new RuntimeException('Миграции завершились ошибкой: ' . implode(' ', $output));
        }
        $this->appendLog('Миграции: ' . trim(implode(' ', $output)));
    }

    private function serviceExists()
    {
        return is_file('/etc/systemd/system/rsp-schedule.service') && $this->commandExists('systemctl');
    }

    private function stopService()
    {
        if (!$this->serviceExists()) {
            return false;
        }
        list($code, $output) = $this->runCommand('systemctl stop rsp-schedule 2>&1');
        if ($code !== 0) {
            throw new RuntimeException('Не удалось остановить сервис: ' . implode(' ', $output));
        }
        return true;
    }

    private function startService()
    {
        if (!$this->serviceExists()) {
            return false;
        }
        list($code, $output) = $this->runCommand('systemctl start rsp-schedule 2>&1');
        if ($code !== 0) {
            throw new RuntimeException('Не удалось запустить сервис: ' . implode(' ', $output));
        }
        $this->waitForHttp();
        return true;
    }

    private function restartService()
    {
        if (!$this->serviceExists()) {
            return false;
        }
        list($code, $output) = $this->runCommand('systemctl restart rsp-schedule 2>&1');
        if ($code !== 0) {
            throw new RuntimeException('Не удалось перезапустить сервис: ' . implode(' ', $output));
        }
        $this->waitForHttp();
        return true;
    }

    /**
     * Отложенный перезапуск для случая, когда процесс обновления запущен
     * внутри cgroup самого сервиса: успеваем записать статус и выйти.
     */
    private function scheduleRestart()
    {
        if (!$this->serviceExists()) {
            return false;
        }
        $this->runCommand(
            'nohup sh -c "sleep 2; systemctl restart rsp-schedule" >/dev/null 2>&1 &'
        );
        return true;
    }

    /**
     * Небольшая пауза после запуска сервиса, чтобы встроенный PHP-сервер
     * успел открыть порт.
     */
    private function waitForHttp($timeoutSeconds = 6)
    {
        $port = (int) App_Config::get('server.port', 8080);
        $deadline = microtime(true) + max(1, (int) $timeoutSeconds);
        do {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($fp) {
                fclose($fp);
                return true;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        $this->appendLog('Сервис запущен, но порт ' . $port . ' пока не отвечает.');
        return false;
    }

    private function phpCli()
    {
        $candidates = [
            PHP_BINDIR . '/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        if (defined('PHP_BINARY') && PHP_BINARY && stripos(basename(PHP_BINARY), 'fpm') === false) {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $candidate) {
            if ($candidate && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'php';
    }

    /* ------------------------------------------------------------------ */
    /* Служебные методы                                                    */
    /* ------------------------------------------------------------------ */

    private function acquireLock()
    {
        $this->ensureDir($this->stateDir);
        $fp = @fopen($this->lockFile, 'c');
        if (!$fp) {
            throw new RuntimeException('Не удалось открыть lock-файл обновления.');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new RuntimeException('Другая задача обновления уже выполняется.');
        }
        $this->lockHandle = $fp;
    }

    private function releaseLock()
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    private function startStatus($action, $message)
    {
        $this->logLines = [];
        $this->status = [
            'state' => 'running',
            'action' => $action,
            'message' => $message,
            'started_at' => date('c'),
            'updated_at' => date('c'),
            'log' => [],
        ];
        $this->writeJson($this->statusFile, $this->status);
    }

    private function setStatus($state, $message, array $extra = [])
    {
        $status = $this->status();
        $status = array_merge($status, $extra, [
            'state' => $state,
            'message' => $message,
            'updated_at' => date('c'),
        ]);
        if ($status['started_at'] === null) {
            $status['started_at'] = date('c');
        }
        if ($state === 'success' || $state === 'error') {
            $status['finished_at'] = date('c');
        }
        $status['log'] = $this->logLines;
        $this->status = $status;
        $this->writeJson($this->statusFile, $status);
    }

    private function appendLog($message)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $this->logLines[] = $line;
        if (count($this->logLines) > 300) {
            array_shift($this->logLines);
        }

        $logsDir = $this->root . '/logs';
        $this->ensureDir($logsDir);
        @file_put_contents($logsDir . '/updater.log', $line . "\n", FILE_APPEND | LOCK_EX);

        $status = $this->status();
        $status['log'] = $this->logLines;
        $status['updated_at'] = date('c');
        $this->status = $status;
        $this->writeJson($this->statusFile, $status);
    }

    private function writeCurrent(array $data)
    {
        $this->writeJson($this->currentFile, $data);
    }

    private function writeJson($file, array $data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($file, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать файл: ' . $file);
        }
    }

    private function ensureDir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $dir);
        }
    }

    private function cleanupTmp()
    {
        foreach ((array) @scandir($this->tmpDir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removePath($this->tmpDir . '/' . $item);
        }
    }

    private function commandExists($command)
    {
        list($code) = $this->runCommand('command -v ' . escapeshellarg($command) . ' >/dev/null 2>&1');
        return $code === 0;
    }

    private function runCommand($command)
    {
        if (!function_exists('exec')) {
            throw new RuntimeException('Функция exec() отключена. Обновление недоступно.');
        }
        $output = [];
        $code = 0;
        @exec($command, $output, $code);
        return [$code, $output];
    }
}
