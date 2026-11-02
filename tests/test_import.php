<?php
// Эмуляция POST-запроса загрузки расписания (admin_post_upload_schedule)
require dirname(__DIR__) . '/bootstrap.php';
require APP_ROOT . '/includes/core/router.php';

$src = APP_ROOT . '/tests/fixtures/sample-schedule.xml';
$tmp = tempnam(sys_get_temp_dir(), 'xml');
copy($src, $tmp);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION['schedule_admin'] = true;
$_FILES['schedule_file'] = [
    'name' => 'sample-schedule.xml', 'type' => 'text/xml',
    'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp),
];
$_POST['action'] = 'upload_schedule';
$_POST['schedule_nonce'] = wp_create_nonce('schedule_upload');

register_shutdown_function(function () {
    $active = get_option('schedule_active_file');
    echo "\nACTIVE FILE: " . var_export($active, true) . "\n";
    echo "EXISTS: " . (is_string($active) && file_exists($active) ? 'yes' : 'no') . "\n";
});

do_action('admin_post_upload_schedule'); // обработчик вызовет exit
