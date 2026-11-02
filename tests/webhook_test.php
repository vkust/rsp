<?php
require __DIR__ . '/../bootstrap.php';

// 1. Проверяем, что webhook без токена возвращает 401 (эмулируем запрос через классы)
$plugin = new SchoolSchedule();
require_once APP_ROOT . '/includes/rest-api.php';
$api = new ScheduleRestAPI($plugin);

$req = new WP_REST_Request('POST');
$req->set_body(json_encode(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Test'], 'text' => '/start']]));

delete_option('schedule_telegram_bot_token');
$res = $api->handle_webhook($req);
echo "no-token: ", (is_wp_error($res) ? 'WP_Error '.$res->get_error_code() : get_class($res).' status='.$res->get_status()), PHP_EOL;

// 2. С тестовым токеном (отправка упадёт на сеть — проверяем, что код отрабатывает и юзер сохраняется)
update_option('schedule_telegram_bot_token', 'TEST:TOKEN');
$bot = new ScheduleTelegramBot($plugin);
$r = $bot->handle_webhook($req);
echo "with-token: ", get_class($r), ' status=', $r->get_status(), PHP_EOL;

global $wpdb;
$u = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}schedule_users ORDER BY id DESC LIMIT 1");
echo "user saved: ", $u ? $u->chat_id : 'NO', PHP_EOL;
$sub = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}schedule_subscriptions");
echo "subscriptions rows: $sub", PHP_EOL;
