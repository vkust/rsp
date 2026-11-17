<?php
/**
 * Учётные записи приложения: хранение, вход и права доступа.
 *
 * Встроенный администратор из config.php / опции schedule_admin_password_hash
 * продолжает работать отдельно; здесь — дополнительные пользователи,
 * которых можно создавать из админки.
 */

if (!defined('APP_ROOT')) {
    exit;
}

function app_accounts_table()
{
    $db = App_Database::instance();
    return $db->prefix . 'schedule_accounts';
}

/**
 * Все учётные записи, сначала старые.
 *
 * @return object[]
 */
function app_accounts_all()
{
    $db = App_Database::instance();
    $rows = $db->get_results('SELECT * FROM `' . app_accounts_table() . '` ORDER BY id ASC');
    return is_array($rows) ? $rows : [];
}

function app_accounts_get($id)
{
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    $db = App_Database::instance();
    $row = $db->get_row($db->prepare(
        'SELECT * FROM `' . app_accounts_table() . '` WHERE id = %d LIMIT 1',
        $id
    ));
    return $row ?: null;
}

function app_accounts_find_by_username($username)
{
    $db = App_Database::instance();
    $row = $db->get_row($db->prepare(
        'SELECT * FROM `' . app_accounts_table() . '` WHERE username = %s LIMIT 1',
        (string) $username
    ));
    return $row ?: null;
}

/**
 * Проверка логина: буквы (включая кириллицу), цифры, точка, дефис,
 * подчёркивание и @. Длина 3–64 символа.
 */
function app_accounts_valid_username($username)
{
    return (bool) preg_match('/^[\p{L}\p{N}._@-]{3,64}$/u', (string) $username);
}

function app_accounts_role_label($role)
{
    return $role === 'admin' ? 'Администратор' : 'Просмотр';
}

/**
 * Создать учётную запись.
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function app_accounts_create(array $data)
{
    $username = trim((string) ($data['username'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    $display_name = trim((string) ($data['display_name'] ?? ''));
    $role = ($data['role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer';
    $can_view_free = !empty($data['can_view_free']) ? 1 : 0;

    if (!app_accounts_valid_username($username)) {
        return ['ok' => false, 'error' => 'Логин: 3–64 символа, буквы, цифры, точка, дефис, подчёркивание или @.'];
    }
    if (app_accounts_find_by_username($username)) {
        return ['ok' => false, 'error' => 'Пользователь с таким логином уже существует.'];
    }
    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'error' => 'Пароль должен быть не короче 6 символов.'];
    }

    $db = App_Database::instance();
    $ok = $db->insert(app_accounts_table(), [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $display_name !== '' ? $display_name : $username,
        'role' => $role,
        'can_view_free' => $can_view_free,
        'is_active' => 1,
    ]);

    if (!$ok) {
        return ['ok' => false, 'error' => 'Не удалось создать пользователя.'];
    }

    return ['ok' => true, 'id' => (int) $db->insert_id];
}

/**
 * Обновить учётную запись. Пустой пароль — не менять.
 *
 * @return array{ok:bool,error?:string}
 */
function app_accounts_update($id, array $data)
{
    $id = (int) $id;
    $row = app_accounts_get($id);
    if (!$row) {
        return ['ok' => false, 'error' => 'Пользователь не найден.'];
    }

    $username = trim((string) ($data['username'] ?? ''));
    if (!app_accounts_valid_username($username)) {
        return ['ok' => false, 'error' => 'Логин: 3–64 символа, буквы, цифры, точка, дефис, подчёркивание или @.'];
    }
    $existing = app_accounts_find_by_username($username);
    if ($existing && (int) $existing->id !== $id) {
        return ['ok' => false, 'error' => 'Пользователь с таким логином уже существует.'];
    }

    $fields = [
        'username' => $username,
        'display_name' => trim((string) ($data['display_name'] ?? '')) ?: $username,
        'role' => ($data['role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer',
        'can_view_free' => !empty($data['can_view_free']) ? 1 : 0,
        'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];

    $password = (string) ($data['password'] ?? '');
    if ($password !== '') {
        if (mb_strlen($password) < 6) {
            return ['ok' => false, 'error' => 'Пароль должен быть не короче 6 символов.'];
        }
        $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $db = App_Database::instance();
    $db->update(app_accounts_table(), $fields, ['id' => $id]);

    return ['ok' => true];
}

function app_accounts_delete($id)
{
    $id = (int) $id;
    if ($id <= 0) {
        return false;
    }
    $db = App_Database::instance();
    return (bool) $db->delete(app_accounts_table(), ['id' => $id]);
}

function app_accounts_touch_login($id)
{
    $db = App_Database::instance();
    $db->update(
        app_accounts_table(),
        ['last_login_at' => date('Y-m-d H:i:s')],
        ['id' => (int) $id]
    );
}

/* -------------------------------------------------------------------------
 * Сессия
 * ---------------------------------------------------------------------- */

/**
 * Записать пользователя в сессию. Администратор получает и старую
 * сессию schedule_admin — на ней завязаны проверки прав и nonce.
 */
function app_account_set_session($row)
{
    app_session_start();

    $role = ((string) $row->role === 'admin') ? 'admin' : 'viewer';
    $session = [
        'id' => (int) $row->id,
        'username' => (string) $row->username,
        'display_name' => (string) (($row->display_name ?? '') !== '' ? $row->display_name : $row->username),
        'role' => $role,
        'can_view_free' => (int) $row->can_view_free === 1,
    ];
    $_SESSION['schedule_account'] = $session;

    if ($role === 'admin') {
        $_SESSION['schedule_admin'] = $session['username'];
    }
}

/**
 * Текущий пользователь из сессии с проверкой актуальности в БД.
 * Возвращает null, если сессии нет, пользователь удалён или отключён.
 */
function app_account_current()
{
    static $loaded = false;
    static $current = null;

    app_session_start();
    if (empty($_SESSION['schedule_account'])) {
        return null;
    }
    if ($loaded) {
        return $current;
    }
    $loaded = true;

    $id = (int) ($_SESSION['schedule_account']['id'] ?? 0);
    $row = $id > 0 ? app_accounts_get($id) : null;

    if (!$row || (int) $row->is_active !== 1) {
        unset($_SESSION['schedule_account'], $_SESSION['schedule_admin']);
        return null;
    }

    $role = ((string) $row->role === 'admin') ? 'admin' : 'viewer';
    $current = [
        'id' => (int) $row->id,
        'username' => (string) $row->username,
        'display_name' => (string) (($row->display_name ?? '') !== '' ? $row->display_name : $row->username),
        'role' => $role,
        'can_view_free' => (int) $row->can_view_free === 1,
    ];
    $_SESSION['schedule_account'] = $current;

    if ($role === 'admin') {
        $_SESSION['schedule_admin'] = $current['username'];
    } else {
        unset($_SESSION['schedule_admin']);
    }

    return $current;
}

function app_account_logout()
{
    app_session_start();
    unset($_SESSION['schedule_account'], $_SESSION['schedule_admin']);
}

/**
 * Может ли текущий пользователь открыть админку.
 */
function app_account_can_manage()
{
    if (current_user_can('manage_options')) {
        return true;
    }
    $current = app_account_current();
    return $current !== null && $current['role'] === 'admin';
}

/**
 * Может ли текущий пользователь видеть свободных учителей и кабинеты.
 * Встроенный администратор и администраторы-аккаунты — всегда.
 */
function app_account_can_view_free()
{
    if (current_user_can('manage_options')) {
        return true;
    }
    $current = app_account_current();
    return $current !== null && ($current['role'] === 'admin' || !empty($current['can_view_free']));
}

/**
 * Проверить логин и пароль дополнительного пользователя и войти.
 *
 * @return array{ok:bool,role?:string,error?:string}
 */
function app_accounts_login($username, $password)
{
    $row = app_accounts_find_by_username($username);
    if (!$row || (int) $row->is_active !== 1) {
        return ['ok' => false, 'error' => 'Неверный логин или пароль.'];
    }
    if (!password_verify((string) $password, (string) $row->password_hash)) {
        return ['ok' => false, 'error' => 'Неверный логин или пароль.'];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    app_account_set_session($row);
    app_accounts_touch_login((int) $row->id);

    $role = ((string) $row->role === 'admin') ? 'admin' : 'viewer';
    if ($role === 'viewer' && (int) $row->can_view_free !== 1) {
        app_account_logout();
        return ['ok' => false, 'error' => 'У вас нет доступа к разделам приложения. Обратитесь к администратору.'];
    }

    return ['ok' => true, 'role' => $role];
}
