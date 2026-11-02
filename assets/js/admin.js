jQuery(document).ready(function($) {
    // Обработчик формы тестовых уведомлений
    $('#test-notification-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $result = $('#test-notification-result');
        var $submitButton = $form.find('button[type="submit"]');
        
        // Получаем данные формы
        var type = $('#notification-type').val();
        var chatId = $('#chat-id').val();
        
        // Блокируем кнопку и показываем индикатор загрузки
        $submitButton.prop('disabled', true).text('Отправка...');
        $result.html('<div class="notice notice-info"><p>Отправка уведомления...</p></div>');
        
        // Отправляем AJAX запрос
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'test_notification',
                _ajax_nonce: $form.find('input[name="_wpnonce"]').val(),
                type: type,
                chat_id: chatId
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + 
                        response.data.split('\n').join('<br>') + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>Ошибка: ' + 
                        (response.data || 'Неизвестная ошибка') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                $result.html('<div class="notice notice-error"><p>Ошибка сервера: ' + error + '</p></div>');
            },
            complete: function() {
                // Разблокируем кнопку и возвращаем исходный текст
                $submitButton.prop('disabled', false).text('Отправить тестовое уведомление');
            }
        });
    });

    // Обработчик для раскрывающихся блоков
    $('.toggle-card').click(function() {
        const $button = $(this);
        const $content = $button.closest('.card-header').next('.card-content');
        const isExpanded = $button.attr('aria-expanded') === 'true';
        
        $button.attr('aria-expanded', !isExpanded);
        
        if (isExpanded) {
            $content.slideUp(300);
        } else {
            $content.slideDown(300);
        }
    });
}); 