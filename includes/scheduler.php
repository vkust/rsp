<?php
class ScheduleNotificationScheduler {
    private $bot;
    private $last_run_option = 'schedule_last_notification_run';
    private $notification_lock_option = 'schedule_notification_lock';
    private $lock_timeout = 300; // 5 минут

    public function __construct($bot) {
        $this->bot = $bot;
        
        // Проверяем и регистрируем CRON-события
        if (!wp_next_scheduled('schedule_next_lesson_notification')) {
            wp_schedule_event(time(), 'every_five_minutes', 'schedule_next_lesson_notification');
            $this->bot->log("CRON: Зарегистрировано событие next_lesson", 'info');
        }
        
        // Устанавливаем фиксированное время для уведомлений о следующем дне
        if (!wp_next_scheduled('schedule_next_day_notification')) {
            $tomorrow = strtotime('tomorrow 16:00:00');
            wp_schedule_event($tomorrow, 'daily', 'schedule_next_day_notification');
            $this->bot->log("CRON: Зарегистрировано событие next_day", 'info');
        }
        
        // Добавляем обработчики
        add_action('schedule_next_lesson_notification', [$this, 'handle_next_lesson_notification']);
        add_action('schedule_next_day_notification', [$this, 'handle_next_day_notification']);
    }

    public function handle_next_lesson_notification() {
        $this->bot->log("=== Начало обработки next_lesson в scheduler ===", 'info');
        
        try {
            // Проверяем текущее время
            $current_hour = (int)current_time('G');
            $current_minute = (int)current_time('i');
            
            $this->bot->log("Текущее время: {$current_hour}:{$current_minute}", 'info');
            
            // Проверяем, рабочее ли время
            if ($current_hour < 8 || $current_hour > 18) {
                $this->bot->log("Нерабочее время, пропускаем", 'info');
                return;
            }
            
            // Проверяем день недели
            $current_day = (int)current_time('N');
            if ($current_day > 5) {
                $this->bot->log("Выходной день, пропускаем", 'info');
                return;
            }
            
            $this->bot->send_next_lesson_notifications();
            
        } catch (Exception $e) {
            $this->bot->log("Ошибка в handle_next_lesson_notification: " . $e->getMessage(), 'error');
            throw $e;
        }
        
        $this->bot->log("=== Завершение обработки next_lesson в scheduler ===", 'info');
    }

    public function handle_next_day_notification() {
        try {
            if (!$this->acquire_lock()) {
                $this->bot->log("Пропуск - другой процесс уже выполняется", 'info');
                return;
            }

            $this->bot->log("Запуск обработчика уведомлений о расписании на завтра", 'info');
            
            // Проверяем день недели
            $tomorrow = (int)date('N', strtotime('tomorrow'));
            if ($tomorrow > 5) {
                $this->bot->log("Пропуск - завтра выходной", 'info');
                return;
            }

            // Проверяем рабочее время (с 8:00 до 19:00)
            $current_hour = (int)current_time('G');
            if ($current_hour < 8 || $current_hour > 19) {
                $this->bot->log("Нерабочее время ($current_hour:00), пропускаем", 'info');
                return;
            }

            // Получаем подписки с включенными уведомлениями на следующий день
            $subscriptions = $this->bot->get_active_subscriptions('notify_next_day');
            
            foreach ($subscriptions as $subscription) {
                try {
                    // Проверяем настройки времени уведомления
                    $notify_next_day = $subscription['notify_next_day'] ?? 0;
                    if (!$notify_next_day) {
                        continue;
                    }

                    // Получаем установленное пользователем время или используем время по умолчанию
                    $next_day_time = $subscription['next_day_time'] ?? '16:00:00';
                    
                    // Проверяем, подходящее ли сейчас время для отправки
                    $current_time = current_time('H:i');
                    $notification_time = date('H:i', strtotime($next_day_time));
                    
                    // Сравниваем текущее время с временем уведомления (с погрешностью в 2 минуты)
                    $current_minutes = strtotime($current_time);
                    $notification_minutes = strtotime($notification_time);
                    $diff = abs($current_minutes - $notification_minutes) / 60;

                    $this->bot->log("Проверка времени для chat_id={$subscription['chat_id']}: текущее=$current_time, установленное=$notification_time, разница=$diff минут", 'debug');

                    if ($diff <= 2) {
                        // Проверяем, не было ли уже отправлено уведомление сегодня
                        $notification_key = sprintf(
                            'next_day_notification_%s_%s',
                            $subscription['id'],
                            date('Y-m-d')
                        );

                        if (!get_transient($notification_key)) {
                            // Отправляем уведомление
                            if ($this->bot->send_next_day_notifications_for_user($subscription)) {
                                // Устанавливаем флаг отправки на 20 часов
                                set_transient($notification_key, true, 20 * HOUR_IN_SECONDS);
                                $this->bot->log("Уведомление отправлено для chat_id={$subscription['chat_id']}", 'info');
                            }
                        } else {
                            $this->bot->log("Уведомление уже было отправлено сегодня для chat_id={$subscription['chat_id']}", 'debug');
                        }
                    }
                } catch (Exception $e) {
                    $this->bot->log("Ошибка отправки для chat_id={$subscription['chat_id']}: " . $e->getMessage(), 'error');
                }
            }

            $this->bot->log("Обработчик уведомлений выполнен успешно", 'info');
            
        } catch (Exception $e) {
            $this->bot->log("Ошибка в обработчике: " . $e->getMessage(), 'error');
        } finally {
            $this->release_lock();
        }
    }

    private function should_run_notifications() {
        $last_run = get_option($this->last_run_option, 0);
        return (time() - $last_run) >= 60; // Минимум 1 минута между запусками
    }

    private function acquire_lock() {
        $lock_time = get_option($this->notification_lock_option);
        if ($lock_time && (time() - $lock_time) < $this->lock_timeout) {
            return false;
        }
        update_option($this->notification_lock_option, time());
        return true;
    }

    private function release_lock() {
        delete_option($this->notification_lock_option);
    }

    private function update_last_run() {
        update_option($this->last_run_option, time());
    }

    // ... остальные методы ...
} 