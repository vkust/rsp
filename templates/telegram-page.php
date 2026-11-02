<?php if (!defined('ABSPATH')) exit; ?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Расписание</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <?php wp_head(); ?>
</head>
<body class="telegram-webapp">
    <div id="schedule-app">
        <?php
        // Подключаем основной шаблон расписания с параметрами для Telegram
        echo do_shortcode('[school_schedule view="horizontal" is_telegram="true"]');
        ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tg = window.Telegram.WebApp;
        
        // Инициализация
        tg.ready();
        tg.expand();
        
        // Установка темы
        const setTheme = () => {
            document.body.classList.toggle('dark-theme', tg.colorScheme === 'dark');
            document.documentElement.style.setProperty('--tg-theme-bg-color', tg.backgroundColor);
            document.documentElement.style.setProperty('--tg-theme-text-color', tg.textColor);
            document.documentElement.style.setProperty('--tg-theme-hint-color', tg.backgroundColor === '#ffffff' ? '#999999' : '#666666');
            document.documentElement.style.setProperty('--tg-theme-button-color', tg.buttonColor || '#3390ec');
            document.documentElement.style.setProperty('--tg-theme-button-text-color', tg.buttonTextColor || '#ffffff');
            document.documentElement.style.setProperty('--tg-theme-secondary-bg-color', 
                tg.colorScheme === 'dark' ? '#2c2c2c' : '#f5f5f5'
            );
        };

        // Первоначальная установка темы
        setTheme();

        // Обработчик изменения темы
        tg.onEvent('themeChanged', setTheme);

        // Обработка ошибок
        window.addEventListener('error', (e) => {
            tg.sendData(JSON.stringify({
                error: e.message,
                stack: e.error?.stack
            }));
        });
    });
    </script>
    <?php wp_footer(); ?>
</body>
</html> 