<?php
/**
 * Слой совместимости с WordPress (shim).
 *
 * Предоставляет автономному приложению функции, классы и поведение,
 * на которые опирался код плагина: хуки (add_action/add_filter),
 * опции (get_option/update_option), трансиенты, нончаны, экранирование,
 * санитизация, URL-хелперы, планировщик (cron) и HTTP-клиент.
 *
 * Все функции объявлены в глобальном пространстве имён, поэтому
 * исходный код плагина работает без изменений.
 */

if (!defined('APP_ROOT')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Константы окружения WordPress
 * ---------------------------------------------------------------------- */
if (!defined('ABSPATH')) {
    define('ABSPATH', APP_ROOT . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('DAY_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);
}

$GLOBALS['wp_app'] = [
    'options'        => [],      // кэш опций
    'options_loaded' => false,
    'hooks'          => [],      // [tag][priority][] = callable
    'shortcodes'     => [],
    'settings_errors'=> [],
    'enqueued'       => ['scripts' => [], 'styles' => []],
    'localized'      => [],
    'is_admin'       => false,
    'menu'           => [],
    'submenu'        => [],
    'rest_routes'    => [],
    'actions_done'   => [],
];

/* -------------------------------------------------------------------------
 * Хуки: add_action / add_filter / do_action / apply_filters
 * ---------------------------------------------------------------------- */
if (!function_exists('add_action')) {
function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    return add_filter($tag, $callback, $priority, $accepted_args);
}
}

if (!function_exists('add_filter')) {
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
{
    global $wp_app;
    $wp_app['hooks'][$tag][$priority][] = [
        'fn' => $callback,
        'args' => $accepted_args,
    ];
    return true;
}
}

if (!function_exists('remove_filter')) {
function remove_filter($tag, $callback, $priority = 10)
{
    global $wp_app;
    if (empty($wp_app['hooks'][$tag][$priority])) {
        return false;
    }
    foreach ($wp_app['hooks'][$tag][$priority] as $i => $h) {
        if ($h['fn'] === $callback) {
            unset($wp_app['hooks'][$tag][$priority][$i]);
            return true;
        }
    }
    return false;
}
}

if (!function_exists('remove_action')) {
function remove_action($tag, $callback, $priority = 10)
{
    return remove_filter($tag, $callback, $priority);
}
}

if (!function_exists('has_filter')) {
function has_filter($tag, $callback = false)
{
    global $wp_app;
    if (empty($wp_app['hooks'][$tag])) {
        return false;
    }
    if ($callback === false) {
        return true;
    }
    foreach ($wp_app['hooks'][$tag] as $priority => $handlers) {
        foreach ($handlers as $h) {
            if ($h['fn'] === $callback) {
                return $priority;
            }
        }
    }
    return false;
}
}

if (!function_exists('has_action')) {
function has_action($tag, $callback = false)
{
    return has_filter($tag, $callback);
}
}

if (!function_exists('apply_filters')) {
function apply_filters($tag, $value, ...$args)
{
    global $wp_app;
    if (!empty($wp_app['hooks'][$tag])) {
        $handlers = $wp_app['hooks'][$tag];
        ksort($handlers);
        foreach ($handlers as $list) {
            foreach ($list as $h) {
                $params = array_merge([$value], $args);
                $params = array_slice($params, 0, max(1, (int) $h['args']));
                $value = call_user_func_array($h['fn'], $params);
            }
        }
    }
    return $value;
}
}

if (!function_exists('do_action')) {
function do_action($tag, ...$args)
{
    global $wp_app;
    if (!empty($wp_app['hooks'][$tag])) {
        $handlers = $wp_app['hooks'][$tag];
        ksort($handlers);
        foreach ($handlers as $list) {
            foreach ($list as $h) {
                $params = $h['args'] > 0 ? array_slice($args, 0, (int) $h['args']) : [];
                @call_user_func_array($h['fn'], $params);
            }
        }
    }
    $wp_app['actions_done'][$tag] = ($wp_app['actions_done'][$tag] ?? 0) + 1;
}
}

if (!function_exists('did_action')) {
function did_action($tag)
{
    return $GLOBALS['wp_app']['actions_done'][$tag] ?? 0;
}
}

if (!function_exists('__return_true')) {
function __return_true()  { return true; }
}
if (!function_exists('__return_false')) {
function __return_false() { return false; }
}

if (!function_exists('register_activation_hook')) {
function register_activation_hook($file, $callback)  { $GLOBALS['wp_app']['activation_cb'] = $callback; }
function register_deactivation_hook($file, $callback){ $GLOBALS['wp_app']['deactivation_cb'] = $callback; }
function register_uninstall_hook($file, $callback)   { $GLOBALS['wp_app']['uninstall_cb'] = $callback; }
}

/* -------------------------------------------------------------------------
 * Шорткоды
 * ---------------------------------------------------------------------- */
if (!function_exists('add_shortcode')) {
function add_shortcode($tag, $callback)
{
    $GLOBALS['wp_app']['shortcodes'][$tag] = $callback;
}
}

if (!function_exists('do_shortcode')) {
function do_shortcode($content)
{
    global $wp_app;
    foreach ($wp_app['shortcodes'] as $tag => $cb) {
        $content = preg_replace_callback('/\[' . preg_quote($tag, '/') . '([^\]]*)\]/', function ($m) use ($cb) {
            $atts = [];
            if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $m[1], $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $atts[$a[1]] = $a[2];
                }
            }
            return (string) call_user_func($cb, $atts);
        }, (string) $content);
    }
    return $content;
}
}

/* -------------------------------------------------------------------------
 * Опции (хранятся в таблице {prefix}options)
 * ---------------------------------------------------------------------- */
function app_options_table()
{
    $db = App_Database::instance();
    return $db->prefix . 'schedule_options';
}

function app_load_options()
{
    global $wp_app;
    if ($wp_app['options_loaded']) {
        return;
    }
    $wp_app['options_loaded'] = true;
    try {
        $db = App_Database::instance();
        $rows = $db->get_results("SELECT option_name, option_value FROM `" . app_options_table() . "`");
        foreach ((array) $rows as $row) {
            $val = @unserialize($row->option_value);
            $wp_app['options'][$row->option_name] = ($val === false && $row->option_value !== serialize(false))
                ? $row->option_value : $val;
        }
    } catch (Throwable $e) {
        // Таблица ещё не создана — работаем на дефолтах
    }
}

if (!function_exists('get_option')) {
function get_option($name, $default = false)
{
    global $wp_app;
    app_load_options();
    if (array_key_exists($name, $wp_app['options'])) {
        return $wp_app['options'][$name];
    }
    return $default;
}
}

if (!function_exists('update_option')) {
function update_option($name, $value, $autoload = null)
{
    global $wp_app;
    app_load_options();
    $old = get_option($name);
    $wp_app['options'][$name] = $value;
    try {
        $db = App_Database::instance();
        $encoded = serialize($value);
        $exists = $db->get_var($db->prepare(
            "SELECT COUNT(*) FROM `" . app_options_table() . "` WHERE option_name = %s", $name
        ));
        if ($exists) {
            $db->query($db->prepare(
                "UPDATE `" . app_options_table() . "` SET option_value = %s WHERE option_name = %s",
                $encoded, $name
            ));
        } else {
            $db->query($db->prepare(
                "INSERT INTO `" . app_options_table() . "` (option_name, option_value) VALUES (%s, %s)",
                $name, $encoded
            ));
        }
    } catch (Throwable $e) {
        error_log('update_option failed: ' . $e->getMessage());
    }
    do_action('update_option_' . $name, $old, $value);
    do_action('updated_option', $name, $old, $value);
    return true;
}
}

if (!function_exists('add_option')) {
function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (get_option($name, '__MISS__') !== '__MISS__') {
        return false;
    }
    return update_option($name, $value);
}
}

if (!function_exists('delete_option')) {
function delete_option($name)
{
    global $wp_app;
    app_load_options();
    unset($wp_app['options'][$name]);
    try {
        $db = App_Database::instance();
        $db->query($db->prepare("DELETE FROM `" . app_options_table() . "` WHERE option_name = %s", $name));
    } catch (Throwable $e) {
        return false;
    }
    return true;
}
}

/* -------------------------------------------------------------------------
 * Трансиенты (таблица опций с префиксом _transient_)
 * ---------------------------------------------------------------------- */
if (!function_exists('get_transient')) {
function get_transient($name)
{
    $val = get_option('_transient_' . $name);
    if ($val === false) {
        return false;
    }
    $expires = get_option('_transient_timeout_' . $name);
    if ($expires !== false && (int) $expires < time()) {
        delete_transient($name);
        return false;
    }
    return $val;
}
}

if (!function_exists('set_transient')) {
function set_transient($name, $value, $expiration = 0)
{
    update_option('_transient_' . $name, $value);
    if ($expiration > 0) {
        update_option('_transient_timeout_' . $name, time() + $expiration);
    } else {
        delete_option('_transient_timeout_' . $name);
    }
    return true;
}
}

if (!function_exists('delete_transient')) {
function delete_transient($name)
{
    delete_option('_transient_' . $name);
    delete_option('_transient_timeout_' . $name);
    return true;
}
}

/* -------------------------------------------------------------------------
 * Планировщик (замена WP-Cron). Задачи хранятся в таблице {prefix}cron.
 * ---------------------------------------------------------------------- */
function app_cron_table()
{
    $db = App_Database::instance();
    return $db->prefix . 'schedule_cron';
}

if (!function_exists('wp_get_schedules')) {
function wp_get_schedules()
{
    $schedules = [
        'hourly'       => ['interval' => 3600,   'display' => 'Каждый час'],
        'daily'        => ['interval' => 86400,  'display' => 'Каждый день'],
        'twicedaily'   => ['interval' => 43200,  'display' => 'Два раза в день'],
        'weekly'       => ['interval' => 604800, 'display' => 'Каждую неделю'],
        'every_minute' => ['interval' => 60,     'display' => 'Каждую минуту'],
    ];
    return apply_filters('cron_schedules', $schedules);
}
}

if (!function_exists('wp_schedule_event')) {
function wp_schedule_event($timestamp, $recurrence, $hook, $args = [])
{
    $db = App_Database::instance();
    $schedules = wp_get_schedules();
    $interval = $schedules[$recurrence]['interval'] ?? 3600;
    $existing = $db->get_var($db->prepare(
        "SELECT id FROM `" . app_cron_table() . "` WHERE hook = %s AND args = %s",
        $hook, serialize($args)
    ));
    if ($existing) {
        $db->query($db->prepare(
            "UPDATE `" . app_cron_table() . "` SET timestamp = %d, interval = %d, recurrence = %s WHERE id = %d",
            (int) $timestamp, (int) $interval, $recurrence, (int) $existing
        ));
        return true;
    }
    $db->query($db->prepare(
        "INSERT INTO `" . app_cron_table() . "` (timestamp, interval, recurrence, hook, args) VALUES (%d, %d, %s, %s, %s)",
        (int) $timestamp, (int) $interval, $recurrence, $hook, serialize($args)
    ));
    return true;
}
}

if (!function_exists('wp_schedule_single_event')) {
function wp_schedule_single_event($timestamp, $hook, $args = [])
{
    $db = App_Database::instance();
    $db->query($db->prepare(
        "INSERT INTO `" . app_cron_table() . "` (timestamp, interval, recurrence, hook, args) VALUES (%d, %d, %s, %s, %s)",
        (int) $timestamp, 0, 'single', $hook, serialize($args)
    ));
    return true;
}
}

if (!function_exists('wp_next_scheduled')) {
function wp_next_scheduled($hook, $args = [])
{
    try {
        $db = App_Database::instance();
        $ts = $db->get_var($db->prepare(
            "SELECT timestamp FROM `" . app_cron_table() . "` WHERE hook = %s AND args = %s ORDER BY timestamp ASC",
            $hook, serialize($args)
        ));
        return $ts ? (int) $ts : false;
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('wp_unschedule_event')) {
function wp_unschedule_event($timestamp, $hook, $args = [])
{
    $db = App_Database::instance();
    $db->query($db->prepare(
        "DELETE FROM `" . app_cron_table() . "` WHERE hook = %s AND args = %s AND timestamp = %d",
        $hook, serialize($args), (int) $timestamp
    ));
    return true;
}
}

if (!function_exists('wp_clear_scheduled_hook')) {
function wp_clear_scheduled_hook($hook, $args = [])
{
    try {
        $db = App_Database::instance();
        $db->query($db->prepare(
            "DELETE FROM `" . app_cron_table() . "` WHERE hook = %s AND args = %s",
            $hook, serialize($args)
        ));
    } catch (Throwable $e) {
        return false;
    }
    return true;
}
}

/**
 * Запуск просроченных задач. Вызывается из index.php (soft cron) и cron-run.php.
 */
function app_run_cron()
{
    try {
        $db = App_Database::instance();
        $due = $db->get_results($db->prepare(
            "SELECT * FROM `" . app_cron_table() . "` WHERE timestamp <= %d", time()
        ));
    } catch (Throwable $e) {
        return;
    }
    foreach ((array) $due as $task) {
        $args = @unserialize((string) $task->args) ?: [];
        do_action($task->hook, ...$args);
        if ((int) $task->interval > 0) {
            $next = time() + (int) $task->interval;
            $db->query($db->prepare(
                "UPDATE `" . app_cron_table() . "` SET timestamp = %d WHERE id = %d",
                $next, (int) $task->id
            ));
        } else {
            $db->query($db->prepare(
                "DELETE FROM `" . app_cron_table() . "` WHERE id = %d", (int) $task->id
            ));
        }
    }
}

/* -------------------------------------------------------------------------
 * URL-хелперы
 * ---------------------------------------------------------------------- */
if (!function_exists('home_url')) {
function home_url($path = '')
{
    return App_Config::site_url(ltrim((string) $path, '/'));
}
}
if (!function_exists('site_url')) {
function site_url($path = '')
{
    return home_url($path);
}
}
if (!function_exists('get_site_url')) {
function get_site_url($blog_id = null, $path = '')
{
    return home_url($path);
}
}
if (!function_exists('get_home_url')) {
function get_home_url($blog_id = null, $path = '')
{
    return home_url($path);
}
}
if (!function_exists('admin_url')) {
function admin_url($path = '')
{
    $path = ltrim((string) $path, '/');
    if ($path === '' || $path === 'admin.php' || strpos($path, 'options-general.php') === 0) {
        return App_Config::site_url('admin');
    }
    if (strpos($path, 'admin-ajax.php') === 0) {
        return App_Config::site_url('ajax');
    }
    return App_Config::site_url('admin/' . $path);
}
}
if (!function_exists('includes_url')) {
function includes_url($path = '')   { return App_Config::site_url('assets/' . ltrim((string) $path, '/')); }
}
if (!function_exists('plugins_url')) {
function plugins_url($path = '', $plugin = '') { return APP_URL . '/' . ltrim((string) $path, '/'); }
}
if (!function_exists('plugin_dir_path')) {
function plugin_dir_path($file)     { return APP_ROOT . '/'; }
}
if (!function_exists('plugin_dir_url')) {
function plugin_dir_url($file)      { return APP_URL . '/'; }
}
if (!function_exists('plugin_basename')) {
function plugin_basename($file)     { return basename(dirname($file)) . '/' . basename($file); }
}

if (!function_exists('add_query_arg')) {
function add_query_arg(...$args)
{
    if (count($args) >= 2 && !is_array($args[0]) && !is_array($args[1])) {
        // add_query_arg(key, value, url)
        $key = $args[0]; $value = $args[1];
        $url = $args[2] ?? (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
        $args = [[ $key => $value ], $url];
    }
    $data = $args[0] ?? [];
    $url = $args[1] ?? (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    if (!is_array($data)) {
        $data = [];
    }
    $frag = '';
    if (($pos = strpos($url, '#')) !== false) {
        $frag = substr($url, $pos);
        $url = substr($url, 0, $pos);
    }
    $parts = parse_url($url);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    foreach ($data as $k => $v) {
        if ($v === false) {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    $newurl = '';
    if (!empty($parts['scheme'])) {
        $newurl .= $parts['scheme'] . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $newurl .= ':' . $parts['port'];
        }
        $newurl .= $parts['path'] ?? '';
    } else {
        $newurl = $parts['path'] ?? $url;
    }
    if ($query) {
        $newurl .= '?' . http_build_query($query);
    }
    return $newurl . $frag;
}
}

if (!function_exists('remove_query_arg')) {
function remove_query_arg($key, $url = false)
{
    $keys = is_array($key) ? $key : [$key];
    $data = [];
    foreach ($keys as $k) {
        $data[$k] = false;
    }
    return add_query_arg($data, $url ?: ($_SERVER['REQUEST_URI'] ?? ''));
}
}

/* -------------------------------------------------------------------------
 * Пути загрузок (аналог wp_upload_dir)
 * ---------------------------------------------------------------------- */
if (!function_exists('wp_upload_dir')) {
function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false)
{
    $basedir = rtrim(App_Config::get('uploads_dir', APP_ROOT . '/data/uploads'), '/');
    if ($create_dir && !is_dir($basedir)) {
        @mkdir($basedir, 0775, true);
    }
    // baseurl строим от APP_URL (авто-определяется из запроса в bootstrap.php),
    // чтобы относительные URL загрузок работали на любом хосте/порту.
    $baseurl = rtrim(APP_URL, '/') . '/uploads';
    return [
        'path'     => $basedir,
        'url'      => $baseurl,
        'subdir'   => '',
        'basedir'  => $basedir,
        'baseurl'  => $baseurl,
        'error'    => false,
    ];
}
}

if (!function_exists('wp_mkdir_p')) {
function wp_mkdir_p($target)
{
    if (is_dir($target)) {
        return true;
    }
    return @mkdir($target, 0775, true);
}
}

/* -------------------------------------------------------------------------
 * Экранирование и санитизация
 * ---------------------------------------------------------------------- */
if (!function_exists('esc_html')) {
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
}
if (!function_exists('esc_attr')) {
function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
}
if (!function_exists('esc_textarea')) {
function esc_textarea($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
}
if (!function_exists('esc_url')) {
function esc_url($url)
{
    $url = (string) $url;
    $url = preg_replace('/[^%a-zA-Z0-9\x7f-\xff\-_.,:\/\?#&;=~+$@!*\'()\[\] ]/', '', $url);
    $url = str_replace(' ', '%20', $url);
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}
}
if (!function_exists('esc_url_raw')) {
function esc_url_raw($url)
{
    $url = (string) $url;
    $url = preg_replace('/[^%a-zA-Z0-9\x7f-\xff\-_.,:\/\?#&;=~+$@!*\'()\[\] ]/', '', $url);
    return str_replace(' ', '%20', $url);
}
}
if (!function_exists('esc_js')) {
function esc_js($text)
{
    $safe_text = preg_replace('/\\\\/', '\\\\\\\\', (string) $text);
    return addslashes(htmlspecialchars($safe_text, ENT_QUOTES, 'UTF-8', false));
}
}
if (!function_exists('sanitize_text_field')) {
function sanitize_text_field($str)
{
    $str = (string) $str;
    $str = wp_strip_all_tags($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    return trim($str);
}
}
if (!function_exists('sanitize_textarea_field')) {
function sanitize_textarea_field($str)
{
    $str = wp_strip_all_tags((string) $str);
    return trim(preg_replace('~\s+(\r\n|\r|\n)$~s', '$1', $str));
}
}
if (!function_exists('sanitize_file_name')) {
function sanitize_file_name($filename)
{
    $filename = (string) $filename;
    $filename = preg_replace('/[^A-Za-z0-9\x7f-\xff_..-]/u', '-', $filename);
    $filename = preg_replace('/-+/', '-', $filename);
    return trim($filename, '-.');
}
}
if (!function_exists('sanitize_key')) {
function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}
}
if (!function_exists('sanitize_hex_color')) {
function sanitize_hex_color($color)
{
    $color = (string) $color;
    if ($color === '') {
        return '';
    }
    if (preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color)) {
        return $color;
    }
    return null;
}
}
if (!function_exists('wp_strip_all_tags')) {
function wp_strip_all_tags($string, $remove_breaks = false)
{
    $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string);
    $string = strip_tags($string);
    if ($remove_breaks) {
        $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
    }
    return trim($string);
}
}
if (!function_exists('wp_kses_post')) {
function wp_kses_post($content)
{
    $allowed = '<a><b><strong><i><em><u><s><p><br><ul><ol><li><table><thead><tbody><tr><th><td><span><div><h1><h2><h3><h4><h5><h6><img><blockquote><pre><code>';
    $content = preg_replace('@<(script|style|iframe|object|embed|form)[^>]*?>.*?</\\1>@siU', '', (string) $content);
    return strip_tags($content, $allowed);
}
}
if (!function_exists('absint')) {
function absint($maybeint)
{
    return abs((int) $maybeint);
}
}
if (!function_exists('wp_json_encode')) {
function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options | JSON_UNESCAPED_UNICODE, $depth);
}
}
if (!function_exists('wp_unslash')) {
function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return stripslashes((string) $value);
}
}
if (!function_exists('wp_parse_args')) {
function wp_parse_args($args, $defaults = [])
{
    if (is_object($args)) {
        $args = get_object_vars($args);
    } elseif (is_string($args)) {
        parse_str($args, $args);
    }
    return array_merge((array) $defaults, (array) $args);
}
}
if (!function_exists('shortcode_atts')) {
function shortcode_atts($defaults, $atts, $shortcode = '')
{
    $atts = (array) $atts;
    $out = [];
    foreach ($defaults as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $out;
}
}

/* -------------------------------------------------------------------------
 * Даты/время (аналог current_time / date_i18n)
 * ---------------------------------------------------------------------- */
if (!function_exists('current_time')) {
function current_time($type, $gmt = 0)
{
    $tz = new DateTimeZone(App_Config::get('timezone', 'Europe/Moscow'));
    if ($type === 'timestamp' || $type === 'U') {
        return time();
    }
    if ($type === 'mysql') {
        return (new DateTime('now', $tz))->format('Y-m-d H:i:s');
    }
    return (new DateTime('now', $tz))->format($type);
}
}
if (!function_exists('date_i18n')) {
function date_i18n($format, $timestamp = null)
{
    $tz = new DateTimeZone(App_Config::get('timezone', 'Europe/Moscow'));
    $dt = $timestamp ? (new DateTime('@' . (int) $timestamp))->setTimezone($tz) : new DateTime('now', $tz);
    return $dt->format($format);
}
}
if (!function_exists('human_time_diff')) {
function human_time_diff($from, $to = 0)
{
    if (!$to) {
        $to = time();
    }
    $diff = abs($to - $from);
    if ($diff < HOUR_IN_SECONDS) {
        return max(1, (int) round($diff / MINUTE_IN_SECONDS)) . ' мин';
    }
    if ($diff < DAY_IN_SECONDS) {
        return (int) round($diff / HOUR_IN_SECONDS) . ' ч';
    }
    return (int) round($diff / DAY_IN_SECONDS) . ' дн';
}
}

/* -------------------------------------------------------------------------
 * Сессии, нончаны и проверка прав (модель для одного администратора)
 * ---------------------------------------------------------------------- */
function app_session_start()
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent() && PHP_SAPI !== 'cli') {
        session_name('SCHEDULE_SESS');
        @session_start();
    }
}

if (!function_exists('wp_create_nonce')) {
function wp_create_nonce($action = -1)
{
    app_session_start();
    $secret = (string) App_Config::get('admin.session_secret', 'salt');
    $uid = $_SESSION['schedule_admin'] ?? 'anon';
    return substr(md5($secret . '|' . $action . '|' . $uid), 0, 10);
}
}
if (!function_exists('wp_verify_nonce')) {
function wp_verify_nonce($nonce, $action = -1)
{
    return hash_equals(wp_create_nonce($action), (string) $nonce) ? 1 : false;
}
}
if (!function_exists('wp_nonce_field')) {
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
{
    $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';
    if ($echo) {
        echo $field;
    }
    return $field;
}
}
if (!function_exists('wp_nonce_url')) {
function wp_nonce_url($actionurl, $action = -1, $name = '_wpnonce')
{
    return add_query_arg($name, wp_create_nonce($action), $actionurl);
}
}
if (!function_exists('check_admin_referer')) {
function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    $nonce = $_REQUEST[$query_arg] ?? '';
    $result = wp_verify_nonce($nonce, $action);
    if (!$result) {
        wp_die('Просроченная защита ссылки. Пожалуйста, обновите страницу и повторите попытку.', 403);
    }
    return $result;
}
}
if (!function_exists('current_user_can')) {
function current_user_can($capability, ...$args)
{
    // В автономном режиме права есть у залогиненного администратора.
    // Для CLI (cron) и обработки webhook Telegram права не требуются.
    if (PHP_SAPI === 'cli') {
        return true;
    }
    app_session_start();
    return !empty($_SESSION['schedule_admin']);
}
}
if (!function_exists('is_user_logged_in')) {
function is_user_logged_in()
{
    app_session_start();
    return !empty($_SESSION['schedule_admin']);
}
}
if (!function_exists('is_admin')) {
function is_admin()
{
    return (bool) $GLOBALS['wp_app']['is_admin'];
}
}
if (!function_exists('wp_doing_ajax')) {
function wp_doing_ajax()
{
    return defined('DOING_AJAX') && DOING_AJAX;
}
}
if (!function_exists('wp_doing_cron')) {
function wp_doing_cron()
{
    return defined('DOING_CRON') && DOING_CRON;
}
}

/* -------------------------------------------------------------------------
 * JSON-ответы (AJAX)
 * ---------------------------------------------------------------------- */
if (!function_exists('wp_send_json_success')) {
function wp_send_json_success($data = null, $status_code = null)
{
    if ($status_code) {
        http_response_code($status_code);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo wp_json_encode(['success' => true, 'data' => $data]);
    exit;
}
}
if (!function_exists('wp_send_json_error')) {
function wp_send_json_error($data = null, $status_code = null)
{
    if ($status_code) {
        http_response_code($status_code);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo wp_json_encode(['success' => false, 'data' => $data]);
    exit;
}
}
if (!function_exists('wp_send_json')) {
function wp_send_json($response, $status_code = null)
{
    if ($status_code) {
        http_response_code($status_code);
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo wp_json_encode($response);
    exit;
}
}

/* -------------------------------------------------------------------------
 * Прочее
 * ---------------------------------------------------------------------- */
if (!function_exists('wp_die')) {
function wp_die($message = '', $title = '', $args = [])
{
    if (is_int($args)) {
        http_response_code($args);
        $args = [];
    }
    if (is_object($message) && method_exists($message, 'get_error_message')) {
        $message = $message->get_error_message();
    }
    error_log('wp_die: ' . print_r($message, true));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, strip_tags((string) $message) . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(is_array($args) && isset($args['response']) ? $args['response'] : 403);
    }
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title>Ошибка</title></head><body>';
    echo '<h1>Ошибка</h1><p>' . esc_html(is_string($message) ? $message : print_r($message, true)) . '</p>';
    echo '</body></html>';
    exit;
}
}

if (!function_exists('wp_redirect')) {
function wp_redirect($location, $status = 302)
{
    if (!headers_sent()) {
        header('Location: ' . str_replace(["\n", "\r"], ['', ''], $location), true, $status);
    }
    return true;
}
}
if (!function_exists('wp_safe_redirect')) {
function wp_safe_redirect($location, $status = 302)
{
    return wp_redirect($location, $status);
}
}
if (!function_exists('language_attributes')) {
function language_attributes()
{
    echo 'lang="ru-RU"';
}
}
if (!function_exists('bloginfo')) {
function bloginfo($show = '')
{
    switch ($show) {
        case 'charset':
            echo 'UTF-8';
            break;
        case 'name':
        case 'title':
            echo get_option('blogname', 'Расписание занятий');
            break;
        default:
            echo '';
    }
}
}
if (!function_exists('body_class')) {
function body_class($class = '')
{
    echo 'class="' . esc_attr(trim('schedule-app ' . $class)) . '"';
}
}
if (!function_exists('get_bloginfo')) {
function get_bloginfo($show = '')
{
    ob_start();
    bloginfo($show);
    return ob_get_clean();
}
}

if (!function_exists('wp_head')) {
function wp_head()
{
    do_action('wp_head');
    app_print_enqueue_styles();
    app_print_enqueue_scripts(false);
}
}
if (!function_exists('wp_footer')) {
function wp_footer()
{
    do_action('wp_footer');
    app_print_enqueue_scripts(true);
}
}

/* Enqueue-система ---------------------------------------------------------- */
if (!function_exists('wp_enqueue_style')) {
function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
{
    $GLOBALS['wp_app']['enqueued']['styles'][$handle] = ['src' => $src, 'ver' => $ver];
}
}
if (!function_exists('wp_register_style')) {
function wp_register_style($handle, $src = '', $deps = [], $ver = false, $media = 'all')
{
    wp_enqueue_style($handle, $src, $deps, $ver, $media);
}
}
if (!function_exists('wp_style_is')) {
function wp_style_is($handle, $list = 'enqueued')
{
    return isset($GLOBALS['wp_app']['enqueued']['styles'][$handle]);
}
}
if (!function_exists('wp_enqueue_script')) {
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
{
    $GLOBALS['wp_app']['enqueued']['scripts'][$handle] = ['src' => $src, 'ver' => $ver, 'footer' => $in_footer];
}
}
if (!function_exists('wp_register_script')) {
function wp_register_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false)
{
    wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
}
}
if (!function_exists('wp_script_is')) {
function wp_script_is($handle, $list = 'enqueued')
{
    return isset($GLOBALS['wp_app']['enqueued']['scripts'][$handle]);
}
}
if (!function_exists('wp_localize_script')) {
function wp_localize_script($handle, $object_name, $l10n)
{
    $GLOBALS['wp_app']['localized'][$handle][] = [$object_name, $l10n];
    return true;
}
}
if (!function_exists('wp_add_inline_script')) {
function wp_add_inline_script($handle, $data, $position = 'after')
{
    $GLOBALS['wp_app']['localized'][$handle][] = [null, $data, 'inline'];
    return true;
}
}

function app_print_enqueue_styles()
{
    foreach ($GLOBALS['wp_app']['enqueued']['styles'] as $handle => $s) {
        if (!$s['src']) {
            continue;
        }
        $ver = $s['ver'] ? '?ver=' . rawurlencode((string) $s['ver']) : '';
        echo '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($s['src'] . $ver) . '" media="all" />' . "\n";
    }
}

function app_print_enqueue_scripts($footer)
{
    foreach ($GLOBALS['wp_app']['enqueued']['scripts'] as $handle => $s) {
        if ((bool) $s['footer'] !== $footer) {
            continue;
        }
        if (!$s['src']) {
            // handle-only registration (jquery и т.п.) — пропускаем
            continue;
        }
        $ver = $s['ver'] ? '?ver=' . rawurlencode((string) $s['ver']) : '';
        echo '<script src="' . esc_url($s['src'] . $ver) . '"></script>' . "\n";
        if (!empty($GLOBALS['wp_app']['localized'][$handle])) {
            foreach ($GLOBALS['wp_app']['localized'][$handle] as $loc) {
                if (($loc[2] ?? '') === 'inline') {
                    echo '<script>' . $loc[1] . '</script>' . "\n";
                } else {
                    echo '<script>var ' . $loc[0] . ' = ' . wp_json_encode($loc[1]) . ';</script>' . "\n";
                }
            }
        }
    }
}

/* Настройки (Settings API) -------------------------------------------------- */
if (!function_exists('register_setting')) {
function register_setting($group, $name, $args = [])
{
    $GLOBALS['wp_app']['registered_settings'][$name] = $args;
}
}
if (!function_exists('settings_fields')) {
function settings_fields($option_group)
{
    echo '<input type="hidden" name="option_group" value="' . esc_attr($option_group) . '" />';
    wp_nonce_field($option_group);
}
}
if (!function_exists('do_settings_sections')) {
// В этом коде секции/поля настроек через add_settings_section/add_settings_field
// не регистрируются (шаблоны рендерят markup напрямую) — функция-заглушка.
function do_settings_sections($page)
{
}
}
if (!function_exists('do_settings_fields')) {
function do_settings_fields($page, $section)
{
}
}
if (!function_exists('add_settings_section')) {
function add_settings_section($id, $title, $callback, $page, $args = [])
{
}
}
if (!function_exists('add_settings_field')) {
function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = [])
{
}
}
if (!function_exists('add_settings_error')) {
function add_settings_error($setting, $code, $message, $type = 'error')
{
    $GLOBALS['wp_app']['settings_errors'][] = [
        'setting' => $setting, 'code' => $code, 'message' => $message, 'type' => $type,
    ];
}
}
if (!function_exists('get_settings_errors')) {
function get_settings_errors($setting = '', $type = 'all')
{
    $errors = $GLOBALS['wp_app']['settings_errors'] ?? [];
    if ($setting !== '') {
        $errors = array_values(array_filter($errors, fn($e) => $e['setting'] === $setting));
    }
    if ($type !== 'all') {
        $errors = array_values(array_filter($errors, fn($e) => $e['type'] === $type));
    }
    return $errors;
}
}
if (!function_exists('settings_errors')) {
function settings_errors($setting = '', $sanitize = false, $hide_on_update = false)
{
    $errors = get_settings_errors($setting);
    foreach ($errors as $e) {
        $cls = $e['type'] === 'updated' ? 'notice notice-success' : 'notice notice-error';
        echo '<div class="' . $cls . ' is-dismissible"><p>' . esc_html($e['message']) . '</p></div>';
    }
}
}
if (!function_exists('checked')) {
function checked($checked, $current = true, $echo = true)
{
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}
}
if (!function_exists('selected')) {
function selected($selected, $current = true, $echo = true)
{
    $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}
}
if (!function_exists('disabled')) {
function disabled($disabled, $current = true, $echo = true)
{
    $result = ((string) $disabled === (string) $current) ? ' disabled="disabled"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}
}
if (!function_exists('submit_button')) {
function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = '')
{
    if (!$text) {
        $text = 'Сохранить изменения';
    }
    echo '<p class="submit"><button type="submit" name="' . esc_attr($name) . '" class="button button-' . esc_attr($type) . '" ' . $other_attributes . '>' . esc_html($text) . '</button></p>';
}
}

/* HTTP-клиент (cURL) --------------------------------------------------------- */
class App_HTTP_Response
{
    private $body;
    private $code;
    private $headers;

    public function __construct($body, $code, $headers = [])
    {
        $this->body = $body;
        $this->code = $code;
        $this->headers = $headers;
    }

    public function get_body()        { return $this->body; }
    public function get_status_code() { return $this->code; }
    public function get_headers()     { return $this->headers; }
}

function app_http_request($url, $args = [])
{
    $method = strtoupper($args['method'] ?? 'GET');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $args['timeout'] ?? 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $headers = [];
    foreach (($args['headers'] ?? []) as $k => $v) {
        $headers[] = "$k: $v";
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $body = $args['body'] ?? '';
        if (is_array($body)) {
            $body = http_build_query($body);
            $has_ct = false;
            foreach ($headers as $h) {
                if (stripos($h, 'Content-Type:') === 0) {
                    $has_ct = true;
                }
            }
            if (!$has_ct) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno) {
        return new WP_Error('http_request_failed', $error);
    }
    return new App_HTTP_Response((string) $response, $code);
}

if (!function_exists('wp_remote_get')) {
function wp_remote_get($url, $args = [])
{
    $args['method'] = 'GET';
    return app_http_request($url, $args);
}
}
if (!function_exists('wp_remote_post')) {
function wp_remote_post($url, $args = [])
{
    $args['method'] = 'POST';
    if (isset($args['body']) && is_array($args['body']) && empty($args['headers'])) {
        // WP посылает массив как form-data
    }
    return app_http_request($url, $args);
}
}
if (!function_exists('wp_remote_retrieve_body')) {
function wp_remote_retrieve_body($response)
{
    if ($response instanceof App_HTTP_Response) {
        return $response->get_body();
    }
    if (is_array($response) && isset($response['body'])) {
        return $response['body'];
    }
    return '';
}
}
if (!function_exists('wp_remote_retrieve_response_code')) {
function wp_remote_retrieve_response_code($response)
{
    if ($response instanceof App_HTTP_Response) {
        return $response->get_status_code();
    }
    if (is_array($response) && isset($response['response']['code'])) {
        return $response['response']['code'];
    }
    return '';
}
}

/* Классы ошибок и REST -------------------------------------------------------- */
if (!class_exists('WP_Error')) {
class WP_Error
{
    private $errors = [];
    private $error_data = [];

    public function __construct($code = '', $message = '', $data = '')
    {
        if ($code) {
            $this->errors[$code][] = $message;
            if ($data) {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code()
    {
        $codes = array_keys($this->errors);
        return $codes ? $codes[0] : '';
    }

    public function get_error_codes()
    {
        return array_keys($this->errors);
    }

    public function get_error_message($code = '')
    {
        if (!$code) {
            $code = $this->get_error_code();
        }
        return $this->errors[$code][0] ?? '';
    }

    public function get_error_messages($code = '')
    {
        if (!$code) {
            $all = [];
            foreach ($this->errors as $list) {
                $all = array_merge($all, $list);
            }
            return $all;
        }
        return $this->errors[$code] ?? [];
    }

    public function get_error_data($code = '')
    {
        if (!$code) {
            $code = $this->get_error_code();
        }
        return $this->error_data[$code] ?? null;
    }

    public function add($code, $message, $data = '')
    {
        $this->errors[$code][] = $message;
        if ($data) {
            $this->error_data[$code] = $data;
        }
    }
}
}

if (!function_exists('is_wp_error')) {
function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}
}

/**
 * Упрощённый аналог WP_REST_Request.
 */
if (!class_exists('WP_REST_Request')) {
class WP_REST_Request
{
    private $params = [];
    private $body = '';
    private $headers = [];
    private $method;

    public function __construct($method = 'POST')
    {
        $this->method = $method;
    }

    public function set_body($body)      { $this->body = $body; }
    public function get_body()           { return $this->body; }
    public function get_json_params()    { return json_decode($this->body, true) ?: []; }
    public function set_header($k, $v)   { $this->headers[strtolower($k)] = $v; }
    public function get_header($k = null)
    {
        if ($k === null) {
            return $this->headers;
        }
        return $this->headers[strtolower($k)] ?? null;
    }
    public function get_param($key)       { return $this->params[$key] ?? null; }
    public function set_param($key, $val) { $this->params[$key] = $val; }
    public function get_params()          { return $this->params; }
    public function get_method()          { return $this->method; }
}
}

if (!class_exists('WP_REST_Response')) {
class WP_REST_Response
{
    public $data;
    public $status;

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data()   { return $this->data; }
    public function get_status() { return $this->status; }
}
}

if (!function_exists('register_rest_route')) {
function register_rest_route($namespace, $route, $args = [], $override = false)
{
    $full = strtolower('/' . trim($namespace, '/') . '/' . ltrim($route, '/'));
    $GLOBALS['wp_app']['rest_routes'][$full][] = $args;
    return true;
}
}

if (!function_exists('rest_url')) {
function rest_url($path = '')
{
    return App_Config::site_url('wp-json/' . ltrim((string) $path, '/'));
}
}

/* Загрузка файлов ------------------------------------------------------------- */
if (!function_exists('wp_handle_upload')) {
function wp_handle_upload($file, $overrides = [], $time = null)
{
    $upload = wp_upload_dir();
    $name = sanitize_file_name($file['name']);
    $target = $upload['basedir'] . '/' . $name;
    if (move_uploaded_file($file['tmp_name'], $target)) {
        return [
            'file' => $target,
            'url'  => $upload['baseurl'] . '/' . rawurlencode($name),
            'type' => $file['type'] ?? '',
        ];
    }
    return ['error' => 'Не удалось сохранить файл'];
}
}

/* Совместимость с админ-меню --------------------------------------------------- */
if (!function_exists('add_menu_page')) {
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon = '', $position = null)
{
    $GLOBALS['wp_app']['menu'][$menu_slug] = compact('page_title', 'menu_title', 'callback');
    return $menu_slug;
}
}
if (!function_exists('add_submenu_page')) {
function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '')
{
    $GLOBALS['wp_app']['submenu'][$parent_slug][$menu_slug] = compact('page_title', 'menu_title', 'callback');
    return $menu_slug;
}
}
if (!function_exists('wp_enqueue_code_editor')) {
function wp_enqueue_code_editor($settings = [])
{
    // CodeMirror подключается напрямую через CDN в шаблонах — здесь no-op.
}
}

/* Разное ------------------------------------------------------------------------ */
if (!function_exists('flush_rewrite_rules'))   { function flush_rewrite_rules($hard = true) {} }
if (!function_exists('deactivate_plugins'))    { function deactivate_plugins($plugin) {} }
if (!function_exists('get_post_meta'))         { function get_post_meta($post_id, $key = '', $single = false) { return $single ? '' : []; } }
if (!function_exists('number_format_i18n'))    { function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals); } }
if (!function_exists('size_format')) {
function size_format($bytes, $decimal_precision = 1)
{
    $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $bytes = max((float) $bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $decimal_precision) . ' ' . $units[$pow];
}
}

/* dbDelta (аналог wp-admin/includes/upgrade.php) ------------------------------ */
if (!function_exists('dbDelta')) {
/**
 * Выполняет набор CREATE TABLE-запросов, адаптируя их под текущую СУБД
 * (SQLite/MySQL). Возвращает массив выполненных запросов.
 */
function dbDelta($queries = '', $execute = true)
{
    if (is_string($queries)) {
        // Разбиваем на отдельные statements по ';' в конце строк
        $parts = preg_split('/;\s*[\r\n]+/', $queries);
        $queries = array_filter(array_map('trim', $parts));
    }
    $done = [];
    $db = App_Database::instance();
    foreach ((array) $queries as $sql) {
        $sql = trim($sql);
        if ($sql === '') {
            continue;
        }
        if ($execute) {
            $db->query($sql);
        }
        $done[] = $sql;
    }
    return $done;
}
}
