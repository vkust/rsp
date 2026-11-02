<?php
if (!defined('ABSPATH')) exit;

// Получаем статистику
$stats = $bot->get_detailed_stats();

// Подключаем необходимые скрипты
wp_enqueue_script('exceljs', 'https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js', [], '4.3.0', true);
?>

<div class="wrap schedule-bot-stats">
    <h1>Статистика Telegram бота</h1>

    <!-- Общая статистика -->
    <div class="stats-card overview">
        <h2>Общая статистика</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <span class="stat-value"><?php echo number_format((float)($stats['total_users'] ?? 0)); ?></span>
                <span class="stat-label">Всего пользователей</span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo number_format((float)($stats['active_users'] ?? 0)); ?></span>
                <span class="stat-label">Активных пользователей</span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo number_format((float)($stats['total_subscriptions'] ?? 0)); ?></span>
                <span class="stat-label">Всего подписок</span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo number_format((float)($stats['notifications_today'] ?? 0)); ?></span>
                <span class="stat-label">Уведомлений сегодня</span>
            </div>
        </div>
    </div>

    <!-- График активности -->
    <div class="stats-card activity">
        <h2>Активность за последние 30 дней</h2>
        <div class="chart-container">
            <canvas id="activityChart"></canvas>
        </div>
    </div>

    <!-- Статистика по типам уведомлений -->
    <div class="stats-card notification-types">
        <h2>Статистика уведомлений за сегодня</h2>
        
        <div class="notification-sections">
            <!-- Автоматические уведомления -->
            <div class="notification-section auto">
                <h3>🤖 Автоматические</h3>
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['auto_next_lesson_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">Следующий урок</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['auto_next_day_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">На завтра</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['auto_changes_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">Изменения</span>
                    </div>
                </div>
            </div>

            <!-- Уведомления по запросу -->
            <div class="notification-section manual">
                <h3>👤 По запросу</h3>
                <div class="stats-grid">
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['manual_current_lesson_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">Текущий урок</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['manual_next_lesson_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">Следующий урок</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['manual_today_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">На сегодня</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-value"><?php echo number_format((float)($stats['manual_next_day_notifications'] ?? 0)); ?></span>
                        <span class="stat-label">На завтра</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Список пользователей -->
    <div class="stats-card users-list">
        <div class="card-header">
            <h2>Список пользователей</h2>
            <div class="card-actions">
                <input type="text" id="userSearch" placeholder="Поиск пользователей...">
                <button id="exportUsers" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span>
                    <span class="button-text">Экспорт</span>
                    <span class="spinner"></span>
                </button>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID чата</th>
                    <th>Пользователь</th>
                    <th>Подписки</th>
                    <th>Настройки уведомлений</th>
                    <th>Активность</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats['users'] as $user): ?>
                <tr>
                    <td>
                        <div class="chat-id-info">
                            <div class="chat-id"><?php echo esc_html($user['chat_id']); ?></div>
                            <?php if ($user['username']): ?>
                                <a href="https://t.me/<?php echo esc_attr($user['username']); ?>" target="_blank" class="telegram-link" title="Открыть чат">
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="user-info">
                            <div class="username">
                                <?php if ($user['username']): ?>
                                    <span class="user-badge">@<?php echo esc_html($user['username']); ?></span>
                                <?php else: ?>
                                    <span class="no-username">Нет username</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($user['first_name'] || $user['last_name']): ?>
                                <div class="real-name">
                                    <?php echo esc_html(trim($user['first_name'] . ' ' . $user['last_name'])); ?>
                                </div>
                            <?php endif; ?>
                            <div class="user-details">
                                <div class="registration-date" title="Дата регистрации">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <?php echo esc_html(date('d.m.Y', strtotime($user['created_at']))); ?>
                                </div>
                                <div class="user-status-wrapper">
                                    <?php
                                    $diff_days = floor((time() - strtotime($user['last_activity'])) / 86400);
                                    if ($diff_days < 1) {
                                        $status_class = 'status-active';
                                        $status_text = 'Активен';
                                    } elseif ($diff_days < 7) {
                                        $status_class = 'status-recent';
                                        $status_text = 'Недавно';
                                    } elseif ($diff_days < 30) {
                                        $status_class = 'status-inactive';
                                        $status_text = 'Неактивен';
                                    } else {
                                        $status_class = 'status-dormant';
                                        $status_text = 'Давно неактивен';
                                    }
                                    ?>
                                    <span class="user-status <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="subscriptions-list">
                            <div class="subscription-summary">
                                <div class="subscription-count">
                                    <span class="count-number"><?php echo count($user['subscriptions']); ?></span>
                                    <span class="count-label">подписок</span>
                                </div>
                                <div class="subscription-types">
                                    <?php
                                    $class_count = 0;
                                    $teacher_count = 0;
                                    foreach ($user['subscriptions'] as $sub) {
                                        if ($sub['type'] === 'class') $class_count++;
                                        else $teacher_count++;
                                    }
                                    if ($class_count > 0): ?>
                                        <span class="subscription-type-count" title="Классы">
                                            <span class="icon">👥</span> <?php echo $class_count; ?>
                                        </span>
                                    <?php endif;
                                    if ($teacher_count > 0): ?>
                                        <span class="subscription-type-count" title="Учителя">
                                            <span class="icon">👨‍🏫</span> <?php echo $teacher_count; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="subscription-details">
                                <?php foreach ($user['subscriptions'] as $sub): ?>
                                    <div class="subscription-item" title="<?php echo $sub['type'] === 'class' ? 'Класс' : 'Учитель'; ?>">
                                        <span class="subscription-type-icon">
                                            <?php echo $sub['type'] === 'class' ? '👥' : '👨‍🏫'; ?>
                                        </span>
                                        <span class="entity-name"><?php echo esc_html($sub['entity_name']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="notification-settings">
                            <div class="notification-types">
                                <div class="notification-type <?php echo $user['notifications']['next_lesson'] ? 'active' : 'inactive'; ?>" title="Следующий урок">
                                    <span class="icon">⏭️</span>
                                    <span class="label">Следующий урок</span>
                                    <span class="status-icon"><?php echo $user['notifications']['next_lesson'] ? '✅' : '❌'; ?></span>
                                </div>
                                <div class="notification-type <?php echo $user['notifications']['next_day'] ? 'active' : 'inactive'; ?>" title="Расписание на завтра">
                                    <span class="icon">📅</span>
                                    <span class="label">На завтра</span>
                                    <span class="status-icon"><?php echo $user['notifications']['next_day'] ? '✅' : '❌'; ?></span>
                                </div>
                                <div class="notification-type <?php echo $user['notifications']['changes'] ? 'active' : 'inactive'; ?>" title="Уведомления об изменениях">
                                    <span class="icon">🔔</span>
                                    <span class="label">Изменения</span>
                                    <span class="status-icon"><?php echo $user['notifications']['changes'] ? '✅' : '❌'; ?></span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="activity-info">
                            <div class="last-activity">
                                <div class="activity-header">Последняя активность:</div>
                                <div class="activity-time">
                                    <?php 
                                    $last_activity = strtotime($user['last_activity']);
                                    $diff = time() - $last_activity;
                                    if ($diff < 3600) {
                                        echo '<span class="recent">Менее часа назад</span>';
                                    } elseif ($diff < 86400) {
                                        echo '<span class="today">' . floor($diff / 3600) . ' ч. назад</span>';
                                    } else {
                                        echo '<span class="date">' . date('d.m.Y H:i', $last_activity) . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.schedule-bot-stats .stats-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 20px;
}

.chart-container {
    height: 300px;
    position: relative;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-box {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 4px;
}

.stat-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
}

.stat-label {
    display: block;
    margin-top: 10px;
    color: #646970;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-actions {
    display: flex;
    gap: 10px;
}

#userSearch {
    min-width: 200px;
}

.chat-id-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

.telegram-link {
    color: #0088cc;
    text-decoration: none;
}

.user-badge {
    background: #e8f0fe;
    color: #1967d2;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
}

.user-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.username {
    font-weight: 500;
}

.real-name {
    color: #666;
    font-size: 0.9em;
}

.user-details {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 4px;
}

.registration-date {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #666;
    font-size: 0.85em;
}

.user-status-wrapper {
    display: flex;
    align-items: center;
    gap: 4px;
}

.user-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.9em;
}

.status-active {
    background: #d1e7dd;
    color: #0a3622;
}

.status-recent {
    background: #fff3cd;
    color: #664d03;
}

.status-inactive {
    background: #f8d7da;
    color: #58151c;
}

.status-dormant {
    background: #e2e3e5;
    color: #41464b;
}

.no-username {
    color: #999;
    font-style: italic;
}

.subscriptions-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.subscription-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
}

.subscription-count {
    display: flex;
    align-items: baseline;
    gap: 4px;
}

.count-number {
    font-size: 1.2em;
    font-weight: 500;
    color: #2271b1;
}

.count-label {
    color: #666;
    font-size: 0.9em;
}

.subscription-types {
    display: flex;
    gap: 8px;
}

.subscription-type-count {
    display: flex;
    align-items: center;
    gap: 4px;
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
}

.subscription-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 0;
}

.subscription-type-icon {
    width: 20px;
}

.notification-settings {
    padding: 4px;
}

.notification-types {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.notification-type {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 8px;
    border-radius: 4px;
    background: #f8f9fa;
}

.notification-type.active {
    background: #e8f0fe;
}

.status-icon {
    margin-left: auto;
}

.activity-info {
    padding: 4px;
}

.activity-header {
    color: #666;
    font-size: 0.9em;
    margin-bottom: 4px;
}

.activity-time {
    font-weight: 500;
}

.activity-time .recent {
    color: #0a3622;
}

.activity-time .today {
    color: #664d03;
}

.activity-time .date {
    color: #666;
}

.subscription-details {
    max-height: 100px;
    overflow-y: auto;
    scrollbar-width: thin;
    padding-right: 4px;
}

.subscription-details::-webkit-scrollbar {
    width: 4px;
}

.subscription-details::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.subscription-details::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 2px;
}

#exportUsers {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

#exportUsers .spinner {
    float: none;
    margin: 0;
    display: none;
}

#exportUsers.loading .spinner {
    display: block;
}

#exportUsers.loading .button-text {
    opacity: 0.5;
}

#exportUsers:disabled {
    cursor: not-allowed;
}

.notification-sections {
    display: flex;
    gap: 20px;
    margin-top: 15px;
}

.notification-section {
    flex: 1;
    background: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.notification-section.auto {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
}

.notification-section.manual {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
}

.notification-section h3 {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid rgba(0,0,0,0.1);
    font-size: 16px;
    color: #374151;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
}

.stat-box {
    background: rgba(255,255,255,0.7);
    border-radius: 8px;
    padding: 12px;
    text-align: center;
    transition: transform 0.2s;
}

.stat-box:hover {
    transform: translateY(-2px);
}

.stat-value {
    display: block;
    font-size: 22px;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 5px;
}

.stat-label {
    display: block;
    font-size: 13px;
    color: #6b7280;
}

.auto .stat-value {
    color: #0369a1;
}

.manual .stat-value {
    color: #15803d;
}

@media (max-width: 768px) {
    .notification-sections {
        flex-direction: column;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Поиск по таблице
    $('#userSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('.users-list tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    // Экспорт в XLSX
    $('#exportUsers').click(function() {
        const $button = $(this);
        
        // Проверяем, загружена ли библиотека ExcelJS
        if (typeof ExcelJS === 'undefined') {
            alert('Ошибка: библиотека ExcelJS не загружена');
            return;
        }

        // Блокируем кнопку и показываем спиннер
        $button.prop('disabled', true).addClass('loading');

        // Создаем новую книгу Excel
        const workbook = new ExcelJS.Workbook();
        const worksheet = workbook.addWorksheet('Пользователи бота');

        // Стили для заголовков
        const headerStyle = {
            font: { bold: true, size: 11 },
            fill: {
                type: 'pattern',
                pattern: 'solid',
                fgColor: { argb: 'FFE8F0FE' }
            },
            alignment: { vertical: 'middle', horizontal: 'center' },
            border: {
                top: { style: 'thin' },
                left: { style: 'thin' },
                bottom: { style: 'thin' },
                right: { style: 'thin' }
            }
        };

        // Заголовки
        const headers = [
            'ID чата',
            'Username',
            'Имя',
            'Фамилия',
            'Дата регистрации',
            'Последняя активность',
            'Статус активности',
            'Всего подписок',
            'Подписки на классы',
            'Подписки на учителей',
            'Список подписок',
            'Уведомления (Следующий урок)',
            'Уведомления (На завтра)',
            'Уведомления (Изменения)'
        ];

        // Добавляем заголовки
        worksheet.addRow(headers);
        worksheet.getRow(1).eachCell(cell => {
            Object.assign(cell, headerStyle);
        });

        // Устанавливаем ширину колонок
        worksheet.getColumn(1).width = 15; // ID чата
        worksheet.getColumn(2).width = 20; // Username
        worksheet.getColumn(3).width = 15; // Имя
        worksheet.getColumn(4).width = 15; // Фамилия
        worksheet.getColumn(5).width = 15; // Дата регистрации
        worksheet.getColumn(6).width = 20; // Последняя активность
        worksheet.getColumn(7).width = 15; // Статус
        worksheet.getColumn(8).width = 10; // Всего подписок
        worksheet.getColumn(9).width = 10; // Классы
        worksheet.getColumn(10).width = 10; // Учителя
        worksheet.getColumn(11).width = 50; // Список подписок
        worksheet.getColumn(12).width = 15; // Уведомления 1
        worksheet.getColumn(13).width = 15; // Уведомления 2
        worksheet.getColumn(14).width = 15; // Уведомления 3

        // Собираем данные
        $('.users-list tbody tr').each(function() {
            const $tr = $(this);
            const userInfo = $tr.find('.user-info');
            const fullName = userInfo.find('.real-name').text().trim().split(' ');
            
            // Получаем количество подписок на классы и учителей
            const classCount = $tr.find('.subscription-type-count').eq(0).text().trim().match(/\d+/);
            const teacherCount = $tr.find('.subscription-type-count').eq(1).text().trim().match(/\d+/);
            
            // Собираем список подписок
            const subscriptions = $tr.find('.subscription-details .subscription-item').map(function() {
                return $(this).find('.entity-name').text().trim();
            }).get();

            // Формируем строку данных
            const rowData = [
                $tr.find('.chat-id').text().trim(),
                userInfo.find('.user-badge').text().trim().replace('@', '') || 'Нет',
                fullName[0] || '',
                fullName[1] || '',
                userInfo.find('.registration-date').text().trim().replace('Регистрация: ', ''),
                $tr.find('.activity-time').text().trim(),
                $tr.find('.user-status').text().trim(),
                $tr.find('.count-number').text().trim(),
                classCount ? classCount[0] : '0',
                teacherCount ? teacherCount[0] : '0',
                subscriptions.join('; '),
            ];

            // Добавляем статусы уведомлений
            $tr.find('.notification-type').each(function() {
                rowData.push($(this).hasClass('active') ? 'Да' : 'Нет');
            });

            // Добавляем строку в таблицу
            const row = worksheet.addRow(rowData);

            // Стили для ячеек
            row.eachCell(cell => {
                cell.border = {
                    top: { style: 'thin' },
                    left: { style: 'thin' },
                    bottom: { style: 'thin' },
                    right: { style: 'thin' }
                };
                
                // Центрируем числовые значения
                if (typeof cell.value === 'number' || cell.value === 'Да' || cell.value === 'Нет') {
                    cell.alignment = { horizontal: 'center' };
                }
            });

            // Особые стили для статуса активности
            const statusCell = row.getCell(7);
            switch (statusCell.value) {
                case 'Активен':
                    statusCell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: { argb: 'FFD1E7DD' }
                    };
                    break;
                case 'Недавно':
                    statusCell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: { argb: 'FFFFF3CD' }
                    };
                    break;
                case 'Неактивен':
                    statusCell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: { argb: 'FFF8D7DA' }
                    };
                    break;
                case 'Давно неактивен':
                    statusCell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: { argb: 'FFE2E3E5' }
                    };
                    break;
            }

            // Стили для уведомлений
            for (let i = 12; i <= 14; i++) {
                const cell = row.getCell(i);
                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: cell.value === 'Да' ? 'FFE8F0FE' : 'FFF8F9FA' }
                };
            }
        });

        // Экспортируем файл
        workbook.xlsx.writeBuffer()
            .then(buffer => {
                const blob = new Blob([buffer], { 
                    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' 
                });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'telegram-bot-users.xlsx';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            })
            .catch(error => {
                console.error('Ошибка при создании файла:', error);
                alert('Произошла ошибка при создании файла');
            })
            .finally(() => {
                // Разблокируем кнопку и скрываем спиннер
                $button.prop('disabled', false).removeClass('loading');
            });
    });

    // График активности
    var ctx = document.getElementById('activityChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: <?php echo json_encode($stats['activity_chart_data']); ?>,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            },
            elements: {
                line: {
                    tension: 0.4
                },
                point: {
                    radius: 3
                }
            }
        }
    });
});
</script> 