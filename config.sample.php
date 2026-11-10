<?php
/**
 * Конфигурация автономного приложения «Расписание занятий».
 * Скопируйте этот файл в config.php и укажите свои параметры.
 */

return [
    // Полное URL приложения (используется для webhook Telegram и генерации ссылок).
    // Например: https://schedule.example.com
    // Если оставить пустым, URL будет определяться автоматически из текущего HTTP-запроса.
    'site_url' => getenv('SCHEDULE_SITE_URL') ?: '',

    // Параметры встроенного веб-сервера (используются setup.sh и админкой).
    'server' => [
        'bind' => getenv('SCHEDULE_BIND') ?: '0.0.0.0',
        'port' => (int) (getenv('SCHEDULE_PORT') ?: 8080),
    ],

    // Система обновлений: откуда брать версии и сколько снимков хранить.
    'updates' => [
        'repo' => getenv('SCHEDULE_UPDATE_REPO') ?: 'vkust/rsp',
        'branch' => getenv('SCHEDULE_UPDATE_BRANCH') ?: 'main',
        'keep_backups' => 10,
    ],

    // База данных. Поддерживаются sqlite и mysql.
    'db' => [
        'type'     => getenv('SCHEDULE_DB_TYPE') ?: 'sqlite',   // sqlite | mysql
        // Для sqlite:
        'sqlite'   => [
            'path' => __DIR__ . '/data/schedule.sqlite',
        ],
        // Для mysql:
        'mysql'    => [
            'host'    => getenv('SCHEDULE_DB_HOST') ?: 'localhost',
            'port'    => getenv('SCHEDULE_DB_PORT') ?: '3306',
            'name'    => getenv('SCHEDULE_DB_NAME') ?: 'schedule',
            'user'    => getenv('SCHEDULE_DB_USER') ?: 'root',
            'pass'    => getenv('SCHEDULE_DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ],
        // Префикс таблиц (как $wpdb->prefix в WordPress). По умолчанию пуст:
        // таблицы называются schedule_users, schedule_options и т.д.
        'prefix'   => '',
    ],

    // Каталог загрузки XML-файлов расписания (аналог wp_upload_dir()).
    'uploads_dir' => __DIR__ . '/data/uploads',

    // Часовой пояс приложения.
    'timezone' => getenv('SCHEDULE_TZ') ?: 'Europe/Moscow',

    // Доступ к административной панели.
    'admin' => [
        'username' => getenv('SCHEDULE_ADMIN_USER') ?: 'admin',
        // Хеш пароля, сгенерируйте его командой: php install.php --hash-password "ВАШ_ПАРОЛЬ"
        'password_hash' => getenv('SCHEDULE_ADMIN_HASH') ?: '',
        'session_secret' => getenv('SCHEDULE_SESSION_SECRET') ?: 'change-me-random-string',
    ],

    // Отладка (аналог WP_DEBUG).
    'debug' => [
        'enabled' => false,
        'display' => true,
        'log'     => __DIR__ . '/logs/app.log',
    ],
];
