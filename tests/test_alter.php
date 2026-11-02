<?php
require "bootstrap.php";
$db = App_Database::instance();
$q = "ALTER TABLE schedule_notifications\n    ADD COLUMN source enum('auto', 'manual') NOT NULL DEFAULT 'auto' AFTER message";
var_dump($db->query($q), $db->last_error);
$cols = $db->get_results("SHOW COLUMNS FROM schedule_notifications LIKE 'source'");
print_r(array_map(fn($c)=>$c->Field.": ".$c->Type, $cols));
var_dump($db->query("ALTER TABLE schedule_subscriptions MODIFY entity_id varchar(50) NOT NULL"), $db->last_error);
$ins = $db->insert("schedule_notifications", ["chat_id"=>"123","notification_type"=>"test","message"=>"hi","source"=>"manual","created_at"=>date("Y-m-d H:i:s")]);
var_dump($ins, $db->last_error);
print_r($db->get_row("SELECT * FROM schedule_notifications ORDER BY id DESC LIMIT 1"));
echo "DONE\n";
