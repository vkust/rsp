<?php if (!defined('ABSPATH')) exit;

// Отключаем автоформатирование для контента внутри карточек
remove_filter('the_content', 'wpautop');
remove_filter('the_excerpt', 'wpautop');

// Функция для определения последнего периода с уроками в дне
if (!function_exists('getLastPeriodInDay')) {
    function getLastPeriodInDay($dayId, $periods, $lessons) {
        $lastPeriod = null;
        foreach ($periods as $period) {
            $hasLessons = array_filter($lessons, function($lesson) use ($period, $dayId) {
                return $lesson['periodId'] === $period['id'] && $lesson['dayId'] === $dayId;
            });
            if (!empty($hasLessons)) {
                $lastPeriod = $period;
            }
        }
        return $lastPeriod;
    }
}

// Функция для проверки, нужно ли показывать период
if (!function_exists('shouldShowPeriod')) {
    function shouldShowPeriod($period, $days, $lessons, $periods) {
        foreach ($days as $dayId => $day) {
            $lastPeriod = getLastPeriodInDay($dayId, $periods, $lessons);
            if ($lastPeriod && $period['id'] <= $lastPeriod['id']) {
                return true;
            }
        }
        return false;
    }
}

$show_class_groups = get_option('schedule_show_class_groups', 'off') === 'on';
$visible_group_ids = get_option('schedule_visible_class_groups', null);
$group_selection_configured = is_array($visible_group_ids);
if ($group_selection_configured) {
    $visible_group_ids = array_map('strval', $visible_group_ids);
}

$hidden_group_names = get_option('schedule_hidden_group_names', []);
$hidden_group_names_map = [];
if (is_array($hidden_group_names)) {
    foreach ($hidden_group_names as $hidden_group_name) {
        $hidden_group_names_map[mb_strtolower(trim((string) $hidden_group_name))] = true;
    }
}

$group_names = [];
if ($show_class_groups && !empty($schedule['classes'])) {
    foreach ($schedule['classes'] as $class) {
        foreach (($class['groups'] ?? []) as $groupId => $group) {
            // «Весь класс» — это не подгруппа, её не показываем.
            if (empty($group['name']) || !empty($group['entireclass'])) {
                continue;
            }
            // Группа скрыта у всех классов по названию.
            if (isset($hidden_group_names_map[mb_strtolower(trim($group['name']))])) {
                continue;
            }
            // Если список групп настроен, показываем только отмеченные.
            if ($group_selection_configured && !in_array((string) $groupId, $visible_group_ids, true)) {
                continue;
            }
            $group_names[$groupId] = $group['name'];
        }
    }
}
?>

<div class="schedule-container<?php echo $atts['is_panel'] ? ' schedule-panels' : ''; ?>">
    <!-- Сообщения об ошибках и успехе -->
    <?php if ($error || $success): ?>
        <div class="notifications">
            <?php if ($error): ?>
                <div class="notification notification-error">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <?php echo esc_html($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="notification notification-success">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                    </svg>
                    <?php echo esc_html($success); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($schedule)): ?>
        <!-- Панель управления -->
        <div class="schedule-controls">
            <div class="controls-header">
                <div class="header-content">
                    <?php
                    $header_position = get_option('schedule_header_position', 'left');
                    $is_panel = !empty($atts['is_panel']);
                    $banner_enabled = $is_panel ? 
                        get_option('schedule_banner_enabled_panel') === 'on' : 
                        get_option('schedule_banner_enabled_regular') === 'on';
                    $qr_enabled = $is_panel ? 
                        get_option('schedule_qr_enabled_panel') === 'on' : 
                        get_option('schedule_qr_enabled_regular') === 'on';
                    $banner_content = $is_panel ? 
                        get_option('schedule_banner_content_panel') : 
                        get_option('schedule_banner_content_regular');
                    $qr_image = $is_panel ? 
                        get_option('schedule_qr_image_panel') : 
                        get_option('schedule_qr_image_regular');
                    $qr_url = $is_panel ? 
                        get_option('schedule_qr_url_panel') : 
                        get_option('schedule_qr_url_regular');
                    $qr_size = $is_panel ? 
                        get_option('schedule_qr_size_panel', 100) : 
                        get_option('schedule_qr_size_regular', 100);
                    $qr_position = $is_panel ? 
                        get_option('schedule_qr_position_panel', 'right') : 
                        get_option('schedule_qr_position_regular', 'right');
                    ?>

                    <div class="header-content-wrapper position-<?php echo esc_attr($header_position); ?>">
                        <?php if ($header_position === 'top'): ?>
                            <h1 class="schedule-title">
                                <?php echo esc_html(get_option('schedule_header_text', 'Расписание занятий')); ?>
                            </h1>
                        <?php endif; ?>

                        <div class="header-main-content">
                            <?php if (in_array($header_position, ['left', 'center', 'right'])): ?>
                                <h1 class="schedule-title text-<?php echo esc_attr($header_position); ?>">
                                    <?php echo esc_html(get_option('schedule_header_text', 'Расписание занятий')); ?>
                                </h1>
                            <?php endif; ?>

                            <?php if ($banner_enabled && !empty($banner_content)): ?>
                                <div class="schedule-banner">
                                    <?php echo wp_kses_post($banner_content); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($qr_enabled && !empty($qr_image)): ?>
                                <div class="schedule-qr qr-<?php echo esc_attr($qr_position); ?>" 
                                     style="width: <?php echo esc_attr($qr_size); ?>px; height: <?php echo esc_attr($qr_size); ?>px;">
                                    <?php if (!empty($qr_url)): ?>
                                        <a href="<?php echo esc_url($qr_url); ?>" target="_blank">
                                            <img src="<?php echo esc_url($qr_image); ?>" alt="QR код" class="qr-code">
                                        </a>
                                    <?php else: ?>
                                        <img src="<?php echo esc_url($qr_image); ?>" alt="QR код" class="qr-code">
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($header_position === 'bottom'): ?>
                            <h1 class="schedule-title">
                                <?php echo esc_html(get_option('schedule_header_text', 'Расписание занятий')); ?>
                            </h1>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="time-status-wrapper">
                    <!-- Контейнер для времени -->
                    <div class="time-container">
                        <div class="current-time-large">
                            <div id="currentTime">
                                <div class="time-main-large">--:--</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Отдельный контейнер для статуса -->
                    <div class="period-container">
                        <div class="time-status-large status-none">
                            <svg class="time-icon" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                            <span>Загрузка...</span>
                        </div>
                    </div>
                </div>

                <!-- Кнопки управления справа -->
                <div class="view-controls">
                    <button id="resetFilters" class="btn btn-secondary animated-btn">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path class="filter-line-1" d="M3 6h18" stroke-linecap="round"/>
                            <path class="filter-line-2" d="M6 12h12" stroke-linecap="round"/>
                            <path class="filter-line-3" d="M9 18h6" stroke-linecap="round"/>
                        </svg>
                        <span>Сбросить фильтры</span>
                    </button>
                    <button id="toggleView" class="btn btn-primary">
                        <svg class="view-icon" viewBox="0 0 24 24">
                            <path class="icon" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <button id="exportExcel" class="btn btn-secondary animated-btn">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path class="arrow-stem" d="M12 4v10" stroke-linecap="round"/>
                            <path class="arrow-left" d="M7 10l5 5" stroke-linecap="round"/>
                            <path class="arrow-right" d="M17 10l-5 5" stroke-linecap="round"/>
                            <path class="landing-line" d="M6 19h12" stroke-linecap="round"/>
                        </svg>
                        <span>Экспорт</span>
                    </button>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="filters-grid">
                <?php
                // Получаем настройки категорий
                $categories = get_option('schedule_categories', []);
                $subject_categories = get_option('schedule_subject_categories', []);

                // Фильтруем предметы по видимым категориям
                $filtered_subjects = array_filter($schedule['subjects'], function($subject, $id) use ($categories, $subject_categories) {
                    $category_slug = $subject_categories[$id] ?? 'main';
                    return $categories[$category_slug]['visible'] ?? true;
                }, ARRAY_FILTER_USE_BOTH);

                $filters = [
                    'teacher' => ['label' => 'Учитель', 'data' => $schedule['teachers']],
                    'subject' => ['label' => 'Предмет', 'data' => $filtered_subjects], // Используем отфильтрованные предметы
                    'class' => ['label' => 'Класс', 'data' => $schedule['classes']],
                    'day' => ['label' => 'День', 'data' => $schedule['days']],
                    'classroom' => ['label' => 'Кабинет', 'data' => $schedule['classrooms']]
                ];

                foreach ($filters as $key => $filter): ?>
                    <div class="filter-group">
                        <select id="<?php echo esc_attr($key); ?>Filter" class="filter-select">
                            <?php
                            $plural_endings = [
                                'teacher' => 'преподаватели',
                                'subject' => 'предметы',
                                'class' => 'классы',
                                'day' => 'дни',
                                'classroom' => 'кабинеты',
                            ];
                            ?>

                            <option value="">Все <?php echo $plural_endings[$key] ?? mb_strtolower($filter['label']) . 'и'; ?></option>
                            <?php 
                            // Сортируем элементы по имени или по порядку для дней
                            $sorted_data = $filter['data'];
                            if ($key === 'day') {
                                // Сортировка дней по порядку
                                uasort($sorted_data, function($a, $b) {
                                    return ($a['order'] ?? 0) - ($b['order'] ?? 0);
                                });
                            } else {
                                // Сортировка остальных элементов по имени
                                uasort($sorted_data, function($a, $b) {
                                    return strcmp($a['name'], $b['name']);
                                });
                            }
                            
                            foreach($sorted_data as $id => $item): ?>
                                <option value="<?php echo esc_attr($id); ?>">
                                    <?php echo esc_html($item['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
                                <td>
        <!-- Таблица распиания -->
        <div class="schedule-table-wrapper">
            <div class="schedule-table-header-scroll">
                <div style="width: 100%; height: 1px;"></div>
            </div>
            <div class="schedule-table-body">
                <table class="schedule-table" 
                       data-periods='<?php echo esc_attr(json_encode($schedule['periods'])); ?>'>
                    <thead>
                        <tr>
                            <th>Время</th>
                            <?php 
                            // Фильтруем дни, в которых есть хотя бы один урок
                            $active_days = array_filter($schedule['days'], function($day, $dayId) use ($schedule) {
                                // Проверяем наличие уроков в этом дне
                                $hasLessons = array_filter($schedule['lessons'], function($lesson) use ($dayId) {
                                    return $lesson['dayId'] === $dayId;
                                });
                                return !empty($hasLessons); // Возвращаем true только если есть уроки
                            }, ARRAY_FILTER_USE_BOTH);
                            
                            foreach($active_days as $day): 
                            ?>
                                <th data-day-id="<?php echo esc_attr($day['id']); ?>">
                                    <?php echo esc_html($day['name']); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach($schedule['periods'] as $period): 
                            // Проверяем, нужно ли показывать этот период
                            if (!shouldShowPeriod($period, $active_days, $schedule['lessons'], $schedule['periods'])) {
                                continue;
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="period-header">
                                        <span class="period-number"><?php echo esc_html($period['name']); ?> урок</span>
                                        <?php if (!empty($period['starttime']) && !empty($period['endtime'])): ?>
                                            <span class="period-time">
                                                <?php echo esc_html($period['starttime']) . ' — ' . esc_html($period['endtime']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <?php 
                                foreach($active_days as $dayId => $day):
                                    $lastPeriodInDay = getLastPeriodInDay($dayId, $schedule['periods'], $schedule['lessons']);
                                    // Показываем ячейку только если период не позже последнего урока в дне
                                    if ($lastPeriodInDay && $period['id'] <= $lastPeriodInDay['id']):
                                        $dayLessons = array_filter($schedule['lessons'], function($lesson) use ($period, $dayId) {
                                            return $lesson['periodId'] === $period['id'] && $lesson['dayId'] === $dayId;
                                        });
                                ?>
                                    <td data-day-id="<?php echo esc_attr($dayId); ?>" 
                                        data-time="<?php echo esc_attr($period['id'] - 1); ?>">
                                        <?php foreach($dayLessons as $lesson): ?>
                                            <div class="lesson-cell"
                                                 data-teachers="<?php echo esc_attr(implode(',', $lesson['teacherIds'])); ?>"
                                                 data-subject="<?php echo esc_attr($lesson['subjectId']); ?>"
                                                 data-classes="<?php echo esc_attr(implode(',', $lesson['classIds'])); ?>"
                                                 data-day="<?php echo esc_attr($dayId); ?>"
                                                 data-classroom="<?php echo esc_attr($lesson['classroomId']); ?>"
                                                 data-category="<?php echo esc_attr($subject_categories[$lesson['subjectId']] ?? 'main'); ?>"
                                                 >
                                                
                                                <div class="lesson-content">
                                                    <div class="lesson-main">
                                                        <div class="subject-name">
                                                            <?php 
                                                                $subject = $schedule['subjects'][$lesson['subjectId']];
                                                                echo esc_html($subject['short'] ?: $subject['name']); 
                                                            ?>
                                                        </div>
                                                        <?php if (!empty($lesson['teacherIds'])): ?>
                                                            <div class="teacher-info">
                                                                <?php
                                                                $teacherNames = array_map(function($tid) use ($schedule) {
                                                                    return $schedule['teachers'][$tid]['name'] ?? '';
                                                                }, $lesson['teacherIds']);
                                                                echo esc_html(implode(', ', array_filter($teacherNames)));
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div class="lesson-details">
                                                        <div class="class-info">
                                                            <?php
                                                            foreach($lesson['classIds'] as $cid) {
                                                                if (isset($schedule['classes'][$cid])) {
                                                                    echo '<span class="class-badge">' . 
                                                                         esc_html($schedule['classes'][$cid]['name']) . 
                                                                         '</span>';
                                                                }
                                                            }
                                                            ?>
                                                        </div>

                                                        <?php if ($show_class_groups && !empty($lesson['groupIds'])): ?>
                                                            <div class="group-info">
                                                                <?php
                                                                foreach ($lesson['groupIds'] as $groupId) {
                                                                    if (isset($group_names[$groupId])) {
                                                                        echo '<span class="group-badge">' .
                                                                             esc_html($group_names[$groupId]) .
                                                                             '</span>';
                                                                    }
                                                                }
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (isset($lesson['classroomId']) && 
                                                                  !empty($lesson['classroomId']) && 
                                                                  isset($schedule['classrooms'][$lesson['classroomId']])): ?>
                                                            <div class="room-info">
                                                                каб. <?php echo esc_html($schedule['classrooms'][$lesson['classroomId']]['name']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                <?php 
                                    else:
                                        echo '<td></td>';
                                    endif;
                                endforeach; 
                                ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($debug_mode): ?>
            <div class="debug-info">
                <details>
                    <summary>Отладочная информация</summary>
                    <pre><?php print_r($parser->getDebugInfo()); ?></pre>
                </details>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-schedule">
            <svg class="icon-large" viewBox="0 0 24 24">
                <path d="M13 2.05v3.03c3.39.49 6 3.39 6 6.92 0 .9-.18 1.75-.5 2.54l2.54 2.54c.65-1.41 1-2.99 1-4.64 0-5.52-4.48-10-10-10zm-2 0C5.48 2.05 1 6.53 1 12.05c0 1.65.36 3.23 1 4.64l2.54-2.54c-.32-.79-.5-1.64-.5-2.54 0-3.53 2.61-6.43 6-6.92V2.05z"/>
            </svg>
            <h2>Расписание не загруено</h2>
            <p>Загрузите файл расписания через панель администратора</p>
        </div>
    <?php endif; ?>

    <!-- Кнопка "Вверх" -->
    <button class="scroll-to-top" aria-label="Прокрутить вверх">
        <svg viewBox="0 0 24 24">
            <path d="M18 15l-6-6-6 6"/>
        </svg>
    </button>
</div> 