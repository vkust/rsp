<?php
/**
 * Расчёт свободных учителей и кабинетов для закрытой страницы.
 * Данные берутся из расписания (как и для модальных окон свободных кабинетов).
 */

if (!defined('APP_ROOT')) {
    exit;
}

/**
 * Собрать справочники и занятость по всем дням и урокам.
 *
 * @param array $schedule         Массив расписания от ScheduleParser.
 * @param array $excluded_rooms   ID кабинетов, исключённых из списка свободных.
 * @return array
 */
function app_free_staff_build(array $schedule, array $excluded_rooms = [])
{
    $excluded = [];
    foreach ($excluded_rooms as $room_id) {
        $excluded[(string) $room_id] = true;
    }

    $days = [];
    foreach ((array) ($schedule['days'] ?? []) as $day_id => $day) {
        $days[(string) $day_id] = [
            'name' => (string) ($day['name'] ?? ''),
            'short' => (string) ($day['short'] ?? ''),
            'order' => (int) ($day['order'] ?? 0),
        ];
    }

    $periods = [];
    foreach ((array) ($schedule['periods'] ?? []) as $period_id => $period) {
        $periods[(string) $period_id] = [
            'name' => (string) ($period['name'] ?? ''),
            'start' => (string) ($period['starttime'] ?? ''),
            'end' => (string) ($period['endtime'] ?? ''),
            'order' => (int) ($period['order'] ?? 0),
        ];
    }

    $teachers = [];
    foreach ((array) ($schedule['teachers'] ?? []) as $teacher_id => $teacher) {
        $name = trim((string) ($teacher['name'] ?? ''));
        $short = trim((string) ($teacher['short'] ?? ''));
        $teachers[(string) $teacher_id] = [
            'name' => $name !== '' ? $name : (string) $teacher_id,
            'short' => $short,
        ];
    }

    $rooms = [];
    foreach ((array) ($schedule['classrooms'] ?? []) as $room_id => $room) {
        $room_id = (string) $room_id;
        if (isset($excluded[$room_id])) {
            continue;
        }
        $name = trim((string) ($room['name'] ?? ''));
        $short = trim((string) ($room['short'] ?? ''));
        if ($short === '' || in_array($short, ['*', '-', '—', '?'], true)) {
            $short = $name;
        }
        $rooms[$room_id] = [
            'name' => $name !== '' ? $name : $room_id,
            'short' => $short !== '' ? $short : $name,
        ];
    }

    $occupied_teachers = [];
    $occupied_rooms = [];
    $used_days = [];

    $lessons = $schedule['lessons_all'] ?? $schedule['lessons'] ?? [];
    foreach ((array) $lessons as $lesson) {
        $day_id = (string) ($lesson['dayId'] ?? '');
        $period_id = (string) ($lesson['periodId'] ?? '');
        if ($day_id === '' || $period_id === '') {
            continue;
        }

        $used_days[$day_id] = true;

        foreach ((array) ($lesson['teacherIds'] ?? []) as $teacher_id) {
            $teacher_id = (string) $teacher_id;
            if (!isset($teachers[$teacher_id])) {
                $teachers[$teacher_id] = [
                    'name' => $teacher_id,
                    'short' => '',
                ];
            }
            $occupied_teachers[$day_id][$period_id][$teacher_id] = true;
        }

        $room_ids = array_filter(array_map('trim', explode(',', (string) ($lesson['classroomId'] ?? ''))));
        foreach ($room_ids as $room_id) {
            $room_id = (string) $room_id;
            if (isset($excluded[$room_id])) {
                continue;
            }
            if (!isset($rooms[$room_id])) {
                $rooms[$room_id] = ['name' => $room_id, 'short' => $room_id];
            }
            $occupied_rooms[$day_id][$period_id][$room_id] = true;
        }
    }

    // Дни без уроков не показываем.
    $days = array_filter($days, function ($day_id) use ($used_days) {
        return isset($used_days[$day_id]);
    }, ARRAY_FILTER_USE_KEY);
    uasort($days, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });

    uasort($periods, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });

    $by_name = function ($a, $b) {
        return strcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8'));
    };
    uasort($teachers, $by_name);
    uasort($rooms, $by_name);

    // Периоды, в которые есть уроки, по каждому дню.
    $periods_by_day = [];
    foreach (array_keys($used_days) as $day_id) {
        foreach (array_keys($periods) as $period_id) {
            if (!empty($occupied_teachers[$day_id][$period_id]) || !empty($occupied_rooms[$day_id][$period_id])) {
                $periods_by_day[$day_id][] = (string) $period_id;
            }
        }
    }

    return [
        'days' => $days,
        'periods' => $periods,
        'periodsByDay' => $periods_by_day,
        'teachers' => $teachers,
        'rooms' => $rooms,
        'occupiedTeachers' => $occupied_teachers,
        'occupiedRooms' => $occupied_rooms,
    ];
}

/**
 * Свободные учителя и кабинеты на выбранный день и урок.
 *
 * @return array{teachers:array,rooms:array,totalTeachers:int,totalRooms:int}
 */
function app_free_staff_for(array $data, $day_id, $period_id)
{
    $day_id = (string) $day_id;
    $period_id = (string) $period_id;

    $busy_teachers = $data['occupiedTeachers'][$day_id][$period_id] ?? [];
    $busy_rooms = $data['occupiedRooms'][$day_id][$period_id] ?? [];

    $free_teachers = [];
    foreach ($data['teachers'] as $id => $teacher) {
        if (!isset($busy_teachers[$id])) {
            $free_teachers[$id] = $teacher;
        }
    }

    $free_rooms = [];
    foreach ($data['rooms'] as $id => $room) {
        if (!isset($busy_rooms[$id])) {
            $free_rooms[$id] = $room;
        }
    }

    return [
        'teachers' => $free_teachers,
        'rooms' => $free_rooms,
        'totalTeachers' => count($data['teachers']),
        'totalRooms' => count($data['rooms']),
    ];
}

/**
 * День по умолчанию: сегодняшний, если в нём есть уроки, иначе первый.
 */
function app_free_staff_default_day(array $data)
{
    $today = (int) date('N');
    if (isset($data['days'][(string) $today])) {
        return (string) $today;
    }
    $first = array_key_first($data['days']);
    return $first !== null ? (string) $first : '';
}

/**
 * Урок по умолчанию: текущий по времени (для сегодняшнего дня),
 * иначе первый урок дня.
 */
function app_free_staff_default_period(array $data, $day_id)
{
    $day_id = (string) $day_id;
    $period_ids = $data['periodsByDay'][$day_id] ?? [];
    if (!$period_ids) {
        return '';
    }

    if ((string) date('N') === $day_id) {
        $now = (int) date('G') * 60 + (int) date('i');
        foreach ($period_ids as $period_id) {
            $period = $data['periods'][$period_id] ?? null;
            if (!$period) {
                continue;
            }
            $start = app_free_staff_time_to_minutes($period['start']);
            $end = app_free_staff_time_to_minutes($period['end']);
            if ($start !== null && $end !== null && $now >= $start && $now <= $end) {
                return (string) $period_id;
            }
        }
    }

    return (string) $period_ids[0];
}

function app_free_staff_time_to_minutes($time)
{
    $time = trim((string) $time);
    if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $m)) {
        return null;
    }
    return (int) $m[1] * 60 + (int) $m[2];
}

/**
 * Подпись урока: «1 урок», но без дублирования, если в названии уже есть слово «урок».
 */
function app_free_staff_period_label(array $period)
{
    $name = trim((string) ($period['name'] ?? ''));
    if ($name === '') {
        return 'Урок';
    }
    if (mb_stripos($name, 'урок', 0, 'UTF-8') === false) {
        $name .= ' урок';
    }
    return $name;
}
