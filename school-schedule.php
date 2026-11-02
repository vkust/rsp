<?php
/*
Plugin Name: Расписание занятий
Plugin URI: https://vkust.ru/school_schedule
Description: Плагин для отображения школьного расписания
Version: 1.9.1
Author: Kustovsky Vladimir
*/

if (!defined('ABSPATH')) {
    exit;
}

// Определяем константы плагина
define('SCHOOL_SCHEDULE_PATH', plugin_dir_path(__FILE__));
define('SCHOOL_SCHEDULE_URL', plugin_dir_url(__FILE__));
define('SCHOOL_SCHEDULE_VERSION', '1.9.1');

// Временно включаем отображение ошибок для отладки
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
error_reporting(E_ALL);
ini_set('display_errors', 1);

class SchoolSchedule {
    private $default_categories = array(
        'main' => array('name' => 'Основные предметы', 'visible' => true),
        'extracurricular' => array('name' => 'Внеурочная деятельность', 'visible' => true),
        'elective' => array('name' => 'Элективный курс', 'visible' => true),
        'additional' => array('name' => 'Дополнительные занятия', 'visible' => true),
        'consultation' => array('name' => 'Консультации', 'visible' => true)
    );

    public function __construct() {
        // Регистрируем действия для CRON
        add_action('schedule_check_next_lesson', [$this, 'check_next_lesson']);
        add_action('schedule_check_next_day', [$this, 'check_next_day']);
        add_action('schedule_check_current_period', [$this, 'check_current_period']);
        
        // Добавляем интервал для CRON
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display' => 'Каждую минуту'
                ];
            }
            return $schedules;
        });
        
        // Регистрируем хуки активации и деактивации
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Инициализируем бота при наличии токена
        $bot_token = get_option('schedule_telegram_bot_token');
        if (!empty($bot_token)) {
            require_once SCHOOL_SCHEDULE_PATH . 'includes/telegram-bot.php';
            $this->bot = new ScheduleTelegramBot($bot_token);
        }

        // Добавляем интервал для CRON
        add_filter('cron_schedules', [$this, 'add_cron_interval']);

        // Добавляем хуки и действия
        $this->init_hooks();

        // Подключаем все необходимые файлы
        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/telegram-bot.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/rest-api.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/scheduler.php';
        
        // Инициализируем объекты
        try {
            $bot = new ScheduleTelegramBot();
            new ScheduleRestAPI($bot);
            new ScheduleNotificationScheduler($bot);
        } catch (Exception $e) {
            error_log('School Schedule Plugin Initialization Error: ' . $e->getMessage());
        }
    }

    private function init_hooks() {
        // Хуки активации/деактивации
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        register_uninstall_hook(__FILE__, 'school_schedule_uninstall');
        
        // Добавляем меню в админку
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Регистрируем шорткоды
        add_shortcode('school_schedule', [$this, 'render_schedule']);
        add_shortcode('school_schedule_panels', [$this, 'render_schedule_panels']);
        add_shortcode('telegram_schedule', [$this, 'render_telegram_schedule']);
        
        // Добавляем обработчики форм
        add_action('admin_post_upload_schedule', [$this, 'handle_file_upload']);
        add_action('admin_post_activate_schedule', [$this, 'activate_schedule_file']);
        add_action('admin_post_delete_schedule', [$this, 'delete_schedule_file']);
        add_action('admin_post_save_schedule_settings', [$this, 'handle_settings_save']);
        add_action('admin_post_save_marquee_settings', [$this, 'handle_save_marquee_settings']);
        add_action('admin_post_manage_webhook', [$this, 'handle_webhook_management']);
        add_action('admin_post_save_bot_code', [$this, 'handle_bot_code_save']);
        add_action('admin_post_save_banner_settings', [$this, 'handle_save_banner_settings']);
        
        // Подключаем стили и скрипты
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Добавляем фильтры для шаблонов
        add_filter('theme_page_templates', [$this, 'add_schedule_template']);
        add_filter('template_include', [$this, 'load_schedule_template']);

        // Добавляем AJAX обработчики
        add_action('wp_ajax_get_bot_file_content', [$this, 'get_bot_file_content']);
        add_action('wp_ajax_get_bot_logs', [$this, 'ajax_get_bot_logs']);
        add_action('wp_ajax_clear_bot_logs', [$this, 'ajax_clear_bot_logs']);
        add_action('wp_ajax_test_notification', [$this, 'handle_test_notification']);

        // Добавляем обработчики CRON
        add_action('schedule_next_lesson_notification', function() {
            $bot = new ScheduleTelegramBot();
            $scheduler = new ScheduleNotificationScheduler($bot);
            $scheduler->handle_next_lesson_notification();
        });
        
        add_action('schedule_next_day_notification', function() {
            $bot = new ScheduleTelegramBot();
            $scheduler = new ScheduleNotificationScheduler($bot);
            $scheduler->handle_next_day_notification();
        });
    }

    public function activate() {
        ob_start();
        
        try {
            // Проверяем версию PHP
            if (version_compare(PHP_VERSION, '7.4', '<')) {
                throw new Exception('Для работы плагина требуется PHP версии 7.4 или выше');
            }

            // Проверяем наличие необходимых расширений PHP
            $required_extensions = ['curl', 'json', 'mbstring'];
            foreach ($required_extensions as $ext) {
                if (!extension_loaded($ext)) {
                    throw new Exception("Требуется PHP расширение: $ext");
                }
            }
            
            // Создаем таблицы если их нет
            $this->create_tables();
            
            // Обновляем структуру существующих таблиц
            $this->update_database_structure();
            
            // Очищаем кэш расписания
            delete_transient('schedule_data');
            
            // Регистрируем события для CRON
            if (!wp_next_scheduled('schedule_check_next_lesson')) {
                wp_schedule_event(time(), 'every_minute', 'schedule_check_next_lesson');
            }
            
            if (!wp_next_scheduled('schedule_check_next_day')) {
                wp_schedule_event(time(), 'every_minute', 'schedule_check_next_day');
            }
            
            if (!wp_next_scheduled('schedule_check_current_period')) {
                wp_schedule_event(time(), 'every_minute', 'schedule_check_current_period');
            }
            
        } catch (Exception $e) {
            // Логируем ошибку
            error_log("Ошибка активации плагина School Schedule: " . $e->getMessage());
            
            // Деактивируем плагин
            deactivate_plugins(plugin_basename(__FILE__));
            
            // Выводим сообщение об ошибке
            wp_die(
                'Ошибка при активации плагина School Schedule: ' . $e->getMessage(),
                'Ошибка активации плагина',
                ['back_link' => true]
            );
        }
        
        ob_end_clean();
    }

    public function deactivate() {
        // Удаляем все CRON-задачи
        wp_clear_scheduled_hook('schedule_check_next_lesson');
        wp_clear_scheduled_hook('schedule_check_next_day');
        wp_clear_scheduled_hook('schedule_check_current_period');
        
        // Очищаем кэш расписания
        delete_transient('schedule_data');
        
        // Очищаем кэш перманентных ссылок
        flush_rewrite_rules();
    }

    public function add_admin_menu() {
        // Добавляем основное меню
        add_menu_page(
            'Школьное расписание',
            'Расписание',
            'manage_options',
            'school-schedule',
            [$this, 'render_admin_page'],
            'dashicons-calendar-alt',
            30
        );

        // Добавляем подменю "Основные настройки" (дублирует главную страницу)
        add_submenu_page(
            'school-schedule',
            'Основные настройки',
            'Основные настройки',
            'manage_options',
            'school-schedule',
            [$this, 'render_admin_page']
        );

        // Добавляем подменю для настроек Telegram бота
        add_submenu_page(
            'school-schedule',
            'Настройки Telegram бота',
            'Telegram бот',
            'manage_options',
            'schedule-telegram-settings',
            [$this, 'render_bot_settings_page']
        );

        // Добавляем подменю для статистики
        add_submenu_page(
            'school-schedule',
            'Статистика Telegram бота',
            'Статистика бота',
            'manage_options',
            'schedule-telegram-stats',
            [$this, 'render_bot_stats_page']
        );

        // Регистрируем настройки
        register_setting(
            'schedule_telegram_bot_options',
            'schedule_telegram_bot_token',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        // Регистрируем настройки времени
        register_setting(
            'schedule_telegram_time_options',
            'schedule_working_hours_start',
            ['type' => 'integer', 'default' => 7]
        );
        
        register_setting(
            'schedule_telegram_time_options',
            'schedule_working_hours_end',
            ['type' => 'integer', 'default' => 22]
        );
        
        register_setting(
            'schedule_telegram_time_options',
            'schedule_next_day_time',
            ['type' => 'integer', 'default' => 16]
        );

        // Добавляем обработчик изменения времени
        add_action('update_option_schedule_next_day_time', function($old_value, $new_value) {
            if ($new_value !== $old_value) {
                wp_clear_scheduled_hook('schedule_next_day_notification');
                $timestamp = strtotime('today ' . $new_value . ':00');
                if (time() > $timestamp) {
                    $timestamp = strtotime('tomorrow ' . $new_value . ':00');
                }
                wp_schedule_event($timestamp, 'daily', 'schedule_next_day_notification');
            }
        }, 10, 2);
    }

    public function render_admin_page() {
        // Получаем директорию для загрузок
        $upload_dir = wp_upload_dir();
        $schedule_dir = $upload_dir['basedir'] . '/schedules';
        
        // Создаем директорию, если она не существует
        if (!file_exists($schedule_dir)) {
            wp_mkdir_p($schedule_dir);
        }
        
        // Получаем список файлов
        $files = glob($schedule_dir . '/*.xml');
        
        // Получаем активный файл
        $schedule_file = get_option('schedule_active_file');
        
        // Получаем категории и их настройки
        $categories = get_option('schedule_categories', $this->default_categories);
        $subject_categories = get_option('schedule_subject_categories', []);
        
        // Инициализируем переменную schedule
        $schedule = [];
        
        // Получаем список предметов из активного файла
        if ($schedule_file && file_exists($schedule_file)) {
            require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
            try {
                $parser = new ScheduleParser();
                $result = $parser->parse($schedule_file);
                if (!empty($result['schedule'])) {
                    $schedule = $result['schedule'];
                } else {
                    error_log('Schedule Parser returned empty schedule');
                }
            } catch (Exception $e) {
                error_log('Schedule Parser Error: ' . $e->getMessage());
            }
        }
        
        if (WP_DEBUG) {
            error_log('Debug - Schedule File: ' . $schedule_file);
            error_log('Debug - Schedule: ' . print_r($schedule, true));
            error_log('Debug - Categories: ' . print_r($categories, true));
            error_log('Debug - Subject Categories: ' . print_r($subject_categories, true));
        }
        
        // Подключаем шаблон
        include SCHOOL_SCHEDULE_PATH . 'templates/admin-page.php';
    }

    public function enqueue_frontend_assets() {
        // Подключаем jQuery UI
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-core');
        wp_enqueue_script('jquery-ui-selectmenu');
        
        // Подключаем стили jQuery UI
        wp_enqueue_style(
            'jquery-ui-style',
            'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
            array(),
            '1.13.2'
        );

        wp_enqueue_style(
            'school-schedule',
            SCHOOL_SCHEDULE_URL . 'assets/css/schedule.css',
            array(),
            SCHOOL_SCHEDULE_VERSION
        );

        wp_enqueue_script(
            'exceljs',
            'https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js',
            array(),
            '4.3.0',
            true
        );

        wp_enqueue_script(
            'school-schedule',
            SCHOOL_SCHEDULE_URL . 'assets/js/schedule.js',
            array('jquery', 'jquery-ui-core', 'jquery-ui-selectmenu'),
            SCHOOL_SCHEDULE_VERSION,
            true
        );

        // Добавляем стили для шаблона
        wp_enqueue_style(
            'school-schedule-template',
            SCHOOL_SCHEDULE_URL . 'assets/css/schedule-template.css',
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/css/schedule-template.css')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['toplevel_page_school-schedule', 'расписание_page_schedule-telegram-settings'])) {
            return;
        }

        // Добавляем эти строки для CodeMirror
        if ($hook === 'расписание_page_schedule-telegram-settings') {
            wp_enqueue_code_editor(['type' => 'text/x-php']);
            wp_enqueue_script('wp-theme-plugin-editor');
            wp_enqueue_style('wp-codemirror');
            
            // Добавляем дополнительные зависимости для CodeMirror
            wp_enqueue_script('code-editor');
            wp_enqueue_style('code-editor');
            
            // Добавляем тему Monokai
            wp_enqueue_style(
                'codemirror-monokai',
                'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css',
                [],
                '5.65.2'
            );
        }

        wp_enqueue_style(
            'school-schedule-admin',
            SCHOOL_SCHEDULE_URL . 'assets/css/admin.css',
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/css/admin.css')
        );

        wp_enqueue_script(
            'school-schedule-admin',
            SCHOOL_SCHEDULE_URL . 'assets/js/admin.js',
            ['jquery'],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/js/admin.js'),
            true
        );

        // Добавляем переменные для AJAX
        wp_localize_script('school-schedule-admin', 'schoolSchedule', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('test_notification'),
            'strings' => [
                'sending' => 'Отправка уведомления...',
                'error' => 'Ошибка',
                'success' => 'Успешно'
            ]
        ]);
    }

    public function render_schedule($atts = []) {
        // Нормализуем атрибуты
        $atts = shortcode_atts([
            'debug' => '0',
            'view' => 'horizontal',
            'is_panel' => false,
            'is_telegram' => false
        ], $atts);

        // Подключаем необходимые айлы
        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/debug.php';

        // Получаем директорию для загрузок
        $upload_dir = wp_upload_dir();
        $schedule_dir = $upload_dir['basedir'] . '/schedules';

        // Инициализируем переменные
        $schedule = [];
        $error = '';
        $success = '';
        $debug_mode = isset($atts['debug']) && $atts['debug'] === '1';

        // Получаем активный файл
        $active_file = get_option('schedule_active_file');
        
        // Если активный файл существует и доступен, используем его
        if ($active_file && file_exists($active_file)) {
            $schedule_file = $active_file;
        } else {
            // Если активного файла нет, получаем список всех файлов
            $files = glob($schedule_dir . '/*.xml');
            if (!empty($files)) {
                $schedule_file = end($files);
                // Обновляем активный файл
                update_option('schedule_active_file', $schedule_file);
            } else {
                $schedule_file = null;
            }
        }

        // Если файл найден, парсим его
        if ($schedule_file) {
            try {
                $parser = new ScheduleParser();
                $result = $parser->parse($schedule_file);
                $schedule = $result['schedule'];
                
                if ($debug_mode) {
                    $success = 'Используется файл: ' . basename($schedule_file);
                }
            } catch (Exception $e) {
                $error = 'Ошибка при загрузке файла: ' . esc_html($e->getMessage());
            }
        } else {
            $error = 'Файл расписания не найден';
        }

        // Начинаем буферизацию вывода
        ob_start();

        // Добавляем бегущую строку перед расписанием
        echo $this->get_schedule_marquee();

        // Подключаем шаблон
        include SCHOOL_SCHEDULE_PATH . 'templates/schedule-template.php';

        return ob_get_clean();
    }

    public function handle_file_upload() {
        try {
            if (!current_user_can('manage_options')) {
                wp_die('У вас нет прав для выполнения этого действия.');
            }

            // Проверяем nonce
            if (!isset($_POST['schedule_nonce']) || !wp_verify_nonce($_POST['schedule_nonce'], 'schedule_upload')) {
                throw new Exception('Ошибка проверки безопасности');
            }

            // Проверяем загрузку файла
            if (!isset($_FILES['schedule_file']) || $_FILES['schedule_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Ошибка загрузки файла: ' . $_FILES['schedule_file']['error']);
            }

            $file = $_FILES['schedule_file'];
            
            // Проверяем расширение
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xml') {
                throw new Exception('Неверный тип файла. Разрешены только XML файлы.');
            }

            // Создаем директорию
            $upload_dir = wp_upload_dir();
            $schedule_dir = $upload_dir['basedir'] . '/schedules';
            
            if (!file_exists($schedule_dir)) {
                if (!wp_mkdir_p($schedule_dir)) {
                    throw new Exception('Не удалось создать директорию для загрузки');
                }
            }

            // Подготавливаем имя файла
            $filename = sanitize_file_name($file['name']);
            $target_path = $schedule_dir . '/' . $filename;

            // Перемещаем файл
            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception('Не удалось переместить загруженный файл');
            }

            // Проверяем XML
            require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
            $parser = new ScheduleParser();
            $result = $parser->parse($target_path);

            if (empty($result['schedule'])) {
                unlink($target_path);
                throw new Exception('Загруженный файл не содержит корректного расписания');
            }

            // Устанавливаем файл как активный
            update_option('schedule_active_file', $target_path);

            // Используем JavaScript для редиректа
            echo '<script>
                window.location.href = "' . esc_url(add_query_arg(
                    ['page' => 'school-schedule', 'file-uploaded' => 'true'],
                    admin_url('admin.php')
                )) . '";
            </script>';
            exit;

        } catch (Exception $e) {
            error_log('School Schedule Error: ' . $e->getMessage());
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=school-schedule')) . '" class="button">Вернуться назад</a></p>';
            exit;
        }
    }

    // Добавляем новый метод для рендеринга панелей
    public function render_schedule_panels($atts = []) {
        // Нормализуем атрибуты
        $atts = shortcode_atts([
            'debug' => '0',
            'view' => 'horizontal'
        ], $atts);
        $atts['is_panel'] = true;
        return $this->render_schedule($atts);
        // Подключаем необходимые файлы
        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/debug.php';

        // Получаем директорию для загрузок
        $upload_dir = wp_upload_dir();
        $schedule_dir = $upload_dir['basedir'] . '/schedules';

        // Инициализируем переменные
        $schedule = [];
        $error = '';
        $success = '';
        $debug_mode = isset($atts['debug']) && $atts['debug'] === '1';

        // Получаем список загруженных файлов
        $uploaded_files = glob($schedule_dir . '/*.xml');
        
        if (!empty($uploaded_files)) {
            $latest_file = end($uploaded_files);
            try {
                $parser = new ScheduleParser();
                $result = $parser->parse($latest_file);
                $schedule = $result['schedule'];
            } catch (Exception $e) {
                $error = 'Ошибка при загрузке файла: ' . esc_html($e->getMessage());
            }
        }

        // Подключаем специальные скрипты для панелей
        wp_enqueue_style(
            'school-schedule',
            SCHOOL_SCHEDULE_URL . 'assets/css/schedule.css',
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/css/schedule.css')
        );

        wp_enqueue_script(
            'school-schedule',
            SCHOOL_SCHEDULE_URL . 'assets/js/schedule.js',
            ['jquery'],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/js/schedule.js'),
            true
        );

        // Добавляем параметр в скрипт
        wp_localize_script('school-schedule', 'scheduleConfig', [
            'isPanel' => true
        ]);

        // Начинаем буферизацию вывода
        ob_start();
        
        // Подключаем шаблон для панелей
        include SCHOOL_SCHEDULE_PATH . 'templates/schedule-panels-template.php';
        
        return ob_get_clean();
    }

    public function handle_settings_save() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('schedule_settings', 'schedule_nonce');

        // Сохраняем настройки категорий
        $categories = $this->default_categories;
        
        // Обновляем видимость категорий
        foreach ($categories as $slug => &$category) {
            $category['visible'] = isset($_POST['category_visible'][$slug]);
        }
        
        // Сохраняем настройки категорий
        update_option('schedule_categories', $categories);

        // Сохраняем связи предметов с категориями
        $subject_categories = [];
        if (isset($_POST['subject_categories'])) {
            foreach ($_POST['subject_categories'] as $subject_id => $category_slug) {
                if (array_key_exists($category_slug, $categories)) {
                    $subject_categories[$subject_id] = sanitize_text_field($category_slug);
                }
            }
        }
        update_option('schedule_subject_categories', $subject_categories);

        // Редирект с сообщением об успехе
        wp_redirect(add_query_arg(
            ['page' => 'school-schedule', 'settings-updated' => 'true'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function activate_schedule_file() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('activate_schedule', 'schedule_nonce');

        $file_path = sanitize_text_field($_POST['schedule_file']);
        if (file_exists($file_path)) {
            // Обновляем активный файл
            update_option('schedule_active_file', $file_path);
            
            wp_redirect(add_query_arg(
                ['page' => 'school-schedule', 'file-activated' => 'true'],
                admin_url('admin.php')
            ));
        } else {
            wp_redirect(add_query_arg(
                ['page' => 'school-schedule', 'error' => 'file-not-found'],
                admin_url('admin.php')
            ));
        }
        exit;
    }

    public function delete_schedule_file() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('delete_schedule', 'schedule_nonce');

        $file_path = sanitize_text_field($_POST['schedule_file']);
        if (file_exists($file_path)) {
            unlink($file_path);
            wp_redirect(add_query_arg(['page' => 'school-schedule', 'file-deleted' => 'true'], admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(['page' => 'school-schedule', 'error' => 'file-not-found'], admin_url('admin.php')));
        }
        exit;
    }

    // Регистрируем новый шаблон
    public function add_schedule_template($templates) {
        $templates['page-schedule.php'] = 'Шаблон расписания (чистый)';
        return $templates;
    }

    // Загружаем шаблон
    public function load_schedule_template($template) {
        if (is_page()) {
            $template_file = get_post_meta(get_the_ID(), '_wp_page_template', true);
            if ('page-schedule.php' === $template_file) {
                $template = SCHOOL_SCHEDULE_PATH . 'page-schedule.php';
            }
        }
        return $template;
    }

    // Добавляем обработчик сохранения настроек бегущей строки
    public function handle_save_marquee_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('marquee_settings', 'marquee_nonce');

        // Сохраняем все настройки
        update_option('schedule_marquee_enabled', isset($_POST['marquee_enabled']) ? 'on' : 'off');
        update_option('schedule_marquee_text', sanitize_textarea_field($_POST['marquee_text']));
        update_option('schedule_marquee_type', sanitize_text_field($_POST['marquee_type']));
        update_option('schedule_marquee_speed', absint($_POST['marquee_speed']));
        update_option('schedule_marquee_text_color', sanitize_hex_color($_POST['marquee_text_color']));
        update_option('schedule_marquee_bg_color', sanitize_hex_color($_POST['marquee_bg_color']));
        update_option('schedule_marquee_font_size', absint($_POST['marquee_font_size']));

        wp_redirect(add_query_arg(['page' => 'school-schedule', 'settings-updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    // Функция для вывода бегущей строки
    public function get_schedule_marquee() {
        $marquee_enabled = get_option('schedule_marquee_enabled', 'off');
        $marquee_text = get_option('schedule_marquee_text', '');

        if ($marquee_enabled === 'on' && !empty($marquee_text)) {
            $type = get_option('schedule_marquee_type', 'scroll');
            $speed = get_option('schedule_marquee_speed', '30');
            $text_color = get_option('schedule_marquee_text_color', '#ffffff');
            $bg_color = get_option('schedule_marquee_bg_color', '#3b82f6');
            $font_size = get_option('schedule_marquee_font_size', '16');

            $style = sprintf(
                'color: %s; background-color: %s; font-size: %spx;',
                esc_attr($text_color),
                esc_attr($bg_color),
                esc_attr($font_size)
            );

            return sprintf(
                '<div class="schedule-marquee %s" role="marquee" aria-live="off" style="%s" data-speed="%s">
                    <div class="marquee-content">%s</div>
                </div>',
                esc_attr($type),
                esc_attr($style),
                esc_attr($speed),
                esc_html($marquee_text)
            );
        }
        return '';
    }

    // Добавляем в класс SchoolSchedule новый метод для рендеринга Telegram-версии
    public function render_telegram_schedule($atts = []) {
        // Нрмализуем атрибуты
        $atts = shortcode_atts([
            'debug' => '0',
            'view' => 'horizontal',
            'is_telegram' => true
        ], $atts);

        // Подключаем необходимые файлы
        require_once SCHOOL_SCHEDULE_PATH . 'includes/parser.php';
        require_once SCHOOL_SCHEDULE_PATH . 'includes/debug.php';

        // Получаем директорию для загрузок
        $upload_dir = wp_upload_dir();
        $schedule_dir = $upload_dir['basedir'] . '/schedules';

        // Инициализируем переменные
        $schedule = [];
        $error = '';
        $success = '';
        $debug_mode = isset($atts['debug']) && $atts['debug'] === '1';

        // Получаем активный файл
        $active_file = get_option('schedule_active_file');
        
        // Если активный файл существует и доступен, используем его
        if ($active_file && file_exists($active_file)) {
            $schedule_file = $active_file;
        } else {
            // Если активного файла нет, получаем список всех файлов
            $files = glob($schedule_dir . '/*.xml');
            if (!empty($files)) {
                $schedule_file = end($files);
                // Обновляем активный файл
                update_option('schedule_active_file', $schedule_file);
            } else {
                $schedule_file = null;
            }
        }

        // Если файл найден, парсим его
        if ($schedule_file) {
            try {
                $parser = new ScheduleParser();
                $result = $parser->parse($schedule_file);
                $schedule = $result['schedule'];
                
                if ($debug_mode) {
                    $success = 'Используется файл: ' . basename($schedule_file);
                }
            } catch (Exception $e) {
                $error = 'Ошибка при загрузке файла: ' . esc_html($e->getMessage());
            }
        } else {
            $error = 'Файл расписания не найден';
        }

        // Начинаем буферизацию вывода
        ob_start();

        // Подключаем шаблон для Telegram
        include SCHOOL_SCHEDULE_PATH . 'templates/telegram-page.php';

        return ob_get_clean();
    }

    public function handle_webhook_management() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('manage_webhook', 'webhook_nonce');
        
        $action = $_POST['webhook_action'] ?? '';
        $bot = new ScheduleTelegramBot();
        $result = false;
        $message = '';
        
        try {
            switch ($action) {
                case 'set':
                    $result = $bot->set_webhook();
                    $message = $result ? 'Webhook успешно установлен' : 'Ошибка установки webhook';
                    break;
                case 'delete':
                    $result = $bot->delete_webhook();
                    $message = $result ? 'Webhook успешно удален' : 'Ошибка удаления webhook';
                    break;
                case 'info':
                    $result = true;
                    $message = 'Информация обновлена';
                    break;
                default:
                    $message = 'Неизвестное действие';
                    break;
            }
        } catch (Exception $e) {
            $result = false;
            $message = 'Ошибка: ' . $e->getMessage();
        }
        
        // Добавляем сообщение в уведомления WordPress
        add_settings_error(
            'schedule_telegram_bot_options',
            'webhook_' . ($result ? 'success' : 'error'),
            $message,
            $result ? 'updated' : 'error'
        );
        
        // Сохраняем сообщения для оображения
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // Редиректим обратно с нужными параметрами
        wp_redirect(add_query_arg([
            'page' => 'schedule-telegram-settings',
            'action_result' => $result ? 'success' : 'error',
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_bot_code_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('save_bot_code', 'code_nonce');
        
        $file = sanitize_text_field($_POST['file']);
        $code = wp_unslash($_POST['bot_code']);
        
        // Проверяем, что файл находится в разрешенной директории
        $allowed_files = ['telegram-bot.php', 'rest-api.php', 'scheduler.php'];
        if (!in_array($file, $allowed_files)) {
            wp_die('Недопустимый файл');
        }
        
        $file_path = SCHOOL_SCHEDULE_PATH . 'includes/' . $file;
        
        // Проверяем, что файл сществует и доступен для записи
        if (!is_writable($file_path)) {
            add_settings_error(
                'schedule_telegram_bot_options',
                'file_not_writable',
                'Ошибка: файл недоступен для записи',
                'error'
            );
            set_transient('settings_errors', get_settings_errors(), 30);
            wp_redirect(add_query_arg([
                'page' => 'schedule-telegram-settings',
                'code_saved' => 'error'
            ], admin_url('admin.php')));
            exit;
        }
        
        // охраняем код
        $result = file_put_contents($file_path, $code);
        
        if ($result !== false) {
            add_settings_error(
                'schedule_telegram_bot_options',
                'code_saved',
                'Код успешно сохранен',
                'updated'
            );
            $status = 'success';
        } else {
            add_settings_error(
                'schedule_telegram_bot_options',
                'save_error',
                'Ошибка при сохранении кода',
                'error'
            );
            $status = 'error';
        }
        
        set_transient('settings_errors', get_settings_errors(), 30);
        
        wp_redirect(add_query_arg([
            'page' => 'schedule-telegram-settings',
            'code_saved' => $status,
            'settings-updated' => 'true'
        ], admin_url('admin.php')));
        exit;
    }

    public function get_bot_file_content() {
        check_ajax_referer('get_bot_file', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $file = sanitize_text_field($_POST['file']);
        $allowed_files = ['telegram-bot.php', 'rest-api.php', 'scheduler.php'];
        
        if (!in_array($file, $allowed_files)) {
            wp_send_json_error('Недопустимый файл');
        }
        
        $content = file_get_contents(SCHOOL_SCHEDULE_PATH . 'includes/' . $file);
        wp_send_json_success($content);
    }

    public function ajax_get_bot_logs() {
        check_ajax_referer('get_bot_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $bot = new ScheduleTelegramBot();
        wp_send_json_success($bot->get_logs());
    }

    public function ajax_clear_bot_logs() {
        check_ajax_referer('clear_bot_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Недостаточно прав');
        }
        
        $bot = new ScheduleTelegramBot();
        $result = $bot->clear_logs();
        
        wp_send_json_success($result !== false);
    }

    // Добавляем метод для обработки тестовых уведомлений
    public function handle_test_notification() {
        try {
            // Проверяем nonce
            check_ajax_referer('test_notification', '_wpnonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Недостаточно прав');
                return;
            }

            $type = sanitize_text_field($_POST['type'] ?? '');
            $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : null;

            if (!in_array($type, ['current_lesson', 'next_lesson', 'next_day'])) {
                wp_send_json_error('Неверный тип уведомления');
                return;
            }

            try {
                $bot = new ScheduleTelegramBot();
                $result = $bot->test_notification($type, $chat_id);

                if (empty($result)) {
                    wp_send_json_error('Нет результатов тестирования');
                } else {
                    wp_send_json_success($result);
                }
            } catch (Exception $e) {
                error_log('Test Notification Error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                wp_send_json_error('Ошибка при тестировании: ' . $e->getMessage());
            }

        } catch (Exception $e) {
            error_log('Critical Test Notification Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error('Критическая ошибка: ' . $e->getMessage());
        }
    }

    // Добавим метод логирования в класс SchoolSchedule
    private function log($message, $type = 'info') {
        $log_dir = SCHOOL_SCHEDULE_PATH . 'logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $log_file = $log_dir . '/schedule.log';
        $date = date('Y-m-d H:i:s');
        $log_entry = "[$date][$type] $message\n";
        
        return file_put_contents($log_file, $log_entry, FILE_APPEND);
    }

    public function add_cron_interval($schedules) {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = array(
                'interval' => 300,
                'display'  => 'Каждые 5 минут'
            );
        }
        return $schedules;
    }

    public function update_database() {
        global $wpdb;
        
        // Проверяем существование поля username
        $row = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}schedule_subscriptions LIKE 'username'");
        
        if (empty($row)) {
            // Добавляем поле username
            $wpdb->query("ALTER TABLE {$wpdb->prefix}schedule_subscriptions 
                         ADD COLUMN username varchar(255) DEFAULT NULL AFTER chat_id,
                         ADD INDEX username (username)");
            
            // Обновляем существующие записи
            $wpdb->query("
                UPDATE {$wpdb->prefix}schedule_subscriptions s
                JOIN {$wpdb->prefix}schedule_users u ON s.chat_id = u.chat_id
                SET s.username = u.username
                WHERE u.username IS NOT NULL
            ");
        }
    }

    public function render_bot_stats_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Подключаем Chart.js для графиков
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js',
            [],
            '3.7.0',
            true
        );

        // Создаем экземпляр бота для получения статистики
        $bot = new ScheduleTelegramBot();

        // Подключаем шаблон страницы статистики
        include SCHOOL_SCHEDULE_PATH . 'templates/bot-stats.php';
    }

    public function render_bot_settings_page() {
        // Проверяем права доступа
        if (!current_user_can('manage_options')) {
            return;
        }

        // Подключаем необходимые скрипты и стили
        wp_enqueue_code_editor(['type' => 'text/x-php']);
        wp_enqueue_script('wp-theme-plugin-editor');
        wp_enqueue_style('wp-codemirror');
        
        // Добавляем дополнительные зависимости для CodeMirror
        wp_enqueue_script('code-editor');
        wp_enqueue_style('code-editor');
        
        // Добавляем тему Monokai
        wp_enqueue_style(
            'codemirror-monokai',
            'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css',
            [],
            '5.65.2'
        );

        // Создаем экземпляр бота для получения информации
        $bot = new ScheduleTelegramBot();
        $bot_status = $bot->get_bot_info();
        $webhook_info = $bot->get_webhook_info();
        $api_status = $bot->test_api_connection();

        // Подключаем шаблон страницы настроек
        include SCHOOL_SCHEDULE_PATH . 'templates/bot-settings.php';
    }

    public function handle_save_banner_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('banner_settings', 'banner_nonce');

        // Сохраняем настройки заголовка
        update_option('schedule_header_text', sanitize_text_field($_POST['header_text']));
        update_option('schedule_header_position', sanitize_text_field($_POST['header_position']));

        // Обновляем настройки для обычной страницы
        update_option('schedule_banner_enabled_regular', isset($_POST['banner_enabled_regular']) ? 'on' : 'off');
        update_option('schedule_banner_content_regular', wp_kses_post($_POST['banner_content_regular']));
        update_option('schedule_qr_enabled_regular', isset($_POST['qr_enabled_regular']) ? 'on' : 'off');
        update_option('schedule_qr_size_regular', absint($_POST['qr_size_regular']));
        update_option('schedule_qr_position_regular', sanitize_text_field($_POST['qr_position_regular']));
        update_option('schedule_qr_url_regular', esc_url_raw($_POST['qr_url_regular']));

        // Обновляем настройки для панелей
        update_option('schedule_banner_enabled_panel', isset($_POST['banner_enabled_panel']) ? 'on' : 'off');
        update_option('schedule_banner_content_panel', wp_kses_post($_POST['banner_content_panel']));
        update_option('schedule_qr_enabled_panel', isset($_POST['qr_enabled_panel']) ? 'on' : 'off');
        update_option('schedule_qr_size_panel', absint($_POST['qr_size_panel']));
        update_option('schedule_qr_position_panel', sanitize_text_field($_POST['qr_position_panel']));
        update_option('schedule_qr_url_panel', esc_url_raw($_POST['qr_url_panel']));

        // Функция для обработки загрузки изображения
        function handle_image_upload($file_key, $old_image) {
            if (!empty($_FILES[$file_key]['tmp_name'])) {
                $upload = wp_handle_upload(
                    $_FILES[$file_key],
                    array('test_form' => false)
                );
                if (!empty($upload['url'])) {
                    if ($old_image) {
                        $old_file = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $old_image);
                        if (file_exists($old_file)) {
                            unlink($old_file);
                        }
                    }
                    return $upload['url'];
                }
            }
            return $old_image;
        }

        // Обрабатываем загрузку изображений
        $old_qr_regular = get_option('schedule_qr_image_regular');
        $new_qr_regular = handle_image_upload('qr_image_regular', $old_qr_regular);
        if ($new_qr_regular) {
            update_option('schedule_qr_image_regular', $new_qr_regular);
        }

        $old_qr_panel = get_option('schedule_qr_image_panel');
        $new_qr_panel = handle_image_upload('qr_image_panel', $old_qr_panel);
        if ($new_qr_panel) {
            update_option('schedule_qr_image_panel', $new_qr_panel);
        }

        wp_redirect(add_query_arg(['page' => 'school-schedule', 'settings-updated' => 'true'], admin_url('admin.php')));
        exit;
    }

    // Функция для обновления структуры таблицы
    public function update_database_structure() {
        global $wpdb;
        
        // Проверяем наличие новых полей в таблице подписок
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        $row = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`");
        
        if ($row) {
            $create_table_sql = $row->{'Create Table'};
            
            // Добавляем поле notify_period_start, если его нет
            if (strpos($create_table_sql, 'notify_period_start') === false) {
                $wpdb->query("ALTER TABLE `{$table_name}` 
                    ADD COLUMN `notify_period_start` tinyint(1) DEFAULT '0' 
                    AFTER `notify_changes`");
            }
            
            // Добавляем поле notify_period_end, если его нет
            if (strpos($create_table_sql, 'notify_period_end') === false) {
                $wpdb->query("ALTER TABLE `{$table_name}` 
                    ADD COLUMN `notify_period_end` tinyint(1) DEFAULT '0' 
                    AFTER `notify_period_start`");
            }
        }
    }

    public function check_current_period() {
        if ($this->bot) {
            $this->bot->check_current_period();
        }
    }
}

// Инициализация плагина
new SchoolSchedule();

if (!function_exists('school_schedule_uninstall')) {
    function school_schedule_uninstall() {
        // Удаляем опции
        delete_option('schedule_active_file');
        delete_option('schedule_marquee_enabled');
        delete_option('schedule_marquee_text');
        delete_option('schedule_telegram_bot_token');
        delete_option('schedule_categories');
        delete_option('schedule_subject_categories');
        delete_option('schedule_working_hours_start');
        delete_option('schedule_working_hours_end');
        delete_option('schedule_next_day_time');

        // Удаляем CRON задачи
        wp_clear_scheduled_hook('schedule_next_lesson_notification');
        wp_clear_scheduled_hook('schedule_next_day_notification');
        
        // Удаляем таблицы
        global $wpdb;
        $tables = [
            'schedule_subscriptions',
            'schedule_users',
            'schedule_notifications'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$table");
        }

        // Удаляем директории и файлы
        $upload_dir = wp_upload_dir();
        $schedule_dir = $upload_dir['basedir'] . '/schedules';
        $log_dir = plugin_dir_path(__FILE__) . 'logs';

        // Функция для рекурсивного удаления директории
        function rrmdir($dir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ($object != "." && $object != "..") {
                        if (is_dir($dir . "/" . $object))
                            rrmdir($dir . "/" . $object);
                        else
                            unlink($dir . "/" . $object);
                    }
                }
                rmdir($dir);
            }
        }

        // Удаляем директории
        if (file_exists($schedule_dir)) {
            rrmdir($schedule_dir);
        }
        if (file_exists($log_dir)) {
            rrmdir($log_dir);
        }
    }
}