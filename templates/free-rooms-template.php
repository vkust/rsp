<?php if (!defined('ABSPATH')) exit; ?>

<div class="rooms-page" id="freeRoomsGridPage">
    <header class="rooms-header">
        <div>
            <h1>Свободные кабинеты</h1>
            <p class="rooms-subtitle">
                Сетка занятости кабинетов по урокам. Учитываются все уроки, включая скрытые категории.
            </p>
        </div>
        <div class="rooms-header-actions">
            <?php if (!empty($active_file)): ?>
                <span class="rooms-file">Файл: <code><?php echo esc_html(basename($active_file)); ?></code></span>
            <?php endif; ?>
            <a class="btn btn-secondary" href="<?php echo esc_url(App_Config::site_url('/')); ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>К расписанию</span>
            </a>
        </div>
    </header>

    <?php if (!empty($error)): ?>
        <div class="rooms-empty"><?php echo esc_html($error); ?></div>
    <?php elseif (empty($free_rooms['rooms']) || empty($free_rooms['periods'])): ?>
        <div class="rooms-empty">Нет данных для отображения сетки кабинетов.</div>
    <?php else: ?>
        <div class="rooms-toolbar">
            <div class="rooms-days" role="tablist" aria-label="День недели">
                <?php foreach ($free_rooms['days'] as $day): ?>
                    <button type="button"
                            class="rooms-day-tab<?php echo $day['id'] === $free_rooms['default_day'] ? ' is-active' : ''; ?>"
                            data-day-tab="<?php echo esc_attr($day['id']); ?>"
                            data-order="<?php echo esc_attr($day['order']); ?>"
                            id="rooms-tab-<?php echo esc_attr($day['id']); ?>"
                            role="tab"
                            aria-controls="rooms-day-<?php echo esc_attr($day['id']); ?>"
                            aria-selected="<?php echo $day['id'] === $free_rooms['default_day'] ? 'true' : 'false'; ?>"
                            tabindex="<?php echo $day['id'] === $free_rooms['default_day'] ? '0' : '-1'; ?>">
                        <span class="rooms-day-full"><?php echo esc_html($day['name']); ?></span>
                        <span class="rooms-day-short"><?php echo esc_html($day['short']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="rooms-legend">
                <span class="legend-free">Свободен</span>
                <span class="legend-busy">Занят</span>
            </div>
        </div>

        <?php foreach ($free_rooms['days'] as $day): ?>
            <?php
            $day_id = $day['id'];
            $is_default = ($day_id === $free_rooms['default_day']);
            ?>
            <section class="rooms-day-panel"
                     data-day-panel="<?php echo esc_attr($day_id); ?>"
                     data-has-lessons="<?php echo $day['has_lessons'] ? '1' : '0'; ?>"
                     id="rooms-day-<?php echo esc_attr($day_id); ?>"
                     role="tabpanel"
                     aria-labelledby="rooms-tab-<?php echo esc_attr($day_id); ?>"
                     <?php echo $is_default ? '' : 'hidden'; ?>>
                <?php if (!$day['has_lessons']): ?>
                    <div class="rooms-empty">На этот день уроков нет.</div>
                <?php else: ?>
                    <div class="rooms-grid-scroll">
                        <table class="rooms-grid">
                            <thead>
                                <tr>
                                    <th class="rooms-grid-corner" scope="col">Кабинет</th>
                                    <?php foreach ($free_rooms['periods'] as $period): ?>
                                        <?php
                                        $period_id = $period['id'];
                                        $free_count = $free_rooms['free_counts'][$day_id][$period_id] ?? count($free_rooms['rooms']);
                                        ?>
                                        <th class="rooms-grid-period-head"
                                            scope="col"
                                            data-period="<?php echo esc_attr($period_id); ?>"
                                            data-start="<?php echo esc_attr($period['start']); ?>"
                                            data-end="<?php echo esc_attr($period['end']); ?>">
                                            <span class="rooms-grid-period"><?php echo esc_html($period['name']); ?> урок</span>
                                            <?php if ($period['start'] !== '' && $period['end'] !== ''): ?>
                                                <span class="rooms-grid-time"><?php echo esc_html($period['start'] . '–' . $period['end']); ?></span>
                                            <?php endif; ?>
                                            <span class="rooms-grid-free">свободно <?php echo esc_html((string) $free_count); ?></span>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="rooms-grid-total" scope="col">Свободно<br>за день</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($free_rooms['rooms'] as $room): ?>
                                    <?php $room_id = $room['id']; ?>
                                    <tr>
                                        <th class="rooms-grid-room" scope="row"><?php echo esc_html($room['name']); ?></th>
                                        <?php foreach ($free_rooms['periods'] as $period): ?>
                                            <?php
                                            $period_id = $period['id'];
                                            $cell = $free_rooms['occupied'][$day_id][$period_id][$room_id] ?? null;
                                            ?>
                                            <?php if ($cell): ?>
                                                <?php
                                                $classes = $cell['classes'] ?? [];
                                                $subjects = $cell['subjects'] ?? [];
                                                $label = !empty($classes) ? implode(', ', $classes) : 'Занят';
                                                $title_parts = array_filter([
                                                    implode(', ', $subjects),
                                                    implode(', ', $classes),
                                                ]);
                                                $title = 'Занят: ' . (!empty($title_parts) ? implode(' · ', $title_parts) : 'урок');
                                                ?>
                                                <td class="rooms-cell is-busy"
                                                    data-period="<?php echo esc_attr($period_id); ?>"
                                                    title="<?php echo esc_attr($title); ?>">
                                                    <span class="rooms-cell-text"><?php echo esc_html($label); ?></span>
                                                </td>
                                            <?php else: ?>
                                                <td class="rooms-cell is-free"
                                                    data-period="<?php echo esc_attr($period_id); ?>"
                                                    title="Свободен">
                                                    <span class="rooms-cell-mark" aria-label="Свободен">✓</span>
                                                </td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <td class="rooms-grid-total">
                                            <?php echo esc_html((string) ($free_rooms['room_free_counts'][$day_id][$room_id] ?? 0)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <p class="rooms-note">
            «Свободно за день» — количество свободных уроков у кабинета среди показанных.
            Текущий урок подсвечивается при просмотре сегодняшнего дня.
        </p>
    <?php endif; ?>
</div>
