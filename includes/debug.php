<?php
function debugSchedule($schedule) {
    echo '<div style="background: #f5f5f5; padding: 20px; margin: 20px; border-radius: 5px;">';
    echo '<h3>Отладочная информация:</h3>';
    
    // Количество элементов
    echo '<h4>Количество элементов в секциях:</h4>';
    echo '<ul>';
    foreach ($schedule as $section => $data) {
        echo '<li>' . htmlspecialchars($section) . ': ' . count($data) . ' элементов</li>';
    }
    echo '</ul>';
    
    // Примеры данных из каждой секции
    foreach ($schedule as $section => $data) {
        if (!empty($data)) {
            echo '<h4>Пример данных из секции ' . htmlspecialchars($section) . ':</h4>';
            echo '<pre>';
            print_r(array_slice($data, 0, 1, true));
            echo '</pre>';
        }
    }
    
    echo '</div>';
}

function debugXmlStructure($xml_path) {
    if (file_exists($xml_path)) {
        echo '<div style="background: #f5f5f5; padding: 20px; margin: 20px; border-radius: 5px;">';
        echo '<h4>Структура XML файла:</h4>';
        $xml = simplexml_load_file($xml_path);
        if ($xml) {
            echo '<pre>';
            print_r($xml);
            echo '</pre>';
        } else {
            echo '<p>Ошибка при чтении XML файла</p>';
        }
        echo '</div>';
    }
}
?>