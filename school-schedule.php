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

// Определяем константы приложения (в автономном режиме это пути проекта)
if (!defined('SCHOOL_SCHEDULE_PATH')) {
    define('SCHOOL_SCHEDULE_PATH', APP_ROOT . '/');
}
// URL приложения может ещё не быть определён на момент загрузки этого файла
// (bootstrap резолвит APP_URL позже для некоторых сценариев) — поэтому константу
// SCHOOL_SCHEDULE_URL вычисляем лениво через функцию app_asset_url().
if (!defined('SCHOOL_SCHEDULE_URL')) {
    define('SCHOOL_SCHEDULE_URL', (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/');
}

if (!function_exists('app_asset_url')) {
    /**
     * Ленивое построение URL к ассету проекта (assets/... или uploads/...).
     * Использует актуальный APP_URL на момент вызова, а не на момент загрузки файла.
     */
    function app_asset_url($rel) {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        if ($base === '' && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        return $base . '/' . ltrim((string) $rel, '/');
    }
}
define('SCHOOL_SCHEDULE_VERSION', '2.17.3');

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
            $bot = $this->bot ?? null;
            if ($bot instanceof ScheduleTelegramBot) {
                new ScheduleRestAPI($bot);
                new ScheduleNotificationScheduler($bot);
            }
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
        add_action('admin_post_save_site_settings', [$this, 'handle_save_site_settings']);
        add_action('admin_post_save_favicon', [$this, 'handle_save_favicon']);
        add_action('admin_post_save_server_settings', [$this, 'handle_save_server_settings']);
        add_action('admin_post_change_admin_password', [$this, 'handle_change_admin_password']);
        add_action('admin_post_check_updates', [$this, 'handle_check_updates']);
        add_action('admin_post_run_update', [$this, 'handle_run_update']);
        add_action('admin_post_run_rollback', [$this, 'handle_run_rollback']);
        add_action('admin_post_save_display_settings', [$this, 'handle_save_display_settings']);
        add_action('admin_post_save_marquee_settings', [$this, 'handle_save_marquee_settings']);
        add_action('admin_post_manage_webhook', [$this, 'handle_webhook_management']);
        add_action('admin_post_save_bot_code', [$this, 'handle_bot_code_save']);
        add_action('admin_post_save_banner_settings', [$this, 'handle_save_banner_settings']);
        add_action('admin_post_save_free_rooms_settings', [$this, 'handle_save_free_rooms_settings']);
        add_action('admin_post_save_layout_settings', [$this, 'handle_save_layout_settings']);
        
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

        // Добавляем подменю для настроек сайта (URL, HTTPS, сертификаты)
        add_submenu_page(
            'school-schedule',
            'Настройки сайта',
            'Настройки сайта',
            'manage_options',
            'schedule-site-settings',
            [$this, 'render_site_settings_page']
        );

        // Обновления и откат версий
        add_submenu_page(
            'school-schedule',
            'Обновления',
            'Обновления',
            'manage_options',
            'schedule-updates',
            [$this, 'render_updates_page']
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
        // Единые локальные бандлы: jQuery + jQuery UI + schedule.js и
        // jQuery UI CSS + schedule.css + schedule-template.css.
        // Так встроенный PHP-сервер обрабатывает 2 запроса вместо 5–6.
        wp_enqueue_style(
            'school-schedule',
            app_asset_url('assets/dist/app.min.css'),
            array(),
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/dist/app.min.css')
        );

        wp_enqueue_script(
            'school-schedule',
            app_asset_url('assets/dist/app.min.js'),
            array(),
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/dist/app.min.js'),
            true
        );
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, [
            'toplevel_page_school-schedule',
            'расписание_page_schedule-telegram-settings',
            'расписание_page_schedule-site-settings',
            'расписание_page_schedule-updates',
        ])) {
            return;
        }

        // jQuery используется админскими скриптами (в автономном режиме
        // зависимости в shim не разрешаются автоматически, поэтому подключаем явно).
        wp_enqueue_script(
            'jquery',
            app_asset_url('assets/vendor/jquery-3.6.0.min.js'),
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/vendor/jquery-3.6.0.min.js'),
            true
        );

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
            app_asset_url('assets/css/admin.css'),
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/css/admin.css')
        );

        wp_enqueue_script(
            'school-schedule-admin',
            app_asset_url('assets/js/admin.js'),
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
            $moved = is_uploaded_file($file["tmp_name"]) ? move_uploaded_file($file["tmp_name"], $target_path) : rename($file["tmp_name"], $target_path);
            if (!$moved) {
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
            app_asset_url('assets/css/schedule.css'),
            [],
            filemtime(SCHOOL_SCHEDULE_PATH . 'assets/css/schedule.css')
        );

        wp_enqueue_script(
            'school-schedule',
            app_asset_url('assets/js/schedule.js'),
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

    public function handle_save_site_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('site_settings', 'site_settings_nonce');

        $site_url = isset($_POST['site_url']) ? esc_url_raw(trim($_POST['site_url'])) : '';
        $use_https = isset($_POST['use_https']) ? 'on' : 'off';
        $ssl_cert = isset($_POST['ssl_cert_path']) ? sanitize_text_field($_POST['ssl_cert_path']) : '';
        $ssl_key = isset($_POST['ssl_key_path']) ? sanitize_text_field($_POST['ssl_key_path']) : '';

        // Если URL задан без схемы — добавляем http/https в зависимости от галочки HTTPS
        if ($site_url !== '' && !preg_match('#^https?://#i', $site_url)) {
            $site_url = ($use_https === 'on' ? 'https://' : 'http://') . $site_url;
        }

        update_option('schedule_site_url', $site_url);
        update_option('schedule_use_https', $use_https);
        update_option('schedule_ssl_cert_path', $ssl_cert);
        update_option('schedule_ssl_key_path', $ssl_key);

        // Пытаемся обновить config.php, если он доступен для записи
        $this->update_config_file([
            'site_url' => $site_url,
        ]);

        wp_redirect(add_query_arg(
            ['page' => 'schedule-site-settings', 'settings-updated' => 'site'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Загрузка/удаление фавикона.
     */
    public function handle_save_favicon() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('favicon_settings', 'favicon_nonce');

        $action = isset($_POST['favicon_action']) ? sanitize_key($_POST['favicon_action']) : 'upload';
        $uploads = wp_upload_dir();
        $dir = rtrim($uploads['basedir'], '/\\') . '/favicon';

        $redirect = function ($args) {
            wp_redirect(add_query_arg(
                array_merge(['page' => 'schedule-site-settings'], $args),
                admin_url('admin.php')
            ));
            exit;
        };

        $deleteCurrent = function () use ($dir) {
            $old = get_option('schedule_favicon', null);
            if (is_string($old) && strpos($old, 'favicon/') === 0) {
                $path = $dir . '/' . basename($old);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        };

        if ($action === 'delete') {
            $deleteCurrent();
            update_option('schedule_favicon', '');
            $redirect(['settings-updated' => 'favicon-deleted']);
        }

        if ($action === 'default') {
            $deleteCurrent();
            delete_option('schedule_favicon');
            $redirect(['settings-updated' => 'favicon-default']);
        }

        if (empty($_FILES['favicon_file']) || $_FILES['favicon_file']['error'] !== UPLOAD_ERR_OK) {
            $redirect(['settings-error' => 'Не удалось загрузить файл фавикона.']);
        }

        $file = $_FILES['favicon_file'];
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            $redirect(['settings-error' => 'Фавикон не должен превышать 2 МБ.']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['ico', 'png', 'svg', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!in_array($ext, $allowed, true)) {
            $redirect(['settings-error' => 'Допустимые форматы: ICO, PNG, SVG, JPG, WEBP, GIF.']);
        }

        $tmp = $file['tmp_name'];
        if ($ext === 'svg') {
            if (@simplexml_load_file($tmp) === false) {
                $redirect(['settings-error' => 'Файл SVG повреждён или не является XML-изображением.']);
            }
        } else {
            $mime = '';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = (string) finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                }
            }
            $mimeMap = [
                'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
                'png'  => ['image/png'],
                'jpg'  => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'webp' => ['image/webp'],
                'gif'  => ['image/gif'],
            ];
            if ($mime !== '' && !in_array($mime, $mimeMap[$ext] ?? [], true)) {
                $redirect(['settings-error' => 'Содержимое файла не соответствует формату .' . $ext . '.']);
            }
        }

        if (!wp_mkdir_p($dir)) {
            $redirect(['settings-error' => 'Не удалось создать каталог для фавикона.']);
        }

        $filename = 'favicon-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . '/' . $filename;
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : @rename($tmp, $target);
        if (!$moved) {
            $redirect(['settings-error' => 'Не удалось сохранить файл фавикона.']);
        }

        $deleteCurrent();
        update_option('schedule_favicon', 'favicon/' . $filename);
        $redirect(['settings-updated' => 'favicon']);
    }

    /**
     * Сохранение порта веб-сервера и перезапуск systemd-сервиса.
     */
    public function handle_save_server_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('server_settings', 'server_settings_nonce');

        $port = isset($_POST['server_port']) ? (int) $_POST['server_port'] : 0;
        if ($port < 1 || $port > 65535) {
            wp_redirect(add_query_arg(
                [
                    'page' => 'schedule-site-settings',
                    'settings-error' => 'Некорректный порт. Укажите число от 1 до 65535.',
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        $currentPort = (int) App_Config::get('server.port', get_option('schedule_server_port', 8080));
        if ($port !== $currentPort) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($probe) {
                fclose($probe);
                wp_redirect(add_query_arg(
                    [
                        'page' => 'schedule-site-settings',
                        'settings-error' => 'Порт ' . $port . ' уже занят другим процессом.',
                    ],
                    admin_url('admin.php')
                ));
                exit;
            }
        }

        update_option('schedule_server_port', $port);

        if (!$this->update_config_file(['server.port' => $port])) {
            wp_redirect(add_query_arg(
                [
                    'page' => 'schedule-site-settings',
                    'settings-error' => 'Не удалось обновить config.php. Проверьте права на файл.',
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        if ($port === $currentPort) {
            wp_redirect(add_query_arg(
                [
                    'page' => 'schedule-site-settings',
                    'settings-updated' => 'site',
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        $restartScheduled = $this->restart_server_with_port($port);

        wp_redirect(add_query_arg(
            [
                'page' => 'schedule-site-settings',
                'settings-updated' => $restartScheduled ? 'port' : 'port-manual',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Обновляет systemd-юнит и планирует перезапуск сервиса на новом порту.
     */
    private function restart_server_with_port($port) {
        $serviceFile = '/etc/systemd/system/rsp-schedule.service';
        if (!is_file($serviceFile) || !is_writable($serviceFile) || !function_exists('exec')) {
            return false;
        }

        $content = @file_get_contents($serviceFile);
        if ($content === false) {
            return false;
        }

        $updated = preg_replace_callback(
            '/^ExecStart=(.*?)-S\s+([^\s:]+):(\d+)(.*)$/m',
            function ($m) use ($port) {
                return 'ExecStart=' . $m[1] . '-S ' . $m[2] . ':' . $port . $m[4];
            },
            $content
        );

        if ($updated === null || $updated === $content) {
            return false;
        }

        $updated = preg_replace(
            '/^Environment=SCHEDULE_SITE_URL=.*$/m',
            'Environment=SCHEDULE_SITE_URL=http://localhost:' . $port,
            $updated
        );

        if (@file_put_contents($serviceFile, $updated) === false) {
            return false;
        }

        $output = [];
        $code = 0;
        @exec('systemctl daemon-reload 2>&1', $output, $code);
        if ($code !== 0) {
            return false;
        }

        // Перезапускаем чуть позже, чтобы текущий HTTP-ответ успел уйти.
        @exec('nohup sh -c "sleep 2; systemctl restart rsp-schedule" >/dev/null 2>&1 &');
        return true;
    }

    /**
     * Смена пароля администратора.
     */
    public function handle_change_admin_password() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }

        check_admin_referer('admin_password', 'admin_password_nonce');

        $current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        $new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirm = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        // Хеш может быть переопределён значением из БД (если config.php недоступен для записи).
        $hash = (string) get_option('schedule_admin_password_hash', '');
        if ($hash === '') {
            $hash = (string) App_Config::get('admin.password_hash', '');
        }

        $error = '';
        if ($hash === '' || !password_verify($current, $hash)) {
            $error = 'Текущий пароль указан неверно.';
        } elseif (strlen($new) < 8) {
            $error = 'Новый пароль должен содержать не менее 8 символов.';
        } elseif ($new !== $confirm) {
            $error = 'Новый пароль и подтверждение не совпадают.';
        }

        if ($error !== '') {
            wp_redirect(add_query_arg(
                [
                    'page' => 'schedule-site-settings',
                    'settings-error' => $error,
                ],
                admin_url('admin.php')
            ));
            exit;
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $configUpdated = $this->update_config_file(['admin.password_hash' => $newHash]);

        if ($configUpdated) {
            // config.php — основной источник пароля; убираем возможное устаревшее переопределение.
            delete_option('schedule_admin_password_hash');
        } else {
            // Файл недоступен для записи — сохраняем хеш в БД.
            update_option('schedule_admin_password_hash', $newHash);
        }

        wp_redirect(add_query_arg(
            [
                'page' => 'schedule-site-settings',
                'settings-updated' => $configUpdated ? 'password' : 'password-db',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Обновляет значения в config.php, сохраняя остальные параметры.
     */
    private function update_config_file(array $updates) {
        $config_path = APP_ROOT . '/config.php';
        if (!file_exists($config_path) || !is_writable($config_path)) {
            return false;
        }

        $config = @include $config_path;
        if (!is_array($config)) {
            return false;
        }

        foreach ($updates as $key => $value) {
            if (strpos($key, '.') !== false) {
                $segments = explode('.', $key);
                $ref = &$config;
                foreach ($segments as $segment) {
                    if (!isset($ref[$segment])) {
                        $ref[$segment] = [];
                    }
                    $ref = &$ref[$segment];
                }
                $ref = $value;
                unset($ref);
            } else {
                $config[$key] = $value;
            }
        }

        $export = var_export($config, true);
        $code = "<?php\n"
            . "/**\n"
            . " * Конфигурация автономного приложения «Расписание занятий».\n"
            . " * Обновлено из админки " . date('Y-m-d H:i:s') . " UTC.\n"
            . " */\n\n"
            . "return " . $export . ";\n";

        $written = @file_put_contents($config_path, $code) !== false;
        if ($written && function_exists('opcache_invalidate')) {
            @opcache_invalidate($config_path, true);
        }

        return $written;
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

    // Настройки отображения расписания
    public function handle_save_display_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('display_settings', 'display_nonce');

        update_option(
            'schedule_show_class_groups',
            isset($_POST['show_class_groups']) ? 'on' : 'off'
        );

        // Список выбранных групп передаётся только если в XML есть подгруппы.
        // Пустой список означает «не показывать ни одной группы».
        if (isset($_POST['groups_selection_present'])) {
            $groups = [];
            if (isset($_POST['visible_class_groups']) && is_array($_POST['visible_class_groups'])) {
                foreach ($_POST['visible_class_groups'] as $group_id) {
                    $group_id = sanitize_text_field($group_id);
                    if ($group_id !== '') {
                        $groups[] = $group_id;
                    }
                }
            }
            update_option('schedule_visible_class_groups', array_values(array_unique($groups)));
        }

        // Группы, скрытые у всех классов (по названию).
        if (isset($_POST['group_names_selection_present'])) {
            $hidden_names = [];
            if (isset($_POST['hidden_group_names']) && is_array($_POST['hidden_group_names'])) {
                foreach ($_POST['hidden_group_names'] as $group_name) {
                    $group_name = sanitize_text_field($group_name);
                    if ($group_name !== '') {
                        $hidden_names[] = $group_name;
                    }
                }
            }
            update_option('schedule_hidden_group_names', array_values(array_unique($hidden_names)));
        }

        wp_redirect(add_query_arg(
            ['page' => 'school-schedule', 'settings-updated' => 'display'],
            admin_url('admin.php')
        ));
        exit;
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

    public function render_site_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Значения по умолчанию
        $site_url = get_option('schedule_site_url', App_Config::get('site_url', ''));
        $use_https = get_option('schedule_use_https', 'off');
        $ssl_cert = get_option('schedule_ssl_cert_path', '');
        $ssl_key = get_option('schedule_ssl_key_path', '');

        // Если URL пустой — показываем текущий автоопределенный
        $effective_url = $site_url ?: App_Config::site_url();

        $server_port = (int) App_Config::get('server.port', get_option('schedule_server_port', 8080));
        $admin_username = (string) App_Config::get('admin.username', 'admin');
        $favicon_url = app_favicon_url();

        include SCHOOL_SCHEDULE_PATH . 'templates/site-settings.php';
    }

    /**
     * Загружает класс системы обновления (с резервной копией в .updates/lib).
     */
    private function get_updater() {
        $primary = APP_ROOT . '/includes/core/updater.php';
        $fallback = APP_ROOT . '/.updates/lib/updater.php';

        // Управляющая копия в .updates/lib обновляется вместе с приложением и
        // переживает откат к версии, где этого класса ещё не было.
        if (is_file($fallback)) {
            require_once $fallback;
        } elseif (is_file($primary)) {
            require_once $primary;
        }
        if (!class_exists('App_Updater')) {
            throw new RuntimeException('Класс App_Updater не найден.');
        }
        return new App_Updater(APP_ROOT);
    }

    public function render_updates_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $updater = $this->get_updater();
        $current = $updater->currentInfo();
        $status = $updater->status();
        $backups = $updater->backups();
        $check = get_option('schedule_update_check', null);
        $checked_at = get_option('schedule_update_checked_at', null);
        $error = get_option('schedule_update_error', '');
        if (!is_string($error)) {
            $error = '';
        }
        if ($error !== '') {
            delete_option('schedule_update_error');
        }

        include SCHOOL_SCHEDULE_PATH . 'templates/updates.php';
    }

    public function handle_check_updates() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }
        check_admin_referer('check_updates', 'updates_nonce');

        try {
            $updater = $this->get_updater();
            $check = $updater->check();
            update_option('schedule_update_check', $check);
            update_option('schedule_update_checked_at', date('c'));
        } catch (Throwable $e) {
            update_option('schedule_update_error', $e->getMessage());
        }

        wp_redirect(add_query_arg(
            ['page' => 'schedule-updates', 'checked' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_run_update() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }
        check_admin_referer('run_update', 'updates_nonce');

        // Старое состояние проверки после обновления уже неактуально.
        delete_option('schedule_update_check');
        delete_option('schedule_update_checked_at');

        try {
            $updater = $this->get_updater();
            $updater->startWorker('update', [
                'force' => isset($_POST['force']),
            ]);
        } catch (Throwable $e) {
            update_option('schedule_update_error', $e->getMessage());
        }

        wp_redirect(add_query_arg(
            ['page' => 'schedule-updates', 'update-started' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_run_rollback() {
        if (!current_user_can('manage_options')) {
            wp_die('У вас нет прав для выполнения этого действия.');
        }
        check_admin_referer('run_rollback', 'updates_nonce');

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $restore_db = isset($_POST['restore_db']);

        // Старое состояние проверки после отката уже неактуально.
        delete_option('schedule_update_check');
        delete_option('schedule_update_checked_at');

        try {
            $updater = $this->get_updater();
            $updater->startWorker('rollback', [
                'id' => $backup_id,
                'restore_db' => $restore_db,
            ]);
        } catch (Throwable $e) {
            update_option('schedule_update_error', $e->getMessage());
        }

        wp_redirect(add_query_arg(
            ['page' => 'schedule-updates', 'rollback-started' => '1'],
            admin_url('admin.php')
        ));
        exit;
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

    public function handle_save_free_rooms_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('free_rooms_settings', 'free_rooms_nonce');

        $excluded = [];
        if (isset($_POST['free_rooms_excluded']) && is_array($_POST['free_rooms_excluded'])) {
            $excluded = array_map('sanitize_text_field', wp_unslash($_POST['free_rooms_excluded']));
            $excluded = array_values(array_unique(array_filter($excluded, 'strlen')));
        }

        update_option('schedule_free_rooms_excluded', $excluded);

        $back = wp_get_referer() ?: admin_url('admin.php?page=school-schedule');
        $back = add_query_arg('settings-updated', 'true', $back);
        wp_redirect($back);
        exit;
    }

    public function handle_save_layout_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        check_admin_referer('layout_settings', 'layout_nonce');

        $placements = ['header', 'right', 'left', 'bottom', 'scroll-right', 'scroll-left', 'scroll-bottom'];
        $button_options = [
            'schedule_main_buttons_desktop',
            'schedule_main_buttons_mobile',
            'schedule_panels_buttons_desktop',
            'schedule_panels_buttons_mobile',
        ];
        foreach ($button_options as $option_name) {
            $value = sanitize_text_field($_POST[$option_name] ?? 'header');
            if (!in_array($value, $placements, true)) {
                $value = 'header';
            }
            update_option($option_name, $value);
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=school-schedule');
        $back = add_query_arg('settings-updated', 'true', $back);
        wp_redirect($back);
        exit;
    }

    // Функция для обновления структуры таблицы
    public function update_database_structure() {
        global $wpdb;
        
        // Проверяем наличие новых полей в таблице подписок (через список колонок —
        // совместимо и с MySQL, и с SQLite, где SHOW CREATE TABLE недоступен)
        $table_name = $wpdb->prefix . 'schedule_subscriptions';
        $columns = array();
        foreach ((array) $wpdb->get_columns($table_name) as $col) {
            $columns[] = isset($col->Field) ? $col->Field : (isset($col->name) ? $col->name : '');
        }

        // Добавляем поле notify_period_start, если его нет
        if (!in_array('notify_period_start', $columns, true)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `notify_period_start` tinyint(1) DEFAULT '0'");
        }

        // Добавляем поле notify_period_end, если его нет
        if (!in_array('notify_period_end', $columns, true)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `notify_period_end` tinyint(1) DEFAULT '0'");
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