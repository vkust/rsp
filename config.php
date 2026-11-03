<?php
/**
 * Конфигурация автономного приложения «Расписание занятий».
 * Файл сгенерирован мастером установки setup.php 2026-11-03 04:10:41 UTC.
 */

return [
    'site_url' => 'http://localhost:8080',

    'db' => [
        'type'   => 'sqlite',
        'sqlite' => ['path' => '/workspace/data/schedule.sqlite'],
        'mysql'  => [
            'host'    => 'localhost',
            'port'    => '3306',
            'name'    => 'schedule',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ],
        'prefix' => '',
    ],

    'uploads_dir' => '/workspace/data/uploads',

    'timezone' => 'Europe/Moscow',

    'admin' => [
        'username'       => 'admin',
        'password_hash'  => '$2y$10$N4qHT/Q65fAIUWa/V5MPEOs8mVAono25qzjGXH5QgDbeN1yQUWaoe',
        'session_secret' => '51a32e9dc6d13f67629810f647f26dd2bfd034b88ff1cfe70d2d80e4fb9065a8',
    ],

    'debug' => [
        'enabled' => false,
        'display' => false,
        'log'     => '/workspace/logs/app.log',
    ],
];
