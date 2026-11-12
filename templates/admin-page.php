<div class="wrap">
    <h1>Управление школьным расписанием</h1>
    
    <?php
    // Добавим отладочную информацию
    if (WP_DEBUG) {
        echo '<!-- Debug: Subjects -->';
        echo '<!-- ' . print_r($schedule, true) . ' -->';
        echo '<!-- Debug: Categories -->';
        echo '<!-- ' . print_r($categories, true) . ' -->';
        echo '<!-- Debug: Subject Categories -->';
        echo '<!-- ' . print_r($subject_categories, true) . ' -->';
    }
    ?>

    <?php if (isset($_GET['file-uploaded'])): ?>
        <div class="notice notice-success">
            <p>Файл успешно загружен и активирован.</p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error">
            <p>
                <?php
                switch ($_GET['error']) {
                    case 'upload':
                        echo 'Ошибка при загрузке файла.';
                        break;
                    case 'file-type':
                        echo 'Допускаются только XML файлы.';
                        break;
                    case 'move-failed':
                        echo 'Не удалось сохранить файл.';
                        break;
                    case 'file-not-found':
                        echo 'Файл не найден.';
                        break;
                    case 'invalid-xml':
                        echo 'Загруженный файл не содержит корректного расписания.';
                        break;
                    case 'parse-error':
                        echo 'Ошибка при обработке файла. Проверьте формат XML.';
                        break;
                    case 'directory-creation-failed':
                        echo 'Не удалось создать директорию для загрузки файлов. Проверьте права доступа.';
                        break;
                    default:
                        echo 'Произошла неизвестная ошибка.';
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['file-deleted'])): ?>
        <div class="notice notice-success">
            <p>Файл успешно удален.</p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['settings-updated'])): ?>
        <div class="notice notice-success">
            <p>Настройки сохранены.</p>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Загрузка расписания</h2>
        <div class="upload-form">
            <form method="POST" enctype="multipart/form-data" 
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('schedule_upload', 'schedule_nonce'); ?>
                <input type="hidden" name="action" value="upload_schedule">
                <input type="file" name="schedule_file" accept=".xml" required>
                <button type="submit" class="button button-primary">Загрузить</button>
            </form>
        </div>
    </div>

    <?php if (!empty($files)): ?>
    <div class="card">
        <h2>Загруженные файлы</h2>
        <div class="file-list">
            <?php foreach(array_reverse($files) as $file): ?>
                <div class="file-item <?php echo ($file === $schedule_file) ? 'active' : ''; ?>">
                    <div class="file-info">
                        <span class="file-name"><?php echo esc_html(basename($file)); ?></span>
                        <span class="file-date">
                            <?php echo esc_html(date('d.m.Y H:i', filemtime($file))); ?>
                        </span>
                    </div>
                    <?php if ($file !== $schedule_file): ?>
                        <div class="file-actions">
                            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="activate-form">
                                <?php wp_nonce_field('activate_schedule', 'schedule_nonce'); ?>
                                <input type="hidden" name="action" value="activate_schedule">
                                <input type="hidden" name="schedule_file" value="<?php echo esc_attr($file); ?>">
                                <button type="submit" class="button button-secondary">Активировать</button>
                            </form>
                            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="delete-form">
                                <?php wp_nonce_field('delete_schedule', 'schedule_nonce'); ?>
                                <input type="hidden" name="action" value="delete_schedule">
                                <input type="hidden" name="schedule_file" value="<?php echo esc_attr($file); ?>">
                                <button type="submit" class="button button-danger">Удалить</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="active-label">Активный</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($schedule['subjects'])): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Настройки категорий предметов
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('schedule_settings', 'schedule_nonce'); ?>
                <input type="hidden" name="action" value="save_schedule_settings">

                <div class="submit-wrapper top-submit">
                    <button type="submit" class="button button-primary">Сохранить настройки</button>
                </div>

                <div class="category-settings">
                    <!-- Секция видимости категорий -->
                    <div class="visibility-section">
                        <h3>Видимость категорий</h3>
                        <div class="category-toggles">
                            <?php foreach ($categories as $slug => $category): ?>
                                <div class="category-toggle">
                                    <label class="switch-label">
                                        <span class="category-name"><?php echo esc_html($category['name']); ?></span>
                                        <label class="switch">
                                            <input type="checkbox" 
                                                   name="category_visible[<?php echo esc_attr($slug); ?>]"
                                                   <?php checked($category['visible'], true); ?>>
                                            <span class="slider round"></span>
                                        </label>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Секция назначения категорий -->
                    <div class="assignment-section">
                        <h3>Назначение категорий предметам</h3>
                        <div class="subjects-grid">
                            <?php foreach ($schedule['subjects'] as $id => $subject): ?>
                                <div class="subject-item">
                                    <span class="subject-name"><?php echo esc_html($subject['name']); ?></span>
                                    <select name="subject_categories[<?php echo esc_attr($id); ?>]" class="compact-select">
                                        <?php foreach ($categories as $slug => $category): ?>
                                            <option value="<?php echo esc_attr($slug); ?>"
                                                    <?php selected($subject_categories[$id] ?? 'main', $slug); ?>>
                                                <?php echo esc_html($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $free_rooms_excluded = get_option('schedule_free_rooms_excluded', []);
    if (!is_array($free_rooms_excluded)) {
        $free_rooms_excluded = [];
    }
    $free_rooms_excluded = array_map('strval', $free_rooms_excluded);
    ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Свободные кабинеты
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('free_rooms_settings', 'free_rooms_nonce'); ?>
                <input type="hidden" name="action" value="save_free_rooms_settings">

                <p class="description">
                    Отметьте кабинеты, которые не нужно учитывать в списке свободных
                    и в сетке занятости. Неотмеченные кабинеты показываются как обычно.
                </p>

                <?php if (!empty($schedule['classrooms'])): ?>
                    <div class="free-rooms-exclude-actions">
                        <button type="button" class="button" id="freeRoomsSelectAll">Отметить все</button>
                        <button type="button" class="button" id="freeRoomsClearAll">Снять все</button>
                    </div>
                    <div class="free-rooms-exclude-grid">
                        <?php foreach ($schedule['classrooms'] as $room_id => $room): ?>
                            <label class="free-rooms-exclude-item">
                                <input type="checkbox"
                                       name="free_rooms_excluded[]"
                                       value="<?php echo esc_attr($room_id); ?>"
                                       <?php checked(in_array((string) $room_id, $free_rooms_excluded, true)); ?>>
                                <span><?php echo esc_html($room['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Загрузите расписание, чтобы выбрать кабинеты.</p>
                <?php endif; ?>

                <div class="submit-wrapper">
                    <button type="submit" class="button button-primary">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $visible_group_ids = get_option('schedule_visible_class_groups', null);
    $group_selection_configured = is_array($visible_group_ids);
    if (!$group_selection_configured) {
        $visible_group_ids = [];
    }
    $visible_group_ids = array_map('strval', $visible_group_ids);

    $hidden_group_names = get_option('schedule_hidden_group_names', []);
    if (!is_array($hidden_group_names)) {
        $hidden_group_names = [];
    }
    $hidden_group_names = array_map('strval', $hidden_group_names);
    $display_enabled = get_option('schedule_show_class_groups') === 'on';
    $groups_configured = $group_selection_configured || !empty($hidden_group_names);

    $classes_with_groups = [];
    $all_group_names = [];
    if (!empty($schedule['classes'])) {
        foreach ($schedule['classes'] as $class_id => $class) {
            $subgroups = [];
            foreach (($class['groups'] ?? []) as $group_id => $group) {
                if (empty($group['entireclass']) && !empty($group['name'])) {
                    $subgroups[$group_id] = $group;
                    $all_group_names[trim($group['name'])] = trim($group['name']);
                }
            }
            if (!empty($subgroups)) {
                $classes_with_groups[$class_id] = [
                    'name' => $class['name'],
                    'groups' => $subgroups,
                ];
            }
        }
        uasort($classes_with_groups, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        ksort($all_group_names, SORT_NATURAL | SORT_FLAG_CASE);
    }
    ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Отображение групп классов
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('display_settings', 'display_nonce'); ?>
                <input type="hidden" name="action" value="save_display_settings">

                <div class="setting-row">
                    <label class="switch-label">
                        <span>Показывать группы классов в карточках уроков</span>
                        <label class="switch">
                            <input type="checkbox"
                                   name="show_class_groups"
                                   <?php checked(get_option('schedule_show_class_groups'), 'on'); ?>>
                            <span class="slider round"></span>
                        </label>
                    </label>
                    <p class="description">
                        Если в XML-файле есть подгруппы классов, они будут показаны рядом с классом
                        (например, «1 группа»). Для обычной страницы и панелей настройка общая.
                    </p>
                </div>

                <?php if (!$display_enabled && $groups_configured): ?>
                    <div class="notice notice-warning">
                        <p>Группы настроены, но их показ выключен. Включите переключатель выше и сохраните настройки.</p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($classes_with_groups)): ?>
                    <input type="hidden" name="groups_selection_present" value="1">
                    <div class="groups-selection-header">
                        <h3>Какие группы показывать</h3>
                        <div class="groups-selection-actions">
                            <button type="button" class="button" data-groups-action="all">Выбрать все</button>
                            <button type="button" class="button" data-groups-action="none">Снять все</button>
                        </div>
                    </div>

                    <div class="groups-selection">
                        <?php foreach ($classes_with_groups as $class): ?>
                            <div class="class-groups-item">
                                <div class="class-groups-title"><?php echo esc_html($class['name']); ?></div>
                                <div class="class-groups-list">
                                    <?php foreach ($class['groups'] as $group_id => $group): ?>
                                        <?php
                                        $is_checked = !$group_selection_configured
                                            || in_array((string) $group_id, $visible_group_ids, true);
                                        ?>
                                        <label class="group-checkbox">
                                            <input type="checkbox"
                                                   name="visible_class_groups[]"
                                                   value="<?php echo esc_attr($group_id); ?>"
                                                   <?php checked($is_checked); ?>>
                                            <span><?php echo esc_html($group['name']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">
                        Отметьте группы, которые нужно показывать. Если снять все галочки,
                        группы не будут отображаться даже при включённом переключателе.
                    </p>

                    <?php if (!empty($all_group_names)): ?>
                        <input type="hidden" name="group_names_selection_present" value="1">
                        <div class="groups-selection-header">
                            <h3>Скрыть группы у всех классов</h3>
                            <div class="groups-selection-actions">
                                <button type="button" class="button" data-group-names-action="none">Показать все</button>
                                <button type="button" class="button" data-group-names-action="all">Скрыть все</button>
                            </div>
                        </div>
                        <div class="group-names-selection">
                            <?php foreach ($all_group_names as $group_name): ?>
                                <label class="group-checkbox">
                                    <input type="checkbox"
                                           name="hidden_group_names[]"
                                           value="<?php echo esc_attr($group_name); ?>"
                                           <?php checked(in_array($group_name, $hidden_group_names, true)); ?>>
                                    <span><?php echo esc_html($group_name); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">
                            Отмеченные группы будут скрыты у всех классов. Это работает вместе
                            с выбором групп выше: скрытая группа не появится, даже если она отмечена
                            в списке класса.
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="description">В загруженном XML нет подгрупп классов.</p>
                <?php endif; ?>

                <div class="submit-wrapper">
                    <button type="submit" class="button button-primary">Сохранить настройки</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Настройки бегущей строки
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('marquee_settings', 'marquee_nonce'); ?>
                <input type="hidden" name="action" value="save_marquee_settings">
                
                <div class="marquee-settings">
                    <div class="setting-row">
                        <label class="switch-label">
                            <span>Включить бегущую строку</span>
                            <label class="switch">
                                <input type="checkbox" 
                                       name="marquee_enabled"
                                       <?php checked(get_option('schedule_marquee_enabled'), 'on'); ?>>
                                <span class="slider round"></span>
                            </label>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_text">Текст бегущей строки:</label>
                        <textarea id="marquee_text" 
                                  name="marquee_text" 
                                  rows="3" 
                                  class="large-text"><?php echo esc_textarea(get_option('schedule_marquee_text')); ?></textarea>
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_type">Тип отображения:</label>
                        <select name="marquee_type" id="marquee_type">
                            <option value="scroll" <?php selected(get_option('schedule_marquee_type'), 'scroll'); ?>>Бегущая строка</option>
                            <option value="fixed" <?php selected(get_option('schedule_marquee_type'), 'fixed'); ?>>Фиксированный текст</option>
                        </select>
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_speed">Скорость прокрутки (секунд):</label>
                        <input type="number" 
                               id="marquee_speed" 
                               name="marquee_speed" 
                               min="5" 
                               max="60" 
                               value="<?php echo esc_attr(get_option('schedule_marquee_speed', '30')); ?>">
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_text_color">Цвет текста:</label>
                        <input type="color" 
                               id="marquee_text_color" 
                               name="marquee_text_color" 
                               value="<?php echo esc_attr(get_option('schedule_marquee_text_color', '#ffffff')); ?>">
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_bg_color">Цвет фона:</label>
                        <input type="color" 
                               id="marquee_bg_color" 
                               name="marquee_bg_color" 
                               value="<?php echo esc_attr(get_option('schedule_marquee_bg_color', '#3b82f6')); ?>">
                    </div>
                    
                    <div class="setting-row">
                        <label for="marquee_font_size">Размер шрифта (px):</label>
                        <input type="number" 
                               id="marquee_font_size" 
                               name="marquee_font_size" 
                               min="12" 
                               max="32" 
                               value="<?php echo esc_attr(get_option('schedule_marquee_font_size', '16')); ?>">
                    </div>
                    
                    <div class="submit-wrapper">
                        <button type="submit" class="button button-primary">Сохранить настройки</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Настройки баннеров и заголовка
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('banner_settings', 'banner_nonce'); ?>
                <input type="hidden" name="action" value="save_banner_settings">
                
                <div class="banner-settings">
                    <!-- Настройки заголовка -->
                    <h3>Настройки заголовка</h3>
                    <div class="setting-row">
                        <label for="header_text">Текст заголовка:</label>
                        <input type="text" 
                               id="header_text" 
                               name="header_text" 
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('schedule_header_text', 'Расписание занятий')); ?>">
                    </div>

                    <div class="setting-row">
                        <label for="header_position">Расположение заголовка:</label>
                        <select name="header_position" id="header_position">
                            <option value="left" <?php selected(get_option('schedule_header_position'), 'left'); ?>>Слева</option>
                            <option value="center" <?php selected(get_option('schedule_header_position'), 'center'); ?>>По центру</option>
                            <option value="right" <?php selected(get_option('schedule_header_position'), 'right'); ?>>Справа</option>
                            <option value="top" <?php selected(get_option('schedule_header_position'), 'top'); ?>>Сверху</option>
                            <option value="bottom" <?php selected(get_option('schedule_header_position'), 'bottom'); ?>>Снизу</option>
                        </select>
                    </div>

                    <!-- Настройки для обычной страницы -->
                    <h3>Настройки для обычной страницы</h3>
                    <div class="setting-row">
                        <label class="switch-label">
                            <span>Показывать баннер</span>
                            <label class="switch">
                                <input type="checkbox" 
                                       name="banner_enabled_regular"
                                       <?php checked(get_option('schedule_banner_enabled_regular'), 'on'); ?>>
                                <span class="slider round"></span>
                            </label>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <label for="banner_content_regular">HTML-код баннера:</label>
                        <textarea id="banner_content_regular" 
                                  name="banner_content_regular" 
                                  rows="5" 
                                  class="large-text code"><?php echo esc_textarea(get_option('schedule_banner_content_regular')); ?></textarea>
                    </div>

                    <div class="setting-row">
                        <label class="switch-label">
                            <span>Показывать QR-код</span>
                            <label class="switch">
                                <input type="checkbox" 
                                       name="qr_enabled_regular"
                                       <?php checked(get_option('schedule_qr_enabled_regular'), 'on'); ?>>
                                <span class="slider round"></span>
                            </label>
                        </label>
                    </div>

                    <div class="setting-row">
                        <label for="qr_image_regular">Изображение QR-кода:</label>
                        <input type="file" 
                               id="qr_image_regular" 
                               name="qr_image_regular" 
                               accept="image/*">
                        <?php if ($current_qr = get_option('schedule_qr_image_regular')): ?>
                            <div class="current-image">
                                <img src="<?php echo esc_url($current_qr); ?>" alt="Текущий QR-код" style="max-width: 100px;">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="setting-row">
                        <label for="qr_size_regular">Размер QR-кода (px):</label>
                        <input type="number" 
                               id="qr_size_regular" 
                               name="qr_size_regular" 
                               min="50" 
                               max="300" 
                               value="<?php echo esc_attr(get_option('schedule_qr_size_regular', '100')); ?>">
                    </div>

                    <div class="setting-row">
                        <label for="qr_position_regular">Расположение QR-кода:</label>
                        <select name="qr_position_regular" id="qr_position_regular">
                            <option value="left" <?php selected(get_option('schedule_qr_position_regular'), 'left'); ?>>Слева</option>
                            <option value="right" <?php selected(get_option('schedule_qr_position_regular'), 'right'); ?>>Справа</option>
                        </select>
                    </div>

                    <div class="setting-row">
                        <label for="qr_url_regular">URL для QR-кода:</label>
                        <input type="url" 
                               id="qr_url_regular" 
                               name="qr_url_regular" 
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('schedule_qr_url_regular')); ?>"
                               placeholder="https://example.com">
                    </div>

                    <!-- Настройки для панелей -->
                    <h3>Настройки для панелей</h3>
                    <div class="setting-row">
                        <label class="switch-label">
                            <span>Показывать баннер</span>
                            <label class="switch">
                                <input type="checkbox" 
                                       name="banner_enabled_panel"
                                       <?php checked(get_option('schedule_banner_enabled_panel'), 'on'); ?>>
                                <span class="slider round"></span>
                            </label>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <label for="banner_content_panel">HTML-код баннера:</label>
                        <textarea id="banner_content_panel" 
                                  name="banner_content_panel" 
                                  rows="5" 
                                  class="large-text code"><?php echo esc_textarea(get_option('schedule_banner_content_panel')); ?></textarea>
                    </div>

                    <div class="setting-row">
                        <label class="switch-label">
                            <span>Показывать QR-код</span>
                            <label class="switch">
                                <input type="checkbox" 
                                       name="qr_enabled_panel"
                                       <?php checked(get_option('schedule_qr_enabled_panel'), 'on'); ?>>
                                <span class="slider round"></span>
                            </label>
                        </label>
                    </div>

                    <div class="setting-row">
                        <label for="qr_image_panel">Изображение QR-кода:</label>
                        <input type="file" 
                               id="qr_image_panel" 
                               name="qr_image_panel" 
                               accept="image/*">
                        <?php if ($current_qr = get_option('schedule_qr_image_panel')): ?>
                            <div class="current-image">
                                <img src="<?php echo esc_url($current_qr); ?>" alt="Текущий QR-код" style="max-width: 100px;">
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="setting-row">
                        <label for="qr_size_panel">Размер QR-кода (px):</label>
                        <input type="number" 
                               id="qr_size_panel" 
                               name="qr_size_panel" 
                               min="50" 
                               max="300" 
                               value="<?php echo esc_attr(get_option('schedule_qr_size_panel', '100')); ?>">
                    </div>

                    <div class="setting-row">
                        <label for="qr_position_panel">Расположение QR-кода:</label>
                        <select name="qr_position_panel" id="qr_position_panel">
                            <option value="left" <?php selected(get_option('schedule_qr_position_panel'), 'left'); ?>>Слева</option>
                            <option value="right" <?php selected(get_option('schedule_qr_position_panel'), 'right'); ?>>Справа</option>
                        </select>
                    </div>

                    <div class="setting-row">
                        <label for="qr_url_panel">URL для QR-кода:</label>
                        <input type="url" 
                               id="qr_url_panel" 
                               name="qr_url_panel" 
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('schedule_qr_url_panel')); ?>"
                               placeholder="https://example.com">
                    </div>

                    <div class="submit-wrapper">
                        <button type="submit" class="button button-primary">Сохранить настройки</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php
    $current_layout = get_option('schedule_layout', 'classic');
    if (!in_array($current_layout, ['classic', 'sidebar-right', 'sidebar-left', 'compact'], true)) {
        $current_layout = 'classic';
    }
    $layout_options = [
        'classic' => [
            'name' => 'Классическая',
            'desc' => 'Часы по центру, кнопки справа, фильтры под шапкой.',
        ],
        'sidebar-right' => [
            'name' => 'Правая панель',
            'desc' => 'Часы, фильтры и кнопки в правой панели. Для широких экранов.',
        ],
        'sidebar-left' => [
            'name' => 'Левая панель',
            'desc' => 'Часы, фильтры и кнопки в левой панели. Для широких экранов.',
        ],
        'compact' => [
            'name' => 'Компактная',
            'desc' => 'Часы и период в одной строке, фильтры в ряд, меньше высоты.',
        ],
    ];
    ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <button type="button" class="toggle-card" aria-expanded="false">
                    Компоновка страницы
                    <span class="toggle-icon">▼</span>
                </button>
            </h2>
        </div>
        <div class="card-content" style="display: none;">
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('layout_settings', 'layout_nonce'); ?>
                <input type="hidden" name="action" value="save_layout_settings">

                <p class="description">
                    Расположение часов, фильтров и кнопок. Варианты с боковой панелью
                    применяются на широких экранах, на мобильных остаётся классическая компоновка.
                </p>

                <div class="layout-choice-grid">
                    <?php foreach ($layout_options as $layout_key => $layout): ?>
                        <label class="layout-choice">
                            <input type="radio" name="schedule_layout" value="<?php echo esc_attr($layout_key); ?>" <?php checked($current_layout, $layout_key); ?>>

                            <span class="layout-preview lp-<?php echo esc_attr($layout_key); ?>" aria-hidden="true">
                                <?php if ($layout_key === 'classic'): ?>
                                    <span class="lp-top"><span class="lp-time"></span><span class="lp-actions"></span></span>
                                    <span class="lp-filters"><i></i><i></i><i></i><i></i></span>
                                    <span class="lp-table"></span>
                                <?php elseif ($layout_key === 'compact'): ?>
                                    <span class="lp-top lp-top-compact"><span class="lp-time"></span><span class="lp-actions"></span></span>
                                    <span class="lp-filters lp-filters-inline"><i></i><i></i><i></i><i></i></span>
                                    <span class="lp-table"></span>
                                <?php else: ?>
                                    <span class="lp-table"></span>
                                    <span class="lp-side"><span class="lp-time"></span><i></i><i></i><i></i></span>
                                <?php endif; ?>
                            </span>

                            <span class="layout-choice-name"><?php echo esc_html($layout['name']); ?></span>
                            <span class="layout-choice-desc"><?php echo esc_html($layout['desc']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="submit-wrapper">
                    <button type="submit" class="button button-primary">Сохранить компоновку</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Управление страницами</h2>
        <p>Публичные страницы приложения доступны по постоянным адресам:</p>
        <table class="list">
            <thead>
                <tr>
                    <th>Страница</th>
                    <th>Адрес</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Обычное расписание</strong></td>
                    <td><code><?php echo esc_html(App_Config::site_url('/')); ?></code></td>
                    <td>
                        <a class="button" href="<?php echo esc_url(App_Config::site_url('/')); ?>" target="_blank" rel="noopener">Открыть</a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Расписание для панелей</strong></td>
                    <td><code><?php echo esc_html(App_Config::site_url('panels')); ?></code></td>
                    <td>
                        <a class="button" href="<?php echo esc_url(App_Config::site_url('panels')); ?>" target="_blank" rel="noopener">Открыть</a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Текущее расписание (XML)</strong></td>
                    <td><code><?php echo esc_html(App_Config::site_url('schedule.xml')); ?></code></td>
                    <td>
                        <a class="button" href="<?php echo esc_url(App_Config::site_url('schedule.xml')); ?>" target="_blank" rel="noopener">Открыть</a>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="description">
            Ссылка <code>/schedule.xml</code> всегда отдаёт XML активного файла расписания.
            Её можно использовать во внешних приложениях — ссылка не меняется при загрузке новой версии.
        </p>
    </div>
</div> 