<?php
/**
 * Админ-раздел «Пользователи»: учётные записи приложения.
 *
 * @var object[]    $accounts
 * @var object|null $edit_account
 * @var array|null  $self
 */
if (!defined('APP_ROOT')) {
    exit;
}

$notice_error = isset($_GET['error']) ? (string) wp_unslash($_GET['error']) : '';
$new_username = isset($_GET['new_username']) ? (string) wp_unslash($_GET['new_username']) : '';
$new_display_name = isset($_GET['new_display_name']) ? (string) wp_unslash($_GET['new_display_name']) : '';
$free_page_url = App_Config::site_url('free');
?>
<div class="wrap">
    <h1>Пользователи</h1>
    <p class="description">
        Учётные записи для входа в приложение. Администраторы получают полный доступ
        к панели управления, пользователи с ролью «Просмотр» — только к закрытой
        странице свободных учителей и кабинетов.
    </p>

    <?php if (isset($_GET['user-created'])): ?>
        <div class="notice notice-success"><p>Пользователь создан.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['user-updated'])): ?>
        <div class="notice notice-success"><p>Изменения сохранены.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['user-deleted'])): ?>
        <div class="notice notice-success"><p>Пользователь удалён.</p></div>
    <?php endif; ?>
    <?php if ($notice_error !== ''): ?>
        <div class="notice notice-error"><p><?php echo esc_html($notice_error); ?></p></div>
    <?php endif; ?>

    <?php if ($edit_account): ?>
        <div class="card">
            <h2>Изменить пользователя</h2>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('app_users', 'app_users_nonce'); ?>
                <input type="hidden" name="action" value="update_app_user">
                <input type="hidden" name="user_id" value="<?php echo (int) $edit_account->id; ?>">

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="edit_username">Логин</label></th>
                            <td>
                                <input type="text" id="edit_username" name="username" class="regular-text"
                                       value="<?php echo esc_attr($edit_account->username); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_display_name">Отображаемое имя</label></th>
                            <td>
                                <input type="text" id="edit_display_name" name="display_name" class="regular-text"
                                       value="<?php echo esc_attr($edit_account->display_name); ?>">
                                <p class="description">Показывается на закрытой странице.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_password">Новый пароль</label></th>
                            <td>
                                <input type="password" id="edit_password" name="password" class="regular-text"
                                       autocomplete="new-password">
                                <p class="description">Оставьте пустым, чтобы не менять пароль. Минимум 6 символов.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="edit_role">Роль</label></th>
                            <td>
                                <select id="edit_role" name="role">
                                    <option value="viewer" <?php selected($edit_account->role, 'viewer'); ?>>Просмотр</option>
                                    <option value="admin" <?php selected($edit_account->role, 'admin'); ?>>Администратор</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Доступ к свободным</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="can_view_free" value="1"
                                           <?php checked((int) $edit_account->can_view_free, 1); ?>>
                                    Разрешить страницу «Свободные учителя и кабинеты»
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Состояние</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1"
                                           <?php checked((int) $edit_account->is_active, 1); ?>>
                                    Учётная запись активна
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Сохранить</button>
                    <a class="button" href="<?php echo esc_url(App_Config::site_url('admin?page=schedule-users')); ?>">Отмена</a>
                </p>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>Добавить пользователя</h2>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('app_users', 'app_users_nonce'); ?>
                <input type="hidden" name="action" value="create_app_user">

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="new_username_field">Логин</label></th>
                            <td>
                                <input type="text" id="new_username_field" name="username" class="regular-text"
                                       value="<?php echo esc_attr($new_username); ?>"
                                       pattern="[A-Za-zА-Яа-яЁё0-9._@\-]{3,64}" required>
                                <p class="description">3–64 символа: буквы, цифры, точка, дефис, подчёркивание или @.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_display_name_field">Отображаемое имя</label></th>
                            <td>
                                <input type="text" id="new_display_name_field" name="display_name" class="regular-text"
                                       value="<?php echo esc_attr($new_display_name); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_password_field">Пароль</label></th>
                            <td>
                                <input type="password" id="new_password_field" name="password" class="regular-text"
                                       autocomplete="new-password" required>
                                <p class="description">Минимум 6 символов.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_role_field">Роль</label></th>
                            <td>
                                <select id="new_role_field" name="role">
                                    <option value="viewer" selected>Просмотр</option>
                                    <option value="admin">Администратор</option>
                                </select>
                                <p class="description">«Администратор» получает полный доступ к панели управления.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Доступ к свободным</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="can_view_free" value="1" checked>
                                    Разрешить страницу «Свободные учителя и кабинеты»
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Создать пользователя</button>
                </p>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Учётные записи</h2>
        <?php if (!$accounts): ?>
            <p>Пока нет дополнительных пользователей. Вход администратора из <code>config.php</code> работает всегда.</p>
        <?php else: ?>
            <table class="list">
                <thead>
                    <tr>
                        <th>Логин</th>
                        <th>Имя</th>
                        <th>Роль</th>
                        <th>Свободные</th>
                        <th>Состояние</th>
                        <th>Последний вход</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><strong><?php echo esc_html($account->username); ?></strong></td>
                            <td><?php echo esc_html($account->display_name); ?></td>
                            <td><?php echo esc_html(app_accounts_role_label((string) $account->role)); ?></td>
                            <td>
                                <?php if ((string) $account->role === 'admin' || (int) $account->can_view_free === 1): ?>
                                    Да
                                <?php else: ?>
                                    Нет
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo ((int) $account->is_active === 1) ? 'Активна' : 'Отключена'; ?>
                            </td>
                            <td>
                                <?php
                                echo $account->last_login_at
                                    ? esc_html(date('d.m.Y H:i', strtotime((string) $account->last_login_at)))
                                    : '—';
                                ?>
                            </td>
                            <td class="file-actions">
                                <a class="button" href="<?php echo esc_url(add_query_arg(
                                    ['page' => 'schedule-users', 'edit' => (int) $account->id],
                                    App_Config::site_url('admin')
                                )); ?>">Изменить</a>
                                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="delete-form"
                                      onsubmit="return confirm('Удалить пользователя «<?php echo esc_js($account->username); ?>»?');">
                                    <?php wp_nonce_field('app_users', 'app_users_nonce'); ?>
                                    <input type="hidden" name="action" value="delete_app_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $account->id; ?>">
                                    <button type="submit" class="button button-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Закрытая страница</h2>
        <p>
            Свободные учителя и кабинеты открываются по адресу
            <code><?php echo esc_html($free_page_url); ?></code>.
            Доступ есть у администраторов и у пользователей с включённой галочкой
            «Доступ к свободным». Остальные увидят форму входа.
        </p>
    </div>
</div>
