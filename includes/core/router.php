<?php
/**
 * Маршрутизация автономного приложения «Расписание занятий».
 * Точка входа: public/index.php
 */

if (!defined('APP_ROOT')) {
    exit;
}

class App_Router
{
    /**
     * Разбор URI и dispatch. Ожидается, что bootstrap уже выполнен.
     */
    public static function dispatch()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script !== '' && $script !== '/' && strpos($uri, $script) === 0) {
            $uri = substr($uri, strlen($script));
        }
        $uri = '/' . trim((string) $uri, '/');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Статические ассеты (css/js), отдаёт веб-сервер — на случай встроенного сервера PHP
        if (preg_match('#^/assets/(.+)$#', $uri, $m)) {
            self::serve_asset($m[1]);
            return;
        }

        // XML-файлы расписаний из каталога загрузок
        if (preg_match('#^/uploads/(.+)$#', $uri, $m)) {
            self::serve_upload($m[1]);
            return;
        }

        // Стабильная публичная ссылка на текущий активный XML-файл.
        if ($uri === '/schedule.xml' || $uri === '/api/schedule.xml' || $uri === '/raspisanie.xml') {
            self::serve_active_schedule_xml();
            return;
        }

        // Webhook Telegram (аналог REST-маршрута /wp-json/school-schedule/v1/telegram-webhook)
        if ($uri === '/wp-json/school-schedule/v1/telegram-webhook' || $uri === '/telegram-webhook') {
            self::handle_telegram_webhook();
            return;
        }

        // Фронтенд расписания
        if ($uri === '/' || $uri === '/schedule' || $uri === '/raspisanie') {
            $GLOBALS['wp_app']['is_admin'] = false;
            self::render_frontend();
            return;
        }

        // Панели расписания (аналог страницы с шорткодом school_schedule_panels)
        if ($uri === '/panels') {
            $GLOBALS['wp_app']['is_admin'] = false;
            self::render_panels();
            return;
        }

        // Telegram-страница подписки (аналог шорткода telegram_schedule)
        if ($uri === '/telegram') {
            $GLOBALS['wp_app']['is_admin'] = false;
            self::render_telegram_page();
            return;
        }

        // Свободные учителя и кабинеты — только для авторизованных пользователей
        if ($uri === '/free' || $uri === '/svobodnye') {
            $GLOBALS['wp_app']['is_admin'] = false;
            self::render_free_staff_page();
            return;
        }

        // AJAX (аналог admin-ajax.php)
        if ($uri === '/ajax') {
            define('DOING_AJAX', true);
            $GLOBALS['wp_app']['is_admin'] = true;
            self::handle_ajax();
            return;
        }

        // Вход (обработка POST /login и GET /login)
        if ($uri === '/login') {
            $GLOBALS['wp_app']['is_admin'] = true;
            app_session_start();
            self::handle_login();
            return;
        }

        // Выход
        if ($uri === '/logout') {
            app_session_start();
            app_account_logout();
            wp_redirect(App_Config::site_url('admin'));
            exit;
        }

        // Аналог options.php — сохранение зарегистрированных настроек (register_setting)
        if ($uri === '/options' || $uri === '/options.php') {
            app_session_start();
            self::handle_options_post();
            return;
        }

        // Админка
        if ($uri === '/admin' || strpos($uri, '/admin') === 0) {
            $GLOBALS['wp_app']['is_admin'] = true;
            self::handle_admin($uri, $method);
            return;
        }

        // Soft-cron: попутно выполняем просроченные задачи планировщика
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>404</title></head>'
           . '<body><h1>Страница не найдена</h1></body></html>';
    }

    /* ------------------------------------------------------------------ */

    private static function handle_admin($uri, $method)
    {
        app_session_start();

        // Вход/выход
        if ($uri === '/login' || ($_POST['app_action'] ?? '') === 'login') {
            self::handle_login();
            return;
        }
        if ($uri === '/logout') {
            app_account_logout();
            wp_redirect(App_Config::site_url('admin'));
            exit;
        }

        if (!app_account_can_manage()) {
            $account = app_account_current();
            if ($account) {
                // Пользователь без прав администратора — на его страницу.
                wp_redirect(App_Config::site_url('free'));
                exit;
            }
            self::render_login_form();
            return;
        }

        // Обработка POST-действий админки через аналог admin-post.php
        if ($method === 'POST' && isset($_POST['action'])) {
            $action = 'admin_post_' . sanitize_text_field($_POST['action']);
            do_action($action);
            exit; // обработчики делают redirect/wp_die сами
        }

        // Активация меню-хуков (add_menu_page/add_submenu_page заполняют реестр)
        do_action('admin_menu');

        $page = $_GET['page'] ?? 'school-schedule';
        $hook = self::admin_hook_for($page);
        $GLOBALS['wp_app']['current_screen'] = $hook;
        do_action('admin_enqueue_scripts', $hook);

        // Выбор отображаемого callback
        $callback = null;
        foreach ($GLOBALS['wp_app']['submenu'] as $subs) {
            if (isset($subs[$page])) {
                $callback = $subs[$page]['callback'];
            }
        }
        if (!$callback && isset($GLOBALS['wp_app']['menu'][$page])) {
            $callback = $GLOBALS['wp_app']['menu'][$page]['callback'];
        }
        if (!$callback) {
            $callback = [$GLOBALS['wp_app']['menu']['school-schedule']['callback'] ?? null];
        }

        ob_start();
        if (is_callable($callback)) {
            call_user_func($callback);
        }
        $content = ob_get_clean();

        self::render_admin_shell($page, $content);
    }

    private static function admin_hook_for($page)
    {
        if ($page === 'school-schedule') {
            return 'toplevel_page_school-schedule';
        }
        return 'расписание_page_' . $page;
    }

    private static function render_admin_shell($page, $content)
    {
        $menu = $GLOBALS['wp_app']['menu'];
        $sub  = $GLOBALS['wp_app']['submenu']['school-schedule'] ?? [];

        // Минималистичные монохромные иконки: контур наследует цвет пункта меню.
        $nav_icon = static function (string $paths): string {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths . '</svg>';
        };
        $nav_icons = [
            'school-schedule' => $nav_icon('<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2.2"/><circle cx="15" cy="12" r="2.2"/><circle cx="8" cy="17" r="2.2"/>'),
            'schedule-users' => $nav_icon('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1.5A4.5 4.5 0 0 1 7 14h4a4.5 4.5 0 0 1 4.5 4.5V20"/><path d="M16.5 5.2a3.5 3.5 0 0 1 0 6.6"/><path d="M18 14h1a3.5 3.5 0 0 1 3.5 3.5V20"/>'),
            'schedule-site-settings' => $nav_icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.4 2.5 3.7 5.6 3.7 9S14.4 18.5 12 21c-2.4-2.5-3.7-5.6-3.7-9S9.6 5.5 12 3z"/>'),
            'schedule-updates' => $nav_icon('<path d="M21 12a9 9 0 1 1-3.2-6.9"/><path d="M21 4v5h-5"/>'),
            'schedule-telegram-settings' => $nav_icon('<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3" r="1"/><circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>'),
            'schedule-telegram-stats' => $nav_icon('<path d="M4 20V6M10 20V10M16 20v-7M22 20H2"/>'),
        ];
        $nav_icon_brand = $nav_icon('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>');
        $nav_icon_external = $nav_icon('<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>');
        $nav_icon_logout = $nav_icon('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>');
        ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Расписание — администрирование</title>
<style>
:root { --blue:#2271b1; --dark:#1d2327; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:#f0f0f1; color:#3c434a; }
.wrap-admin { display:flex; min-height:100vh; }
.admin-nav { width:220px; background:var(--dark); color:#fff; flex-shrink:0; }
.admin-nav .brand { display:flex; align-items:center; gap:10px; padding:16px; font-size:16px; font-weight:700; border-bottom:1px solid #3c434a; }
.admin-nav a { display:flex; align-items:center; gap:10px; color:#c3c4c7; text-decoration:none; padding:10px 16px; }
.admin-nav a:hover, .admin-nav a.active { background:#2c3338; color:#72aee6; }
.admin-nav-icon { width:20px; height:20px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; }
.admin-nav-icon svg { width:18px; height:18px; display:block; fill:none; stroke:currentColor; stroke-width:1.5; stroke-linecap:round; stroke-linejoin:round; }
.admin-body { flex:1; padding:20px 30px; overflow-x:auto; }
.button { display:inline-block; padding:6px 14px; border-radius:3px; border:1px solid #2271b1; background:#f6f7f7; color:var(--blue); cursor:pointer; text-decoration:none; font-size:13px; }
.button-primary { background:var(--blue); color:#fff; }
.notice { padding:10px 14px; border-left:4px solid var(--blue); background:#fff; margin:0 0 16px; box-shadow:0 1px 1px rgba(0,0,0,.04); }
.notice-error { border-left-color:#d63638; }
.card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:18px 22px; margin-bottom:18px; }
.card h2 { margin-top:0; font-size:17px; }
input[type=text], input[type=password], input[type=url], input[type=number], textarea, select { padding:6px 8px; border:1px solid #8c8f94; border-radius:3px; font-size:14px; max-width:100%; }
table.list { width:100%; border-collapse:collapse; background:#fff; }
table.list th, table.list td { text-align:left; padding:8px 10px; border-bottom:1px solid #dcdcde; font-size:14px; }
label { font-size:14px; }
.form-table th { text-align:left; padding:10px 10px 10px 0; vertical-align:top; }
.form-table td { padding:10px 0; }
code { background:#f0f0f1; padding:2px 5px; border-radius:3px; }
</style>
<?php app_print_favicon(); ?>
<?php app_print_enqueue_styles(); ?>
</head>
<body>
<div class="wrap-admin">
    <nav class="admin-nav">
        <div class="brand"><span class="admin-nav-icon"><?= $nav_icon_brand ?></span>Расписание</div>
        <?php
        foreach ($sub as $slug => $item) {
            $active = ($page === $slug) ? ' class="active"' : '';
            $icon = $nav_icons[$slug] ?? $nav_icon('<circle cx="12" cy="12" r="3"/>');
            echo '<a' . $active . ' href="' . esc_attr(App_Config::site_url('admin?page=' . $slug)) . '">'
                . '<span class="admin-nav-icon">' . $icon . '</span>'
                . esc_html($item['menu_title']) . '</a>';
        }
        ?>
        <a href="<?= esc_url(App_Config::site_url('/')) ?>"><span class="admin-nav-icon"><?= $nav_icon_external ?></span>Открыть расписание</a>
        <a href="<?= esc_url(App_Config::site_url('logout')) ?>"><span class="admin-nav-icon"><?= $nav_icon_logout ?></span>Выйти</a>
    </nav>
    <main class="admin-body">
        <?php settings_errors(); ?>
        <?= $content ?>
    </main>
</div>
<?php do_action('admin_footer'); app_print_enqueue_scripts(true); ?>
</body>
</html><?php
    }

    /* ------------------------------------------------------------------ */

    private static function handle_login()
    {
        $error = '';
        $redirect_to = (string) ($_POST['redirect_to'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $user = sanitize_text_field($_POST['app_user'] ?? '');
            $pass = (string) ($_POST['app_password'] ?? '');
            $cfg_user = (string) App_Config::get('admin.username', 'admin');
            // Пароль, изменённый из админки, хранится в опциях и имеет приоритет
            // над значением из config.php (на случай, если файл недоступен для записи).
            $hash = (string) get_option('schedule_admin_password_hash', '');
            if ($hash === '') {
                $hash = (string) App_Config::get('admin.password_hash', '');
            }
            if ($hash !== '' && $user === $cfg_user && password_verify($pass, $hash)) {
                session_regenerate_id(true);
                unset($_SESSION['schedule_account']);
                $_SESSION['schedule_admin'] = $user;
                $target = ($redirect_to === 'free') ? 'free' : 'admin';
                wp_redirect(App_Config::site_url($target));
                exit;
            }

            // Дополнительные пользователи из раздела «Пользователи».
            $result = app_accounts_login($user, $pass);
            if (!empty($result['ok'])) {
                $is_admin = ($result['role'] ?? 'viewer') === 'admin';
                $target = ($is_admin && $redirect_to !== 'free') ? 'admin' : 'free';
                wp_redirect(App_Config::site_url($target));
                exit;
            }
            $error = $result['error'] ?? 'Неверный логин или пароль.';
        }
        self::render_login_form($error, $redirect_to);
    }

    private static function render_login_form($error = '', $redirect_to = '')
    {
        ?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — Расписание</title>
<?php app_print_favicon(); ?>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.login-box{background:#fff;padding:32px;border:1px solid #c3c4c7;border-radius:6px;width:340px}
.login-box h1{font-size:20px;margin:0 0 18px}
.login-box input{width:100%;padding:9px 10px;margin:6px 0 14px;border:1px solid #8c8f94;border-radius:3px;box-sizing:border-box}
.login-box button{width:100%;padding:10px;background:#2271b1;color:#fff;border:0;border-radius:3px;font-size:15px;cursor:pointer}
.error{color:#d63638;font-size:13px;margin-bottom:10px}
</style></head>
<body>
<form class="login-box" method="post" action="<?= esc_url(App_Config::site_url('login')) ?>">
    <h1>📅 Панель управления расписанием</h1>
    <?php if ($error): ?><div class="error"><?= esc_html($error) ?></div><?php endif; ?>
    <label>Имя пользователя<input type="text" name="app_user" autocomplete="username" required></label>
    <label>Пароль<input type="password" name="app_password" autocomplete="current-password" required></label>
    <input type="hidden" name="app_action" value="login">
    <?php if ($redirect_to !== ''): ?>
        <input type="hidden" name="redirect_to" value="<?= esc_attr($redirect_to) ?>">
    <?php endif; ?>
    <button type="submit">Войти</button>
</form>
</body></html><?php
    }

    /* ------------------------------------------------------------------ */

    /**
     * Аналог wp-admin/options.php: сохраняет POST-поля, соответствующие
     * настройкам, зарегистрированным через register_setting($group, $name).
     */
    private static function handle_options_post()
    {
        if (!current_user_can('manage_options')) {
            self::render_login_form('Требуется вход в административную панель.');
            return;
        }
        $group = sanitize_text_field($_POST['option_page'] ?? $_POST['option_group'] ?? '');
        if ($group !== '' && isset($_POST['_wpnonce'])) {
            check_admin_referer($group); // аналог поведения options.php
        }

        // Реестр register_setting() заполняется в хуке admin_menu
        // (school-schedule.php::add_admin_menu). На прямом запросе
        // /options.php хук ещё не выполнялся — вызываем его, иначе
        // реестр пуст и настройки не сохранятся.
        if (empty($GLOBALS['wp_app']['registered_settings'])) {
            do_action('admin_menu');
        }

        $registry = $GLOBALS['wp_app']['registered_settings'] ?? [];
        $saved = 0;
        foreach ($registry as $name => $args) {
            if (!array_key_exists($name, $_POST)) {
                continue;
            }
            $value = wp_unslash($_POST[$name]);
            $sanitize = $args['sanitize_callback'] ?? null;
            if (is_callable($sanitize)) {
                $value = call_user_func($sanitize, $value, $name);
            }
            update_option($name, $value);
            $saved++;
        }

        // Redirect back: referrer или страница настроек бота по умолчанию
        $back = esc_url_raw(wp_get_referer() ?: App_Config::site_url('admin?page=schedule-telegram-settings'));
        $back = add_query_arg('settings-updated', 'true', $back);
        wp_redirect($back);
        exit;
    }

    /* ------------------------------------------------------------------ */

    private static function handle_ajax()
    {
        $action = 'wp_ajax_' . sanitize_text_field($_REQUEST['action'] ?? '');
        do_action($action);
        // Если обработчик не найден — WP возвращает 0
        echo '0';
        exit;
    }

    /* ------------------------------------------------------------------ */

    private static function handle_telegram_webhook()
    {
        require_once APP_ROOT . '/includes/rest-api.php';
        $plugin = new SchoolSchedule();
        $api = new ScheduleRestAPI($plugin);

        $request = new WP_REST_Request('POST');
        $request->set_body(file_get_contents('php://input') ?: '');
        foreach (getallheaders() as $k => $v) {
            $request->set_header($k, $v);
        }

        $result = $api->handle_webhook($request);
        if (is_wp_error($result)) {
            http_response_code((int) ($result->get_error_data()['status'] ?? 500));
            header('Content-Type: application/json; charset=UTF-8');
            echo wp_json_encode(['error' => $result->get_error_message()]);
            exit;
        }
        $status = ($result instanceof WP_REST_Response) ? $result->get_status() : 200;
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode(($result instanceof WP_REST_Response) ? $result->get_data() : ['status' => 'ok']);
        exit;
    }

    /* ------------------------------------------------------------------ */

    private static function instance()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new SchoolSchedule();
        }
        return $inst;
    }

    private static function render_frontend()
    {
        do_action('wp_enqueue_scripts');
        $plugin = self::instance();
        $content = $plugin->render_schedule(['view' => 'horizontal']);
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Расписание</title>
    <?php wp_head(); ?>
    <style>
        body { margin:0; padding:0; background:#ffffff;
               font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif; }
        .schedule-page { max-width:100%; margin:0; padding:0; }
    </style>
</head>
<body <?php body_class('schedule-page'); ?>>
    <div class="schedule-content"><?php echo $content; ?></div>
    <?php wp_footer(); ?>
</body>
</html><?php
    }

    private static function render_panels()
    {
        do_action('wp_enqueue_scripts');
        $plugin = self::instance();
        $content = method_exists($plugin, 'render_schedule_panels')
            ? $plugin->render_schedule_panels([])
            : $plugin->render_schedule(['is_panel' => '1']);
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Расписание — панели</title>
    <?php wp_head(); ?>
    <style>body{margin:0;background:#fff;font-family:-apple-system,"Segoe UI",Roboto,sans-serif}</style>
</head>
<body <?php body_class('schedule-page'); ?>>
    <div class="schedule-content"><?php echo $content; ?></div>
    <?php wp_footer(); ?>
</body>
</html><?php
    }

    private static function render_telegram_page()
    {
        do_action('wp_enqueue_scripts');
        $plugin = self::instance();
        $content = method_exists($plugin, 'render_telegram_schedule')
            ? $plugin->render_telegram_schedule([])
            : '<p>Раздел недоступен.</p>';
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telegram-подписка на расписание</title>
    <?php wp_head(); ?>
    <style>body{margin:0;background:#fff;font-family:-apple-system,"Segoe UI",Roboto,sans-serif}</style>
</head>
<body <?php body_class('schedule-page'); ?>>
    <div class="schedule-content"><?php echo $content; ?></div>
    <?php wp_footer(); ?>
</body>
</html><?php
    }

    /* ------------------------------------------------------------------ */

    /**
     * Страница свободных учителей и кабинетов.
     * Доступна встроенному администратору, администраторам-аккаунтам
     * и пользователям с флагом can_view_free.
     */
    private static function render_free_staff_page()
    {
        app_session_start();

        if (!app_account_can_view_free()) {
            if (app_account_current()) {
                self::render_forbidden_page();
            } else {
                self::render_login_form('Войдите, чтобы открыть свободные учителя и кабинеты.', 'free');
            }
            return;
        }

        require_once APP_ROOT . '/includes/free-staff.php';

        $schedule = self::load_schedule();
        $excluded = get_option('schedule_free_rooms_excluded', []);
        if (!is_array($excluded)) {
            $excluded = [];
        }
        $data = app_free_staff_build($schedule, $excluded);

        $day_id = isset($_GET['day']) ? (string) $_GET['day'] : app_free_staff_default_day($data);
        if ($day_id === '' || !isset($data['days'][$day_id])) {
            $day_id = app_free_staff_default_day($data);
        }

        $day_periods = $data['periodsByDay'][$day_id] ?? [];
        $period_id = isset($_GET['period']) ? (string) $_GET['period'] : app_free_staff_default_period($data, $day_id);
        if (!in_array($period_id, $day_periods, true)) {
            $period_id = app_free_staff_default_period($data, $day_id);
        }

        $free = app_free_staff_for($data, $day_id, $period_id);
        $current = app_account_current();

        // Данные для сеток: строки/столбцы и подробности занятых ячеек.
        $grid_periods = $data['periods'];
        foreach ($grid_periods as $grid_period_id => $grid_period) {
            $grid_periods[$grid_period_id]['label'] = app_free_staff_period_label($grid_period);
        }
        $grid_payload = [
            'days' => $data['days'],
            'periods' => $grid_periods,
            'rooms' => $data['rooms'],
            'teachers' => $data['teachers'],
            'roomLessons' => $data['roomLessons'],
            'teacherLessons' => $data['teacherLessons'],
            'usedPeriods' => $data['usedPeriods'],
            'selectedDay' => (string) $day_id,
        ];
        ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Свободные учителя и кабинеты</title>
<?php app_print_favicon(); ?>
<style>
:root{--blue:#2271b1;--blue-dark:#135e96;--bg:#f0f0f1;--card:#fff;--border:#dcdcde;--text:#1d2327;--muted:#646970;--chip:#eef4fb;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:15px}
.page{max-width:1100px;margin:0 auto;padding:20px 16px 40px}
.topbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:18px}
.title{font-size:22px;font-weight:700;margin:0}
.user{display:flex;flex-wrap:wrap;gap:10px;align-items:center;color:var(--muted);font-size:13px}
.user a{color:var(--blue);text-decoration:none}
.user a:hover{text-decoration:underline}
.card{background:var(--card);border:1px solid var(--border);border-radius:6px;padding:16px 18px;margin-bottom:16px}
.filters{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
.filters label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--muted)}
.filters select{padding:7px 9px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;background:#fff;min-width:220px;max-width:100%}
.summary{color:var(--muted);font-size:14px;margin:2px 0 0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
.panel h2{font-size:16px;margin:0 0 4px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.count{font-size:13px;font-weight:600;color:var(--blue);background:var(--chip);border-radius:999px;padding:3px 10px;white-space:nowrap}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.chip{background:var(--chip);border:1px solid #cfe0f2;border-radius:999px;padding:5px 11px;font-size:14px}
.chip small{color:var(--muted);font-size:12px}
.empty{color:var(--muted);font-size:14px;margin-top:12px}
.notice{background:#fff;border-left:4px solid #d63638;padding:10px 14px;border-radius:4px;margin-bottom:16px}
.filters-card{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;justify-content:space-between}
.grid-actions{display:flex;flex-wrap:wrap;gap:8px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border:1px solid var(--blue);border-radius:4px;background:#fff;color:var(--blue);font:inherit;font-size:14px;line-height:1.2;cursor:pointer}
.btn svg{width:16px;height:16px;flex-shrink:0;fill:none;stroke:currentColor;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round}
.btn:hover{background:#f0f6fc}
.btn:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.gm{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.gm.is-open{display:flex}
.gm-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55)}
.gm-dialog{position:relative;display:flex;flex-direction:column;width:min(1200px,100%);max-height:calc(100vh - 32px);background:#fff;border-radius:8px;box-shadow:0 24px 60px rgba(15,23,42,.3);overflow:hidden}
.gm-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--border)}
.gm-head h2{margin:0;font-size:18px}
.gm-sub{margin:4px 0 0;min-height:1em;color:var(--muted);font-size:13px}
.gm-close{border:0;background:none;padding:0 4px;color:var(--muted);font-size:26px;line-height:1;cursor:pointer}
.gm-close:hover{color:var(--text)}
.gm-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:12px 18px}
.gm-days{display:flex;flex-wrap:wrap;gap:6px}
.gm-day{border:1px solid var(--border);border-radius:6px;background:#fff;padding:6px 12px;color:var(--muted);font:inherit;font-size:13px;cursor:pointer}
.gm-day:hover{background:#f8fafc}
.gm-day.is-active{border-color:var(--blue);background:var(--blue);color:#fff}
.gm-legend{display:flex;gap:12px;color:var(--muted);font-size:12px}
.gm-legend span::before{content:"";display:inline-block;width:10px;height:10px;margin-right:6px;border-radius:3px;vertical-align:-1px}
.lg-free::before{background:#dcfce7}
.lg-busy::before{background:#fee2e2}
.gm-scroll{overflow:auto;padding:0 18px 8px}
.gm-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.gm-table th,.gm-table td{border-right:1px solid #eef0f2;border-bottom:1px solid #e5e7eb;padding:6px 4px;text-align:center;vertical-align:middle}
.gm-table thead th{position:sticky;top:0;z-index:2;background:#f8fafc;color:#334155;font-weight:600}
.gm-corner,.gm-row-head{position:sticky;left:0;z-index:1;background:#f8fafc;text-align:left;padding-left:10px}
.gm-table thead .gm-corner{z-index:3}
.gm-row-head{min-width:150px;max-width:190px;overflow:hidden;color:#334155;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
.gm-period{display:block;font-size:12px}
.gm-time{display:block;color:var(--muted);font-size:11px;font-variant-numeric:tabular-nums}
.gm-free-count{display:block;color:var(--blue);font-size:11px;font-weight:600}
.gm-cell.is-free{background:#f0fdf4;color:#16a34a}
.gm-cell.is-busy{background:#fef2f2;color:#b91c1c;font-weight:500;cursor:pointer}
.gm-cell.is-busy:hover{background:#fee2e2}
.gm-cell.is-current{box-shadow:inset 2px 0 0 rgba(59,130,246,.45),inset -2px 0 0 rgba(59,130,246,.45)}
.gm-cell-text{display:block;max-width:96px;margin:0 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gm-foot{border-top:1px solid var(--border);padding:10px 18px 14px;color:var(--muted);font-size:12px}
.dm-dialog{width:min(440px,100%)}
.dm-list{display:grid;grid-template-columns:auto 1fr;gap:8px 14px;margin:0;padding:16px 18px;font-size:14px}
.dm-list dt{color:var(--muted)}
.dm-list dd{margin:0}
.dm-actions{padding:0 18px 16px;text-align:right}
@media(max-width:480px){.chip{font-size:14px;padding:4px 10px}.page{padding:16px 12px 32px}.title{font-size:19px}}
@media(max-width:560px){.gm{padding:0}.gm-dialog{max-height:100vh;border-radius:0}.gm-row-head{min-width:120px;max-width:150px}.gm-table{font-size:11px}.gm-head h2{font-size:16px}}
</style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <h1 class="title">Свободные учителя и кабинеты</h1>
        <div class="user">
            <?php if ($current): ?>
                <span><?= esc_html($current['display_name']) ?></span>
            <?php endif; ?>
            <a href="<?= esc_url(App_Config::site_url('free')) ?>">Сбросить</a>
            <a href="<?= esc_url(App_Config::site_url('/')) ?>">Расписание</a>
            <a href="<?= esc_url(App_Config::site_url('logout')) ?>">Выйти</a>
        </div>
    </div>

    <?php if (!$data['days']): ?>
        <div class="notice">Расписание не загружено. Загрузите XML-файл в админке.</div>
    <?php else: ?>
        <div class="card filters-card">
            <form class="filters" method="get" action="<?= esc_url(App_Config::site_url('free')) ?>">
                <label>
                    <span>День</span>
                    <select name="day" onchange="this.form.submit()">
                        <?php foreach ($data['days'] as $id => $item): ?>
                            <option value="<?= esc_attr($id) ?>" <?= ((string) $id === $day_id) ? 'selected' : '' ?>>
                                <?= esc_html($item['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Урок</span>
                    <select name="period" onchange="this.form.submit()">
                        <?php foreach ($day_periods as $id): ?>
                            <?php $item = $data['periods'][$id] ?? null; if (!$item) { continue; } ?>
                            <option value="<?= esc_attr($id) ?>" <?= ((string) $id === $period_id) ? 'selected' : '' ?>>
                                <?= esc_html(app_free_staff_period_label($item)) ?><?php if ($item['start'] !== '' || $item['end'] !== ''): ?> · <?= esc_html($item['start']) ?>—<?= esc_html($item['end']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <div class="grid-actions">
                <button type="button" class="btn" data-grid-open="rooms">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    Сетка кабинетов
                </button>
                <button type="button" class="btn" data-grid-open="teachers">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="9" cy="8" r="3.5"/>
                        <path d="M2.5 20v-1.5A4.5 4.5 0 0 1 7 14h4a4.5 4.5 0 0 1 4.5 4.5V20"/>
                        <path d="M16.5 5.2a3.5 3.5 0 0 1 0 6.6"/>
                        <path d="M18 14h1a3.5 3.5 0 0 1 3.5 3.5V20"/>
                    </svg>
                    Сетка учителей
                </button>
            </div>
        </div>

        <div class="grid">
            <div class="card panel">
                <h2>
                    <span>Учителя</span>
                    <span class="count">Свободно <?= (int) count($free['teachers']) ?> из <?= (int) $free['totalTeachers'] ?></span>
                </h2>
                <?php if ($free['teachers']): ?>
                    <div class="chips">
                        <?php foreach ($free['teachers'] as $teacher): ?>
                            <span class="chip"><?= esc_html($teacher['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">Все учителя заняты на этом уроке.</div>
                <?php endif; ?>
            </div>

            <div class="card panel">
                <h2>
                    <span>Кабинеты</span>
                    <span class="count">Свободно <?= (int) count($free['rooms']) ?> из <?= (int) $free['totalRooms'] ?></span>
                </h2>
                <?php if ($free['rooms']): ?>
                    <div class="chips">
                        <?php foreach ($free['rooms'] as $room): ?>
                            <span class="chip"><?= esc_html($room['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">Все кабинеты заняты на этом уроке.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="gm" id="gridModal" aria-hidden="true">
            <div class="gm-backdrop" data-gm-close></div>
            <div class="gm-dialog" role="dialog" aria-modal="true" aria-labelledby="gmTitle">
                <div class="gm-head">
                    <div>
                        <h2 id="gmTitle">Сетка</h2>
                        <p class="gm-sub" id="gmSub"></p>
                    </div>
                    <button type="button" class="gm-close" data-gm-close aria-label="Закрыть">×</button>
                </div>
                <div class="gm-toolbar">
                    <div class="gm-days" id="gmDays" role="tablist" aria-label="День недели"></div>
                    <div class="gm-legend">
                        <span class="lg-free">Свободен</span>
                        <span class="lg-busy">Занят</span>
                    </div>
                </div>
                <div class="gm-scroll">
                    <table class="gm-table" id="gmTable"></table>
                </div>
                <div class="gm-foot" id="gmHint"></div>
            </div>
        </div>

        <div class="gm" id="gmDetail" aria-hidden="true">
            <div class="gm-backdrop" data-dm-close></div>
            <div class="gm-dialog dm-dialog" role="dialog" aria-modal="true" aria-labelledby="dmTitle">
                <div class="gm-head">
                    <div>
                        <h2 id="dmTitle">Подробности</h2>
                        <p class="gm-sub" id="dmSub"></p>
                    </div>
                    <button type="button" class="gm-close" data-dm-close aria-label="Закрыть">×</button>
                </div>
                <dl class="dm-list" id="dmList"></dl>
                <div class="dm-actions">
                    <button type="button" class="btn" data-dm-close>Закрыть</button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var DATA = <?= json_encode($grid_payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function entries(map) {
                return map ? Object.keys(map).map(function (key) { return [key, map[key]]; }) : [];
            }
            function byOrder(list) {
                return list.slice().sort(function (a, b) { return (a[1].order || 0) - (b[1].order || 0); });
            }
            function el(tag, className, text) {
                var node = document.createElement(tag);
                if (className) node.className = className;
                if (text !== undefined && text !== null) node.textContent = text;
                return node;
            }
            function toMinutes(value) {
                var match = /^(\d{1,2})[:.](\d{2})$/.exec(String(value || ''));
                return match ? Number(match[1]) * 60 + Number(match[2]) : null;
            }

            var days = byOrder(entries(DATA.days));
            var used = {};
            (DATA.usedPeriods || []).forEach(function (id) { used[String(id)] = true; });
            var periods = byOrder(entries(DATA.periods)).filter(function (item) {
                return !Object.keys(used).length || used[item[0]];
            });
            if (!periods.length) {
                periods = byOrder(entries(DATA.periods));
            }

            var mode = 'rooms';
            var activeDay = (DATA.selectedDay && DATA.days[DATA.selectedDay])
                ? String(DATA.selectedDay)
                : (days.length ? days[0][0] : '');

            var gridModal = document.getElementById('gridModal');
            var detailModal = document.getElementById('gmDetail');
            var titleEl = document.getElementById('gmTitle');
            var subEl = document.getElementById('gmSub');
            var daysEl = document.getElementById('gmDays');
            var tableEl = document.getElementById('gmTable');
            var hintEl = document.getElementById('gmHint');

            var titles = { rooms: 'Сетка свободных кабинетов', teachers: 'Сетка свободных учителей' };
            var cornerLabels = { rooms: 'Кабинет', teachers: 'Учитель' };
            var hints = {
                rooms: 'Нажмите на занятую ячейку, чтобы увидеть класс, предмет и учителя.',
                teachers: 'Нажмите на занятую ячейку, чтобы увидеть класс, предмет и кабинет.'
            };

            function todayId() {
                return String(new Date().getDay());
            }
            function currentPeriod() {
                if (activeDay !== todayId()) {
                    return '';
                }
                var now = new Date();
                var minutes = now.getHours() * 60 + now.getMinutes();
                for (var i = 0; i < periods.length; i++) {
                    var start = toMinutes(periods[i][1].start);
                    var end = toMinutes(periods[i][1].end);
                    if (start !== null && end !== null && minutes >= start && minutes <= end) {
                        return periods[i][0];
                    }
                }
                return '';
            }
            function lessonsForDay(dayId) {
                var source = mode === 'rooms' ? DATA.roomLessons : DATA.teacherLessons;
                return (source && source[dayId]) || {};
            }
            function rows() {
                return entries(mode === 'rooms' ? DATA.rooms : DATA.teachers);
            }

            function renderTabs() {
                daysEl.textContent = '';
                days.forEach(function (item) {
                    var id = item[0];
                    var day = item[1];
                    var button = el('button', 'gm-day' + (id === activeDay ? ' is-active' : ''), day.short || day.name);
                    button.type = 'button';
                    button.title = day.name;
                    button.setAttribute('role', 'tab');
                    button.setAttribute('aria-selected', id === activeDay ? 'true' : 'false');
                    button.addEventListener('click', function () {
                        activeDay = id;
                        renderTabs();
                        renderGrid();
                    });
                    daysEl.appendChild(button);
                });
            }

            function renderGrid() {
                var day = DATA.days[activeDay] || { name: '' };
                titleEl.textContent = titles[mode];
                subEl.textContent = day.name + (activeDay === todayId() ? ' · текущий урок подсвечен' : '');
                hintEl.textContent = hints[mode];

                var current = currentPeriod();
                var list = rows();
                var byPeriod = lessonsForDay(activeDay);

                tableEl.textContent = '';
                tableEl.setAttribute('aria-label', titles[mode] + ': ' + day.name);

                var thead = el('thead');
                var headRow = el('tr');
                var corner = el('th', 'gm-corner', cornerLabels[mode]);
                corner.scope = 'col';
                headRow.appendChild(corner);

                periods.forEach(function (item) {
                    var periodId = item[0];
                    var period = item[1];
                    var busyCount = Object.keys(byPeriod[periodId] || {}).length;
                    var th = el('th', 'gm-period-head' + (periodId === current ? ' is-current' : ''));
                    th.scope = 'col';
                    th.appendChild(el('span', 'gm-period', period.label || period.name));
                    if (period.start || period.end) {
                        th.appendChild(el('span', 'gm-time', period.start + '–' + period.end));
                    }
                    th.appendChild(el('span', 'gm-free-count', 'свободно ' + Math.max(0, list.length - busyCount)));
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                tableEl.appendChild(thead);

                var tbody = el('tbody');
                list.forEach(function (item) {
                    var rowId = item[0];
                    var rowData = item[1];
                    var row = el('tr');
                    var head = el('th', 'gm-row-head', rowData.name);
                    head.scope = 'row';
                    head.title = rowData.name;
                    row.appendChild(head);

                    periods.forEach(function (periodItem) {
                        var periodId = periodItem[0];
                        var lesson = (byPeriod[periodId] || {})[rowId];
                        var cell = el('td', 'gm-cell ' + (lesson ? 'is-busy' : 'is-free'));
                        if (periodId === current) {
                            cell.classList.add('is-current');
                        }

                        if (lesson) {
                            var classes = lesson.classes || [];
                            cell.appendChild(el('span', 'gm-cell-text', classes.length ? classes.join(', ') : 'Занят'));
                            cell.title = (lesson.subjects || []).concat(classes).join(' · ') || 'Занят';
                            cell.tabIndex = 0;
                            cell.setAttribute('role', 'button');
                            cell.addEventListener('click', function () {
                                openDetail(rowData, periodItem[1], lesson);
                            });
                            cell.addEventListener('keydown', function (event) {
                                if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                                    event.preventDefault();
                                    openDetail(rowData, periodItem[1], lesson);
                                }
                            });
                        } else {
                            cell.textContent = '✓';
                            cell.title = 'Свободен';
                        }
                        row.appendChild(cell);
                    });
                    tbody.appendChild(row);
                });
                tableEl.appendChild(tbody);
            }

            function openDetail(rowData, period, lesson) {
                var title = document.getElementById('dmTitle');
                var sub = document.getElementById('dmSub');
                var list = document.getElementById('dmList');
                var day = DATA.days[activeDay] || { name: '' };

                title.textContent = rowData.name;
                sub.textContent = day.name + ', ' + (period.label || period.name)
                    + (period.start ? ' · ' + period.start + '–' + period.end : '');
                list.textContent = '';

                var classes = (lesson.classesFull && lesson.classesFull.length) ? lesson.classesFull : (lesson.classes || []);
                var pairs = mode === 'rooms'
                    ? [
                        ['Предмет', (lesson.subjects || []).join(', ')],
                        ['Класс', classes.join(', ')],
                        ['Учитель', (lesson.teachers || []).join(', ')]
                    ]
                    : [
                        ['Предмет', (lesson.subjects || []).join(', ')],
                        ['Класс', classes.join(', ')],
                        ['Кабинет', (lesson.rooms || []).join(', ')]
                    ];

                pairs.forEach(function (pair) {
                    list.appendChild(el('dt', '', pair[0]));
                    list.appendChild(el('dd', '', pair[1] || '—'));
                });

                openModal(detailModal);
            }

            function openModal(modal) {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }
            function closeModal(modal) {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                if (!document.querySelector('.gm.is-open')) {
                    document.body.style.overflow = '';
                }
            }

            Array.prototype.forEach.call(document.querySelectorAll('[data-grid-open]'), function (button) {
                button.addEventListener('click', function () {
                    mode = button.getAttribute('data-grid-open') === 'teachers' ? 'teachers' : 'rooms';
                    renderTabs();
                    renderGrid();
                    openModal(gridModal);
                });
            });

            gridModal.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-gm-close')) {
                    closeModal(gridModal);
                }
            });
            detailModal.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-dm-close')) {
                    closeModal(detailModal);
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }
                if (detailModal.classList.contains('is-open')) {
                    closeModal(detailModal);
                } else if (gridModal.classList.contains('is-open')) {
                    closeModal(gridModal);
                }
            });
        })();
        </script>
    <?php endif; ?>
</div>
</body>
</html><?php
    }

    private static function render_forbidden_page()
    {
        http_response_code(403);
        ?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Доступ закрыт</title>
<?php app_print_favicon(); ?>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f0f1;font-family:-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327}
.box{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:28px 32px;max-width:420px;text-align:center}
h1{font-size:20px;margin:0 0 10px}
p{color:#646970;margin:0 0 18px}
a{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:9px 16px;border-radius:4px}
</style></head>
<body>
<div class="box">
    <h1>Доступ закрыт</h1>
    <p>У вашей учётной записи нет доступа к свободным учителям и кабинетам.</p>
    <a href="<?= esc_url(App_Config::site_url('logout')) ?>">Выйти</a>
</div>
</body></html><?php
    }

    /**
     * Разобрать активный XML расписания для закрытых страниц.
     */
    private static function load_schedule()
    {
        $file = self::active_schedule_file();
        if (!$file) {
            return [];
        }
        require_once APP_ROOT . '/includes/parser.php';
        try {
            $parser = new ScheduleParser();
            $result = $parser->parse($file);
            return $result['schedule'] ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /* ------------------------------------------------------------------ */

    private static function serve_asset($rel)
    {
        $file = realpath(APP_ROOT . '/assets/' . $rel);
        if ($file && strpos($file, realpath(APP_ROOT . '/assets')) === 0 && is_file($file)) {
            $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
                      'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
                      'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
                      'woff2' => 'font/woff2'];
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff2'], true)) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream') . (in_array($ext, ['css', 'js', 'svg'], true) ? '; charset=UTF-8' : ''));
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($file);
            exit;
        }
        http_response_code(404);
    }

    /**
     * Определяет активный XML-файл расписания.
     * Возвращает только файлы внутри каталога uploads/schedules.
     */
    private static function active_schedule_file()
    {
        $uploads = wp_upload_dir();
        $dir = rtrim($uploads['basedir'], '/\\') . '/schedules';
        $base = realpath($dir);

        $active = get_option('schedule_active_file');
        if ($active && is_file($active)) {
            $real = realpath($active);
            if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
                return $real;
            }
        }

        // Fallback: самый свежий XML в каталоге расписаний.
        $files = glob($dir . '/*.xml') ?: [];
        if (!$files) {
            return null;
        }
        usort($files, function ($a, $b) {
            $ta = filemtime($a) ?: 0;
            $tb = filemtime($b) ?: 0;
            if ($ta === $tb) {
                return strcmp($b, $a);
            }
            return $tb <=> $ta;
        });
        return $files[0];
    }

    /**
     * Публичная ссылка /schedule.xml: всегда отдаёт XML активного расписания.
     */
    private static function serve_active_schedule_xml()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        header('Access-Control-Allow-Headers: If-None-Match, If-Modified-Since');
        header('X-Content-Type-Options: nosniff');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(204);
            exit;
        }

        $file = self::active_schedule_file();
        if (!$file) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Активный XML-файл расписания не найден.';
            exit;
        }

        $mtime = filemtime($file) ?: time();
        $etag = '"' . md5_file($file) . '"';

        header('Content-Type: application/xml');
        header('Content-Disposition: inline; filename="' . basename($file) . '"');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Schedule-File: ' . basename($file));
        header('X-Schedule-Md5: ' . trim($etag, '"'));

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(304);
            exit;
        }

        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $mtime <= $since) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(304);
                exit;
            }
        }

        if ($method === 'HEAD') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            exit;
        }

        readfile($file);
        exit;
    }

    private static function serve_upload($rel)
    {
        $base = App_Config::get('uploads_dir', APP_ROOT . '/data/uploads');
        $file = realpath($base . '/' . $rel);
        if ($file && strpos($file, realpath($base)) === 0 && is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['xml' => 'application/xml', 'json' => 'application/json',
                      'ico' => 'image/x-icon', 'png' => 'image/png', 'jpg' => 'image/jpeg',
                      'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
                      'svg' => 'image/svg+xml'];
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff2'], true)) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream') . (in_array($ext, ['xml', 'json', 'svg'], true) ? '; charset=UTF-8' : ''));
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            exit;
        }
        http_response_code(404);
    }
}
