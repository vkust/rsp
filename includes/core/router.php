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
            unset($_SESSION['schedule_admin']);
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
            unset($_SESSION['schedule_admin']);
            wp_redirect(App_Config::site_url('admin'));
            exit;
        }

        if (!current_user_can('manage_options')) {
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
.admin-nav .brand { padding:16px; font-size:16px; font-weight:700; border-bottom:1px solid #3c434a; }
.admin-nav a { display:block; color:#c3c4c7; text-decoration:none; padding:10px 16px; }
.admin-nav a:hover, .admin-nav a.active { background:#2c3338; color:#72aee6; }
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
<?php app_print_enqueue_styles(); ?>
</head>
<body>
<div class="wrap-admin">
    <nav class="admin-nav">
        <div class="brand">📅 Расписание</div>
        <?php
        $items = [
            'school-schedule' => 'Основные настройки',
            'schedule-telegram-settings' => 'Telegram бот',
            'schedule-telegram-stats' => 'Статистика бота',
        ];
        foreach ($items as $slug => $title) {
            $active = ($page === $slug) ? ' class="active"' : '';
            echo '<a' . $active . ' href="' . esc_attr(App_Config::site_url('admin?page=' . $slug)) . '">' . esc_html($title) . '</a>';
        }
        ?>
        <a href="<?= esc_url(App_Config::site_url('/')) ?>">🔗 Открыть расписание</a>
        <a href="<?= esc_url(App_Config::site_url('logout')) ?>">Выйти</a>
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $user = sanitize_text_field($_POST['app_user'] ?? '');
            $pass = (string) ($_POST['app_password'] ?? '');
            $cfg_user = (string) App_Config::get('admin.username', 'admin');
            $hash = (string) App_Config::get('admin.password_hash', '');
            if ($hash !== '' && $user === $cfg_user && password_verify($pass, $hash)) {
                session_regenerate_id(true);
                $_SESSION['schedule_admin'] = $user;
                wp_redirect(App_Config::site_url('admin'));
                exit;
            }
            $error = 'Неверный логин или пароль.';
        }
        self::render_login_form($error);
    }

    private static function render_login_form($error = '')
    {
        ?><!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вход — Расписание</title>
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

    private static function serve_asset($rel)
    {
        $file = realpath(APP_ROOT . '/assets/' . $rel);
        if ($file && strpos($file, realpath(APP_ROOT . '/assets')) === 0 && is_file($file)) {
            $types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
                      'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2'];
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream') . '; charset=UTF-8');
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            exit;
        }
        http_response_code(404);
    }

    private static function serve_upload($rel)
    {
        $base = App_Config::get('uploads_dir', APP_ROOT . '/data/uploads');
        $file = realpath($base . '/' . $rel);
        if ($file && strpos($file, realpath($base)) === 0 && is_file($file)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $types = ['xml' => 'application/xml', 'json' => 'application/json',
                      'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                      'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            exit;
        }
        http_response_code(404);
    }
}
