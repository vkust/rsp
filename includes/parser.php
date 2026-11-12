<?php
if (!defined('ABSPATH')) exit;

class ScheduleParser {
    private $xml;
    private $schedule;
    private $errors = [];
    private $warnings = [];
    private $debugInfo = [];
    private $output_buffer = false;

    public function __construct() {
        $this->schedule = [
            'periods' => [],
            'subjects' => [],
            'teachers' => [],
            'classes' => [],
            'classrooms' => [],
            'lessons' => [],
            'lessons_all' => [],
            'days' => []
        ];
    }

    public function parse($xml_path, $use_buffer = false) {
        $this->output_buffer = $use_buffer;
        $buffer_started = false;
        
        try {
            if ($this->output_buffer && !headers_sent()) {
                $buffer_started = ob_start();
            }
            
            if (!file_exists($xml_path)) {
                throw new Exception("Файл не найден: $xml_path");
            }

            libxml_use_internal_errors(true);
            $this->xml = simplexml_load_file($xml_path);

            if ($this->xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                throw new Exception("Ошибка парсинга XML: " . implode(", ", array_map(function($error) {
                    return $error->message;
                }, $errors)));
            }

            // Парсим основные секции
            $this->parsePeriods();
            $this->parseDays();
            $this->parseSubjects();
            $this->parseTeachers();
            $this->parseClassrooms();
            $this->parseClasses();
            $this->parseLessons();

            $result = [
                'schedule' => $this->schedule,
                'warnings' => $this->warnings,
                'errors' => $this->errors
            ];

            if ($buffer_started) {
                ob_end_clean();
            }

            return $result;

        } catch (Exception $e) {
            if ($buffer_started) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private function parsePeriods() {
        if (!isset($this->xml->periods)) {
            $this->warnings[] = "Секция periods не найдена";
            return;
        }

        foreach ($this->xml->periods->period as $period) {
            $id = (string)$period['period'];
            $this->schedule['periods'][$id] = [
                'id' => $id,
                'name' => (string)$period['name'],
                'short' => (string)$period['short'],
                'starttime' => (string)$period['starttime'],
                'endtime' => (string)$period['endtime'],
                'order' => (int)$period['period']
            ];
        }
    }

    private function parseDays() {
        // Создаем фиксированный массив дней недели
        $this->schedule['days'] = [
            '1' => [
                'id' => '1',
                'name' => 'Понедельник',
                'short' => 'Пн',
                'order' => 1,
                'mask' => '10000'
            ],
            '2' => [
                'id' => '2',
                'name' => 'Вторник',
                'short' => 'Вт',
                'order' => 2,
                'mask' => '01000'
            ],
            '3' => [
                'id' => '3',
                'name' => 'Среда',
                'short' => 'Ср',
                'order' => 3,
                'mask' => '00100'
            ],
            '4' => [
                'id' => '4',
                'name' => 'Четверг',
                'short' => 'Чт',
                'order' => 4,
                'mask' => '00010'
            ],
            '5' => [
                'id' => '5',
                'name' => 'Пятница',
                'short' => 'Пт',
                'order' => 5,
                'mask' => '00001'
            ]
        ];
    }

    private function getDayIdByMask($daysMask) {
        foreach ($this->schedule['days'] as $dayId => $day) {
            if ($day['mask'] === $daysMask) {
                return $dayId;
            }
        }
        return null;
    }

    private function parseSubjects() {
        if (!isset($this->xml->subjects)) {
            $this->warnings[] = "Секция subjects не найдена";
            return;
        }

        // Получаем настройки категорий
        $categories = get_option('schedule_categories', []);
        $subject_categories = get_option('schedule_subject_categories', []);

        foreach ($this->xml->subjects->subject as $subject) {
            $id = (string)$subject['id'];
            
            // Получаем категорию предмета
            $category = $subject_categories[$id] ?? 'main';
            
            // Убираем проверку видимости категории для админки
            $this->schedule['subjects'][$id] = [
                'id' => $id,
                'name' => (string)$subject['name'],
                'short' => (string)$subject['short'],
                'category' => $category
            ];
        }
    }

    private function parseTeachers() {
        if (!isset($this->xml->teachers)) {
            $this->warnings[] = "Секция teachers не найдена";
            return;
        }

        foreach ($this->xml->teachers->teacher as $teacher) {
            $id = (string)$teacher['id'];
            $this->schedule['teachers'][$id] = [
                'name' => (string)$teacher['name'],
                'short' => (string)$teacher['short']
            ];
        }
    }

    private function parseClassrooms() {
        if (!isset($this->xml->classrooms)) {
            $this->warnings[] = "Секция classrooms не найдена";
            return;
        }

        foreach ($this->xml->classrooms->classroom as $classroom) {
            $id = (string)$classroom['id'];
            $this->schedule['classrooms'][$id] = [
                'name' => (string)$classroom['name'],
                'short' => (string)$classroom['short']
            ];
        }
    }

    private function parseClasses() {
        if (!isset($this->xml->classes)) {
            $this->warnings[] = "Секция classes не найдена";
            return;
        }

        // Парсим классы
        foreach ($this->xml->classes->class as $class) {
            $id = (string)$class['id'];
            $this->schedule['classes'][$id] = [
                'name' => (string)$class['name'],
                'short' => (string)$class['short'],
                'groups' => [] // Добавляем массив для групп
            ];
        }

        // Парсим группы и связываем их с классами
        if (isset($this->xml->groups)) {
            foreach ($this->xml->groups->group as $group) {
                $groupId = (string)$group['id'];
                $classId = (string)$group['classid'];
                
                if (isset($this->schedule['classes'][$classId])) {
                    $this->schedule['classes'][$classId]['groups'][$groupId] = [
                        'name' => (string)$group['name'],
                        'id' => $groupId,
                        'entireclass' => (string)$group['entireclass'] === '1',
                        'divisiontag' => (string)$group['divisiontag'],
                    ];
                }
            }
        }
    }

    private function parseLessons() {
        if (!isset($this->xml->lessons) || !isset($this->xml->cards)) {
            $this->warnings[] = "Секция lessons или cards не найдена";
            return;
        }

        // Получаем настройки категорий
        $categories = get_option('schedule_categories', []);
        $subject_categories = get_option('schedule_subject_categories', []);

        // Сначала собираем ВСЕ уроки без фильтра категорий.
        // Это нужно для честного расчёта занятости кабинетов.
        $lessons = [];
        foreach ($this->xml->lessons->lesson as $lesson) {
            $id = (string)$lesson['id'];
            $subject_id = (string)$lesson['subjectid'];

            $teacherIds = (string)$lesson['teacherids'];
            $teacherIdsArray = $teacherIds ? explode(',', $teacherIds) : [];

            $classIds = (string)$lesson['classids'];
            $classIdsArray = $classIds ? explode(',', $classIds) : [];

            $groupIds = (string)$lesson['groupids'];
            $groupIdsArray = $groupIds ? explode(',', $groupIds) : [];

            $lessons[$id] = [
                'subjectId' => $subject_id,
                'teacherIds' => $teacherIdsArray,
                'classIds' => $classIdsArray,
                'groupIds' => $groupIdsArray
            ];
        }

        // Обрабатываем карточки
        foreach ($this->xml->cards->card as $card) {
            $lessonId = (string)$card['lessonid'];
            if (!isset($lessons[$lessonId])) {
                $this->warnings[] = "Урок с ID $lessonId не найден";
                continue;
            }

            $daysMask = (string)$card['days'];
            $dayId = $this->getDayIdByMask($daysMask);

            if (!$dayId) {
                $this->warnings[] = "День не найден для маски $daysMask";
                continue;
            }

            $lessonData = array_merge($lessons[$lessonId], [
                'id' => $lessonId,
                'periodId' => (string)$card['period'],
                'dayId' => $dayId,
                'classroomId' => (string)$card['classroomids'],
                'weeks' => (string)$card['weeks'],
                'terms' => (string)$card['terms']
            ]);

            // Полный список — для свободных кабинетов и других служебных задач.
            $this->schedule['lessons_all'][] = $lessonData;

            // Отображаемый список — с учётом видимости категорий.
            $subject_id = $lessons[$lessonId]['subjectId'];
            $category = $subject_categories[$subject_id] ?? 'main';
            if ($categories && (!isset($categories[$category]) || empty($categories[$category]['visible']))) {
                continue;
            }

            $this->schedule['lessons'][] = $lessonData;
        }
    }

    // Добавим метод для отладки конкретного урока
    public function debugLesson($lessonId) {
        $lesson = null;
        foreach ($this->schedule['lessons'] as $l) {
            if ($l['id'] === $lessonId) {
                $lesson = $l;
                break;
            }
        }

        if (!$lesson) {
            return "Урок не найден";
        }

        return [
            'lesson' => $lesson,
            'subject' => $this->schedule['subjects'][$lesson['subjectId']] ?? 'Предмет не найден',
            'teachers' => array_map(function($tid) {
                return $this->schedule['teachers'][$tid] ?? 'Учитель не найден';
            }, $lesson['teacherIds']),
            'classes' => array_map(function($cid) {
                return $this->schedule['classes'][$cid] ?? 'Класс не найден';
            }, $lesson['classIds']),
            'classroom' => $this->schedule['classrooms'][$lesson['classroomId']] ?? 'Кабинет не найден',
            'period' => $this->schedule['periods'][$lesson['periodId']] ?? 'Период не найден',
            'day' => $this->schedule['days'][$lesson['dayId']] ?? 'День не найден'
        ];
    }

    // Вспомогательный метод для отладки
    public function getDebugInfo() {
        return $this->debugInfo;
    }
}