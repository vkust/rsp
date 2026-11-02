<?php
if (!defined('ABSPATH')) exit;

// Получаем текущий статус бота
$bot = new ScheduleTelegramBot();
$bot_status = $bot->get_bot_info();
$webhook_info = $bot->get_webhook_info();
$api_status = $bot->test_api_connection();
?>

<div class="wrap schedule-bot-settings">
    <h1>Настройки Telegram бота</h1>

    <?php settings_errors('schedule_telegram_bot_options'); ?>

    <!-- Статистика -->
    <div class="settings-card stats-overview">
        <h2>Общая статистика</h2>
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-value"><?php echo esc_html($bot->get_subscribers_count()); ?></span>
                <span class="stat-label">Подписчиков</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?php echo esc_html($bot->get_notifications_count('day')); ?></span>
                <span class="stat-label">Уведомлений за день</span>
            </div>
            <div class="stat-item">
                <span class="stat-value"><?php echo esc_html($bot->get_active_subscriptions_count()); ?></span>
                <span class="stat-label">Активных подписок</span>
            </div>
        </div>
    </div>

    <!-- Статус и настройки бота -->
    <div class="settings-card bot-status">
        <h2>Статус и настройки бота</h2>
        <div class="status-grid">
            <!-- Левая колонка: Статус бота и токен -->
            <div class="status-column">
                <div class="status-section">
                    <h3>Состояние бота</h3>
                    <?php if ($api_status['success']): ?>
                        <div class="status-item success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div class="status-info">
                                <strong>Бот активен</strong>
                                <p>Имя: <?php echo esc_html($bot_status['username'] ?? 'Не указано'); ?></p>
                                <p>Описание: <?php echo esc_html($bot_status['description'] ?? 'Не указано'); ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="status-item error">
                            <span class="dashicons dashicons-warning"></span>
                            <div class="status-info">
                                <strong>Ошибка подключения</strong>
                                <p><?php echo esc_html($api_status['message']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="token-field compact">
                        <form method="post" action="options.php">
                            <?php 
                            settings_fields('schedule_telegram_bot_options');
                            do_settings_sections('schedule_telegram_bot_options');
                            ?>
                            <input type="text" 
                                   name="schedule_telegram_bot_token" 
                                   value="<?php echo esc_attr(get_option('schedule_telegram_bot_token')); ?>" 
                                   class="regular-text"
                                   placeholder="Введите token бота">
                            <?php submit_button('Сохранить токен', 'secondary small'); ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Webhook -->
            <div class="status-column">
                <div class="status-section">
                    <h3>Webhook</h3>
                    <div class="webhook-info compact">
                        <div class="info-row">
                            <span class="label">URL:</span>
                            <span class="value"><?php echo esc_html($webhook_info['url'] ?? 'Не установлен'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">Ошибки:</span>
                            <span class="value"><?php echo esc_html($webhook_info['last_error_message'] ?? 'Нет'); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="label">В очереди:</span>
                            <span class="value"><?php echo esc_html($webhook_info['pending_update_count'] ?? '0'); ?></span>
                        </div>
                    </div>
                    
                    <div class="webhook-actions compact">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <?php wp_nonce_field('manage_webhook', 'webhook_nonce'); ?>
                            <input type="hidden" name="action" value="manage_webhook">
                            <div class="button-group">
                                <button type="submit" name="webhook_action" value="set" class="button button-small">
                                    <span class="dashicons dashicons-update"></span>
                                </button>
                                <button type="submit" name="webhook_action" value="delete" class="button button-small">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                                <button type="submit" name="webhook_action" value="info" class="button button-small">
                                    <span class="dashicons dashicons-info"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Настройки времени уведомлений -->
    <div class="settings-card">
        <h2>Настройки времени уведомлений</h2>
        <form method="post" action="options.php">
            <?php 
            settings_fields('schedule_telegram_time_options'); // Используем новую группу настроек
            ?>
            
            <div class="form-group">
                <label>Рабочее время бота:</label>
                <div class="time-range">
                    <select name="schedule_working_hours_start">
                        <?php for($i = 0; $i < 24; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(get_option('schedule_working_hours_start'), $i); ?>>
                                <?php echo sprintf('%02d:00', $i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <span>—</span>
                    <select name="schedule_working_hours_end">
                        <?php for($i = 0; $i < 24; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected(get_option('schedule_working_hours_end'), $i); ?>>
                                <?php echo sprintf('%02d:00', $i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <p class="description">В это время будут работать уведомления о текущих и следующих уроках</p>
            </div>

            <div class="form-group">
                <label>Время отправки расписания на завтра:</label>
                <select name="schedule_next_day_time">
                    <?php for($i = 0; $i < 24; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php selected(get_option('schedule_next_day_time'), $i); ?>>
                            <?php echo sprintf('%02d:00', $i); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <p class="description">Время, когда бот будет отправлять расписание на следующий день</p>
            </div>

            <?php submit_button('Сохранить настройки времени'); ?>
        </form>
    </div>

    <!-- Отправка сообщений -->
    <div class="settings-card">
        <h2>Отправка сообщений</h2>
        <div class="message-sender">
            <div class="message-form">
                <div class="form-group">
                    <label for="chatId">ID чата или @username:</label>
                    <input type="text" id="chatId" class="regular-text" 
                           placeholder="Например: 123456789, @username или all для всех">
                    <p class="description">Введите 'all' для отправки всем подписчикам</p>
                </div>
                <div class="form-group">
                    <label for="messageText">Текст сообщения:</label>
                    <textarea id="messageText" rows="4" class="large-text" 
                             placeholder="Введите текст сообщения..."></textarea>
                    <p class="description">Поддерживается Markdown-форматирование</p>
                </div>
                <div class="form-actions">
                    <button id="sendMessage" class="button button-primary">
                        <span class="dashicons dashicons-email-alt"></span> Отправить сообщение
                    </button>
                    <span id="sendStatus" style="display: none; margin-left: 10px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Редактор кода -->
    <div class="settings-card">
        <h2>Редактор кода бота</h2>
        <div class="code-editor-wrapper">
            <div class="code-editor-tabs">
                <button class="tab-button active" data-file="telegram-bot.php">telegram-bot.php</button>
                <button class="tab-button" data-file="rest-api.php">rest-api.php</button>
                <button class="tab-button" data-file="scheduler.php">scheduler.php</button>
            </div>
            <div class="code-editor">
                <textarea id="bot-code-editor" name="bot_code" rows="20" data-code-editor="php"></textarea>
            </div>
            <div class="code-editor-actions">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('save_bot_code', 'code_nonce'); ?>
                    <input type="hidden" name="action" value="save_bot_code">
                    <input type="hidden" name="file" value="telegram-bot.php">
                    <textarea name="bot_code" style="display: none;"></textarea>
                    <button type="submit" class="button button-primary" onclick="return prepareCodeSubmit(this)">
                        <span class="dashicons dashicons-saved"></span> Сохранить изменения
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Логи бота -->
    <div class="settings-card">
        <h2>Логи бота</h2>
        <div class="logs-wrapper">
            <div class="logs-header">
                <div class="logs-filters">
                    <select id="logLevel" class="log-filter">
                        <option value="all">Все типы</option>
                        <option value="info">Информация</option>
                        <option value="error">Ошибки</option>
                        <option value="webhook">Webhook</option>
                    </select>
                    <input type="text" id="logSearch" class="log-search" placeholder="Поиск в логах...">
                </div>
                <div class="logs-actions">
                    <button id="refreshLogs" class="button">
                        <span class="dashicons dashicons-update"></span> Обновить
                    </button>
                    <button id="clearLogs" class="button button-secondary">
                        <span class="dashicons dashicons-trash"></span> Очистить
                    </button>
                    <button id="downloadLogs" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> Скачать
                    </button>
                </div>
            </div>
            <div class="logs-content">
                <pre id="logViewer" class="log-viewer"></pre>
            </div>
            <div class="logs-footer">
                <div class="logs-stats">
                    Записей: <span id="logsCounter">0</span>
                </div>
                <div class="logs-refresh">
                    <label>
                        <input type="checkbox" id="autoRefresh"> Автообновление
                    </label>
                    <select id="refreshInterval">
                        <option value="5000">5 секунд</option>
                        <option value="10000">10 секунд</option>
                        <option value="30000">30 секунд</option>
                        <option value="60000">1 минута</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Отладка уведомлений -->
    <div class="settings-card debug-notifications">
        <h2>Отладка уведомлений</h2>
        <div class="debug-controls">
            <div class="test-notifications-section">
                <h3>Тестовые уведомления</h3>
                <form id="test-notification-form">
                    <?php wp_nonce_field('test_notification', '_wpnonce'); ?>
                    <select name="type" id="notification-type">
                        <option value="current_lesson">Текущий урок</option>
                        <option value="next_lesson">Следующий урок</option>
                        <option value="next_day">Расписание на завтра</option>
                    </select>
                    
                    <input type="text" name="chat_id" id="chat-id" placeholder="ID чата (необязательно)">
                    
                    <button type="submit" class="button button-primary">
                        Отправить тестовое уведомление
                    </button>
                </form>
                <div id="test-result" style="display: none;">
                    <pre class="debug-output"></pre>
                </div>
            </div>

            <div class="debug-section">
                <h3>Результат</h3>
                <div id="debugResult" style="display: none;">
                    <pre class="debug-output"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.schedule-bot-settings .settings-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 15px;
    margin: 20px 0;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.schedule-bot-settings h2 {
    margin: 0 0 15px;
    padding: 0;
    font-size: 1.2em;
}

.schedule-bot-settings .status-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-radius: 4px;
    margin: 5px 0;
}

.schedule-bot-settings .status-item.success {
    background: #f0f6e9;
    border-left: 4px solid #46b450;
}

.schedule-bot-settings .status-item.error {
    background: #fef1f1;
    border-left: 4px solid #dc3232;
}

.schedule-bot-settings .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    margin-right: 10px;
}

.schedule-bot-settings .status-info {
    flex: 1;
}

.schedule-bot-settings .status-info p {
    margin: 0;
    font-size: 13px;
    line-height: 1.4;
}

.schedule-bot-settings .webhook-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.schedule-bot-settings .code-editor-wrapper {
    border: 1px solid #ddd;
    border-radius: 4px;
}

.schedule-bot-settings .code-editor-tabs {
    display: flex;
    border-bottom: 1px solid #ddd;
    background: #f5f5f5;
}

.schedule-bot-settings .tab-button {
    padding: 10px 15px;
    border: none;
    background: none;
    cursor: pointer;
}

.schedule-bot-settings .tab-button.active {
    background: #fff;
    border-bottom: 2px solid #2271b1;
}

.schedule-bot-settings .code-editor {
    padding: 10px;
}

.schedule-bot-settings #bot-code-editor {
    width: 100%;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.4;
}

.schedule-bot-settings .code-editor-actions {
    padding: 10px;
    border-top: 1px solid #ddd;
    background: #f5f5f5;
    text-align: right;
}

.schedule-bot-settings .webhook-info p {
    margin: 5px 0;
    font-size: 13px;
}

.schedule-bot-settings .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 10px;
}

.schedule-bot-settings .stat-item {
    text-align: center;
    padding: 12px;
    background: #f5f5f5;
    border-radius: 4px;
}

.schedule-bot-settings .stat-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
}

.schedule-bot-settings .stat-label {
    display: block;
    margin-top: 5px;
    color: #646970;
}

.schedule-bot-settings #bot-code-editor:disabled {
    opacity: 0.7;
    cursor: wait;
}

.schedule-bot-settings .tab-button {
    position: relative;
    transition: all 0.2s ease;
}

.schedule-bot-settings .tab-button:hover {
    background: rgba(0,0,0,0.05);
}

.schedule-bot-settings .tab-button.active {
    background: #fff;
    border-bottom: 2px solid #2271b1;
    font-weight: 500;
}

.logs-wrapper {
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.logs-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    border-bottom: 1px solid #ddd;
    background: #fff;
}

.logs-filters {
    display: flex;
    gap: 10px;
}

.log-filter,
.log-search {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.logs-actions {
    display: flex;
    gap: 5px;
}

.logs-content {
    max-height: 500px;
    overflow-y: auto;
    padding: 10px;
    background: #1e1e1e;
    color: #d4d4d4;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.4;
}

#logViewer {
    margin: 0;
    white-space: pre-wrap;
}

.logs-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    border-top: 1px solid #ddd;
    background: #fff;
    font-size: 12px;
}

.auto-refresh {
    display: flex;
    align-items: center;
    gap: 5px;
}

.log-entry {
    padding: 2px 0;
}

.log-entry.info { color: #89d4ff; }
.log-entry.error { color: #ff8989; }
.log-entry.webhook { color: #89ff9d; }

#refreshInterval {
    padding: 2px 5px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.message-sender {
    background: #fff;
    padding: 15px;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.message-form .form-group {
    margin-bottom: 15px;
}

.message-form label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.message-form textarea {
    width: 100%;
    resize: vertical;
}

.message-form .form-actions {
    display: flex;
    align-items: center;
}

#sendStatus {
    padding: 5px 10px;
    border-radius: 3px;
}

#sendStatus.success {
    background: #e7f5ea;
    color: #0a6b1d;
}

#sendStatus.error {
    background: #fae7e7;
    color: #dc3232;
}

.status-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.status-column {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.webhook-info.compact {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 5px;
}

.info-row .label {
    font-weight: 500;
    color: #666;
}

.webhook-actions.compact .button-group {
    display: flex;
    gap: 5px;
}

.webhook-actions.compact .button-small {
    padding: 0 8px;
    height: 30px;
}

.debug-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 10px;
}

.debug-result {
    margin-top: 15px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
}

.debug-output {
    margin: 0;
    padding: 10px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 12px;
    max-height: 200px;
    overflow-y: auto;
}

/* Стили для блока результатов */
#test-result {
    margin-top: 15px;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
}

#test-result .debug-output {
    margin: 0;
    font-family: monospace;
    white-space: pre-wrap;
}

/* Стили для уведомлений */
.notice {
    padding: 10px 15px;
    margin: 5px 0;
    border-left: 4px solid #ccd0d4;
    background: #fff;
}

.notice-info {
    border-left-color: #00a0d2;
}

.notice-success {
    border-left-color: #46b450;
}

.notice-error {
    border-left-color: #dc3232;
}

/* Стили для формы */
#test-notification-form {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 15px;
}

#notification-type {
    min-width: 150px;
}

#chat-id {
    width: 200px;
}

.CodeMirror {
    height: 600px;
    border: 1px solid #ddd;
    font-family: 'Fira Code', monospace;
    font-size: 14px;
}

/* Стили для темы Monokai */
.cm-s-monokai.CodeMirror { background: #272822; color: #f8f8f2; }
.cm-s-monokai div.CodeMirror-selected { background: #49483E; }
.cm-s-monokai .CodeMirror-line::selection,
.cm-s-monokai .CodeMirror-line > span::selection,
.cm-s-monokai .CodeMirror-line > span > span::selection { background: rgba(73, 72, 62, .99); }
.cm-s-monokai .CodeMirror-gutters { background: #272822; border-right: 0px; }
.cm-s-monokai .CodeMirror-guttermarker { color: white; }
.cm-s-monokai .CodeMirror-guttermarker-subtle { color: #d0d0d0; }
.cm-s-monokai .CodeMirror-linenumber { color: #d0d0d0; }
.cm-s-monokai .CodeMirror-cursor { border-left: 1px solid #f8f8f0; }
.cm-s-monokai span.cm-comment { color: #75715e; }
.cm-s-monokai span.cm-atom { color: #ae81ff; }
.cm-s-monokai span.cm-number { color: #ae81ff; }
.cm-s-monokai span.cm-comment.cm-attribute { color: #97b757; }
.cm-s-monokai span.cm-comment.cm-def { color: #bc9262; }
.cm-s-monokai span.cm-comment.cm-tag { color: #bc6283; }
.cm-s-monokai span.cm-comment.cm-type { color: #5998a6; }
.cm-s-monokai span.cm-property { color: #a6e22e; }
.cm-s-monokai span.cm-attribute { color: #a6e22e; }
.cm-s-monokai span.cm-keyword { color: #f92672; }
.cm-s-monokai span.cm-builtin { color: #66d9ef; }
.cm-s-monokai span.cm-string { color: #e6db74; }
.cm-s-monokai span.cm-variable { color: #f8f8f2; }
.cm-s-monokai span.cm-variable-2 { color: #9effff; }
.cm-s-monokai span.cm-variable-3,
.cm-s-monokai span.cm-type { color: #66d9ef; }
.cm-s-monokai span.cm-def { color: #fd971f; }
.cm-s-monokai span.cm-bracket { color: #f8f8f2; }
.cm-s-monokai span.cm-tag { color: #f92672; }
.cm-s-monokai span.cm-header { color: #ae81ff; }
.cm-s-monokai span.cm-link { color: #ae81ff; }
.cm-s-monokai span.cm-error { background: #f92672; color: #f8f8f0; }
.cm-s-monokai .CodeMirror-activeline-background { background: #373831; }
.cm-s-monokai .CodeMirror-matchingbracket {
    text-decoration: underline;
    color: white !important;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Инициализация CodeMirror
    let editor = null;
    
    function initCodeMirror() {
        if (wp.codeEditor) {
            const editorSettings = wp.codeEditor.defaultSettings ? {...wp.codeEditor.defaultSettings} : {};
            editorSettings.codemirror = {
                ...editorSettings.codemirror,
                mode: 'php',
                lineNumbers: true,
                lineWrapping: true,
                autoCloseBrackets: true,
                matchBrackets: true,
                indentUnit: 4,
                indentWithTabs: true,
                theme: 'monokai', // Добавляем тему
                extraKeys: {"Ctrl-Space": "autocomplete"},
                styleActiveLine: true,
                autoRefresh: true,
                gutters: ["CodeMirror-linenumbers", "CodeMirror-foldgutter"],
                foldGutter: true,
                scrollbarStyle: "overlay"
            };
            
            editor = wp.codeEditor.initialize($('#bot-code-editor'), editorSettings);
            window.botCodeMirror = editor.codemirror;
        }
    }

    // Загрузка начального файла
    function loadInitialFile() {
        const file = 'telegram-bot.php';
        $.post(ajaxurl, {
            action: 'get_bot_file_content',
            file: file,
            nonce: '<?php echo wp_create_nonce("get_bot_file"); ?>'
        }, function(response) {
            if (response.success) {
                if (window.botCodeMirror) {
                    window.botCodeMirror.setValue(response.data);
                    window.botCodeMirror.refresh();
                } else {
                    $('#bot-code-editor').val(response.data);
                }
                $('input[name="file"]').val(file);
            }
        });
    }

    // Инициализируем редактор и загружаем начальный файл
    initCodeMirror();
    loadInitialFile();

    // Обработка переключения вкладок
    $('.tab-button').click(function() {
        if ($(this).hasClass('active')) return;
        
        $('.tab-button').removeClass('active');
        $(this).addClass('active');
        
        const file = $(this).data('file');
        const $fileInput = $('input[name="file"]');
        
        // Показываем индикатор загрузки
        if (window.botCodeMirror) {
            window.botCodeMirror.setOption('readOnly', true);
        }
        
        $.post(ajaxurl, {
            action: 'get_bot_file_content',
            file: file,
            nonce: '<?php echo wp_create_nonce("get_bot_file"); ?>'
        }, function(response) {
            if (response.success) {
                if (window.botCodeMirror) {
                    window.botCodeMirror.setValue(response.data);
                    window.botCodeMirror.refresh();
                    window.botCodeMirror.setOption('readOnly', false);
                } else {
                    $('#bot-code-editor').val(response.data);
                }
                $fileInput.val(file);
            }
        });
    });

    let autoRefreshInterval;

    // Функция обновления логов
    function refreshLogs() {
        $.post(ajaxurl, {
            action: 'get_bot_logs',
            nonce: '<?php echo wp_create_nonce("get_bot_logs"); ?>'
        }, function(response) {
            if (response.success) {
                updateLogViewer(response.data);
            }
        });
    }

    // Обновление отображения логов
    function updateLogViewer(logs) {
        const logViewer = $('#logViewer');
        const filter = $('#logLevel').val();
        const search = $('#logSearch').val().toLowerCase();

        let filteredLogs = logs;
        if (filter !== 'all') {
            filteredLogs = logs.filter(log => log.toLowerCase().includes(`[${filter}]`));
        }
        if (search) {
            filteredLogs = filteredLogs.filter(log => log.toLowerCase().includes(search));
        }

        logViewer.html(filteredLogs.join('\n'));
        $('#logsCounter').text(filteredLogs.length);
    }

    // Обработчики событий
    $('#refreshLogs').click(refreshLogs);

    $('#clearLogs').click(function() {
        if (confirm('Очистить все логи?')) {
            $.post(ajaxurl, {
                action: 'clear_bot_logs',
                nonce: '<?php echo wp_create_nonce("clear_bot_logs"); ?>'
            }, function(response) {
                if (response.success) {
                    $('#logViewer').empty();
                    $('#logsCounter').text('0');
                }
            });
        }
    });

    $('#downloadLogs').click(function() {
        const logs = $('#logViewer').text();
        const blob = new Blob([logs], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `telegram-bot-logs-${new Date().toISOString().slice(0,10)}.log`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    });

    $('#logLevel, #logSearch').on('change keyup', function() {
        refreshLogs();
    });

    $('#autoRefresh').change(function() {
        if (this.checked) {
            const interval = $('#refreshInterval').val();
            autoRefreshInterval = setInterval(refreshLogs, interval);
        } else {
            clearInterval(autoRefreshInterval);
        }
    });

    $('#refreshInterval').change(function() {
        if ($('#autoRefresh').prop('checked')) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(refreshLogs, $(this).val());
        }
    });

    $('#sendMessage').click(function() {
        const chatId = $('#chatId').val().trim();
        const messageText = $('#messageText').val().trim();
        const $status = $('#sendStatus');
        const $button = $(this);

        if (!chatId || !messageText) {
            $status.removeClass('success').addClass('error')
                .text('Заполните все поля!')
                .show();
            return;
        }

        $button.prop('disabled', true);
        $status.removeClass('success error').text('Отправка...').show();

        $.post(ajaxurl, {
            action: 'send_telegram_message',
            nonce: '<?php echo wp_create_nonce("send_telegram_message"); ?>',
            chat_id: chatId,
            message: messageText
        }, function(response) {
            if (response.success) {
                $status.removeClass('error').addClass('success')
                    .text('Сообщение успешно отправлено!');
                $('#messageText').val('');
            } else {
                $status.removeClass('success').addClass('error')
                    .text('Ошибка: ' + (response.data || 'Не удалось отправить сообщение'));
            }
        }).fail(function() {
            $status.removeClass('success').addClass('error')
                .text('Ошибка сервера!');
        }).always(function() {
            $button.prop('disabled', false);
            setTimeout(() => $status.fadeOut(), 5000);
        });
    });

    // Обработчики для тестовых уведомлений
    $('#test-notification-form').submit(function(event) {
        event.preventDefault();
        
        const type = $('#notification-type').val();
        const chatId = $('#chat-id').val().trim();
        const $result = $('#test-result');
        const $output = $result.find('.debug-output');
        const $button = $(this).find('button[type="submit"]');
        
        // Показываем блок результатов и индикатор загрузки
        $result.show();
        $output.html('<div class="notice notice-info">' + 
            (window.schoolSchedule?.strings?.sending || 'Отправка тестового уведомления...') + 
            '</div>');
        $button.prop('disabled', true);
        
        // Отправляем AJAX запрос
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'test_notification',
                _wpnonce: $('#test-notification-form input[name="_wpnonce"]').val(),
                type: type,
                chat_id: chatId || ''
            },
            success: function(response) {
                console.log('Success Response:', response);
                if (response.success) {
                    $output.html('<div class="notice notice-success">' + 
                        response.data.split('\n').join('<br>') + '</div>');
                } else {
                    $output.html('<div class="notice notice-error">Ошибка: ' + 
                        (response.data || 'Неизвестная ошибка') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                let errorMessage = 'Ошибка сервера';
                try {
                    const response = JSON.parse(xhr.responseText);
                    errorMessage = response.data || error || 'Неизвестная ошибка';
                } catch (e) {
                    errorMessage = xhr.responseText || error || 'Неизвестная ошибка';
                }
                
                $output.html('<div class="notice notice-error">Ошибка: ' + errorMessage + '</div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});

function prepareCodeSubmit(button) {
    var form = button.closest('form');
    var codeInput = form.querySelector('textarea[name="bot_code"]');
    
    // Получаем код из CodeMirror если он инициализирован
    if (window.botCodeMirror) {
        codeInput.value = window.botCodeMirror.getValue();
    } else {
        // Иначе берем значение из обычного textarea
        codeInput.value = document.getElementById('bot-code-editor').value;
    }
    
    return true;
}
</script> 