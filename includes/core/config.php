<?php
/**
 * Класс конфигурации приложения.
 */

if (!defined('APP_ROOT')) {
    exit;
}

class App_Config
{
    private static $config = null;

    public static function load($path = null)
    {
        if (self::$config === null) {
            $path = $path ?: APP_ROOT . '/config.php';
            if (!file_exists($path)) {
                // Если config.php отсутствует, используем sample с дефолтами
                $path = APP_ROOT . '/config.sample.php';
            }
            self::$config = require $path;
            date_default_timezone_set(self::get('timezone', 'Europe/Moscow'));
        }
        return self::$config;
    }

    /**
     * Получение значения по "точечному" пути: get('db.prefix').
     */
    public static function get($key, $default = null)
    {
        self::load();
        $segments = explode('.', $key);
        $value = self::$config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function site_url($path = '')
    {
        $url = rtrim((string) self::get('site_url', ''), '/');
        if ($path !== '') {
            $url .= '/' . ltrim($path, '/');
        }
        return $url;
    }
}
