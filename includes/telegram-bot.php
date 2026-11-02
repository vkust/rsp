<?php
// Проверяем, что файл вызван из WordPress
if (!defined('ABSPATH')) {
    exit;
}

class ScheduleTelegramBot {
    private $token;
    private $api_url = 'https://api.telegram.org/bot';
    private $webhook_url;
    private $db;
    private $schedule;
    private $rate_limit_delay = 1; // секунда между запросами
    private $last_api_call = 0;
    private $max_retries = 3;
    private $retry_delay = 2;
    private $max_batch_size = 30; // Максимальное количество сообщений в пакете

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->token = get_option('schedule_telegram_bot_token');
        $this->webhook_url = get_site_url() . '/wp-json/school-schedule/v1/telegram-webhook';
        
        // Инициализируем базу данных только при активации плагина
        if (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'plugins.php') {
            $this->init_db();
        }
        
        // Обновляем структуру таблицы уведомлений
        $this->update_notifications_table();
        
        // Загружаем расписание при инициализации
        $this->schedule = $this->get_current_schedule();
        
        // Добавляем обработчик для уведомлений о расписании на следующий день
        add_action('schedule_next_day_notification', [$this, 'send_next_day_notifications']);
        
        // Проверяем статус CRON при инициализации
        add_action('init', [$this, 'check_cron_status']);
        
        // Добавляем обработчик AJAX
        add_action('wp_ajax_send_telegram_message', [$this, 'ajax_send_message']);

        // Устанавливаем команды бота
        $this->set_bot_commands();
        
        // Регистрируем обработчики для асинхронной отправки
        add_action('schedule_send_telegram_message', [$this, 'handle_scheduled_message'], 10, 2);
        add_action('schedule_retry_telegram_request', [$this, 'handle_retry_request'], 10, 3);
        
        // Добавляем обработчик изменения времени в админке
        add_action('update_option_schedule_next_day_time', [$this, 'handle_admin_time_change'], 10, 3);
    }

    private function init_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schedule_users (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                chat_id bigint(20) NOT NULL,
                username varchar(255),
                first_name varchar(255),
                last_name varchar(255),
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
                UNIQUE KEY chat_id (chat_id)
            ) $charset_collate;";

        $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schedule_subscriptions (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                chat_id bigint(20) NOT NULL,
                subscription_type varchar(50) NOT NULL,
            entity_id varchar(50) NOT NULL,
                notify_next_lesson tinyint(1) DEFAULT 0,
                notify_next_day tinyint(1) DEFAULT 0,
                notify_changes tinyint(1) DEFAULT 0,
            next_lesson_type varchar(50) DEFAULT NULL,
            next_day_time time DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
                UNIQUE KEY unique_subscription (chat_id, subscription_type, entity_id)
            ) $charset_collate;";

        $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}schedule_notifications (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                chat_id bigint(20) NOT NULL,
                notification_type varchar(50) NOT NULL,
                message text NOT NULL,
            source varchar(50) DEFAULT 'auto',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
                KEY chat_id (chat_id),
                KEY notification_type (notification_type),
                KEY created_at (created_at)
            ) $charset_collate;";

        
            dbDelta($sql);

        // Проверяем и обновляем структуру таблицы подписок
        $this->update_subscriptions_table_structure();
    }

    private function update_subscriptions_table_structure() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';

        // Проверяем текущий тип поля entity_id
        $column_info = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} WHERE Field = 'entity_id'");
        
        if ($column_info && stripos($column_info->Type, 'bigint') !== false) {
            $this->log("Обновление структуры таблицы подписок: изменение типа поля entity_id", 'info');
            
            try {
                // Создаем временную таблицу для хранения данных
                $wpdb->query("CREATE TEMPORARY TABLE tmp_subscriptions SELECT * FROM {$table_name}");
                
                // Изменяем тип поля
                $wpdb->query("ALTER TABLE {$table_name} MODIFY entity_id varchar(50) NOT NULL");
                
                // Восстанавливаем данные
                $wpdb->query("UPDATE {$table_name} s 
                             JOIN tmp_subscriptions ts ON s.id = ts.id 
                             SET s.entity_id = ts.entity_id");
                
                // Удаляем временную таблицу
                $wpdb->query("DROP TEMPORARY TABLE IF EXISTS tmp_subscriptions");
                
                $this->log("Структура таблицы подписок успешно обновлена", 'info');
            } catch (Exception $e) {
                $this->log("Ошибка при обновлении структуры таблицы подписок: " . $e->getMessage(), 'error');
            }
        }
    }

    private function update_notifications_table() {
        try {
            // Проверяем наличие поля source
            $columns = $this->db->get_results("SHOW COLUMNS FROM {$this->db->prefix}schedule_notifications LIKE 'source'");
            
            if (empty($columns)) {
                // Добавляем поле source
                $result = $this->db->query("ALTER TABLE {$this->db->prefix}schedule_notifications 
                    ADD COLUMN source enum('auto', 'manual') NOT NULL DEFAULT 'auto' AFTER message");
                
                if ($result === false) {
                    $this->log("Ошибка при добавлении поля source: " . $this->db->last_error, 'error');
                    return false;
                }
                
                $this->log("Добавлено поле source в таблицу уведомлений", 'info');
            }
            
            return true;
        } catch (Exception $e) {
            $this->log("Ошибка при обновлении таблицы уведомлений: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function set_webhook() {
        try {
            // Сначала удаляем текущий webhook
            $delete_url = $this->api_url . $this->token . '/deleteWebhook';
            wp_remote_post($delete_url);
            
            // Устанавливаем новый webhook
            $url = $this->api_url . $this->token . '/setWebhook';
            $params = [
                'url' => $this->webhook_url,
                'allowed_updates' => ['message', 'callback_query'],
                'max_connections' => 100
            ];
            
            $response = wp_remote_post($url, [
                'body' => json_encode($params),
                'headers' => ['Content-Type' => 'application/json']
            ]);
            
            if (is_wp_error($response)) {
                $this->log("Ошибка установки webhook: " . $response->get_error_message(), 'error');
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!$data['ok']) {
                $this->log("Ошибка API при установке webhook: " . ($data['description'] ?? 'Unknown error'), 'error');
                return false;
            }
            
            $this->log("Webhook успешно установлен на URL: " . $this->webhook_url, 'info');
            return true;
            
        } catch (Exception $e) {
            $this->log("Исключение при установке webhook: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function handle_webhook($request) {
        try {
            // Проверяем токен
            if (empty($this->token)) {
                $this->log("Webhook error: Token не настроен", 'error');
                return new WP_REST_Response(['status' => 'error', 'message' => 'Token not configured'], 500);
            }

            // Получаем и проверяем тело запроса
            $body = $request->get_body();
            $update = json_decode($body, true);

            if (empty($update)) {
                $this->log("Webhook error: Пустое тело запроса", 'error');
                return new WP_REST_Response(['status' => 'error', 'message' => 'Empty request body'], 400);
            }

            $this->log("Получен webhook запрос: " . print_r($update, true), 'debug');

            // Обрабатываем различные типы обновлений
            if (isset($update['message'])) {
                $result = $this->handle_message($update['message']);
            } elseif (isset($update['callback_query'])) {
                $result = $this->handle_callback($update['callback_query']);
            } else {
                $this->log("Webhook error: Неизвестный тип обновления", 'error');
                return new WP_REST_Response(['status' => 'error', 'message' => 'Unknown update type'], 400);
            }

            // Возвращаем успешный ответ
            return new WP_REST_Response(['status' => 'ok'], 200);

        } catch (Exception $e) {
            $this->log("Webhook error: " . $e->getMessage(), 'error');
            return new WP_REST_Response([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function handle_message($message) {
        try {
            $chat_id = $message['chat']['id'];
            $text = $message['text'] ?? '';
            
            // Сохраняем информацию о пользователе
            $this->save_user_info($message['chat']);
            
            $this->log("Получено сообщение от $chat_id: $text", 'info');
            
            // Обработка команд и кнопок меню
            switch ($text) {
                case '/start':
                case '/menu':
                case '📋 Главное меню':
                    $this->send_welcome_message($chat_id);
                    break;

                case '📖 Текущий урок':
                case '/current':
                    $this->test_notification('current_lesson', $chat_id);
                    break;

                case '⏭️ Следующий урок':
                case '/next':
                    $this->test_notification('next_lesson', $chat_id);
                    break;

                case '📅 Расписание на сегодня':
                case '/today':
                    $this->test_notification('today', $chat_id);
                    break;

                case '📅 Расписание на завтра':
                case '/tomorrow':
                    $this->log("Получена команда 'Расписание на завтра' от chat_id={$chat_id}", 'info');
                    $this->test_notification('tomorrow', $chat_id);
                    break;

                case '/subscribe':
                    return $this->show_subscription_types($chat_id);

                case '/unsubscribe':
                    return $this->send_unsubscribe_options($chat_id);

                case '/settings':
                    return $this->show_my_subscriptions($chat_id);

                case '/help':
                    return $this->send_help_message($chat_id);

                case '⏰ Текущий период':
                case '/period':
                    $current_period_info = $this->get_current_period_info();
                    $message = "";
                    
                    if ($current_period_info) {
                        $message .= "*" . $current_period_info . "*\n\n";
                    }
                    
                    // Получаем информацию о следующем периоде
                    if (!$this->schedule) {
                        $this->schedule = $this->get_current_schedule();
                    }
                    
                    $current_time = strtotime(current_time('H:i'));
                    $periods = $this->schedule['periods'];
                    usort($periods, function($a, $b) {
                        return strtotime($a['starttime']) - strtotime($b['starttime']);
                    });
                    
                    $next_period = null;
                    $current_period = null;
                    
                    foreach ($periods as $i => $period) {
                        $start_time = strtotime($period['starttime']);
                        $end_time = strtotime($period['endtime']);
                        
                        if ($current_time >= $start_time && $current_time <= $end_time) {
                            $current_period = $period;
                            $next_period = isset($periods[$i + 1]) ? $periods[$i + 1] : null;
                            break;
                        } elseif ($current_time < $start_time) {
                            $next_period = $period;
                            break;
                        }
                    }
                    
                    if ($current_period) {
                        $minutes_left = ceil((strtotime($current_period['endtime']) - $current_time) / 60);
                        $message .= "⏱️ До конца " . $current_period['name'] . " урока осталось: *{$minutes_left} мин.*\n";
                        $message .= "⏳ " . $current_period['starttime'] . " - " . $current_period['endtime'] . "\n";
                    }
                    
                    if ($next_period) {
                        $minutes_until = ceil((strtotime($next_period['starttime']) - $current_time) / 60);
                        if ($current_period) {
                            $message .= "\n*Следующий период:*\n";
                        }
                        $message .= "📌 " . $next_period['name'] . " урок\n";
                        $message .= "⏳ " . $next_period['starttime'] . " - " . $next_period['endtime'] . "\n";
                        $message .= "⏱️ До начала: *{$minutes_until} мин.*";
                    } else {
                        $message .= "\n✅ Это последний урок на сегодня";
                    }
                    
                    $this->send_message($chat_id, $message);
                    break;

                case '🔍 Быстрый поиск':
                case '/search':
                    $message = "*🔍 Быстрый поиск расписания*\n\n";
                    $message .= "Выберите, чье расписание вы хотите посмотреть:";
                    
                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '👥 Класс', 'callback_data' => 'quick_search:class'],
                                ['text' => '👨‍🏫 Учитель', 'callback_data' => 'quick_search:teacher']
                            ]
                        ]
                    ];
                    
                    return $this->send_message($chat_id, $message, $keyboard);
                    break;

                default:
                    return $this->send_message(
                        $chat_id, 
                        "Используйте кнопки меню или команды /help для получения списка доступных команд."
                    );
            }
        } catch (Exception $e) {
            $this->log("Ошибка в handle_message: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function handle_callback($callback_query) {
        $chat_id = $callback_query['from']['id'];
        $callback_data = $callback_query['data'];
        
        // Разбираем callback_data
        if (strpos($callback_data, ':') !== false) {
            list($action, $param) = explode(':', $callback_data);
        } else {
            $action = $callback_data;
            $param = null;
        }
        
        switch ($action) {
            case 'start':
                $this->send_welcome_message($chat_id);
                break;
                
            case 'my_subscriptions':
                $this->show_my_subscriptions($chat_id);
                break;
                
            case 'add_subscription':
                $this->show_subscription_types($chat_id);
                break;
                
            case 'subscribe_class':
                $this->show_class_list($chat_id);
                break;
                
            case 'subscribe_teacher':
                $this->show_teacher_list($chat_id);
                break;
                
            case 'subscribe_period':
                $this->show_period_list($chat_id);
                break;
                
            case 'select_class':
            case 'select_teacher':
            case 'select_period':
                $this->create_subscription($chat_id, $action, $param);
                break;
                
            case 'manage_subscription':
                $this->manage_subscription($chat_id, $param);
                break;
                
            case 'delete_subscription':
                $this->delete_subscription($chat_id, $param);
                break;
                
            case 'toggle_next_lesson':
                $this->toggle_next_lesson($chat_id, $param);
                break;
                
            case 'toggle_next_day':
                $this->toggle_next_day($chat_id, $param);
                break;
                
            case 'toggle_changes':
                $this->toggle_changes($chat_id, $param);
                break;
                
            case 'toggle_period_start':
                $this->toggle_period_start($chat_id, $param);
                break;
                
            case 'toggle_period_end':
                $this->toggle_period_end($chat_id, $param);
                break;
                
            case 'disable_all_notifications':
                $this->disable_all_notifications($chat_id);
                break;
                
            case 'delete_all_subscriptions':
                $this->delete_all_subscriptions($chat_id);
                break;
        }
        
        // Отвечаем на callback query
        $this->answer_callback_query($callback_query['id']);
    }

    public function send_next_lesson_notifications() {
        try {
            // Проверяем глобальную блокировку
            $lock_key = 'schedule_notifications_lock';
            if (get_transient($lock_key)) {
                $this->log("Предыдущая отправка еще выполняется", 'info');
                return;
            }
            
            // Устанавливаем блокировку на 1 минуту
            set_transient($lock_key, true, MINUTE_IN_SECONDS);
            
            // Базовые проверки
            if (!$this->check_notification_conditions()) {
                return;
            }
            
            // Получаем активные подписки
            $subscriptions = $this->get_active_subscriptions('notify_next_lesson');
            if (empty($subscriptions)) {
                $this->log("Нет активных подписок", 'info');
                return;
            }

            foreach ($subscriptions as $subscription) {
                $this->process_subscription_notification($subscription);
            }
            
        } catch (Exception $e) {
            $this->log("Ошибка при отправке уведомлений: " . $e->getMessage(), 'error');
        } finally {
            delete_transient($lock_key);
        }
    }

    private function process_subscription_notification($subscription) {
        try {
            if (!isset($subscription['id'])) {
                $this->log("Некорректная подписка", 'error');
                return;
            }

            $current_lesson = $this->get_current_lesson($subscription);
            $next_lesson = $this->get_next_lesson($subscription);

            $debug_info = [
                'subscription_id' => $subscription['id'],
                'current_lesson' => $current_lesson ? 'exists' : 'null',
                'next_lesson' => $next_lesson ? 'exists' : 'null',
                'time' => current_time('H:i:s')
            ];
            $this->log("Отладочная информация: " . json_encode($debug_info, JSON_UNESCAPED_UNICODE), 'debug');

            if (!$next_lesson) {
                return;
            }

            $notification_type = $subscription['next_lesson_type'] ?? 'auto';
            $custom_time = isset($subscription['next_lesson_time']) ? (int)$subscription['next_lesson_time'] : 10;
            $should_notify = false;

            $current_lesson_key = null;
            $first_lesson_key = null;
            
            if ($current_lesson && isset($current_lesson['lesson']['periodId'])) {
                $current_lesson_key = sprintf(
                    'lesson_notification_%s_%s_%s',
                    $subscription['id'],
                    date('Y-m-d'),
                    $current_lesson['lesson']['periodId']
                );
            }
            
            if ($next_lesson && isset($next_lesson['lesson']['periodId'])) {
                $first_lesson_key = sprintf(
                    'first_lesson_notification_%s_%s_%s',
                    $subscription['id'],
                    date('Y-m-d'),
                    $next_lesson['lesson']['periodId']
                );
            }

            if ($notification_type === 'auto') {
                if (!$current_lesson && $this->is_first_lesson_of_day($next_lesson)) {
                    $minutes_until = isset($next_lesson['minutes_until']) ? (int)$next_lesson['minutes_until'] : null;
                    if ($minutes_until !== null && $minutes_until <= 10 && $minutes_until >= 8) {
                        if (!get_transient($first_lesson_key)) {
                            $should_notify = true;
                            $this->log("Уведомление перед первым уроком (minutes_until: $minutes_until)", 'debug');
                        } else {
                            $this->log("Уведомление о первом уроке уже было отправлено", 'debug');
                        }
                    }
                }
                elseif ($current_lesson && 
                        is_array($current_lesson) && 
                        isset($current_lesson['minutes_left'])) {
                    $minutes_left = (int)$current_lesson['minutes_left'];
                    
                    if ($minutes_left <= 0 && $minutes_left >= -2) {
                        if (!get_transient($current_lesson_key)) {
                            $should_notify = true;
                            $this->log("Уведомление в момент окончания урока (minutes_left: $minutes_left)", 'debug');
                        } else {
                            $this->log("Уведомление уже было отправлено для этого урока", 'debug');
                        }
                    }
                }
            }
            elseif ($notification_type === 'custom' && isset($next_lesson['minutes_until'])) {
                $minutes_until = (int)$next_lesson['minutes_until'];
                if ($minutes_until === $custom_time) {
                    $should_notify = true;
                    $this->log("Уведомление по настроенному времени", 'debug');
                }
            }

            if ($should_notify) {
                if (get_transient('processing_notification_' . $subscription['id'])) {
                    $this->log("Пропуск отправки - уведомление уже обрабатывается для подписки {$subscription['id']}", 'debug');
                    return false;
                }

                set_transient('processing_notification_' . $subscription['id'], true, 30);

                $message = $this->format_next_lesson_message($next_lesson);
                if ($message && $this->send_message($subscription['chat_id'], $message)) {
                    $this->save_notification($subscription['chat_id'], 'next_lesson', $message, 'auto');
                    if ($first_lesson_key) {
                        set_transient($first_lesson_key, true, 5 * MINUTE_IN_SECONDS);
                    }
                    if ($current_lesson_key) {
                        set_transient($current_lesson_key, true, 5 * MINUTE_IN_SECONDS);
                    }
                    $this->log("Уведомление отправлено для подписки {$subscription['id']}", 'info');
                    return true;
                }
            }

        } catch (Exception $e) {
            $this->log("Ошибка обработки подписки {$subscription['id']}: " . $e->getMessage(), 'error');
        }
    }

    private function check_notification_conditions() {
        // Загружаем расписание
        if (!$this->schedule) {
            $this->schedule = $this->get_current_schedule();
        }
        
        if (!$this->schedule || !isset($this->schedule['periods']) || !isset($this->schedule['lessons'])) {
            $this->log("Расписание не загружено или некорректно", 'error');
            return false;
        }
        
        // Проверяем время
        $current_hour = (int)current_time('G');
        $start_hour = (int)get_option('schedule_working_hours_start', 7);
        $end_hour = (int)get_option('schedule_working_hours_end', 22);
        
        if ($current_hour < $start_hour || $current_hour > $end_hour) {
            $this->log("Нерабочее время ($current_hour), пропускаем", 'info');
            return false;
        }
        
        // Проверяем день недели
        $current_day = (int)current_time('N');
        if ($current_day > 5) {
            $this->log("Выходной день, пропускаем", 'info');
            return false;
        }
        
        return true;
    }

    public function send_next_day_notifications() {
        // Проверяем текущее время
        $current_hour = (int)current_time('G');
        if ($current_hour < 6 || $current_hour > 22) {
            $this->log("Уведомления не отправляются в ночное время ($current_hour:00)", 'info');
            return;
        }
        
        $this->log("Начало отправки уведомлений о расписании на завтра", 'info');
        
        $subscriptions = $this->get_active_subscriptions('notify_next_day');
        if (empty($subscriptions)) {
            $this->log("Нет активных подписок", 'info');
            return;
        }

        $schedule = $this->get_next_day_schedule();
        if (!$schedule) {
            $this->log("Не удалось получить расписание", 'error');
            return;
        }

        foreach ($subscriptions as $subscription) {
            try {
                // Проверяем время отправки для подписки
                $notification_time = null;
                if ($subscription['next_lesson_type'] === 'auto') {
                    // Получаем время из настроек админки
                    $notification_time = (int)get_option('schedule_next_day_time', '16');
                } else {
                    // Получаем пользовательское время
                    $time = strtotime($subscription['next_day_time']);
                    $notification_time = (int)date('G', $time);
                }

                // Если текущий час не совпадает с временем отправки - пропускаем
                if ($current_hour !== $notification_time) {
                    $this->log("Пропуск отправки - не время для уведомления (текущий час: $current_hour, время отправки: $notification_time)", 'debug');
                    continue;
                }

                // Создаем уникальный ключ для уведомления на завтра с учетом времени
                $next_day_key = sprintf(
                    'next_day_notification_%s_%s_%d',
                    $subscription['id'],
                    date('Y-m-d'),
                    $notification_time
                );

                // Проверяем, не было ли уже отправлено уведомление
                if (get_transient($next_day_key)) {
                    $this->log("Уведомление о расписании на завтра уже было отправлено для подписки {$subscription['id']}", 'debug');
                    continue;
                }

                $filtered_schedule = $this->get_filtered_schedule($schedule, $subscription);
                if ($filtered_schedule) {
                    $message = $this->format_day_schedule_message($filtered_schedule, $subscription);
                    if ($this->send_message($subscription['chat_id'], $message)) {
                        // Устанавливаем флаг отправки на 3 часа
                        set_transient($next_day_key, true, 3 * HOUR_IN_SECONDS);
                        $this->log("Уведомление отправлено для chat_id={$subscription['chat_id']}", 'info');
                    } else {
                        $this->log("Ошибка отправки для chat_id={$subscription['chat_id']}", 'error');
                    }
                }
            } catch (Exception $e) {
                $this->log("Ошибка при обработке подписки: " . $e->getMessage(), 'error');
            }
        }
    }

    public function send_schedule_changes() {
        $subscriptions = $this->get_active_subscriptions('notify_changes');
        $changes = $this->detect_schedule_changes();
        
        foreach ($subscriptions as $subscription) {
            $relevant_changes = $this->filter_changes_for_subscription($changes, $subscription);
            if ($relevant_changes) {
                $message = $this->format_changes_message($relevant_changes);
                $this->send_message($subscription['chat_id'], $message);
            }
        }
    }

    public function send_message($chat_id, $text, $inline_keyboard = null, $keyboard = null) {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        } elseif ($inline_keyboard !== null) {
            $params['reply_markup'] = json_encode($inline_keyboard);
        }

        return $this->send_telegram_request('sendMessage', $params);
    }

    private function update_notifications_count() {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'schedule_notifications';
            
            // Проверяем существование таблицы
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                // Создаем таблицу, если она не существует
                $charset_collate = $wpdb->get_charset_collate();
                $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    chat_id bigint(20) NOT NULL,
                    notification_type varchar(50) NOT NULL,
                    message text NOT NULL,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY chat_id (chat_id),
                    KEY notification_type (notification_type),
                    KEY created_at (created_at)
                ) $charset_collate;";
                
                
                dbDelta($sql);
            }
            
            // Добавляем запись об уведомлении
            $wpdb->insert(
                $table_name,
                [
                    'chat_id' => 0, // Общий счетчик
                    'notification_type' => 'system',
                    'message' => 'Notification sent'
                ],
                ['%d', '%s', '%s']
            );
            
            return true;
        } catch (Exception $e) {
            $this->log("Ошибка при обновлении счетчика уведомлений: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function get_bot_info() {
        $url = $this->api_url . $this->token . '/getMe';
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['ok'] ? $body['result'] : false;
    }

    public function get_webhook_info() {
        try {
            $url = $this->api_url . $this->token . '/getWebhookInfo';
            $response = wp_remote_get($url);
            
            if (is_wp_error($response)) {
                $this->log("Ошибка получения информации о webhook: " . $response->get_error_message(), 'error');
                return false;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!$data['ok']) {
                $this->log("Ошибка API при получении информации о webhook: " . ($data['description'] ?? 'Unknown error'), 'error');
                return false;
            }
            
            return $data['result'];
        } catch (Exception $e) {
            $this->log("Исключение при получении информации о webhook: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function test_api_connection() {
        $url = $this->api_url . $this->token . '/getMe';
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message()
            ];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return [
            'success' => $body['ok'],
            'message' => $body['ok'] ? 'Подключение успешно' : $body['description']
        ];
    }

    public function get_subscribers_count() {
        try {
            return (int)$this->db->get_var("
                SELECT COUNT(DISTINCT chat_id) 
                FROM {$this->db->prefix}schedule_subscriptions 
                WHERE notify_next_lesson = 1 OR notify_next_day = 1 OR notify_changes = 1
            ");
        } catch (Exception $e) {
            $this->log("Ошибка при получении количества подписчиков: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    public function get_notifications_count($period = 'day') {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications WHERE 1=1";
            
            switch($period) {
                case 'day':
                    $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                    break;
                case 'week':
                case 'month':
                    $sql .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                    break;
            }
            
            return (int)$this->db->get_var($sql);
        } catch (Exception $e) {
            $this->log("Ошибка при получении количества уведомлений: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    public function get_active_subscriptions_count() {
        try {
            return (int)$this->db->get_var("
                SELECT COUNT(*) 
                FROM {$this->db->prefix}schedule_subscriptions 
                WHERE notify_next_lesson = 1 OR notify_next_day = 1 OR notify_changes = 1
            ");
        } catch (Exception $e) {
            $this->log("Ошибка при получении количества активных подписок: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    public function delete_webhook() {
        $url = $this->api_url . $this->token . '/deleteWebhook';
        $response = wp_remote_post($url);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['ok'] ?? false;
    }

    private function send_welcome_photo($chat_id, $keyboard) {
        try {
            return $this->send_telegram_request('sendPhoto', [
                'chat_id' => $chat_id,
                'photo' => 'https://datatg.ru/wp-content/uploads/2025/03/AvaBotScheduleTG.png',
                'reply_markup' => json_encode($keyboard)
            ]);
        } catch (Exception $e) {
            $this->log("Ошибка при отправке приветственного изображения: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function send_welcome_message($chat_id) {
        try {
            // Проверяем наличие активных подписок
            $subscriptions = $this->get_user_subscriptions($chat_id);
            
            // Формируем основную клавиатуру (всегда будет доступна)
            $main_keyboard = [
                'keyboard' => [
                    [['text' => '📖 Текущий урок'], ['text' => '⏭️ Следующий урок']],
                    [['text' => '📅 Расписание на сегодня'], ['text' => '📅 Расписание на завтра']],
                    [['text' => '⏰ Текущий период'], ['text' => '🔍 Быстрый поиск']],
                    [['text' => '📋 Главное меню']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
                'persistent' => true // Делаем клавиатуру постоянной
            ];

            // Сначала отправляем основную клавиатуру с приветственным сообщением
            $welcome_text = "👋 *Добро пожаловать в бот расписания Старшей школы!*\n\n";
            $welcome_text .= "🤖 *Возможности бота:*\n";
            $welcome_text .= "• 🔔 Уведомления о текущих и следующих уроках\n";
            $welcome_text .= "• 📅 Расписание на сегодня и завтра\n"; 
            $welcome_text .= "• 📱 Удобный доступ к расписанию в любое время";

            // Отправляем первое сообщение с основной клавиатурой
            $this->send_message($chat_id, $welcome_text, null, $main_keyboard);

            // Формируем второе сообщение с inline-клавиатурой в зависимости от наличия подписок
            if (empty($subscriptions)) {
                $subscription_text = "\n🔸 Для начала работы подпишитесь на интересующие вас классы или учителей:";
                
                $inline_keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '👥 На класс', 'callback_data' => 'subscribe_type:class'],
                            ['text' => '👨‍🏫 На учителя', 'callback_data' => 'subscribe_type:teacher']
                        ],
                        [
                            ['text' => '❓ Показать справку', 'callback_data' => 'help']
                        ]
                    ]
                ];
            } else {
                $subscription_text = "\nДля управления подписками воспользуйтесь кнопками ниже:";
                
                $inline_keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '➕ Добавить подписку', 'callback_data' => 'add_subscription']
                        ],
                        [
                            ['text' => '⚙️ Управление подписками', 'callback_data' => 'my_subscriptions']
                        ],
                        [
                            ['text' => '❓ Показать справку', 'callback_data' => 'help']
                        ]
                    ]
                ];
            }
            
            // Отправляем второе сообщение с inline-клавиатурой
            return $this->send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $subscription_text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($inline_keyboard)
            ]);

        } catch (Exception $e) {
            $this->log("Ошибка в send_welcome_message: " . $e->getMessage(), 'error');
            return $this->send_message($chat_id, "❌ Произошла ошибка при отправке приветствия");
        }
    }

    private function handle_subscription_type($chat_id, $type) {
        $message = '';
        $keyboard = ['inline_keyboard' => []];

        if ($type === 'teacher') {
            $message = "👨‍🏫 *Выберите учителя из списка:*";
            $teachers = $this->get_teachers_list();
            
            // Сортируем список по фамилии
            uasort($teachers, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            // Разбиваем список учителей на пары для двух колонок
            $rows = [];
            $current_row = [];
            
            foreach ($teachers as $id => $teacher) {
                $current_row[] = [
                    'text' => $teacher['name'],
                    'callback_data' => "subscribe_entity:teacher:{$id}"
                ];
                
                // Когда в текущей строке два элемента, добавляем её в rows и начинаем новую
                if (count($current_row) === 2) {
                    $rows[] = $current_row;
                    $current_row = [];
                }
            }
            
            // Если остался один элемент, добавляем его с пустой кнопкой
            if (!empty($current_row)) {
                $current_row[] = [
                    'text' => ' ',
                    'callback_data' => 'none'
                ];
                $rows[] = $current_row;
            }
            
            // Добавляем строки в клавиатуру
            $keyboard['inline_keyboard'] = array_merge($keyboard['inline_keyboard'], $rows);
            
        } else if ($type === 'class') {
            $message = "👨‍🎓 *Выберите класс из списка:*";
            $classes = $this->get_classes_list();
            
            // Группируем классы по параллелям (10 11)
            $grouped = [
                '10' => [],
                '11' => []
            ];
            
            foreach ($classes as $id => $class) {
                preg_match('/^(\d+)/', $class['name'], $matches);
                $parallel = $matches[1];
                if (isset($grouped[$parallel])) {
                    $grouped[$parallel][] = [
                        'id' => $id,
                        'name' => $class['name']
                    ];
                }
            }

            // Сортируем классы внутри каждой параллели
            foreach ($grouped as &$parallelClasses) {
                usort($parallelClasses, function($a, $b) {
                    return strnatcmp($a['name'], $b['name']);
                });
            }

            // Находим максимальное количество классов в параллели
            $maxCount = max(count($grouped['10']), count($grouped['11']));

            // Создаем кнопки в два столбца
            $rows = [];

            // Добавляем 10-е классы в первый столбец
            foreach ($grouped['10'] as $class) {
                $rows[] = [
                    [
                        'text' => $class['name'],
                        'callback_data' => "subscribe_entity:class:{$class['id']}"
                    ],
                    // Добавляем пустую кнопку во второй столбец
                    [
                        'text' => ' ',
                        'callback_data' => 'none'
                    ]
                ];
            }

            // Добавляем 11-е классы во второй столбец
            foreach ($grouped['11'] as $index => $class) {
                if (isset($rows[$index])) {
                    // Если строка существует, заменяем пустую кнопку
                    $rows[$index][1] = [
                        'text' => $class['name'],
                        'callback_data' => "subscribe_entity:class:{$class['id']}"
                    ];
                } else {
                    // Если строки нет, создаем новую с пустой кнопкой слева
                    $rows[] = [
                        [
                            'text' => ' ',
                            'callback_data' => 'none'
                        ],
                        [
                            'text' => $class['name'],
                            'callback_data' => "subscribe_entity:class:{$class['id']}"
                        ]
                    ];
                }
            }

            // Добавляем строку в клавиатуру
            $keyboard['inline_keyboard'] = array_merge($keyboard['inline_keyboard'], $rows);
        }

        // Добавляем кнопку "Назад"
        $keyboard['inline_keyboard'][] = [[
            'text' => '↩️ Назад',
            'callback_data' => 'start'
        ]];

        $this->log("Отправка списка для типа: $type", 'info');
        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function create_subscription($chat_id, $action, $entity_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        
        // Определяем тип подписки из действия
        $subscription_type = str_replace('select_', '', $action);
        
        // Проверяем существование подписки
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE chat_id = %d AND subscription_type = %s AND entity_id = %d",
            $chat_id,
            $subscription_type,
            $entity_id
        ));
        
        if ($existing) {
            return $this->send_message($chat_id, "❌ У вас уже есть подписка на это расписание");
        }
        
        // Получаем имя сущности для сообщения
        $entity_name = '';
        switch ($subscription_type) {
            case 'class':
                $entity_name = $this->schedule['classes'][$entity_id]['name'] ?? 'Неизвестный класс';
                break;
            case 'teacher':
                $entity_name = $this->schedule['teachers'][$entity_id]['name'] ?? 'Неизвестный учитель';
                break;
            case 'period':
                foreach ($this->schedule['periods'] as $period) {
                    if ($period['id'] == $entity_id) {
                        $entity_name = "{$period['name']} урок ({$period['starttime']}-{$period['endtime']})";
                        break;
                    }
                }
                if (empty($entity_name)) {
                    $entity_name = 'Неизвестный период';
                }
                break;
        }
        
        // Подготавливаем данные для вставки
        $data = [
            'chat_id' => $chat_id,
            'subscription_type' => $subscription_type,
            'entity_id' => $entity_id,
            'created_at' => current_time('mysql'),
            'notify_next_lesson' => 0,
            'notify_next_day' => 0,
            'notify_changes' => 0,
            'notify_period_start' => 0,
            'notify_period_end' => 0,
            'next_lesson_type' => 'auto',
            'next_lesson_time' => 15,
            'next_day_time' => '16:00'
        ];
        
        // Для периодов включаем уведомления по умолчанию
        if ($subscription_type === 'period') {
            $data['notify_period_start'] = 1;
            $data['notify_period_end'] = 1;
        } else {
            // Для классов и учителей включаем стандартные уведомления
            $data['notify_next_lesson'] = 1;
            $data['notify_next_day'] = 1;
            $data['notify_changes'] = 1;
        }
        
        // Вставляем подписку
        $wpdb->insert($table_name, $data, [
            '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s'
        ]);
        
        if ($wpdb->last_error) {
            $this->log("Ошибка при создании подписки: " . $wpdb->last_error, 'error');
            return $this->send_message($chat_id, "❌ Произошла ошибка при создании подписки");
        }
        
        // Формируем сообщение об успешной подписке
        $message = "✅ *Подписка успешно создана*\n\n";
        
        if ($subscription_type === 'period') {
            $message .= "Период: *{$entity_name}*\n\n";
            $message .= "По умолчанию включены уведомления о начале и окончании урока.\n";
        } else {
            $message .= "Тип: *" . ($subscription_type === 'class' ? 'Класс' : 'Учитель') . "*\n";
            $message .= "Название: *{$entity_name}*\n\n";
            $message .= "По умолчанию включены все уведомления.\n";
        }
        
        $message .= "\nВы можете настроить параметры уведомлений в разделе «Мои подписки»";
        
        // Показываем сообщение и возвращаемся к списку подписок
        $this->send_message($chat_id, $message);
        return $this->show_my_subscriptions($chat_id);
    }

    private function show_next_lesson_options($chat_id, $subscription_id) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        $message = "*Настройка уведомлений о следующем уроке*\n\n";
        $message .= "Выберите, когда получать уведомления:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🔄 Автоматически (после окончания текущего урока)',
                        'callback_data' => "set_next_lesson:{$subscription_id}:auto"
                    ]
                ],
                [
                    [
                        'text' => '⏰ За 5 минут',
                        'callback_data' => "set_next_lesson:{$subscription_id}:custom:5"
                    ],
                    [
                        'text' => '⏰ За 15 минут',
                        'callback_data' => "set_next_lesson:{$subscription_id}:custom:15"
                    ]
                ],
                [
                    [
                        'text' => '🔕 Отключить',
                        'callback_data' => "set_next_lesson:{$subscription_id}:off"
                    ]
                ],
                [
                    [
                        'text' => '↩️ Назад',
                        'callback_data' => "manage_subscription:{$subscription_id}"
                    ]
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function show_next_day_options($chat_id, $subscription_id) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }

        $message = "*Настройка уведомлений о расписании на завтра*\n\n";
        $message .= "Выберите время получения уведомления:";

        // Получаем глобальное время
        $global_time = get_option('schedule_next_day_time', '16');
        
        // Создаем кнопки для каждого часа с 13:00 до 19:00
        $keyboard = ['inline_keyboard' => []];
        
        // Добавляем кнопку для автоматического режима
        $keyboard['inline_keyboard'][] = [
            [
                'text' => "🔄 Автоматически (в {$global_time}:00)",
                'callback_data' => "set_next_day:{$subscription_id}:auto"
            ]
        ];
        
        // Предустановленные варианты времени
        $times = [
            '13:00', '14:00', '15:00', '16:00', '17:00', '18:00'
        ];
        
        // Создаем ряды по 3 кнопки для пользовательского выбора времени
        $row = [];
        foreach ($times as $time) {
            $row[] = [
                'text' => $time,
                'callback_data' => "set_next_day:{$subscription_id}:{$time}" // Убираем префикс 'custom:'
            ];
            
            if (count($row) === 3) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        
        // Добавляем оставшиеся кнопки
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        
        // Добавляем кнопки управления
        $keyboard['inline_keyboard'][] = [
            [
                'text' => '🔕 Отключить уведомления',
                'callback_data' => "set_next_day:{$subscription_id}:off"
            ]
        ];
        $keyboard['inline_keyboard'][] = [
            [
                'text' => '↩️ Назад',
                'callback_data' => "manage_subscription:{$subscription_id}"
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function update_next_day_settings($chat_id, $subscription_id, $time) {
        try {
            // Отключаем любой вывод до отправки заголовков
            @ob_clean();
            
            // Проверяем, что это AJAX запрос
            if (wp_doing_ajax()) {
                // Устанавливаем заголовки для AJAX
                nocache_headers();
                header('Content-Type: application/json');
            }

            $subscription = $this->get_subscription_by_id($subscription_id);
            if (!$subscription || $subscription['chat_id'] != $chat_id) {
                return $this->send_message($chat_id, "❌ Подписка не найдена");
            }

            // Получаем имя сущности
            $entity_name = '';
            if ($subscription['subscription_type'] === 'class' && 
                isset($this->schedule['classes'][$subscription['entity_id']])) {
                $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
            } elseif ($subscription['subscription_type'] === 'teacher' && 
                      isset($this->schedule['teachers'][$subscription['entity_id']])) {
                $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
            }

            // Очищаем старый transient перед любым изменением
            $old_transient_key = sprintf(
                'next_day_notification_%s_%s_%d',
                $subscription_id,
                date('Y-m-d'),
                (int)date('G', strtotime($subscription['next_day_time']))
            );
            delete_transient($old_transient_key);

            if ($time === 'off') {
                // Отключаем уведомления
                $result = $this->db->update(
                    $this->db->prefix . 'schedule_subscriptions',
                    [
                        'notify_next_day' => 0,
                        'next_lesson_type' => 'custom'
                    ],
                    ['id' => $subscription_id],
                    ['%d', '%s'],
                    ['%d']
                );
                
                $message = "🔕 Уведомления о расписании на завтра отключены для {$entity_name}";
            } elseif ($time === 'auto') {
                // Устанавливаем автоматический режим
                $global_time = get_option('schedule_next_day_time', '16');
                $time_with_seconds = sprintf('%02d:00:00', $global_time);
                
                $result = $this->db->update(
                    $this->db->prefix . 'schedule_subscriptions',
                    [
                        'notify_next_day' => 1,
                        'next_lesson_type' => 'auto',
                        'next_day_time' => $time_with_seconds
                    ],
                    ['id' => $subscription_id],
                    ['%d', '%s', '%s'],
                    ['%d']
                );
                
                $message = "✅ *Настройки обновлены для {$entity_name}*\n\n";
                $message .= "Вы будете получать расписание на завтра автоматически в {$global_time}:00";
            } else {
                // Обрабатываем пользовательское время
                $time_with_seconds = $time . ':00';
                
                // Проверяем валидность времени
                $parsed_time = strtotime($time_with_seconds);
                if ($parsed_time === false) {
                    return $this->send_message($chat_id, "❌ Ошибка: некорректное время");
                }

                // Проверяем, что время находится в допустимом диапазоне (6:00 - 22:00)
                $hour = (int)date('G', $parsed_time);
                if ($hour < 6 || $hour > 22) {
                    return $this->send_message($chat_id, "❌ Время должно быть между 06:00 и 22:00");
                }

                $result = $this->db->update(
                    $this->db->prefix . 'schedule_subscriptions',
                    [
                        'notify_next_day' => 1,
                        'next_lesson_type' => 'custom',
                        'next_day_time' => $time_with_seconds
                    ],
                    ['id' => $subscription_id],
                    ['%d', '%s', '%s'],
                    ['%d']
                );

                $message = "✅ *Настройки обновлены для {$entity_name}*\n\n";
                $message .= "Вы будете получать расписание на завтра в {$time}";
            }

            if ($result === false) {
                $this->log("Ошибка SQL при обновлении настроек: " . $this->db->last_error, 'error');
                return $this->send_message($chat_id, "❌ Ошибка при обновлении настроек");
            }

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '↩️ К настройкам подписки', 'callback_data' => "manage_subscription:{$subscription_id}"]
                    ]
                ]
            ];

            return $this->send_message($chat_id, $message, $keyboard);
            
        } catch (Exception $e) {
            $this->log("Ошибка в update_next_day_settings: " . $e->getMessage(), 'error');
            return $this->send_message($chat_id, "❌ Произошла ошибка при обновлении настроек");
        }
    }

    private function show_subscriptions($chat_id) {
        // Получаем все подписки пользователя
        $subscriptions = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->db->prefix}schedule_subscriptions WHERE chat_id = %d",
            $chat_id
        ));

        if (empty($subscriptions)) {
            return $this->send_message(
                $chat_id, 
                "❌ *У вас нет активных подписок*\n\nИспользуйте команду /subscribe чтобы подписаться на расписание"
            );
        }

        $message = "📋 *Ваши подписки:*\n\n";
        $keyboard = ['inline_keyboard' => []];

        foreach ($subscriptions as $sub) {
            $entity_name = $this->get_entity_name($sub->subscription_type, $sub->entity_id);
            $type_icon = $sub->subscription_type === 'teacher' ? '👨‍🏫' : '👨‍🎓';
            
            // Формируем информацию о настройках уведомлений
            $next_lesson_status = $sub->notify_next_lesson ? '✅' : '❌';
            $next_day_status = $sub->notify_next_day ? '✅' : '❌';
            
            // Получаем время для уведомлений о расписании на завтра
            $next_day_time = '';
            if ($sub->notify_next_day) {
                if ($sub->next_lesson_type === 'auto') {
                    // Для автоматического режима берем время из настроек
                    $global_time = get_option('schedule_next_day_time', '16');
                    $next_day_time = sprintf(' в %02d:00', $global_time);
                } else {
                    // Для пользовательского режима берем установленное время
                    $time = date('H:i', strtotime($sub->next_day_time));
                    $next_day_time = " в $time";
                }
            }

            $message .= "{$type_icon} *{$entity_name}*\n";
            $message .= "├ Следующий урок: {$next_lesson_status}\n";
            $message .= "└ Расписание на завтра: {$next_day_status}{$next_day_time}\n\n";
            
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "⚙️ Настройка уведомлений для {$entity_name}",
                    'callback_data' => "manage_subscription:" . $sub->id
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "❌ Удалить подписку для {$entity_name}",
                    'callback_data' => "delete_subscription:" . $sub->id
                ]
            ];
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '↩️ Назад', 'callback_data' => 'settings']
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function get_teachers_list() {
        $schedule_file = get_option('schedule_active_file');
        if (!$schedule_file || !file_exists($schedule_file)) {
            return [];
        }

        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        $parser = new ScheduleParser();
        $result = $parser->parse($schedule_file);
        
        $teachers = $result['schedule']['teachers'] ?? [];
        
        // Фильтруем и форматируем список учителей
        $formatted = [];
        foreach ($teachers as $id => $teacher) {
            // Пропускаем пустые или некорректные записи
            if (empty($teacher['name'])) continue;
            
            // Форматируем ФИО
            $name = $this->format_teacher_name($teacher['name']);
            
            $formatted[$id] = [
                'name' => $name,
                'original' => $teacher['name']
            ];
        }
        
        return $formatted;
    }

    private function get_classes_list() {
        $schedule_file = get_option('schedule_active_file');
        if (!$schedule_file || !file_exists($schedule_file)) {
            return [];
        }

        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        $parser = new ScheduleParser();
        $result = $parser->parse($schedule_file);
        
        $classes = $result['schedule']['classes'] ?? [];
        
        // Фильтруем и форматируем спсок классов
        $formatted = [];
        foreach ($classes as $id => $class) {
            // Пропускаем пустые или некорректные записи
            if (empty($class['name'])) continue;
            
            $formatted[$id] = [
                'name' => $class['name'],
                'parallel' => (int)$class['name']
            ];
        }
        
        return $formatted;
    }

    private function format_teacher_name($fullName) {
        // Разбиваем ФИО на части
        $parts = explode(' ', trim($fullName));
        
        if (count($parts) >= 2) {
            // Фамилия поностью, остальные - инициалы
            $lastName = $parts[0];
            $initials = array_map(function($part) {
                return mb_substr($part, 0, 1) . '.';
            }, array_slice($parts, 1));
            
            return $lastName . ' ' . implode('', $initials);
        }
        
        return $fullName;
    }

    private function send_help_message($chat_id) {
        $message = "*📚 Справка по командам бота:*\n\n";
        $message .= "*Основные команды:*\n";
        $message .= "*/start* - Открыть главное меню\n";
        $message .= "*/current* - Информация о текущем уроке\n";
        $message .= "*/next* - Информация о следующем уроке\n";
        $message .= "*/today* - Расписание на сегодня\n";
        $message .= "*/tomorrow* - Расписание на завтра\n\n";
        
        $message .= "*Дополнительные команды:*\n";
        $message .= "*/period* - Информация о текущем номере урока или перемене\n";
        $message .= "*/search* - Просмотр расписания для любого класса/учителя без подписки\n\n";
        
        $message .= "*Управление подписками:*\n";
        $message .= "*/subscribe* - Добавить новую подписку\n";
        $message .= "*/settings* - Управление существующими подписками\n\n";
        
        $message .= "*Дополнительно:*\n";
        $message .= "• Используйте кнопки быстрого доступа для основных команд\n";
        $message .= "• В главном меню доступны кнопки для управления подписками\n";
        $message .= "• Вы можете подписаться на уведомления о расписании для классов и учителей\n\n";
        
        $message .= "Для возврата в главное меню используйте команду /start";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📋 Открыть главное меню', 'callback_data' => 'start']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    // Обновляем метод get_current_lesson
    private function get_current_lesson($subscription) {
        // Загружаем рсписание, если оно еще не загружено
        if (!$this->schedule) {
            $this->schedule = $this->get_current_schedule();
        }
        
        if (empty($this->schedule) || empty($this->schedule['periods']) || empty($this->schedule['lessons'])) {
            $this->log("Расписание пусто или некорректно", 'error');
            return null;
        }

        $current_time = current_time('H:i');
        $current_day = (int)current_time('N');
        $current = strtotime($current_time);

        $this->log("Поиск текущего урока: время $current_time, день $current_day", 'debug');

        // Получаем все уроки на текущий день
        $today_lessons = array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
            return (int)$lesson['dayId'] === $current_day;
        });

        // Фильтруем уроки по подписке
        $relevant_lessons = array_filter($today_lessons, function($lesson) use ($subscription) {
            return $this->is_relevant_lesson($lesson, $subscription);
        });

        if (empty($relevant_lessons)) {
            $this->log("Нет пдходящих уроков для подписки", 'debug');
            return null;
        }

        // Сортируем уроки по времени начала
        usort($relevant_lessons, function($a, $b) {
            $period_a = $this->schedule['periods'][$a['periodId']];
            $period_b = $this->schedule['periods'][$b['periodId']];
            return strtotime($period_a['starttime']) - strtotime($period_b['starttime']);
        });

        foreach ($relevant_lessons as $lesson) {
            $period = $this->schedule['periods'][$lesson['periodId']];
            $start_time = strtotime($period['starttime']);
            $end_time = strtotime($period['endtime']);
            
            if ($current >= $start_time && $current <= $end_time) {
                $this->log("Найден текущий урок: " . print_r($lesson, true), 'debug');
                return [
                    'lesson' => $lesson,
                    'period' => $period,
                    'minutes_passed' => floor(($current - $start_time) / 60),
                    'minutes_left' => ceil(($end_time - $current) / 60),
                    'subscription' => $subscription // Добавляем информацию о подписке
                ];
            }
        }

        $this->log("Текущий урок не найден", 'debug');
        return null;
    }

    private function get_next_lesson($subscription) {
        $this->log("Получение следующего урока для подписки ID={$subscription['id']}", 'debug');
        
        if (!$this->schedule) {
            $this->schedule = $this->get_current_schedule();
        }
        
        if (empty($this->schedule) || empty($this->schedule['periods']) || empty($this->schedule['lessons'])) {
            $this->log("Расписание пусто или некорректно", 'error');
            return null;
        }

        $current_time = current_time('H:i');
        $current_day = (int)current_time('N');
        $current = strtotime($current_time);

        if ($current_day > 5) {
            $this->log("Сегодня выходной (день {$current_day})", 'info');
            return null;
        }

        // Получаем все уроки на текущий день
        $today_lessons = array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
            return (int)$lesson['dayId'] === $current_day;
        });

        // Фильтруем уроки по подписке
        $relevant_lessons = array_filter($today_lessons, function($lesson) use ($subscription) {
            if ($subscription['subscription_type'] === 'teacher') {
                return in_array($subscription['entity_id'], $lesson['teacherIds']);
            } else { // class
                return in_array($subscription['entity_id'], $lesson['classIds']);
            }
        });

        if (empty($relevant_lessons)) {
            return null;
        }

        // Сортируем уроки по времени начала
        usort($relevant_lessons, function($a, $b) {
            $period_a = $this->schedule['periods'][$a['periodId']];
            $period_b = $this->schedule['periods'][$b['periodId']];
            return strtotime($period_a['starttime']) - strtotime($period_b['starttime']);
        });

        foreach ($relevant_lessons as $lesson) {
            $period = $this->schedule['periods'][$lesson['periodId']];
            $start_time = strtotime($period['starttime']);
            
            if ($start_time > $current) {
                $minutes_until = ceil(($start_time - $current) / 60);
                return [
                    'lesson' => $lesson,
                    'period' => $period,
                    'minutes_until' => $minutes_until,
                    'is_next' => true,
                    'subscription' => $subscription
                ];
            }
        }

        return null;
    }

    private function get_next_day_schedule() {
        try {
            if (!$this->schedule) {
                $this->schedule = $this->get_current_schedule();
            }
            
            if (!$this->schedule) {
                $this->log("Не удалось получить расписание", 'error');
                return null;
            }

            // Фильтруем уроки только для завтрашнего дня
            $tomorrow_day = (int)date('N', strtotime('tomorrow'));
            
            $filtered_schedule = $this->schedule;
            $filtered_schedule['lessons'] = array_filter($this->schedule['lessons'], function($lesson) use ($tomorrow_day) {
                return isset($lesson['dayId']) && (int)$lesson['dayId'] === $tomorrow_day;
            });

            if (empty($filtered_schedule['lessons'])) {
                $this->log("Нет уроков на завтра", 'debug');
                return null;
            }

            return $filtered_schedule;

        } catch (Exception $e) {
            $this->log("Ошибка в get_next_day_schedule: " . $e->getMessage(), 'error');
            return null;
        }
    }

    private function get_minutes_until($time) {
        $now = new DateTime();
        $target = DateTime::createFromFormat('H:i', $time);
        if ($target < $now) {
            $target->modify('+1 day');
        }
        $interval = $now->diff($target);
        return ($interval->h * 60) + $interval->i;
    }

    public function get_logs($limit = 100) {
        $log_file = SCHOOL_SCHEDULE_PATH . 'logs/telegram-bot.log';
        if (!file_exists($log_file)) {
            return [];
        }

        $logs = array_filter(array_map('trim', file($log_file)));
        $logs = array_reverse($logs); // Последние записи первыми
        return array_slice($logs, 0, $limit);
    }

    public function clear_logs() {
        $log_file = SCHOOL_SCHEDULE_PATH . 'logs/telegram-bot.log';
        return file_put_contents($log_file, '');
    }

    public function log($message, $type = 'info') {
        try {
            $log_dir = SCHOOL_SCHEDULE_PATH . 'logs';
            if (!file_exists($log_dir)) {
                if (!mkdir($log_dir, 0755, true)) {
                    error_log("Failed to create log directory: $log_dir");
                    return false;
                }
            }

            if (!is_writable($log_dir)) {
                error_log("Log directory is not writable: $log_dir");
                return false;
            }

            $log_file = $log_dir . '/telegram-bot.log';
            $date = date('Y-m-d H:i:s');
            $log_entry = "[$date][$type] $message\n";
            
            return file_put_contents($log_file, $log_entry, FILE_APPEND);
        } catch (Exception $e) {
            error_log("Error writing to log: " . $e->getMessage());
            return false;
        }
    }

    private function set_next_lesson_notification($chat_id, $type, $time = null) {
        // Обновляем настройки в БД
        $update = [
            'notify_next_lesson' => 1,
            'next_lesson_type' => $type
        ];
        
        if ($time !== null) {
            $update['next_lesson_time'] = $time;
        }

        $this->db->update(
            $this->db->prefix . 'schedule_subscriptions',
            $update,
            ['chat_id' => $chat_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        $message = "✅ *Настройки обновлены*\n\n";
        switch ($type) {
            case 'auto':
                $message .= "Вы будете получать уведомления:\n";
                $message .= "• После окончания текущего урока\n";
                $message .= "• За 10 минут до начала первого урока";
                break;
            case 'custom':
                $message .= "Вы буете получать уведомления за {$time} минут до начала урока";
                break;
            case 'off':
                $message .= "Уведомления о следующем уроке отключены";
                break;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '↩️ К настройкам', 'callback_data' => 'settings']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function set_next_day_notification($chat_id, $time) {
        if ($time === 'off') {
            $this->db->update(
                $this->db->prefix . 'schedule_subscriptions',
                ['notify_next_day' => 0],
                ['chat_id' => $chat_id],
                ['%d'],
                ['%d']
            );

            $message = "❌ Уведомления о расписании на завтра отключены";
        } else {
            $this->db->update(
                $this->db->prefix . 'schedule_subscriptions',
                [
                    'notify_next_day' => 1,
                    'next_day_time' => $time
                ],
                ['chat_id' => $chat_id],
                ['%d', '%s'],
                ['%d']
            );

            $message = "✅ *Настройки обновлены*\n\n";
            $message .= "Вы удете получать расписание на завтра в {$time}";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '↩️ К настройкам', 'callback_data' => 'settings']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function unsubscribe_user($chat_id, $subscription_id) {
        // Получаем информацию о подписке перед удалением
        $subscription = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->db->prefix}schedule_subscriptions 
            WHERE id = %d AND chat_id = %d",
            $subscription_id, $chat_id
        ));

        if (!$subscription) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }

        // Удаляем подписку
        $this->db->delete(
            $this->db->prefix . 'schedule_subscriptions',
            [
                'id' => $subscription_id,
                'chat_id' => $chat_id
            ],
            ['%d', '%d']
        );

        $entity_name = $this->get_entity_name($subscription->subscription_type, $subscription->entity_id);
        $message = "✅ *Подписка отменена*\n\n";
        $message .= "Вы отписались от расписания: {$entity_name}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Новая подписка', 'callback_data' => 'start']
                ],
                [
                    ['text' => '↩️ К подпискам', 'callback_data' => 'toggle_notification:subscriptions']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function get_entity_name($type, $id) {
        if ($type === 'teacher') {
            $teachers = $this->get_teachers_list();
            return $teachers[$id]['name'] ?? 'Неизвестный учитель';
        } else {
            $classes = $this->get_classes_list();
            return $classes[$id]['name'] ?? 'Неизвестный класс';
        }
    }

    public function get_active_subscriptions($notify_field = null) {
        global $wpdb;
        try {
            $sql = "SELECT * FROM {$wpdb->prefix}schedule_subscriptions WHERE 1=1";
            
            if ($notify_field) {
                // Если укаано конкретное поле уведомления
                // Убираем кавычки вокруг имени пля
                $sql .= " AND $notify_field = 1";
            } else {
                // Если поле не указано, проверяем все типы уведомлений
                $sql .= " AND (notify_next_lesson = 1 OR notify_next_day = 1 OR notify_changes = 1)";
            }

            $this->log("SQL запрос для получения подписок: $sql", 'debug');
            
            $subscriptions = $wpdb->get_results($sql, ARRAY_A);
            
            $this->log("Найдено подписок: " . count($subscriptions), 'debug');
            
            if (empty($subscriptions)) {
                $this->log("Активные подписки не найдены" . ($notify_field ? " для поля $notify_field" : ""), 'info');
                return [];
            }

            return $subscriptions;

        } catch (Exception $e) {
            $this->log("Ошибка при получении подписок: " . $e->getMessage(), 'error');
            return [];
        }
    }

    private function save_subscription($chat_id, $type, $entity_id) {
        global $wpdb;
        try {
            // Проверяем входные данные
            if (empty($chat_id) || empty($type) || empty($entity_id)) {
                $this->log("Ошибка сохранения подписки: пустые входные данные", 'error');
                return false;
            }

            $this->log("Попытка сохранения подписки: chat_id=$chat_id, type=$type, entity_id=$entity_id", 'info');

            // Проверяем существующую подписку
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}schedule_subscriptions 
                WHERE chat_id = %d AND subscription_type = %s AND entity_id = %s",
                $chat_id, $type, $entity_id
            ));

            if ($existing) {
                // Обновляем существующую подписку
                $result = $wpdb->update(
                    $wpdb->prefix . 'schedule_subscriptions',
                    [
                        'notify_next_lesson' => 1,
                        'notify_next_day' => 1,
                        'notify_changes' => 1
                    ],
                    [
                        'chat_id' => $chat_id,
                        'subscription_type' => $type,
                        'entity_id' => $entity_id
                    ],
                    ['%d', '%d', '%d'],
                    ['%d', '%s', '%s']
                );
            } else {
                // Создаем новую подписку
                $result = $wpdb->insert(
                    $wpdb->prefix . 'schedule_subscriptions',
                    [
                        'chat_id' => $chat_id,
                        'subscription_type' => $type,
                        'entity_id' => $entity_id,
                        'notify_next_lesson' => 1,
                        'notify_next_day' => 1,
                        'notify_changes' => 1,
                        'next_lesson_type' => 'auto',
                        'next_lesson_time' => 10,
                        'next_day_time' => '16:00:00'
                    ],
                    ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s']
                );
            }

            if ($result === false) {
                $this->log("Ошибка SQL при сохранении подписки: " . $wpdb->last_error, 'error');
                return false;
            }

            $this->log("Подписка успешно " . ($existing ? "обновлена" : "создана"), 'info');
            return true;

        } catch (Exception $e) {
            $this->log("Исключение при сохранении подписки: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function check_subscription_exists($chat_id, $type, $entity_id) {
        global $wpdb;
        try {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$wpdb->prefix}schedule_subscriptions 
                WHERE chat_id = %d 
                AND subscription_type = %s 
                AND entity_id = %s 
                AND active = 1",
                $chat_id,
                $type,
                $entity_id
            ));

            return (int)$exists > 0;

        } catch (Exception $e) {
            $this->log("Ошибка проверки существования подписки: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function delete_subscription($subscription_id) {
        global $wpdb;
        try {
            $result = $wpdb->update(
                $wpdb->prefix . 'schedule_subscriptions',
                ['active' => 0],
                ['id' => $subscription_id],
                ['%d'],
                ['%d']
            );

            if ($result === false) {
                $this->log("Ошибка SQL при удалении подписки: " . $wpdb->last_error, 'error');
                return false;
            }

            $this->log("Деактивирована подписка ID=$subscription_id", 'info');
            return true;

        } catch (Exception $e) {
            $this->log("Ошибка при удалении подписки: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // Вспомогательные методы...

    private function get_current_schedule() {
        $this->log("Получение текущего расписания", 'info');
        
        $schedule_file = get_option('schedule_active_file');
        if (!$schedule_file || !file_exists($schedule_file)) {
            $this->log("Файл расписания не найден: {$schedule_file}", 'error');
            return null;
        }

        try {
            require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
            $parser = new ScheduleParser();
            $this->log("Начало парсинга файла расписания: {$schedule_file}", 'debug');
            $result = $parser->parse($schedule_file, true); // Включаем буферизацию для бота
            
            if (!$result || !isset($result['schedule'])) {
                $this->log("Ошибка парсинга расписания: результат пуст или некорректен", 'error');
                return null;
            }
            
            $this->log("Расписание успешно загружено", 'info');
            return $result['schedule'] ?? null;
        } catch (Exception $e) {
            $this->log("Ошибка при парсинге расписания: " . $e->getMessage(), 'error');
            return null;
        }
    }

    public function get_filtered_schedule($schedule, $subscription) {
        if (empty($schedule) || empty($subscription)) {
            return null;
        }

        // Фильтруем уроки по подиске
        $filtered_lessons = array_filter($schedule['lessons'], function($lesson) use ($subscription) {
            return $this->is_relevant_lesson($lesson, $subscription);
        });

        if (empty($filtered_lessons)) {
            return null;
        }

        return [
            'lessons' => $filtered_lessons,
            'periods' => $schedule['periods']
        ];
    }

    public function test_notifications() {
        $this->log("Запуск тестовой отправки уведомлений", 'info');
        $this->send_next_lesson_notifications();
        $this->send_next_day_notifications();
        return true;
    }

    public function check_cron_status() {
        $this->log("Проверка статуса CRON", 'info');
        
        // Проверяем, включен ли WP-Cron
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->log("WP-Cron отключен в настройках WordPress", 'error');
            return false;
        }

        // Получаем все запанированные события
        $cron = _get_cron_array();
        $this->log("Текущие CRON задачи: " . print_r($cron, true), 'debug');

        // Проверяем следующие запланированные события
        $next_lesson = wp_next_scheduled('schedule_next_lesson_notification');
        $next_day = wp_next_scheduled('schedule_next_day_notification');

        $this->log("Следующее уведомление об уроке запланировано на: " . 
            ($next_lesson ? date('Y-m-d H:i:s', $next_lesson) : 'не запланировано'), 'info');
        $this->log("Следующее уведомление о расписании запланировано на: " . 
            ($next_day ? date('Y-m-d H:i:s', $next_day) : 'не запанировано'), 'info');

        if (!$next_lesson || !$next_day) {
            $this->log("Не се уведомления запланированы", 'error');
            
            // Пробуем перепланировать задачи
            if (!$next_lesson) {
                wp_schedule_event(time(), 'every_five_minutes', 'schedule_next_lesson_notification');
                $this->log("Попытка переланировать увдомления о следующем уроке", 'info');
            }
            if (!$next_day) {
                $timestamp = strtotime('today 20:00');
                if (time() > $timestamp) {
                    $timestamp = strtotime('tomorrow 20:00');
                }
                wp_schedule_event($timestamp, 'daily', 'schedule_next_day_notification');
                $this->log("Попытка перепланировать уведомления о расписании на завтра", 'info');
            }
            return false;
        }

        $this->log("CRON работает нормально", 'info');
        return true;
    }

    public function ajax_send_message() {
        check_ajax_referer('send_telegram_message', 'nonce');
        
        if (!current_user_can('manage_options')) {
            $this->log("Попытка отправки без прав администратора", 'error');
            wp_send_json_error('Недостаточно прав');
        }
        
        $chat_id = sanitize_text_field($_POST['chat_id']);
        $message = sanitize_textarea_field($_POST['message']);
        
        if (empty($message)) {
            $this->log("устой текст сообщения", 'error');
            wp_send_json_error('е указан текст сообщения');
        }

        // Если chat_id = 'all', отправляем всем подписчикам
        if ($chat_id === 'all') {
            return $this->send_broadcast_message($message);
        }

        // Проверяем формат chat_id для индивидуальной отправки
        if (strpos($chat_id, '@') === 0) {
            $username = substr($chat_id, 1);
            $this->log("Получение chat_id для username: @$username", 'info');
            $chat_id = $this->get_chat_id_by_username($username);
            if (!$chat_id) {
                $this->log("Не удалось найти пользователя @$username в базе данных", 'error');
                wp_send_json_error('Пользователь должен начать диалог с ботом, прежде чем вы сможете отправить ему сообщение');
            }
        } elseif (!is_numeric($chat_id)) {
            $this->log("Некорректный формат chat_id: $chat_id", 'error');
            wp_send_json_error('ID чата должен быть числом ии начинаться с @');
        }
        
        $result = $this->send_message($chat_id, $message);
        
        if ($result) {
            $this->log("Сообщение успешно отправлено", 'info');
            wp_send_json_success('Сообщение отправлен');
        } else {
            $this->log("Ошибка отправки собщения", 'error');
            wp_send_json_error('Не удалось отправить сообщение. Проверьте логи для подробностей.');
        }
    }

    private function get_chat_id_by_username($username) {
        // Сначала ищем в базе данных
        $user = $this->db->get_row($this->db->prepare(
            "SELECT chat_id FROM {$this->db->prefix}schedule_users 
            WHERE username = %s",
            $username
        ));

        if ($user) {
            $this->log("Найден chat_id в базе данных для @$username: {$user->chat_id}", 'info');
            return $user->chat_id;
        }

        $this->log("Username @$username не найден в базе данных", 'info');
        
        // Если не нашли в базе, возвращаем false
        return false;
    }

    private function save_user_info($chat_info) {
        try {
            $chat_id = $chat_info['id'];
            $username = $chat_info['username'] ?? null;
            $first_name = $chat_info['first_name'] ?? null;
            $last_name = $chat_info['last_name'] ?? null;

            // Обновляем или создаем запись в таблице users
            $this->db->replace(
                $this->db->prefix . 'schedule_users',
                [
                    'chat_id' => $chat_id,
                    'username' => $username,
                    'first_name' => $first_name,
                    'last_name' => $last_name
                ],
                ['%d', '%s', '%s', '%s']
            );

            // Обновляем username во всех подписках пользователя
            if ($username !== null) {
                $this->db->update(
                    $this->db->prefix . 'schedule_subscriptions',
                    ['username' => $username],
                    ['chat_id' => $chat_id],
                    ['%s'],
                    ['%d']
                );
            }

            return true;
        } catch (Exception $e) {
            $this->log("Ошибка при сохранении информации о пользователе: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function send_broadcast_message($message) {
        $this->log("Начало массовой рассылки", 'info');
        
        // Получаем всех активных пользователей
        $users = $this->db->get_results("
            SELECT DISTINCT chat_id 
            FROM {$this->db->prefix}schedule_subscriptions 
            WHERE chat_id IS NOT NULL
        ", ARRAY_A);

        if (empty($users)) {
            $this->log("Нет активных пользователей для рассылки", 'error');
            wp_send_json_error('Нет актвных пользователей для рассылки');
            return;
        }

        $success_count = 0;
        $fail_count = 0;

        foreach ($users as $user) {
            $chat_id = $user['chat_id'];
            $result = $this->send_message($chat_id, $message);
            
            if ($result) {
                $success_count++;
                $this->log("Сообщение отправлено пользователю $chat_id", 'info');
            } else {
                $fail_count++;
                $this->log("Ошибка отправки сообщения пользователю $chat_id", 'error');
            }

            // Небольшая задержка между отправками
            usleep(100000); // 100ms
        }

        $total = count($users);
        $response = "Массовая рассылка завершена:\n";
        $response .= "Всего получателей: $total\n";
        $response .= "Успешно отправлено: $success_count\n";
        $response .= "Ошибок отправки: $fail_count";

        $this->log($response, 'info');
        wp_send_json_success($response);
    }

    public function send_next_lesson_notifications_for_user($subscription) {
        try {
            $lesson_info = $this->get_next_lesson_info($subscription);
            
            if (!$lesson_info) {
                $this->log("Нет информации о следующем уроке для chat_id={$subscription['chat_id']}", 'info');
                return false;
            }

            $message = $this->format_next_lesson_message($lesson_info);
            $result = $this->send_message($subscription['chat_id'], $message);
            
            if ($result) {
                $this->save_notification($subscription['chat_id'], 'next_lesson', $message, 'auto');
                $this->log("Уведомление о следующем уроке отправлено для chat_id={$subscription['chat_id']}", 'info');
            } else {
                $this->log("Ошибка отправки уведомления для chat_id={$subscription['chat_id']}", 'error');
            }

            return $result;
        } catch (Exception $e) {
            $this->log("Ошибка при отправке уведомления о следующем уроке: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function get_next_lesson_info($subscription) {
        try {
            $next_lesson = $this->get_next_lesson($subscription);
            
            if (!$next_lesson) {
                $this->log("Следующий урок не найден", 'info');
                return null;
            }

            // Проверяем наличие всех необходимых полей
            $required_fields = ['subjectId', 'teacherIds', 'classroomId', 'startTime', 'endTime', 'minutes_until'];
            foreach ($required_fields as $field) {
                if (!isset($next_lesson[$field])) {
                    $this->log("Отсутствует обязательное поле {$field} в данных урока", 'error');
                    $this->log("Данные урока: " . print_r($next_lesson, true), 'debug');
                    return null;
                }
            }

            // Проверяем наличие данных в schedule
            if (!isset($this->schedule['subjects'][$next_lesson['subjectId']])) {
                $this->log("Предмет не найден: {$next_lesson['subjectId']}", 'error');
                return null;
            }

            // Получаем информацию о предмете
            $subject_name = $this->schedule['subjects'][$next_lesson['subjectId']]['name'] ?? 'Неизвестный предмет';
            
            // Проверяем массив teacherIds
            if (!is_array($next_lesson['teacherIds'])) {
                $this->log("teacherIds не является массивом", 'error');
                return null;
            }

            // Получаем информацию об учителях
            $teachers = array_map(function($tid) {
                return $this->schedule['teachers'][$tid]['name'] ?? 'Неизвестный учитель';
            }, $next_lesson['teacherIds']);
            
            // Получаем информацию о кабинете
            $classroom = $this->schedule['classrooms'][$next_lesson['classroomId']]['name'] ?? 'Кабинет не указан';
            
            // Проверяем и форматируем время
            try {
                $start_time = date('H:i', strtotime($next_lesson['startTime']));
                $end_time = date('H:i', strtotime($next_lesson['endTime']));
            } catch (Exception $e) {
                $this->log("Ошибка форматирования времени: " . $e->getMessage(), 'error');
                return null;
            }
            
            return [
                'subject' => $subject_name,
                'teachers' => $teachers,
                'classroom' => $classroom,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'minutes_until' => $next_lesson['minutes_until']
            ];

        } catch (Exception $e) {
            $this->log("Ошибка при получении информации о следующем уроке: " . $e->getMessage(), 'error');
            $this->log("Stack trace: " . $e->getTraceAsString(), 'error');
            return null;
        }
    }

    private function format_end_lesson_message($lesson_info) {
        $current = $lesson_info['lesson'];
        $period = $lesson_info['period'];
        $next = isset($lesson_info['next_lesson']) ? $lesson_info['next_lesson'] : null;

        $message = "*🔔 Текущий урок заканчивается!*\n\n";
        $message .= sprintf(
            "Урок: %s\nКабинет: %s\nВремя: %s - %s\n",
            $this->schedule['subjects'][$current['subjectId']]['name'],
            $this->schedule['classrooms'][$current['classroomId']]['name'],
            $period['starttime'],
            $period['endtime']
        );

        if ($next) {
            $next_period = $next['period'];
            $message .= "\n*Следующий урок:*\n";
            $message .= sprintf(
                "%s\nКабинет: %s\nНачало: %s\n",
                $this->schedule['subjects'][$next['lesson']['subjectId']]['name'],
                $this->schedule['classrooms'][$next['lesson']['classroomId']]['name'],
                $next_period['starttime']
            );
        }

        return $message;
    }

    private function format_first_lesson_message($lesson_info) {
        $lesson = $lesson_info['lesson'];
        $period = $lesson_info['period'];

        $message = "*🌅 Первый урок через " . $lesson_info['minutes_until'] . " минут!*\n\n";
        $message .= sprintf(
            "Предмет: %s\nКабинет: %s\nНачало: %s\n",
            $this->schedule['subjects'][$lesson['subjectId']]['name'],
            $this->schedule['classrooms'][$lesson['classroomId']]['name'],
            $period['starttime']
        );

        return $message;
    }

    public function is_relevant_lesson($lesson, $subscription) {
        // Проверяем тип подписк
        switch ($subscription['subscription_type']) {
            case 'teacher':
                // Проверяем, ведет ли учитель тот урок
                return in_array($subscription['entity_id'], $lesson['teacherIds']);
                
            case 'class':
                // Проверяем, учится ли клас на этом уроке
                return in_array($subscription['entity_id'], $lesson['classIds']);
                
            default:
                return false;
        }
    }

    public function handle_test_notification($type) {
        $this->log("Запуск тестового уведомления типа: $type", 'info');
        
        $result = [];
        // Преобразуем тип уведомления для получения подписок
        $notify_type = $type;
        if ($type === 'tomorrow' || $type === 'today') {
            $notify_type = 'next_day';
        }
        $subscriptions = $this->get_active_subscriptions("notify_" . $notify_type);
        
        foreach ($subscriptions as $subscription) {
            try {
                if ($type === 'next_lesson') {
                    $this->send_next_lesson_notifications_for_user($subscription);
                } elseif ($type === 'tomorrow' || $type === 'next_day') {
                    $this->send_next_day_notifications_for_user($subscription);
                } elseif ($type === 'today') {
                    $this->send_today_schedule_for_user($subscription);
                } else {
                    throw new Exception("Неизвестный тип уведомления: $type");
                }
                $result[] = "✅ Отправлено уведомление для chat_id={$subscription['chat_id']}";
            } catch (Exception $e) {
                $result[] = "❌ Ошибка для chat_id={$subscription['chat_id']}: {$e->getMessage()}";
            }
        }
        
        if (empty($result)) {
            return "Нет активных подписок для тестирования";
        }
        
        return implode("\n", $result);
    }

    private function send_today_schedule_for_user($subscription) {
        if (!$this->schedule) {
            $this->schedule = $this->get_current_schedule();
        }

        if (!$this->schedule) {
            throw new Exception("Не удалось получить расписание");
        }

        // Фильтруем расписание только для текущего дня
        $current_day = (int)date('N');
        $today_schedule = [
            'periods' => $this->schedule['periods'],
            'lessons' => array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
                return isset($lesson['dayId']) && (int)$lesson['dayId'] === $current_day;
            })
        ];

        // Фильтруем расписание для конкретной подписки
        $filtered_schedule = $this->get_filtered_schedule($today_schedule, $subscription);

        // Получаем информацию о подписке для сообщения
        $entity_name = '';
        if ($subscription['subscription_type'] === 'class' && 
            isset($this->schedule['classes'][$subscription['entity_id']])) {
            $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
        } elseif ($subscription['subscription_type'] === 'teacher' && 
                  isset($this->schedule['teachers'][$subscription['entity_id']])) {
            $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
        }

        if (empty($filtered_schedule['lessons'])) {
            $message = "*📅 Расписание на сегодня • " . $this->get_weekday_name($current_day) . "*\n";
            if ($entity_name) {
                $message .= ($subscription['subscription_type'] === 'class' ? "👥" : "👨‍🏫") . " *{$entity_name}*\n";
            }
            $message .= "\n🔍 Сегодня нет уроков";
        } else {
            $message = $this->format_day_schedule_message($filtered_schedule, $subscription, true);
        }

        if ($this->send_message($subscription['chat_id'], $message)) {
            $this->save_notification($subscription['chat_id'], 'today', $message, 'auto');
            return true;
        }

        return false;
    }

    private function format_current_lesson_message($lesson_info) {
        try {
            // Получаем информацию о подписке
            $subscription = $lesson_info['subscription'] ?? null;
            $entity_name = '';
            if ($subscription) {
                if (!$this->schedule) {
                    $this->schedule = $this->get_current_schedule();
                }
                
                if ($subscription['subscription_type'] === 'class' && 
                    isset($this->schedule['classes']) && 
                    isset($this->schedule['classes'][$subscription['entity_id']])) {
                    $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
                } elseif ($subscription['subscription_type'] === 'teacher' && 
                          isset($this->schedule['teachers']) && 
                          isset($this->schedule['teachers'][$subscription['entity_id']])) {
                    $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
                }
            }

            // Если нет информации об уроке
            if (empty($lesson_info['lesson'])) {
                // ... существующий код для обработки перемены ...
                return "*Текущего урока нет!*";
            }

            // Получаем период
            $period = isset($lesson_info['period']) ? $lesson_info['period'] : null;
            if (!$period) {
                throw new Exception("Не найдена информация о периоде урока");
            }

            // Формируем сообщение
            $message = sprintf("*Сейчас %s урок, ", $period['name'] ?? 'Неизвестный');
            if (isset($lesson_info['minutes_left'])) {
                $message .= sprintf("осталось %d мин:*\n", $lesson_info['minutes_left']);
            } else {
                $message .= ":*\n";
            }
            $message .= sprintf("⏰ %s - %s\n\n",
                $period['starttime'] ?? '--:--',
                $period['endtime'] ?? '--:--'
            );

            // Добавляем информацию о предмете и учителе
            if (isset($lesson_info['lesson'])) {
                $lesson = $lesson_info['lesson'];
                
                // Предмет
                if (isset($this->schedule['subjects'][$lesson['subjectId']]['name'])) {
                    $message .= "📖 *Предмет:* " . 
                        $this->schedule['subjects'][$lesson['subjectId']]['name'] . "\n";
                }

                // Учителя
                if (!empty($lesson['teacherIds'])) {
                    $valid_teachers = array_filter($lesson['teacherIds'], function($tid) {
                        return isset($this->schedule['teachers'][$tid]);
                    });
                    
                    if (!empty($valid_teachers)) {
                        $teachers = array_map(function($tid) use ($subscription) {
                            $teacher_name = $this->schedule['teachers'][$tid]['name'];
                            // Добавляем стрелочку перед учителем, если это подписка на учителя
                            if ($subscription && 
                                $subscription['subscription_type'] === 'teacher' && 
                                $subscription['entity_id'] === $tid) {
                                return "★ " . $teacher_name;
                            }
                            return $teacher_name;
                        }, $valid_teachers);
                        $message .= "👨‍🏫 *Учитель:* " . implode(", ", $teachers) . "\n";
                    }
                }

                // Кабинет
                if (isset($lesson['classroomId']) && 
                    isset($this->schedule['classrooms'][$lesson['classroomId']]['name'])) {
                    $message .= "🚪 *Кабинет:* " . 
                        $this->schedule['classrooms'][$lesson['classroomId']]['name'] . "\n";
                }

                // Классы
                if (!empty($lesson['classIds'])) {
                    $valid_classes = array_filter($lesson['classIds'], function($cid) {
                        return isset($this->schedule['classes'][$cid]);
                    });
                    
                    if (!empty($valid_classes)) {
                        $classes = array_map(function($cid) use ($subscription) {
                            $class_name = $this->schedule['classes'][$cid]['name'];
                            // Добавляем стрелочку перед классом, если это подписка на класс
                            if ($subscription && 
                                $subscription['subscription_type'] === 'class' && 
                                $subscription['entity_id'] === $cid) {
                                return "★ " . $class_name;
                            }
                            return $class_name;
                        }, $valid_classes);
                        $message .= "👥 *Класс:* " . implode(", ", $classes) . "\n";
                    }
                }
            }

            return $message;

        } catch (Exception $e) {
            $this->log("Ошибка при форматировании текущего урока: " . $e->getMessage(), 'error');
            return "❌ Ошибка при формировании сообщения";
        }
    }

    // Добавляем новый метод для проверки оставшихся уроков
    private function get_today_remaining_lessons($subscription) {
        if (!$this->schedule) {
            $this->schedule = $this->get_current_schedule();
        }

        $current_time = current_time('H:i');
        $lessons = [];

        if ($subscription) {
            $entity_id = $subscription['entity_id'];
            $subscription_type = $subscription['subscription_type'];

            foreach ($this->schedule['lessons'] as $lesson) {
                if (strtotime($lesson['period']['starttime']) > strtotime($current_time)) {
                    if ($subscription_type === 'class' && in_array($entity_id, $lesson['classIds'])) {
                        $lessons[] = $lesson;
                    } else if ($subscription_type === 'teacher' && in_array($entity_id, $lesson['teacherIds'])) {
                        $lessons[] = $lesson;
                    }
                }
            }
        }

        return $lessons;
    }

    private function check_subscriptions() {
        global $wpdb;
        try {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}schedule_subscriptions");
            $this->log("Проверка пописок: найдено $count записей", 'info');
            return $count > 0;
        } catch (Exception $e) {
            $this->log("Ошибка при проверке подписок: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function cleanup_old_notifications($days = 30) {
        global $wpdb;
        try {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}schedule_notifications 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            
            $this->log("Очищено $result старых уведомлений", 'info');
            return true;
        } catch (Exception $e) {
            $this->log("Ошибка при очистке увдомлений: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function get_subscription_by_chat_id($chat_id) {
        if (empty($chat_id)) {
            return null;
        }

        try {
            $sql = $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}schedule_subscriptions WHERE chat_id = %d LIMIT 1",
                $chat_id
            );
            
            $subscription = $this->db->get_row($sql, ARRAY_A);
            
            if ($subscription) {
                $this->log("Найдена подписка для chat_id=$chat_id: " . print_r($subscription, true), 'debug');
                return $subscription;
            }
            
            $this->log("Подписка не найдена для chat_id=$chat_id", 'info');
            return null;

        } catch (Exception $e) {
            $this->log("Ошибка при получении подписки для chat_id=$chat_id: " . $e->getMessage(), 'error');
            return null;
        }
    }

    private function get_user_subscriptions($chat_id) {
        global $wpdb;
        try {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}schedule_subscriptions WHERE chat_id = %d",
                $chat_id
            );
            
            $subscriptions = $wpdb->get_results($sql, ARRAY_A);
            
            if ($subscriptions) {
                $this->log("Найдены подписки для chat_id=$chat_id: " . print_r($subscriptions, true), 'debug');
                return $subscriptions;
            }
            
            $this->log("Подписки не найдены для chat_id=$chat_id", 'info');
            return [];

        } catch (Exception $e) {
            $this->log("Ошибка при получении подписок для chat_id=$chat_id: " . $e->getMessage(), 'error');
            return [];
        }
    }

    private function show_subscription_types($chat_id) {
        $message = "*Выберите тип подписки:*\n\n";
        $message .= "👥 *Класс* - получайте расписание для выбранного класса\n";
        $message .= "👨‍🏫 *Учитель* - получайте расписание для выбранного учителя\n";
        $message .= "⏰ *Период* - получайте уведомления о начале и окончании выбранного урока\n";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👥 На класс', 'callback_data' => 'subscribe_class']
                ],
                [
                    ['text' => '👨‍🏫 На учителя', 'callback_data' => 'subscribe_teacher']
                ],
                [
                    ['text' => '⏰ На период', 'callback_data' => 'subscribe_period']
                ],
                [
                    ['text' => '↩️ Назад', 'callback_data' => 'my_subscriptions']
                ]
            ]
        ];
        
        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function show_my_subscriptions($chat_id) {
        $subscriptions = $this->get_user_subscriptions($chat_id);
        
        if (empty($subscriptions)) {
            $message = "У вас пока нет активных подписок.";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➕ Добавить подписку', 'callback_data' => 'add_subscription']
                    ],
                    [
                        ['text' => '↩️ Назад', 'callback_data' => 'start']
                    ]
                ]
            ];
            
            return $this->send_message($chat_id, $message, $keyboard);
        }
        
        $message = "*📋 Ваши подписки:*\n\n";
        $keyboard = ['inline_keyboard' => []];
        
        // Группируем подписки по типу
        $grouped = [
            'class' => [],
            'teacher' => [],
            'period' => []
        ];
        
        foreach ($subscriptions as $subscription) {
            $type = $subscription['subscription_type'];
            $grouped[$type][] = $subscription;
        }
        
        // Подписки на классы и учителей
        foreach ($subscriptions as $sub) {
            if ($sub['subscription_type'] === 'period') {
                continue; // Пропускаем периоды, они будут обработаны отдельно
            }
            
            $entity_name = '';
            $entity_emoji = '';
            if ($sub['subscription_type'] === 'class') {
                $entity_name = $this->schedule['classes'][$sub['entity_id']]['name'];
                $entity_emoji = '👥';
            } else {
                $entity_name = $this->schedule['teachers'][$sub['entity_id']]['name'];
                $entity_emoji = '👨‍🏫';
            }
            
            // Форматируем информацию о подписке
            $message .= "*{$entity_emoji} {$entity_name}*\n";
            
            // Информация об уведомлениях
            $message .= "├ Следующий урок: ";
            if ($sub['notify_next_lesson']) {
                $time_info = $sub['next_lesson_type'] === 'auto' ? 
                    '✅ авто (по окончанию текущего урока)' : 
                    "✅ за {$sub['next_lesson_time']} мин";
                $message .= $time_info;
            } else {
                $message .= "❌ отключено";
            }
            $message .= "\n";
            
            $message .= "└ Расписание на завтра: ";
            if ($sub['notify_next_day']) {
                if ($sub['next_lesson_type'] === 'auto') {
                    // Для автоматического режима берем время из настроек
                    $global_time = get_option('schedule_next_day_time', '16');
                    $message .= "✅ в " . sprintf('%02d:00', $global_time);
                } else {
                    // Для пользовательского режима берем установленное время
                    $time = date('H:i', strtotime($sub['next_day_time']));
                    $message .= "✅ в $time";
                }
            } else {
                $message .= "❌ отключено";
            }
            $message .= "\n\n";
            
            // Кнопки управления подпиской
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "⚙️ Настройка уведомлений для {$entity_name}",
                    'callback_data' => "manage_subscription:" . $sub['id']
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "❌ Удалить подписку для {$entity_name}",
                    'callback_data' => "delete_subscription:" . $sub['id']
                ]
            ];
        }
        
        // Подписки на периоды
        if (!empty($grouped['period'])) {
            $message .= "*⏰ Подписки на периоды:*\n\n";
            foreach ($grouped['period'] as $sub) {
                $period_name = '';
                foreach ($this->schedule['periods'] as $period) {
                    if ($period['id'] == $sub['entity_id']) {
                        $period_name = "{$period['name']} урок ({$period['starttime']}-{$period['endtime']})";
                        break;
                    }
                }
                
                if (empty($period_name)) {
                    $period_name = 'Неизвестный период';
                }
                
                $message .= "*{$period_name}*\n";
                $message .= "├ Начало урока: " . ($sub['notify_period_start'] ? "✅ включено" : "❌ отключено") . "\n";
                $message .= "└ Окончание урока: " . ($sub['notify_period_end'] ? "✅ включено" : "❌ отключено") . "\n\n";
                
                // Кнопки управления подпиской на период
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => "⚙️ Настройка уведомлений для {$period_name}",
                        'callback_data' => "manage_subscription:" . $sub['id']
                    ]
                ];
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => "❌ Удалить подписку для {$period_name}",
                        'callback_data' => "delete_subscription:" . $sub['id']
                    ]
                ];
            }
        }
        
        // Общие кнопки управления
        $keyboard['inline_keyboard'][] = [
            ['text' => '➕ Добавить подписку', 'callback_data' => 'add_subscription']
        ];
        
        if (count($subscriptions) > 1) {
            $keyboard['inline_keyboard'][] = [
                ['text' => '🔕 Отключить все уведомления', 'callback_data' => 'disable_all_notifications']
            ];
            $keyboard['inline_keyboard'][] = [
                ['text' => '🗑️ Удалить все подписки', 'callback_data' => 'delete_all_subscriptions']
            ];
        }
        
        $keyboard['inline_keyboard'][] = [
            ['text' => '↩️ Назад', 'callback_data' => 'start']
        ];
        
        return $this->send_message($chat_id, $message, $keyboard);
    }

    // Добавляем метод для управления конкретной подпиской
    private function show_subscription_management($chat_id, $subscription_id) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        // Получаем имя сущности
        $entity_name = '';
        if ($subscription['subscription_type'] === 'class') {
            $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
        } else {
            $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
        }
        
        $message = sprintf("*Настройки уведомлений для:* %s\n\n", $entity_name);
        
        // Информация о текущих настройках
        if ($subscription['notify_next_lesson']) {
            $time_info = $subscription['next_lesson_type'] === 'auto' ? 
                'автоматически' : 
                "за {$subscription['next_lesson_time']} минут";
            $message .= "🔔 *Следующий урок:* включено, $time_info\n";
        } else {
            $message .= "🔕 *Следующий урок:* отключено\n";
        }
        
        // Получаем глобальное время отправки расписания на завтра
        $global_next_day_time = get_option('schedule_next_day_time', '16');
        $global_time_formatted = sprintf('%02d:00', $global_next_day_time);
        
        if ($subscription['notify_next_day']) {
            $time = $subscription['next_lesson_type'] === 'auto' ? 
                $global_time_formatted : 
                date('H:i', strtotime($subscription['next_day_time']));
            $message .= "🔔 *Расписание на завтра:* включено, в $time\n";
        } else {
            $message .= "🔕 *Расписание на завтра:* отключено\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '⚙️ Настроить "Следующий урок"',
                        'callback_data' => "set_next_lesson_options:{$subscription_id}"
                    ]
                ],
                [
                    [
                        'text' => '⚙️ Настроить "На завтра"',
                        'callback_data' => "set_next_day_options:{$subscription_id}"
                    ]
                ],
                [
                    [
                        'text' => ($subscription['notify_next_lesson'] || $subscription['notify_next_day']) ? 
                            '🔕 Отключить все уведомления' : '🔔 Включить все уведомления',
                        'callback_data' => "toggle_all_notifications:{$subscription_id}"
                    ]
                ],
                [
                    [
                        'text' => '↩️ Назад к подпискам',
                        'callback_data' => 'my_subscriptions'
                    ]
                ]
            ]
        ];
        
        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function toggle_subscription_notification($chat_id, $subscription_id, $notification_type) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        $field = 'notify_' . $notification_type;
        $new_value = !$subscription[$field];
        
        if ($this->update_subscription_notification($subscription_id, $field, $new_value)) {
            return $this->show_subscription_management($chat_id, $subscription_id);
        }
        
        return $this->send_message($chat_id, "❌ Ошибка при обновлении настроек");
    }

    private function delete_user_subscription($chat_id, $subscription_id) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        // Удаляем подписку из базы данных
        $result = $this->db->delete(
            $this->db->prefix . 'schedule_subscriptions',
            [
                'id' => $subscription_id,
                'chat_id' => $chat_id
            ],
            ['%d', '%d']
        );

        if ($result !== false) {
            $message = "✅ *Подписка успешно удалена*";
            return $this->show_my_subscriptions($chat_id);
        }
        
        return $this->send_message($chat_id, "❌ Ошибка при удалении подписи");
    }

    private function get_subscription_by_id($subscription_id) {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}schedule_subscriptions WHERE id = %d",
                $subscription_id
            ),
            ARRAY_A
        );
    }

    private function update_subscription_notification($subscription_id, $field, $value) {
        return $this->db->update(
            $this->db->prefix . 'schedule_subscriptions',
            [$field => $value],
            ['id' => $subscription_id],
            ['%d'],
            ['%d']
        ) !== false;
    }

    private function disable_all_notifications($chat_id) {
        $result = $this->db->update(
            $this->db->prefix . 'schedule_subscriptions',
            [
                'notify_next_lesson' => 0,
                'notify_next_day' => 0
            ],
            ['chat_id' => $chat_id],
            ['%d', '%d'],
            ['%d']
        );

        if ($result !== false) {
        $message = "✅ *Все уведомления отключены*\n\n";
        $message .= "Вы можете включить уведомления для отдельных подписок в любой момент.";
        } else {
            $message = "❌ Произошла ошибка при отключении уведомлений";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '↩️ К подпискам', 'callback_data' => 'my_subscriptions']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function delete_all_subscriptions($chat_id) {
        $result = $this->db->delete(
            $this->db->prefix . 'schedule_subscriptions',
            ['chat_id' => $chat_id],
            ['%d']
        );

        if ($result !== false) {
        $message = "✅ *Все подписки удалены*\n\n";
        $message .= "Вы можете оформить новые подписки в любой момент.";
        } else {
            $message = "❌ Произошла ошибка при удалении подписок";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕ Добавить подписку', 'callback_data' => 'add_subscription']
                ],
                [
                    ['text' => '↩️ В главное меню', 'callback_data' => 'start']
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function toggle_all_notifications_for_subscription($chat_id, $subscription_id) {
        // Получаем подписку и проверяем результат
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription) {
            $this->log("Подписка не найдена: ID=$subscription_id", 'error');
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }

        // Проверяем принадлежность подписки пользователю
        if ((int)$subscription['chat_id'] !== (int)$chat_id) {
            $this->log("Попытка доступа к чужой подписке: chat_id=$chat_id, subscription_chat_id={$subscription['chat_id']}", 'error');
            return $this->send_message($chat_id, "❌ Доступ запрещен");
        }
        
        // Определяем текущий статус и новое значение
        $current_status = !empty($subscription['notify_next_lesson']) || !empty($subscription['notify_next_day']);
        $new_value = $current_status ? 0 : 1;
        
        // Обновляем все уведомления для этой подписки
        $result = $this->db->update(
            $this->db->prefix . 'schedule_subscriptions',
            [
                'notify_next_lesson' => $new_value,
                'notify_next_day' => $new_value
            ],
            ['id' => $subscription_id],
            ['%d', '%d'],
            ['%d']
        );

        if ($result !== false) {
            $this->log("Успешно обновлены настройки уведомлений для подписки ID=$subscription_id", 'info');
            return $this->show_my_subscriptions($chat_id);
        }
        
        $this->log("Ошибка при обновлении настроек: " . $this->db->last_error, 'error');
        return $this->send_message($chat_id, "❌ Ошибка при обновлении настроек");
    }

    // Добавляем новый метод в класс ScheduleTelegramBot
    public function set_bot_commands() {
            $commands = [
            [
                'command' => 'start',
                'description' => 'Открыть главное меню'
            ],
                [
                    'command' => 'current',
                'description' => 'Информация о текущем уроке'
                ],
                [
                    'command' => 'next',
                'description' => 'Информация о следующем уроке'
                ],
                [
                    'command' => 'today',
                    'description' => 'Расписание на сегодня'
                ],
                [
                    'command' => 'tomorrow',
                    'description' => 'Расписание на завтра'
                ],
            [
                'command' => 'period',
                'description' => 'Информация о текущем периоде'
            ],
            [
                'command' => 'search',
                'description' => 'Быстрый поиск расписания'
                ],
                [
                    'command' => 'subscribe',
                'description' => 'Добавить новую подписку'
                ],
                [
                    'command' => 'settings',
                    'description' => 'Управление подписками'
                ],
                [
                    'command' => 'help',
                'description' => 'Показать справку'
            ]
        ];

        $params = [
            'commands' => json_encode($commands)
        ];

        return $this->send_telegram_request('setMyCommands', $params);
    }


    private function update_next_lesson_settings($chat_id, $subscription_id, $type, $time = null) {
        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }

        // Получаем имя сущности
        $entity_name = '';
        if ($subscription['subscription_type'] === 'class') {
            $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
        } else {
            $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
        }

        // Обновляем настройи в БД только для конкретной подписки
        $update = [];
        
        if ($type === 'off') {
            $update['notify_next_lesson'] = 0;
        } else {
            $update = [
                'notify_next_lesson' => 1,
                'next_lesson_type' => $type
            ];
            
            if ($type === 'custom' && $time !== null) {  // Исправлено здесь
                $update['next_lesson_time'] = $time;
            }
        }

        $result = $this->db->update(
            $this->db->prefix . 'schedule_subscriptions',
            $update,
            ['id' => $subscription_id],
            ['%d', '%s', '%d'],
            ['%d']
        );

        if ($result === false) {
            return $this->send_message($chat_id, "❌ Ошибка при обновлении настроек");
        }

        $message = "✅ *Настройки обновлены для {$entity_name}*\n\n";
        switch ($type) {
            case 'auto':
                $message .= "Вы будете получать уведомления:\n";
                $message .= "• Сразу после окончания текущего урока";
                break;
            case 'custom':
                $message .= "Вы будете получать уведомления за {$time} минут до начала урока";
                break;
            case 'off':
                $message .= "Уведомления о следующем уроке отключены";
                break;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '↩️ К настройкам подписки', 'callback_data' => "manage_subscription:{$subscription_id}"]
                ]
            ]
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function format_next_lesson_message($next_lesson) {
        try {
            if (!$next_lesson) {
                return "❌ Не удалось получить информацию о следующем уроке";
            }

            // Получаем информацию о подписке
            $subscription = $next_lesson['subscription'] ?? null;
            $entity_name = '';
            if ($subscription) {
                if ($subscription['subscription_type'] === 'class' && 
                    isset($this->schedule['classes'][$subscription['entity_id']])) {
                    $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
                } elseif ($subscription['subscription_type'] === 'teacher' && 
                          isset($this->schedule['teachers'][$subscription['entity_id']])) {
                    $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
                }
            }

            $lesson = $next_lesson['lesson'];
            $period = $next_lesson['period'];

            // Формируем заголовок сообщения
            $message = sprintf("*Следующий %s урок (%s - %s), до начала %d мин:*\n",
                $period['name'] ?? 'Неизвестный',
                $period['starttime'] ?? '--:--',
                $period['endtime'] ?? '--:--',
                $next_lesson['minutes_until'] ?? 0
            );

            // Информация об уроке
            $message .= "📖 *Предмет:* " . 
                $this->schedule['subjects'][$lesson['subjectId']]['name'] . "\n";

            // Учителя
            $valid_teachers = array_filter($lesson['teacherIds'], function($tid) {
                return isset($this->schedule['teachers'][$tid]);
            });
            
            if (!empty($valid_teachers)) {
                $teachers = array_map(function($tid) use ($subscription) {
                    $teacher_name = $this->schedule['teachers'][$tid]['name'];
                    // Добавляем стрелочку перед учителем, если это подписка на учителя
                    if ($subscription && 
                        $subscription['subscription_type'] === 'teacher' && 
                        $subscription['entity_id'] === $tid) {
                        return "★ " . $teacher_name;
                    }
                    return $teacher_name;
                }, $valid_teachers);
                $message .= "👨‍🏫 *Учитель:* " . implode(", ", $teachers) . "\n";
            }

            // Кабинет
            if (isset($this->schedule['classrooms'][$lesson['classroomId']]['name'])) {
                $message .= "🚪 *Кабинет:* " . 
                    $this->schedule['classrooms'][$lesson['classroomId']]['name'] . "\n";
            }

            // Классы
            if (!empty($lesson['classIds'])) {
                $valid_classes = array_filter($lesson['classIds'], function($cid) {
                    return isset($this->schedule['classes'][$cid]);
                });
                
                if (!empty($valid_classes)) {
                    $classes = array_map(function($cid) use ($subscription) {
                        $class_name = $this->schedule['classes'][$cid]['name'];
                        // Добавляем стрелочку перед классом, если это подписка на класс
                        if ($subscription && 
                            $subscription['subscription_type'] === 'class' && 
                            $subscription['entity_id'] === $cid) {
                            return "★ " . $class_name;
                        }
                        return $class_name;
                    }, $valid_classes);
                    $message .= "👥 *Класс:* " . implode(", ", $classes) . "\n";
                }
            }

            return $message;

        } catch (Exception $e) {
            $this->log("Ошибка при форматировании сообщения о следующем уроке: " . $e->getMessage(), 'error');
            return "❌ Ошибка при формировании сообщения";
        }
    }

    // Добавляем вспомогательные методы для форматирования
    private function format_parallel_lessons($lessons) {
        try {
            if (empty($lessons) || !is_array($lessons)) {
                $this->log("Пустой массив уроков в format_parallel_lessons", 'warning');
                return "❌ Нет данных о параллельных уроках";
            }

            $message = "*Параллельные уроки:*\n";
            $lessons_added = false;
            
            foreach ($lessons as $pl) {
                try {
                    // Проверяем структуру урока
                    if (!is_array($pl)) {
                        continue;
                    }

                    // Проверяем наличие всех необходимых данных
                    if (!isset($pl['subjectId']) || 
                        !isset($this->schedule['subjects'][$pl['subjectId']]) ||
                        !isset($this->schedule['subjects'][$pl['subjectId']]['name'])) {
                        $this->log("Пропущен урок из-за отсутствия данных о предмете", 'warning');
                        continue;
                    }

                    $message .= "\n📖 *Предмет:* " . 
                        $this->schedule['subjects'][$pl['subjectId']]['name'] . "\n";

                    // Учителя
                    if (!empty($pl['teacherIds']) && is_array($pl['teacherIds'])) {
                        $valid_teachers = array_filter($pl['teacherIds'], function($tid) {
                            return isset($this->schedule['teachers'][$tid]) && 
                                   isset($this->schedule['teachers'][$tid]['name']);
                        });
                        
                        if (!empty($valid_teachers)) {
                            $teachers = array_map(function($tid) {
                                return $this->schedule['teachers'][$tid]['name'];
                            }, $valid_teachers);
                            $message .= "👨‍🏫 *Учитель:* " . implode(", ", $teachers) . "\n";
                        }
                    }

                    // Кабинет
                    if (isset($pl['classroomId']) && 
                        isset($this->schedule['classrooms'][$pl['classroomId']]) &&
                        isset($this->schedule['classrooms'][$pl['classroomId']]['name'])) {
                        $message .= "🚪 *Кабинет:* " . 
                            $this->schedule['classrooms'][$pl['classroomId']]['name'] . "\n";
                    }

                    // Классы
                    if (!empty($pl['classIds']) && is_array($pl['classIds'])) {
                        $valid_classes = array_filter($pl['classIds'], function($cid) {
                            return isset($this->schedule['classes'][$cid]) && 
                                   isset($this->schedule['classes'][$cid]['name']);
                        });
                        
                        if (!empty($valid_classes)) {
                            $classes = array_map(function($cid) {
                                return $this->schedule['classes'][$cid]['name'];
                            }, $valid_classes);
                            $message .= "👥 *Класс:* " . implode(", ", $classes) . "\n";
                        }
                    }
                    
                    $message .= "─────────────\n";
                    $lessons_added = true;

                } catch (Exception $e) {
                    $this->log("Ошибка при форматировании параллельного урока: " . $e->getMessage(), 'error');
                    continue;
                }
            }

            // Проверяем, были ли добавлены уроки
            if (!$lessons_added) {
                return "❌ Нет данных о параллельных уроках";
            }
            
            return $message;

        } catch (Exception $e) {
            $this->log("Ошибка в format_parallel_lessons: " . $e->getMessage(), 'error');
            return "❌ Ошибка при формировании списка параллельных уроков";
        }
    }

    private function format_single_lesson($lesson) {
        $message = '';
        try {
            if (isset($lesson['subjectId']) && isset($this->schedule['subjects'][$lesson['subjectId']])) {
                $message .= "📖 *Предмет:* " . 
                    $this->schedule['subjects'][$lesson['subjectId']]['name'] . "\n";
            }

            if (!empty($lesson['teacherIds'])) {
                $valid_teachers = array_filter($lesson['teacherIds'], function($tid) {
                    return isset($this->schedule['teachers'][$tid]);
                });
                
                if (!empty($valid_teachers)) {
                $teachers = array_map(function($tid) {
                    return $this->schedule['teachers'][$tid]['name'];
                    }, $valid_teachers);
                $message .= "👨‍🏫 *Учитель:* " . implode(", ", $teachers) . "\n";
                }
            }

            if (isset($lesson['classroomId']) && isset($this->schedule['classrooms'][$lesson['classroomId']])) {
                $message .= "🚪 *Кабинет:* " . 
                    $this->schedule['classrooms'][$lesson['classroomId']]['name'] . "\n";
            }

                if (!empty($lesson['classIds'])) {
                $valid_classes = array_filter($lesson['classIds'], function($cid) {
                    return isset($this->schedule['classes'][$cid]);
                });
                
                if (!empty($valid_classes)) {
                    $classes = array_map(function($cid) {
                        return $this->schedule['classes'][$cid]['name'];
                    }, $valid_classes);
                    $message .= "👥 *Класс:* " . implode(", ", $classes) . "\n";
                }
            }
        } catch (Exception $e) {
            $this->log("Ошибка при форматировании одиночного урока: " . $e->getMessage(), 'error');
            }

            return $message;
    }

    // Добавляем новый метод для контроля частоты запросов
    private function respect_rate_limit() {
        $current_time = microtime(true);
        $time_passed = $current_time - $this->last_api_call;
        
        if ($time_passed < $this->rate_limit_delay) {
            // Округляем до ближайшего целого микросекунд и явно конвертируем в int
            $sleep_time = (int)round(($this->rate_limit_delay - $time_passed) * 1000000);
            if ($sleep_time > 0) {
                usleep($sleep_time);
            }
        }
        
        $this->last_api_call = microtime(true);
    }

    // Обновляем метод для отправки запросов к API
    private function send_telegram_request($method, $params = [], $attempts = 0) {
        try {
            $this->respect_rate_limit();

            $url = $this->api_url . $this->token . '/' . $method;
            
            $response = wp_remote_post($url, [
                'body' => json_encode($params),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 15, // Уменьшаем timeout
                'blocking' => true
            ]);

            if (is_wp_error($response)) {
                throw new Exception("WP Error: " . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!$result['ok']) {
                if ($result['error_code'] == 429 && $attempts < $this->max_retries) {
                    $retry_after = isset($result['parameters']['retry_after']) ? 
                        $result['parameters']['retry_after'] : 
                        $this->retry_delay;
                    
                    $this->log("Получена ошибка 429, следующая попытка через {$retry_after} сек", 'info');
                    
                    // Планируем повторную отправку
                    wp_schedule_single_event(time() + $retry_after, 'schedule_retry_telegram_request', [
                        $method,
                        $params,
                        $attempts + 1
                    ]);
                    return false;
                }
                throw new Exception($result['description'] ?? 'Unknown error');
            }

            return $result;

        } catch (Exception $e) {
            $this->log("Ошибка запроса к API ($method): " . $e->getMessage(), 'error');
            return false;
        }
    }

    // Добавляем новый метод для проверки необходимости отправки уведомления
    private function should_send_next_lesson_notification($current_lesson, $next_lesson, $subscription) {
        if (!isset($subscription['next_lesson_type']) || !isset($next_lesson['minutes_until'])) {
            return false;
        }

        $notification_type = $subscription['next_lesson_type'];
        $custom_time = isset($subscription['next_lesson_time']) ? (int)$subscription['next_lesson_time'] : 10;

        // Для автоматического режима
        if ($notification_type === 'auto') {
            // Перед первым уроком
            if (!$current_lesson && $next_lesson['minutes_until'] <= 10 && $next_lesson['minutes_until'] > 8) {
                $this->log("Отправка уведомления перед первым уроком", 'debug');
            return true;
            }
            // После окончания текущего урока
            if ($current_lesson && $current_lesson['minutes_left'] <= 1) {
                $this->log("Отправка уведомления после окончания урока", 'debug');
                return true;
            }
        }
        // Для пользовательского режима
        elseif ($notification_type === 'custom') {
            $minutes_until = $next_lesson['minutes_until'];
            if ($minutes_until <= $custom_time && $minutes_until > ($custom_time - 2)) {
                $this->log("Отправка уведомления по настроенному времени: {$custom_time} минут", 'debug');
                return true;
            }
        }

        return false;
    }

    // Обновляем метод is_first_lesson с дополнительными проверками
    private function is_first_lesson($lesson) {
        try {
            if (!$this->schedule || 
                empty($this->schedule['periods']) || 
                empty($lesson['lesson']) || 
                !isset($lesson['lesson']['periodId'])) {
                return false;
            }

            $periods = array_values($this->schedule['periods']);
            if (empty($periods)) {
                return false;
            }

            usort($periods, function($a, $b) {
                return strtotime($a['starttime']) - strtotime($b['starttime']);
            });

            return !empty($periods[0]['id']) && $periods[0]['id'] === $lesson['lesson']['periodId'];

        } catch (Exception $e) {
            $this->log("Ошибка в is_first_lesson: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // Добавляем новые методы для обработки отложенных задач
    public function handle_scheduled_message($chat_id, $message) {
        $this->send_message($chat_id, $message);
    }

    public function handle_retry_request($method, $params, $attempts) {
        $this->send_telegram_request($method, $params, $attempts);
    }

    private function format_day_schedule_message($schedule, $subscription = null, $is_today = false, $target_date = null) {
        try {
            if (empty($schedule)) {
                return "❌ Нет данных о расписании";
            }

            $weekdays = [
                1 => 'Понедельник',
                2 => 'Вторник',
                3 => 'Среду',
                4 => 'Четверг',
                5 => 'Пятницу',
                6 => 'Субботу',
                7 => 'Воскресенье'
            ];

            if ($target_date) {
                $date = $target_date;
                $weekday = (int)date('N', strtotime($target_date));
            } else {
                $weekday = (int)($is_today ? date('N') : date('N', strtotime('tomorrow')));
                $date = $is_today ? date('d.m.Y') : date('d.m.Y', strtotime('tomorrow'));
            }
            
            // Формируем заголовок
            $header = sprintf(
                "📅 *%s на %s • %s*\n",
                $is_today ? "Расписание" : "Расписание",
                ucfirst($weekdays[$weekday]),
                $date
            );
            
            // Добавляем информацию о подписке
            if ($subscription) {
                if ($subscription['subscription_type'] === 'class' && 
                    isset($this->schedule['classes'][$subscription['entity_id']])) {
                    $header .= "👥 *" . $this->schedule['classes'][$subscription['entity_id']]['name'] . "*\n";
                } elseif ($subscription['subscription_type'] === 'teacher' && 
                          isset($this->schedule['teachers'][$subscription['entity_id']])) {
                    $header .= "👨‍🏫 *" . $this->schedule['teachers'][$subscription['entity_id']]['name'] . "*\n";
                }
            }

            $header .= "━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            $message = $header;

            // Фильтруем и сортируем уроки
            $valid_lessons = array_filter($schedule['lessons'], function($lesson) {
                return isset($lesson['periodId']) && 
                       isset($this->schedule['periods'][$lesson['periodId']]) &&
                       isset($lesson['subjectId']) && 
                       isset($this->schedule['subjects'][$lesson['subjectId']]);
            });

            if (empty($valid_lessons)) {
                return "❌ Нет уроков на этот день";
            }

            usort($valid_lessons, function($a, $b) {
                $period_a = $this->schedule['periods'][$a['periodId']];
                $period_b = $this->schedule['periods'][$b['periodId']];
                return strtotime($period_a['starttime']) - strtotime($period_b['starttime']);
            });
            
            foreach ($valid_lessons as $lesson) {
                try {
                    $period = $this->schedule['periods'][$lesson['periodId']];
                    
                    // Время урока
                    $message .= sprintf("⏰ *%s урок (%s - %s)*\n",
                        $period['name'] ?? 'Урок',
                        $period['starttime'] ?? '--:--',
                        $period['endtime'] ?? '--:--'
                    );

                    // Предмет
                    $subject_name = isset($this->schedule['subjects'][$lesson['subjectId']]) 
                        ? $this->schedule['subjects'][$lesson['subjectId']]['name']
                        : 'Неизвестный предмет';
                    $message .= "📖 *Предмет:* " . $subject_name . "\n";

                    // Учителя (показываем только если это не подписка на учителя)
                    if (empty($subscription) || $subscription['subscription_type'] !== 'teacher') {
                        if (!empty($lesson['teacherIds'])) {
                            $valid_teachers = array_filter($lesson['teacherIds'], function($tid) {
                                return isset($this->schedule['teachers'][$tid]);
                            });
                            
                            if (!empty($valid_teachers)) {
                                $teachers = array_map(function($tid) {
                                    return $this->schedule['teachers'][$tid]['name'];
                                }, $valid_teachers);
                                $message .= "👨‍🏫 *Учитель:* " . implode(", ", $teachers) . "\n";
                            }
                        }
                    }

                    // Кабинет
                    if (isset($lesson['classroomId']) && isset($this->schedule['classrooms'][$lesson['classroomId']])) {
                        $message .= "🚪 *Кабинет:* " . 
                            $this->schedule['classrooms'][$lesson['classroomId']]['name'] . "\n";
                    }

                    // Классы (показываем только если это не подписка на класс)
                    if (empty($subscription) || $subscription['subscription_type'] !== 'class') {
                        if (!empty($lesson['classIds'])) {
                            $valid_classes = array_filter($lesson['classIds'], function($cid) {
                                return isset($this->schedule['classes'][$cid]);
                            });
                            
                            if (!empty($valid_classes)) {
                                $classes = array_map(function($cid) {
                                    return $this->schedule['classes'][$cid]['name'];
                                }, $valid_classes);
                                $message .= "👥 *Класс:* " . implode(", ", $classes) . "\n";
                            }
                        }
                    }

                    $message .= "\n";

                } catch (Exception $e) {
                    $this->log("Ошибка при форматировании урока: " . $e->getMessage(), 'error');
                    continue;
                }
            }

            if ($message === $header) {
                return "❌ Нет уроков на этот день";
            }

            return $message;

        } catch (Exception $e) {
            $this->log("Ошибка при форматировании расписания: " . $e->getMessage(), 'error');
            return "❌ Ошибка при формировании расписания";
        }
    }

    // Добавляем новый метод для проверки первого урока
    private function is_first_lesson_of_day($next_lesson) {
        try {
            if (!isset($next_lesson['lesson']) || !isset($next_lesson['lesson']['periodId'])) {
                return false;
            }

            if (!$this->schedule) {
                $this->schedule = $this->get_current_schedule();
            }

            if (!$this->schedule || empty($this->schedule['periods']) || empty($this->schedule['lessons'])) {
                return false;
            }

            $current_day = (int)current_time('N');
            
            // Получаем все уроки на текущий день
            $today_lessons = array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
                return isset($lesson['dayId']) && (int)$lesson['dayId'] === $current_day;
            });

            if (empty($today_lessons)) {
                return false;
            }

            // Сортируем уроки по времени начала
            usort($today_lessons, function($a, $b) {
                if (!isset($a['periodId']) || !isset($b['periodId']) || 
                    !isset($this->schedule['periods'][$a['periodId']]) || 
                    !isset($this->schedule['periods'][$b['periodId']])) {
                    return 0;
                }
                $period_a = $this->schedule['periods'][$a['periodId']];
                $period_b = $this->schedule['periods'][$b['periodId']];
                return strtotime($period_a['starttime']) - strtotime($period_b['starttime']);
            });

            // Получаем первый урок дня
            $first_lesson = reset($today_lessons);
            if (!$first_lesson || !isset($first_lesson['periodId']) || 
                !isset($this->schedule['periods'][$first_lesson['periodId']])) {
                return false;
            }

            return $first_lesson['periodId'] === $next_lesson['lesson']['periodId'];

        } catch (Exception $e) {
            $this->log("Ошибка в is_first_lesson_of_day: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // Добавляем новый метод
    public function send_next_day_notifications_for_user($subscription) {
        try {
            if (!isset($subscription['id'])) {
                $this->log("Некорректная подписка", 'error');
                return false;
            }

            $schedule = $this->get_next_day_schedule();
            if (!$schedule) {
                $this->log("Не удалось получить расписание на завтра для подписки {$subscription['id']}", 'error');
                return false;
            }

            $filtered_schedule = $this->get_filtered_schedule($schedule, $subscription);
            if (!$filtered_schedule || empty($filtered_schedule['lessons'])) {
                $this->log("Нет уроков на завтра для подписки {$subscription['id']}", 'debug');
                return false;
            }

            $message = $this->format_day_schedule_message($filtered_schedule, $subscription, false);
            if (!$message) {
                $this->log("Не удалось сформировать сообщение для подписки {$subscription['id']}", 'error');
                return false;
            }

            if ($this->send_message($subscription['chat_id'], $message)) {
                $this->save_notification($subscription['chat_id'], 'next_day', $message, 'auto');
                $this->log("Уведомление о расписании на завтра отправлено для chat_id={$subscription['chat_id']}", 'info');
                return true;
            } else {
                $this->log("Ошибка отправки уведомления для chat_id={$subscription['chat_id']}", 'error');
                return false;
            }

        } catch (Exception $e) {
            $this->log("Ошибка в send_next_day_notifications_for_user для подписки {$subscription['id']}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    // Добавляем новый метод для определения текущего периода
    private function get_current_period() {
        try {
            if (!$this->schedule || empty($this->schedule['periods'])) {
                return null;
            }

            $current_time = strtotime(current_time('H:i'));

            // Сортируем периоды по времени
            $periods = $this->schedule['periods'];
            usort($periods, function($a, $b) {
                return strtotime($a['starttime']) - strtotime($b['starttime']);
            });

            // Проверяем каждый период
            foreach ($periods as $period) {
                $start_time = strtotime($period['starttime']);
                $end_time = strtotime($period['endtime']);

                if ($current_time >= $start_time && $current_time <= $end_time) {
                    // Сейчас идет урок
                    return 'lesson';
                }
            }

            // Проверяем перемены
            for ($i = 0; $i < count($periods) - 1; $i++) {
                $current_period_end = strtotime($periods[$i]['endtime']);
                $next_period_start = strtotime($periods[$i + 1]['starttime']);
                
                if ($current_time > $current_period_end && $current_time < $next_period_start) {
                    return 'break';
                }
            }

            return null;

        } catch (Exception $e) {
            $this->log("Ошибка в get_current_period: " . $e->getMessage(), 'error');
            return null;
        }
    }

    private function get_current_lesson_period() {
        try {
            if (!$this->schedule || empty($this->schedule['periods'])) {
                return null;
            }

            $current_time = strtotime(current_time('H:i'));

            foreach ($this->schedule['periods'] as $period) {
                $start_time = strtotime($period['starttime']);
                $end_time = strtotime($period['endtime']);

                if ($current_time >= $start_time && $current_time <= $end_time) {
                    // Вычисляем оставшееся время урока
                    $minutes_left = ceil(($end_time - $current_time) / 60);
                    
                    return [
                        'name' => $period['name'],
                        'starttime' => $period['starttime'],
                        'endtime' => $period['endtime'],
                        'minutes_left' => $minutes_left
                    ];
                }
            }

            return null;

        } catch (Exception $e) {
            $this->log("Ошибка в get_current_lesson_period: " . $e->getMessage(), 'error');
            return null;
        }
    }

    // Для изменения времени в админке
    private function clear_next_day_notifications_transients() {
        global $wpdb;
        
        // Получаем текущую дату
        $today = date('Y-m-d');
        
        // Очищаем все transients связанные с уведомлениями о расписании на завтра
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 AND option_name LIKE %s",
                $wpdb->esc_like('_transient_next_day_notification_') . '%',
                '%' . $wpdb->esc_like($today) . '%'
            )
        );
        
        $this->log("Очищены все transient-ы уведомлений о расписании на завтра", 'info');
    }

    // Добавляем хук для очистки transient при изменении глобального времени
    public function handle_admin_time_change($old_value, $new_value, $option_name) {
        if ($option_name === 'schedule_next_day_time' && $old_value !== $new_value) {
            global $wpdb;
            
            // Очищаем все transient-ы уведомлений о расписании на завтра
            $this->clear_next_day_notifications_transients();
            
            // Обновляем время только для подписок в автоматическом режиме с включенными уведомлениями
            $time_with_seconds = sprintf('%02d:00:00', $new_value);
            
            $result = $wpdb->update(
                $wpdb->prefix . 'schedule_subscriptions',
                [
                    'next_day_time' => $time_with_seconds
                ],
                [
                    'next_lesson_type' => 'auto',
                    'notify_next_day' => 1  // Добавляем проверку на включенные уведомления
                ],
                ['%s'],
                ['%s', '%d']  // Добавляем формат для notify_next_day
            );
            
            if ($result === false) {
                $this->log("Ошибка при обновлении времени для автоматических подписок: " . $wpdb->last_error, 'error');
            } else {
                $this->log("Обновлено время для автоматических подписок на $time_with_seconds", 'info');
            }
        }
    }

    public function get_detailed_stats() {
        // Отладочный код
        $debug_query = "SELECT notification_type, source, COUNT(*) as count 
                       FROM {$this->db->prefix}schedule_notifications 
                       WHERE DATE(created_at) = CURDATE()
                       GROUP BY notification_type, source";
        $debug_results = $this->db->get_results($debug_query);
        $this->log("Отладка статистики: " . print_r($debug_results, true), 'debug');

        // Проверяем структуру таблицы
        $table_info = $this->db->get_results("SHOW COLUMNS FROM {$this->db->prefix}schedule_notifications");
        $this->log("Структура таблицы: " . print_r($table_info, true), 'debug');

        // Существующий код
        $stats = [
            'total_users' => $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}schedule_users"),
            'active_users' => $this->db->get_var("
                SELECT COUNT(DISTINCT u.id) 
                FROM {$this->db->prefix}schedule_users u
                JOIN {$this->db->prefix}schedule_subscriptions s ON u.chat_id = s.chat_id
                WHERE s.notify_next_lesson = 1 OR s.notify_next_day = 1 OR s.notify_changes = 1
            "),
            'total_subscriptions' => $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}schedule_subscriptions"),
            'notifications_today' => $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications WHERE DATE(created_at) = CURDATE()"),

            // Автоматические уведомления
            'auto_next_lesson_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'next_lesson',
                'auto'
            )),
            'auto_next_day_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'next_day',
                'auto'
            )),
            'auto_changes_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'changes',
                'auto'
            )),

            // Уведомления по запросу
            'manual_current_lesson_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'current_lesson',
                'manual'
            )),
            'manual_next_lesson_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'next_lesson',
                'manual'
            )),
            'manual_today_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'today',
                'manual'
            )),
            'manual_next_day_notifications' => $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}schedule_notifications 
                WHERE notification_type = %s 
                AND source = %s
                AND DATE(created_at) = CURDATE()",
                'next_day',
                'manual'
            ))
        ];

        // Получаем данные для графика активности
        $activity_data = $this->db->get_results("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM {$this->db->prefix}schedule_notifications
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");

        $stats['activity_chart_data'] = [
            'labels' => array_map(function($item) { 
                return date('d.m', strtotime($item->date)); 
            }, $activity_data),
            'datasets' => [[
                'label' => 'Количество уведомлений',
                'data' => array_map(function($item) { 
                    return $item->count; 
                }, $activity_data),
                'borderColor' => '#2271b1',
                'tension' => 0.1
            ]]
        ];

        // Получаем список пользователей с их подписками
        $users = $this->db->get_results("
            SELECT u.*, 
                   MAX(n.created_at) as last_activity
            FROM {$this->db->prefix}schedule_users u
            LEFT JOIN {$this->db->prefix}schedule_notifications n ON u.chat_id = n.chat_id
            GROUP BY u.id
            ORDER BY last_activity DESC
        ");

        $stats['users'] = array_map(function($user) {
            $subscriptions = $this->db->get_results($this->db->prepare("
                SELECT * FROM {$this->db->prefix}schedule_subscriptions
                WHERE chat_id = %d
            ", $user->chat_id));

            return [
                'chat_id' => $user->chat_id,
                'username' => $user->username,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'created_at' => $user->created_at,
                'last_activity' => $user->last_activity ?: $user->created_at,
                'subscriptions' => array_map(function($sub) {
                    return [
                        'type' => $sub->subscription_type,
                        'entity_name' => $this->get_entity_name($sub->subscription_type, $sub->entity_id),
                        'notifications' => [
                            'next_lesson' => $sub->notify_next_lesson,
                            'next_day' => $sub->notify_next_day,
                            'changes' => $sub->notify_changes
                        ]
                    ];
                }, $subscriptions),
                'notifications' => [
                    'next_lesson' => array_reduce($subscriptions, function($carry, $sub) {
                        return $carry || $sub->notify_next_lesson;
                    }, false),
                    'next_day' => array_reduce($subscriptions, function($carry, $sub) {
                        return $carry || $sub->notify_next_day;
                    }, false),
                    'changes' => array_reduce($subscriptions, function($carry, $sub) {
                        return $carry || $sub->notify_changes;
                    }, false)
                ]
            ];
        }, $users);

        return $stats;
    }

    private function get_next_school_day_schedule($start_date = 'tomorrow') {
        try {
            $this->log("Начало get_next_school_day_schedule для даты: $start_date", 'info');
            
            if (!$this->schedule) {
                $this->log("Загрузка расписания...", 'info');
                $this->schedule = $this->get_current_schedule();
            }
            
            if (!$this->schedule) {
                $this->log("Не удалось получить расписание", 'error');
                return null;
            }

            $current_date = strtotime($start_date);
            $attempts = 0;
            $max_attempts = 7; // Максимальное количество дней для поиска
            $messages = [];

            $this->log("Начало поиска уроков, текущая дата: " . date('Y-m-d', $current_date), 'debug');

            while ($attempts < $max_attempts) {
                $day_number = (int)date('N', $current_date);
                $day_name = $this->get_weekday_name($day_number);
                $date_str = date('d.m.Y', $current_date);
                
                $this->log("Проверка дня: $day_name ($date_str)", 'debug');
                
                // Фильтруем уроки для текущего проверяемого дня
                $day_schedule = [
                    'periods' => $this->schedule['periods'],
                    'lessons' => array_filter($this->schedule['lessons'], function($lesson) use ($day_number) {
                        return isset($lesson['dayId']) && (int)$lesson['dayId'] === $day_number;
                    })
                ];

                $lessons_count = count($day_schedule['lessons']);
                $this->log("Найдено уроков на $day_name: $lessons_count", 'debug');

                if (!empty($day_schedule['lessons'])) {
                    $this->log("Найдены уроки на $day_name ($date_str)", 'info');
                    return [
                        'schedule' => $day_schedule,
                        'date' => $current_date,
                        'messages' => $messages
                    ];
                } else {
                    // День без уроков, добавляем сообщение
                    if ($attempts === 0) {
                        $messages[] = "📅 *Завтра $day_name ({$date_str}) - уроков нет*";
                    } else {
                        $messages[] = "📅 *$day_name ({$date_str}) - уроков нет*";
                    }
                    $this->log("Уроков на $day_name нет, добавлено сообщение", 'debug');
                }

                // Переходим к следующему дню
                $current_date = strtotime('+1 day', $current_date);
                $attempts++;
                
                $this->log("Переход к следующему дню, попытка $attempts из $max_attempts", 'debug');
            }

            $this->log("Достигнут лимит попыток поиска уроков ($max_attempts дней)", 'info');
            return null;
        } catch (Exception $e) {
            $this->log("Ошибка в get_next_school_day_schedule: " . $e->getMessage(), 'error');
            return null;
        }
    }

    private function save_notification($chat_id, $type, $message, $source = 'auto') {
        try {
            return $this->db->insert(
                $this->db->prefix . 'schedule_notifications',
                [
                    'chat_id' => $chat_id,
                    'notification_type' => $type,
                    'message' => $message,
                    'source' => $source,
                    'created_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
        } catch (Exception $e) {
            $this->log("Ошибка при сохранении уведомления: " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function test_notification($type, $chat_id) {
        try {
            $this->log("Запуск тестового уведомления типа: $type для chat_id: $chat_id", 'info');
            
            // Получаем все подписки пользователя
            $subscriptions = $this->get_user_subscriptions($chat_id);
            if (empty($subscriptions)) {
                return $this->send_message($chat_id, "❌ У вас нет активных подписок. Используйте /subscribe для подписки на расписание.");
            }
            
            switch ($type) {
                case 'current_lesson':
                    $found_lessons = false;
                    foreach ($subscriptions as $subscription) {
                        $current_lesson = $this->get_current_lesson($subscription);
                        $next_lesson = $this->get_next_lesson($subscription);
                        
                        // Получаем все уроки на сегодня для этой подписки
                        $today_lessons = $this->get_today_lessons($subscription);
                        $has_lessons_today = !empty($today_lessons);
                        $lessons_ended = $has_lessons_today && $this->have_lessons_ended($today_lessons);
                        
                        // Получаем информацию о текущем периоде
                        $current_period_info = $this->get_current_period_info();
                        
                        if ($current_lesson) {
                            $message = $this->format_current_lesson_message($current_lesson);
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'current_lesson', $message, 'manual');
                            $found_lessons = true;
                        } elseif ($next_lesson) {
                            // Если сейчас перемена и есть следующий урок
                            $minutes_until = $next_lesson['minutes_until'] ?? 0;
                            $message = "";
                            
                            if ($current_period_info) {
                                $message .= "*" . $current_period_info . "*\n\n";
                            }
                            
                            $message .= $this->format_next_lesson_message($next_lesson);
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'current_lesson', $message, 'manual');
                            $found_lessons = true;
                        } else if ($lessons_ended) {
                            // Если уроки на сегодня закончились
                            $message = "";
                            if ($current_period_info) {
                                $message .= "*" . $current_period_info . "*\n\n";
                            }
                            
                            // Добавляем информацию о подписке
                            if ($subscription['subscription_type'] === 'class' && 
                                isset($this->schedule['classes'][$subscription['entity_id']])) {
                                $message .= "Для " . $this->schedule['classes'][$subscription['entity_id']]['name'] . " уроки на сегодня закончились!";
                            } elseif ($subscription['subscription_type'] === 'teacher' && 
                                    isset($this->schedule['teachers'][$subscription['entity_id']])) {
                                $message .= "У " . $this->schedule['teachers'][$subscription['entity_id']]['name'] . " уроки на сегодня закончились!";
                            }
                            
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'current_lesson', $message, 'manual');
                            $found_lessons = true;
                        }
                    }
                    if (!$found_lessons) {
                        $message = "🕐 Сейчас нет уроков";
                        if ($current_period_info = $this->get_current_period_info()) {
                            $message = "*" . $current_period_info . "*\n\n" . $message;
                        }
                        $this->send_message($chat_id, $message);
                    }
                    break;

                case 'next_lesson':
                    $found_lessons = false;
                    foreach ($subscriptions as $subscription) {
                        $next_lesson = $this->get_next_lesson($subscription);
                        if ($next_lesson) {
                            $message = $this->format_next_lesson_message($next_lesson);
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'next_lesson', $message, 'manual');
                            $found_lessons = true;
                        }
                    }
                    if (!$found_lessons) {
                        $this->send_message($chat_id, "⏭️ Следующих уроков сегодня нет");
                    }
                    break;

                case 'today':
                    if (!$this->schedule) {
                        $this->schedule = $this->get_current_schedule();
                    }
                    
                    $current_day = (int)date('N');
                    $today_schedule = [
                        'periods' => $this->schedule['periods'],
                        'lessons' => array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
                            return isset($lesson['dayId']) && (int)$lesson['dayId'] === $current_day;
                        })
                    ];
                    
                    $found_lessons = false;
                    foreach ($subscriptions as $subscription) {
                        $filtered_schedule = $this->get_filtered_schedule($today_schedule, $subscription);
                        if ($filtered_schedule) {
                            $message = $this->format_day_schedule_message($filtered_schedule, $subscription, true);
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'today', $message, 'manual');
                            $found_lessons = true;
                        }
                    }
                    if (!$found_lessons) {
                        $this->send_message($chat_id, "❌ На сегодня нет уроков");
                    }
                    break;

                case 'tomorrow':
                    if (!$this->schedule) {
                        $this->schedule = $this->get_current_schedule();
                    }
                    
                    if (empty($subscriptions)) {
                        return $this->send_message($chat_id, "❌ У вас нет активных подписок. Используйте /subscribe для подписки на расписание.");
                    }
                    
                    $next_day_data = $this->get_next_school_day_schedule('tomorrow');
                    if (!$next_day_data) {
                        return $this->send_message($chat_id, "❌ Не удалось получить расписание на ближайшие дни");
                    }

                    $found_lessons = false;
                    foreach ($subscriptions as $subscription) {
                        $filtered_schedule = $this->get_filtered_schedule($next_day_data['schedule'], $subscription);
                        
                        if ($filtered_schedule) {
                            $message = "";
                            if (!empty($next_day_data['messages'])) {
                                $message .= implode("\n", $next_day_data['messages']) . "\n\n";
                            }
                            $message .= $this->format_day_schedule_message(
                                $filtered_schedule, 
                                $subscription, 
                                false,
                                date('d.m.Y', $next_day_data['date'])
                            );
                            $this->send_message($chat_id, $message);
                            $this->save_notification($chat_id, 'next_day', $message, 'manual');
                            $found_lessons = true;
                        }
                    }
                    if (!$found_lessons) {
                        $message = "";
                        if (!empty($next_day_data['messages'])) {
                            $message .= implode("\n", $next_day_data['messages']);
                        } else {
                            $message = "📅 На завтра нет уроков";
                        }
                        $this->send_message($chat_id, $message);
                    }
                    break;

                default:
                    throw new Exception("Неизвестный тип уведомления: $type");
            }
        } catch (Exception $e) {
            $this->log("Ошибка в test_notification: " . $e->getMessage(), 'error');
            return $this->send_message($chat_id, "❌ Произошла ошибка при обработке команды");
        }
    }

    private function get_today_lessons($subscription) {
        try {
            if (!$this->schedule) {
                $this->schedule = $this->get_current_schedule();
            }

            $current_day = (int)current_time('N');
            
            // Фильтруем уроки для текущего дня
            $today_lessons = array_filter($this->schedule['lessons'], function($lesson) use ($current_day) {
                return isset($lesson['dayId']) && (int)$lesson['dayId'] === $current_day;
            });

            // Фильтруем уроки для конкретной подписки
            $filtered_lessons = array_filter($today_lessons, function($lesson) use ($subscription) {
                if ($subscription['subscription_type'] === 'class') {
                    return in_array($subscription['entity_id'], $lesson['classIds']);
                } else { // teacher
                    return in_array($subscription['entity_id'], $lesson['teacherIds']);
                }
            });

            // Сортируем уроки по времени
            usort($filtered_lessons, function($a, $b) {
                $period_a = $this->schedule['periods'][$a['periodId']];
                $period_b = $this->schedule['periods'][$b['periodId']];
                return strtotime($period_a['starttime']) - strtotime($period_b['starttime']);
            });

            return $filtered_lessons;
        } catch (Exception $e) {
            $this->log("Ошибка в get_today_lessons: " . $e->getMessage(), 'error');
            return [];
        }
    }

    private function have_lessons_ended($lessons) {
        if (empty($lessons)) {
            return false;
        }

        $current_time = strtotime(current_time('H:i'));
        
        // Получаем время окончания последнего урока
        $last_lesson = end($lessons);
        $last_period = $this->schedule['periods'][$last_lesson['periodId']];
        $last_lesson_end = strtotime($last_period['endtime']);

        return $current_time > $last_lesson_end;
    }

    private function get_current_period_info() {
        try {
            if (!$this->schedule || empty($this->schedule['periods'])) {
                return null;
            }

            $current_time = strtotime(current_time('H:i'));
            $periods = $this->schedule['periods'];
            
            // Сортируем периоды по времени начала
            usort($periods, function($a, $b) {
                return strtotime($a['starttime']) - strtotime($b['starttime']);
            });

            // Проверяем каждый период
            for ($i = 0; $i < count($periods); $i++) {
                $period = $periods[$i];
                $start_time = strtotime($period['starttime']);
                $end_time = strtotime($period['endtime']);

                if ($current_time >= $start_time && $current_time <= $end_time) {
                    // Сейчас идет урок
                    return "Сейчас " . $period['name'] . " урок!";
                }

                // Проверяем перемену
                if ($i < count($periods) - 1) {
                    $next_period = $periods[$i + 1];
                    $next_start = strtotime($next_period['starttime']);
                    
                    if ($current_time > $end_time && $current_time < $next_start) {
                        return "Сейчас перемена перед " . $next_period['name'] . " уроком!";
                    }
                }
            }

            // Если время до начала первого урока
            if ($current_time < strtotime($periods[0]['starttime'])) {
                return "Уроки еще не начались";
            }

            // Если время после последнего урока
            if ($current_time > strtotime(end($periods)['endtime'])) {
                return "Уроки на сегодня закончились";
            }

            return null;
        } catch (Exception $e) {
            $this->log("Ошибка в get_current_period_info: " . $e->getMessage(), 'error');
            return null;
        }
    }

    private function handle_quick_search($chat_id, $type) {
        $message = "";
        $keyboard = ['inline_keyboard' => []];

        if ($type === 'teacher') {
            $message = "👨‍🏫 *Выберите учителя из списка:*";
            $teachers = $this->get_teachers_list();
            
            // Сортируем список по фамилии
            uasort($teachers, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            
            // Разбиваем список учителей на пары для двух колонок
            $rows = [];
            $current_row = [];
            
            foreach ($teachers as $id => $teacher) {
                $current_row[] = [
                    'text' => $teacher['name'],
                    'callback_data' => "quick_view:teacher:{$id}:menu"
                ];
                
                if (count($current_row) === 2) {
                    $rows[] = $current_row;
                    $current_row = [];
                }
            }
            
            if (!empty($current_row)) {
                $current_row[] = ['text' => ' ', 'callback_data' => 'none'];
                $rows[] = $current_row;
            }
            
            $keyboard['inline_keyboard'] = array_merge($keyboard['inline_keyboard'], $rows);
            
        } else if ($type === 'class') {
            $message = "👥 *Выберите класс из списка:*";
            $classes = $this->get_classes_list();
            
            // Группируем классы по параллелям
            $grouped = ['10' => [], '11' => []];
            foreach ($classes as $id => $class) {
                preg_match('/^(\d+)/', $class['name'], $matches);
                $parallel = $matches[1];
                if (isset($grouped[$parallel])) {
                    $grouped[$parallel][] = [
                        'id' => $id,
                        'name' => $class['name']
                    ];
                }
            }

            // Сортируем классы в каждой параллели
            foreach ($grouped as &$parallelClasses) {
                usort($parallelClasses, function($a, $b) {
                    return strnatcmp($a['name'], $b['name']);
                });
            }

            // Создаем кнопки в два столбца
            $rows = [];
            foreach ($grouped['10'] as $class) {
                $rows[] = [
                    [
                        'text' => $class['name'],
                        'callback_data' => "quick_view:class:{$class['id']}:menu"
                    ],
                    ['text' => ' ', 'callback_data' => 'none']
                ];
            }

            foreach ($grouped['11'] as $index => $class) {
                if (isset($rows[$index])) {
                    $rows[$index][1] = [
                        'text' => $class['name'],
                        'callback_data' => "quick_view:class:{$class['id']}:menu"
                    ];
                } else {
                    $rows[] = [
                        ['text' => ' ', 'callback_data' => 'none'],
                        [
                            'text' => $class['name'],
                            'callback_data' => "quick_view:class:{$class['id']}:menu"
                        ]
                    ];
                }
            }

            $keyboard['inline_keyboard'] = array_merge($keyboard['inline_keyboard'], $rows);
        }

        // Добавляем кнопку "Назад"
        $keyboard['inline_keyboard'][] = [
            ['text' => '↩️ Назад', 'callback_data' => 'start']
        ];

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function show_quick_view($chat_id, $type, $entity_id, $view_type) {
        // Создаем временную подписку для просмотра
        $temp_subscription = [
            'subscription_type' => $type,
            'entity_id' => $entity_id
        ];

        // Получаем имя сущности для заголовка
        $entity_name = $this->get_entity_name($type, $entity_id);

        // Формируем клавиатуру с опциями просмотра
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📖 Текущий урок', 'callback_data' => "quick_view:{$type}:{$entity_id}:current"],
                    ['text' => '⏭️ Следующий урок', 'callback_data' => "quick_view:{$type}:{$entity_id}:next"]
                ],
                [
                    ['text' => '📅 На сегодня', 'callback_data' => "quick_view:{$type}:{$entity_id}:today"],
                    ['text' => '📅 На завтра', 'callback_data' => "quick_view:{$type}:{$entity_id}:tomorrow"]
                ],
                [
                    ['text' => '➕ Подписаться', 'callback_data' => "subscribe_entity:{$type}:{$entity_id}"]
                ],
                [
                    ['text' => '↩️ К поиску', 'callback_data' => "quick_search:{$type}"]
                ]
            ]
        ];

        // В зависимости от типа просмотра показываем соответствующую информацию
        switch ($view_type) {
            case 'menu':
                $message = "*🔍 Быстрый просмотр расписания*\n\n";
                $message .= ($type === 'class' ? "👥" : "👨‍🏫") . " *{$entity_name}*\n\n";
                $message .= "Выберите, что вы хотите посмотреть:";
                break;

            case 'current':
                $current_lesson = $this->get_current_lesson($temp_subscription);
                if ($current_lesson) {
                    $message = $this->format_current_lesson_message($current_lesson);
                } else {
                    $message = "🕐 Сейчас нет урока у {$entity_name}";
                }
                break;

            case 'next':
                $next_lesson = $this->get_next_lesson($temp_subscription);
                if ($next_lesson) {
                    $message = $this->format_next_lesson_message($next_lesson);
                } else {
                    $message = "⏭️ Следующих уроков сегодня нет у {$entity_name}";
                }
                break;

            case 'today':
                $today_schedule = [
                    'periods' => $this->schedule['periods'],
                    'lessons' => array_filter($this->schedule['lessons'], function($lesson) {
                        return (int)$lesson['dayId'] === (int)date('N');
                    })
                ];
                
                $filtered_schedule = $this->get_filtered_schedule($today_schedule, $temp_subscription);
                if ($filtered_schedule) {
                    $message = $this->format_day_schedule_message($filtered_schedule, $temp_subscription, true);
                } else {
                    $message = "📅 На сегодня нет уроков у {$entity_name}";
                }
                break;

            case 'tomorrow':
                $next_day_data = $this->get_next_school_day_schedule('tomorrow');
                if ($next_day_data) {
                    $filtered_schedule = $this->get_filtered_schedule($next_day_data['schedule'], $temp_subscription);
                    if ($filtered_schedule) {
                        $message = "";
                        if (!empty($next_day_data['messages'])) {
                            $message .= implode("\n", $next_day_data['messages']) . "\n\n";
                        }
                        $message .= $this->format_day_schedule_message(
                            $filtered_schedule,
                            $temp_subscription,
                            false,
                            date('d.m.Y', $next_day_data['date'])
                        );
                    } else {
                        $message = "📅 На завтра нет уроков у {$entity_name}";
                    }
                } else {
                    $message = "❌ Не удалось получить расписание на ближайшие дни";
                }
                break;

            default:
                $message = "❌ Неизвестный тип просмотра";
                break;
        }

        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function get_weekday_name($day_number) {
        $weekdays = [
            1 => 'Понедельник',
            2 => 'Вторник',
            3 => 'Среда',
            4 => 'Четверг',
            5 => 'Пятница',
            6 => 'Суббота',
            7 => 'Воскресенье'
        ];
        return $weekdays[$day_number] ?? 'Неизвестный день';
    }

    private function manage_subscription($chat_id, $subscription_id) {
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        $entity_name = '';
        $entity_emoji = '';
        $keyboard = ['inline_keyboard' => []];
        
        if ($subscription['subscription_type'] === 'class') {
            $entity_name = $this->schedule['classes'][$subscription['entity_id']]['name'];
            $entity_emoji = '👥';
            
            // Кнопки для подписки на класс
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_next_lesson'] ? '✅' : '❌') . ' Следующий урок',
                    'callback_data' => "toggle_next_lesson:{$subscription_id}"
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_next_day'] ? '✅' : '❌') . ' Расписание на завтра',
                    'callback_data' => "toggle_next_day:{$subscription_id}"
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_changes'] ? '✅' : '❌') . ' Изменения в расписании',
                    'callback_data' => "toggle_changes:{$subscription_id}"
                ]
            ];
            
        } elseif ($subscription['subscription_type'] === 'teacher') {
            $entity_name = $this->schedule['teachers'][$subscription['entity_id']]['name'];
            $entity_emoji = '👨‍🏫';
            
            // Кнопки для подписки на учителя
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_next_lesson'] ? '✅' : '❌') . ' Следующий урок',
                    'callback_data' => "toggle_next_lesson:{$subscription_id}"
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_next_day'] ? '✅' : '❌') . ' Расписание на завтра',
                    'callback_data' => "toggle_next_day:{$subscription_id}"
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_changes'] ? '✅' : '❌') . ' Изменения в расписании',
                    'callback_data' => "toggle_changes:{$subscription_id}"
                ]
            ];
            
        } elseif ($subscription['subscription_type'] === 'period') {
            // Находим информацию о периоде
            foreach ($this->schedule['periods'] as $period) {
                if ($period['id'] == $subscription['entity_id']) {
                    $entity_name = "{$period['name']} урок ({$period['starttime']}-{$period['endtime']})";
                    break;
                }
            }
            if (empty($entity_name)) {
                $entity_name = 'Неизвестный период';
            }
            $entity_emoji = '⏰';
            
            // Кнопки для подписки на период
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_period_start'] ? '✅' : '❌') . ' Начало урока',
                    'callback_data' => "toggle_period_start:{$subscription_id}"
                ]
            ];
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => ($subscription['notify_period_end'] ? '✅' : '❌') . ' Окончание урока',
                    'callback_data' => "toggle_period_end:{$subscription_id}"
                ]
            ];
        }
        
        $message = "*{$entity_emoji} Управление подпиской*\n\n";
        $message .= "Настройка уведомлений для: *{$entity_name}*\n\n";
        
        if ($subscription['subscription_type'] !== 'period') {
            if ($subscription['notify_next_lesson']) {
                $time_info = $subscription['next_lesson_type'] === 'auto' ? 
                    'авто (по окончанию текущего урока)' : 
                    "за {$subscription['next_lesson_time']} мин";
                $message .= "├ Следующий урок: ✅ {$time_info}\n";
            } else {
                $message .= "├ Следующий урок: ❌ отключено\n";
            }
            
            if ($subscription['notify_next_day']) {
                if ($subscription['next_lesson_type'] === 'auto') {
                    $global_time = get_option('schedule_next_day_time', '16');
                    $message .= "└ Расписание на завтра: ✅ в " . sprintf('%02d:00', $global_time) . "\n";
                } else {
                    $time = date('H:i', strtotime($subscription['next_day_time']));
                    $message .= "└ Расписание на завтра: ✅ в {$time}\n";
                }
            } else {
                $message .= "└ Расписание на завтра: ❌ отключено\n";
            }
        } else {
            $message .= "├ Начало урока: " . ($subscription['notify_period_start'] ? "✅ включено" : "❌ отключено") . "\n";
            $message .= "└ Окончание урока: " . ($subscription['notify_period_end'] ? "✅ включено" : "❌ отключено") . "\n";
        }
        
        // Общие кнопки управления
        $keyboard['inline_keyboard'][] = [
            ['text' => '❌ Удалить подписку', 'callback_data' => "delete_subscription:{$subscription_id}"]
        ];
        $keyboard['inline_keyboard'][] = [
            ['text' => '↩️ Назад', 'callback_data' => 'my_subscriptions']
        ];
        
        return $this->send_message($chat_id, $message, $keyboard);
    }

    private function toggle_period_start($chat_id, $subscription_id) {
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        if ($subscription['subscription_type'] !== 'period') {
            return $this->send_message($chat_id, "❌ Неверный тип подписки");
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        
        $new_value = !$subscription['notify_period_start'];
        $wpdb->update(
            $table_name,
            ['notify_period_start' => $new_value],
            ['id' => $subscription_id],
            ['%d'],
            ['%d']
        );
        
        return $this->manage_subscription($chat_id, $subscription_id);
    }
    
    private function toggle_period_end($chat_id, $subscription_id) {
        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription || $subscription['chat_id'] != $chat_id) {
            return $this->send_message($chat_id, "❌ Подписка не найдена");
        }
        
        if ($subscription['subscription_type'] !== 'period') {
            return $this->send_message($chat_id, "❌ Неверный тип подписки");
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        
        $new_value = !$subscription['notify_period_end'];
        $wpdb->update(
            $table_name,
            ['notify_period_end' => $new_value],
            ['id' => $subscription_id],
            ['%d'],
            ['%d']
        );
        
        return $this->manage_subscription($chat_id, $subscription_id);
    }

    private function show_period_list($chat_id) {
        if (!$this->schedule || empty($this->schedule['periods'])) {
            return $this->send_message($chat_id, "❌ Не удалось загрузить список периодов");
        }
        
        $message = "*Выберите период для подписки:*\n\n";
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($this->schedule['periods'] as $period) {
            $period_name = "{$period['name']} урок ({$period['starttime']}-{$period['endtime']})";
            $keyboard['inline_keyboard'][] = [
                ['text' => $period_name, 'callback_data' => "select_period:{$period['id']}"]
            ];
        }
        
        // Добавляем кнопку "Назад"
        $keyboard['inline_keyboard'][] = [
            ['text' => '↩️ Назад', 'callback_data' => 'add_subscription']
        ];
        
        return $this->send_message($chat_id, $message, $keyboard);
    }

    public function check_current_period() {
        $this->log("Начало проверки текущего периода", 'info');
        
        if (!$this->schedule) {
            $this->log("Загрузка расписания...", 'info');
            $this->schedule = $this->get_current_schedule();
        }
        
        if (empty($this->schedule['periods'])) {
            $this->log("Периоды не найдены в расписании", 'warning');
            return;
        }
        
        $current_time = current_time('H:i');
        $this->log("Текущее время: {$current_time}", 'debug');
        
        foreach ($this->schedule['periods'] as $period) {
            $start_time = $period['starttime'];
            $end_time = $period['endtime'];
            
            // Проверяем начало периода
            if ($current_time === $start_time) {
                $this->log("Найдено начало периода {$period['name']}", 'info');
                $this->notify_period_start($period);
            }
            
            // Проверяем окончание периода
            if ($current_time === $end_time) {
                $this->log("Найдено окончание периода {$period['name']}", 'info');
                $this->notify_period_end($period);
            }
        }
    }
    
    private function notify_period_start($period) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        
        // Получаем все подписки на этот период с включенными уведомлениями о начале
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE subscription_type = 'period' 
            AND entity_id = %d 
            AND notify_period_start = 1",
            $period['id']
        ));
        
        if (empty($subscriptions)) {
            $this->log("Нет подписок на начало периода {$period['name']}", 'debug');
            return;
        }
        
        $message = "🔔 *Начало урока*\n\n";
        $message .= "{$period['name']} урок\n";
        $message .= "Время: {$period['starttime']}-{$period['endtime']}";
        
        foreach ($subscriptions as $subscription) {
            $this->send_message($subscription->chat_id, $message);
            $this->save_notification($subscription->chat_id, 'period_start', $message);
        }
    }
    
    private function notify_period_end($period) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        
        // Получаем все подписки на этот период с включенными уведомлениями об окончании
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE subscription_type = 'period' 
            AND entity_id = %d 
            AND notify_period_end = 1",
            $period['id']
        ));
        
        if (empty($subscriptions)) {
            $this->log("Нет подписок на окончание периода {$period['name']}", 'debug');
            return;
        }
        
        $message = "🔔 *Окончание урока*\n\n";
        $message .= "{$period['name']} урок\n";
        $message .= "Время: {$period['starttime']}-{$period['endtime']}";
        
        foreach ($subscriptions as $subscription) {
            $this->send_message($subscription->chat_id, $message);
            $this->save_notification($subscription->chat_id, 'period_end', $message);
        }
    }

} // Закрывающая скобка для класса ScheduleTelegramBot