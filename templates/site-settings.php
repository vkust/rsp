<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>Настройки сайта</h1>

    <?php if (isset($_GET['settings-updated'])): ?>
        <div class="notice notice-success">
            <p>Настройки сохранены.</p>
        </div>
    <?php endif; ?>

    <?php if (empty($site_url) && !empty($effective_url)): ?>
        <div class="notice notice-info">
            <p><strong>URL не задан явно.</strong> Сейчас используется автоопределённый URL: <code><?php echo esc_html($effective_url); ?></code></p>
        </div>
    <?php endif; ?>

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
                        Оставьте пустым для автоматического определения из текущего запроса (удобно для разработки).
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
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}</pre>
        <p>После настройки reverse proxy укажите в поле «Полный URL приложения» адрес вида <code>https://rasp.example.com</code> и нажмите «Сохранить».</p>
    </div>
</div>
