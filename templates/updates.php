<?php
if (!defined('ABSPATH')) exit;

$fmt_date = function ($iso) {
    if (!$iso) {
        return '—';
    }
    $ts = strtotime($iso);
    return $ts ? date('d.m.Y H:i:s', $ts) : $iso;
};
$fmt_size = function ($bytes) {
    $bytes = (int) $bytes;
    if ($bytes < 1024) {
        return $bytes . ' Б';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' КБ';
    }
    return round($bytes / (1024 * 1024), 1) . ' МБ';
};
$reason_label = function ($reason) {
    $map = [
        'before-update' => 'перед обновлением',
        'before-rollback' => 'перед откатом',
    ];
    return $map[$reason] ?? ($reason ?: '—');
};
$is_running = in_array($status['state'], ['starting', 'running'], true);
$log_lines = array_slice((array) ($status['log'] ?? []), -100);
?>

<div class="wrap">
    <h1>Обновления</h1>

    <?php if ($error !== ''): ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($error); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['update-started']) || isset($_GET['rollback-started'])): ?>
        <div class="notice notice-info">
            <p>Задача запущена в фоне. Страница обновится автоматически.</p>
        </div>
    <?php endif; ?>

    <?php if ($is_running): ?>
        <div class="notice notice-info">
            <p><strong><?php echo esc_html($status['message'] ?: 'Выполняется обновление…'); ?></strong></p>
            <p>Не закрывайте контейнер и не перезапускайте сервис вручную. Страница обновится через несколько секунд.</p>
        </div>
        <script>window.setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php elseif ($status['state'] === 'success'): ?>
        <div class="notice notice-success">
            <p><?php echo esc_html($status['message']); ?></p>
        </div>
    <?php elseif ($status['state'] === 'error'): ?>
        <div class="notice notice-error">
            <p><strong>Ошибка:</strong> <?php echo esc_html($status['message']); ?></p>
            <p>Если сервис не поднялся, выполните в консоли: <code>php update.php status</code> и <code>php update.php rollback</code>.</p>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Текущая версия</h2>
        <table class="list">
            <tbody>
                <tr>
                    <th>Версия</th>
                    <td><strong><?php echo esc_html($current['version']); ?></strong></td>
                </tr>
                <tr>
                    <th>Коммит / релиз</th>
                    <td><code><?php echo esc_html($current['identifier'] ?: 'неизвестен'); ?></code></td>
                </tr>
                <tr>
                    <th>Источник</th>
                    <td><?php echo esc_html($current['source'] ?: '—'); ?></td>
                </tr>
                <tr>
                    <th>Установлено</th>
                    <td><?php echo esc_html($fmt_date($current['installed_at'] ?? null)); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Проверка обновлений</h2>
        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('check_updates', 'updates_nonce'); ?>
            <input type="hidden" name="action" value="check_updates">
            <?php submit_button('Проверить обновления', 'secondary', 'submit', false); ?>
            <?php if ($checked_at): ?>
                <span class="description">Последняя проверка: <?php echo esc_html($fmt_date($checked_at)); ?></span>
            <?php endif; ?>
        </form>

        <?php if (is_array($check)): ?>
            <hr>
            <?php if (!empty($check['error'])): ?>
                <div class="notice notice-error">
                    <p>Не удалось проверить обновления: <?php echo esc_html($check['error']); ?></p>
                </div>
            <?php else: ?>
                <table class="list">
                    <tbody>
                        <tr>
                            <th>Доступная версия</th>
                            <td><strong><?php echo esc_html($check['remote']['version']); ?></strong>
                                (<?php echo esc_html($check['remote']['source']); ?>)</td>
                        </tr>
                        <tr>
                            <th>Коммит / релиз</th>
                            <td><code><?php echo esc_html($check['remote']['identifier'] ?: 'неизвестен'); ?></code></td>
                        </tr>
                        <tr>
                            <th>Дата</th>
                            <td><?php echo esc_html($fmt_date($check['remote']['date'] ?? null)); ?></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!empty($check['remote']['notes'])): ?>
                    <pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;max-height:220px;overflow:auto;"><?php echo esc_html(trim($check['remote']['notes'])); ?></pre>
                <?php endif; ?>

                <?php if (!empty($check['available'])): ?>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Запустить обновление? Текущая версия будет сохранена в снимок.');">
                        <?php wp_nonce_field('run_update', 'updates_nonce'); ?>
                        <input type="hidden" name="action" value="run_update">
                        <?php submit_button('Обновить до ' . $check['remote']['version'], 'primary', 'submit', false); ?>
                    </form>
                <?php else: ?>
                    <p><strong>Установлена последняя версия.</strong></p>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Принудительно переустановить текущую версию?');">
                        <?php wp_nonce_field('run_update', 'updates_nonce'); ?>
                        <input type="hidden" name="action" value="run_update">
                        <input type="hidden" name="force" value="1">
                        <?php submit_button('Переустановить принудительно', 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <p class="description">Нажмите «Проверить обновления», чтобы узнать доступную версию.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Снимки и откат</h2>
        <?php if (empty($backups)): ?>
            <p>Снимков пока нет. Они создаются автоматически перед обновлением и откатом.</p>
        <?php else: ?>
            <table class="list">
                <thead>
                    <tr>
                        <th>Снимок</th>
                        <th>Версия</th>
                        <th>Создан</th>
                        <th>Причина</th>
                        <th>БД</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><code><?php echo esc_html($backup['id']); ?></code></td>
                            <td><?php echo esc_html($backup['version']); ?></td>
                            <td><?php echo esc_html($fmt_date($backup['created_at'] ?? null)); ?></td>
                            <td><?php echo esc_html($reason_label($backup['reason'] ?? '')); ?></td>
                            <td><?php echo esc_html($fmt_size($backup['db_size'] ?? 0)); ?></td>
                            <td style="white-space:nowrap;">
                                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      style="display:flex;gap:8px;align-items:center;"
                                      onsubmit="return confirm('Откатиться к снимку <?php echo esc_js($backup['id']); ?>? Текущая версия также будет сохранена.');">
                                    <?php wp_nonce_field('run_rollback', 'updates_nonce'); ?>
                                    <input type="hidden" name="action" value="run_rollback">
                                    <input type="hidden" name="backup_id" value="<?php echo esc_attr($backup['id']); ?>">
                                    <label style="font-size:12px;white-space:nowrap;">
                                        <input type="checkbox" name="restore_db" checked>
                                        БД
                                    </label>
                                    <button type="submit" class="button">Откатить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                По умолчанию вместе с кодом восстанавливается база данных. Снимите галочку «БД»,
                если нужно откатить только файлы приложения.
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($log_lines)): ?>
        <div class="card">
            <h2>Журнал</h2>
            <pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;max-height:360px;overflow:auto;font-size:12px;"><?php
                echo esc_html(implode("\n", $log_lines));
            ?></pre>
        </div>
    <?php endif; ?>
</div>
