<?php
if (!defined('ABSPATH')) exit;

$updated = isset($_GET['settings-updated']) ? sanitize_key(wp_unslash($_GET['settings-updated'])) : '';
$error = isset($_GET['settings-error']) ? sanitize_text_field(wp_unslash($_GET['settings-error'])) : '';

$host = parse_url($effective_url, PHP_URL_HOST) ?: '';
$new_site_url = '';
if ($updated === 'port' || $updated === 'port-manual') {
    $new_site_url = 'http://' . $host . ($server_port ? ':' . $server_port : '');
}
?>
<div class="wrap">
    <h1>Настройки сайта</h1>

    <?php if ($updated !== ''): ?>
        <div class="notice notice-success">
            <p>
                <?php if ($updated === 'port'): ?>
                    Порт сохранён. Сервис перезапускается.
                    <?php if ($new_site_url): ?>
                        Новый адрес: <a href="<?php echo esc_url($new_site_url . '/admin?page=schedule-site-settings'); ?>"><?php echo esc_html($new_site_url); ?></a>
                    <?php endif; ?>
                <?php elseif ($updated === 'port-manual'): ?>
                    Порт сохранён в конфигурации. Автоматический перезапуск недоступен — перезапустите сервер вручную.
                    <?php if ($new_site_url): ?>
                        Новый адрес после перезапуска: <code><?php echo esc_html($new_site_url); ?></code>
                    <?php endif; ?>
                <?php elseif ($updated === 'password'): ?>
                    Пароль администратора изменён.
                <?php elseif ($updated === 'password-db'): ?>
                    Пароль изменён и сохранён в базе. Файл config.php обновить не удалось — проверьте права.
                <?php elseif ($updated === 'favicon'): ?>
                    Фавикон загружен.
                <?php elseif ($updated === 'favicon-deleted'): ?>
                    Фавикон удалён.
                <?php elseif ($updated === 'favicon-default'): ?>
                    Восстановлен стандартный фавикон.
                <?php else: ?>
                    Настройки сохранены.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($error); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($site_url) && !empty($effective_url)): ?>
        <div class="notice notice-info">
            <p><strong>URL не задан явно.</strong> Сейчас используется автоопределённый URL: <code><?php echo esc_html($effective_url); ?></code></p>
        </div>
    <?php endif; ?>

    <h2>Адрес и HTTPS</h2>
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('site_settings', 'site_settings_nonce'); ?>
        <input type="hidden" name="action" value="save_site_settings">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="site_url">Полный URL приложения</label></th>
                <td>
                    <input type="text" id="site_url" name="site_url" value="<?php echo esc_attr($site_url); ?>" class="regular-text" placeholder="https://rasp.example.com">
                    <p class="description">
                        Укажите домен с протоколом (<code>https://rasp.example.com</code>).<br>
                        Оставьте пустым для автоматического определения из текущего запроса.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">HTTPS</th>
                <td>
                    <label>
                        <input type="checkbox" name="use_https" <?php checked($use_https, 'on'); ?>>
                        Использовать HTTPS
                    </label>
                    <p class="description">
                        Встроенный PHP-сервер не поддерживает HTTPS напрямую. Включите эту опцию, если перед приложением стоит reverse proxy (nginx, Caddy, Traefik) с SSL-терминацией.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ssl_cert_path">Путь к SSL-сертификату</label></th>
                <td>
                    <input type="text" id="ssl_cert_path" name="ssl_cert_path" value="<?php echo esc_attr($ssl_cert); ?>" class="regular-text" placeholder="/etc/letsencrypt/live/example.com/fullchain.pem">
                    <p class="description">Используется только для информации и генерации конфигов reverse proxy.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ssl_key_path">Путь к SSL-ключу</label></th>
                <td>
                    <input type="text" id="ssl_key_path" name="ssl_key_path" value="<?php echo esc_attr($ssl_key); ?>" class="regular-text" placeholder="/etc/letsencrypt/live/example.com/privkey.pem">
                </td>
            </tr>
        </table>

        <?php submit_button('Сохранить настройки'); ?>
    </form>

    <h2>Фавикон</h2>
    <div class="card" style="max-width:100%;">
        <table class="form-table">
            <tr>
                <th scope="row">Текущий фавикон</th>
                <td>
                    <?php if (!empty($favicon_url)): ?>
                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                            <img src="<?php echo esc_url($favicon_url); ?>" alt="Фавикон" style="width:64px;height:64px;object-fit:contain;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:6px;">
                            <code><?php echo esc_html($favicon_url); ?></code>
                        </div>
                    <?php else: ?>
                        <p>Фавикон не задан.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('favicon_settings', 'favicon_nonce'); ?>
            <input type="hidden" name="action" value="save_favicon">
            <input type="hidden" name="favicon_action" value="upload">

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="favicon_file">Загрузить фавикон</label></th>
                    <td>
                        <input type="file" id="favicon_file" name="favicon_file" accept=".ico,.png,.svg,.jpg,.jpeg,.webp,.gif" required>
                        <p class="description">
                            Поддерживаются ICO, PNG, SVG, JPG, WEBP и GIF. Максимальный размер — 2 МБ.<br>
                            Рекомендуется квадратное изображение, например 64×64 или 128×128.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Загрузить фавикон</button>
            </p>
        </form>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Удалить текущий фавикон?');">
                <?php wp_nonce_field('favicon_settings', 'favicon_nonce'); ?>
                <input type="hidden" name="action" value="save_favicon">
                <input type="hidden" name="favicon_action" value="delete">
                <button type="submit" class="button">Удалить фавикон</button>
            </form>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('favicon_settings', 'favicon_nonce'); ?>
                <input type="hidden" name="action" value="save_favicon">
                <input type="hidden" name="favicon_action" value="default">
                <button type="submit" class="button">Вернуть стандартный</button>
            </form>
        </div>
    </div>

    <h2>Сервер</h2>
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('server_settings', 'server_settings_nonce'); ?>
        <input type="hidden" name="action" value="save_server_settings">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="server_port">Порт веб-сервера</label></th>
                <td>
                    <input type="number" id="server_port" name="server_port" min="1" max="65535" value="<?php echo esc_attr($server_port); ?>" class="small-text">
                    <p class="description">
                        После сохранения systemd-сервис будет перезапущен на новом порту.<br>
                        Если используется reverse proxy, не забудьте обновить upstream.
                    </p>
                    <p>Текущий адрес: <code><?php echo esc_html($effective_url); ?></code></p>
                </td>
            </tr>
        </table>

        <?php submit_button('Сохранить порт'); ?>
    </form>

    <h2>Смена пароля администратора</h2>
    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('admin_password', 'admin_password_nonce'); ?>
        <input type="hidden" name="action" value="change_admin_password">

        <table class="form-table">
            <tr>
                <th scope="row"><label for="current_password">Текущий пароль</label></th>
                <td>
                    <input type="password" id="current_password" name="current_password" class="regular-text" autocomplete="current-password" required>
                    <p class="description">Пользователь: <code><?php echo esc_html($admin_username); ?></code></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="new_password">Новый пароль</label></th>
                <td>
                    <input type="password" id="new_password" name="new_password" class="regular-text" autocomplete="new-password" minlength="8" required>
                    <p class="description">Не менее 8 символов.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="confirm_password">Повторите новый пароль</label></th>
                <td>
                    <input type="password" id="confirm_password" name="confirm_password" class="regular-text" autocomplete="new-password" minlength="8" required>
                </td>
            </tr>
        </table>

        <?php submit_button('Сменить пароль'); ?>
    </form>

    <h2>Справка по HTTPS</h2>
    <div class="card">
        <p>Для работы приложения по HTTPS рекомендуется использовать reverse proxy. Пример конфигурации <strong>nginx</strong>:</p>
        <pre style="background:#f6f7f7;padding:12px;overflow:auto;">
server {
    listen 443 ssl;
    server_name <?php echo esc_html(parse_url($effective_url, PHP_URL_HOST) ?: 'example.com'); ?>;

    ssl_certificate     <?php echo esc_html($ssl_cert ?: '/path/to/fullchain.pem'); ?>;
    ssl_certificate_key <?php echo esc_html($ssl_key ?: '/path/to/privkey.pem'); ?>;

    location / {
        proxy_pass http://127.0.0.1:<?php echo esc_html($server_port); ?>;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}</pre>
        <p>После настройки reverse proxy укажите в поле «Полный URL приложения» адрес вида <code>https://rasp.example.com</code> и нажмите «Сохранить».</p>
    </div>
</div>
