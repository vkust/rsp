<?php

if (!defined('ABSPATH')) {
    exit;
}

class ScheduleRestAPI {
    private $plugin;
    private $telegram_bot;

    public function __construct($plugin) {
        $this->plugin = $plugin;
        $this->telegram_bot = new ScheduleTelegramBot($plugin);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('school-schedule/v1', '/telegram-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handle_webhook($request) {
        try {
            // Проверяем токен
            $token = get_option('schedule_telegram_bot_token');
            if (empty($token)) {
                return new WP_Error('invalid_token', 'Token not configured', ['status' => 401]);
            }

            // Получаем тело запроса
            $body = $request->get_body();
            $update = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Invalid JSON in request body', ['status' => 400]);
            }

            // Обрабатываем запрос через бота
            $result = $this->telegram_bot->handle_webhook($request);

            // Проверяем результат
            if (is_wp_error($result)) {
                return $result;
            }

            return new WP_REST_Response(['status' => 'ok'], 200);

        } catch (Exception $e) {
            return new WP_Error(
                'webhook_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
} 