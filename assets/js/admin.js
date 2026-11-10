(function () {
    'use strict';

    function initCardToggles() {
        var buttons = document.querySelectorAll('.toggle-card');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var header = button.closest('.card-header');
                if (!header) {
                    return;
                }

                var content = header.nextElementSibling;
                if (!content || !content.classList.contains('card-content')) {
                    return;
                }

                var isExpanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');

                if (isExpanded) {
                    content.style.height = content.scrollHeight + 'px';
                    content.style.transition = 'height 0.3s ease';
                    requestAnimationFrame(function () {
                        content.style.height = '0px';
                    });
                    window.setTimeout(function () {
                        content.style.display = 'none';
                        content.style.height = '';
                        content.style.transition = '';
                    }, 300);
                } else {
                    content.style.display = 'block';
                    content.style.height = '0px';
                    content.style.transition = 'height 0.3s ease';
                    requestAnimationFrame(function () {
                        content.style.height = content.scrollHeight + 'px';
                    });
                    window.setTimeout(function () {
                        content.style.height = '';
                        content.style.transition = '';
                    }, 300);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCardToggles);
    } else {
        initCardToggles();
    }
})();

(function () {
    'use strict';

    function initGroupSelection() {
        var buttons = document.querySelectorAll('[data-groups-action]');

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var form = button.closest('form');
                var action = button.getAttribute('data-groups-action');
                var checkboxes = form
                    ? form.querySelectorAll('input[name="visible_class_groups[]"]')
                    : [];

                Array.prototype.forEach.call(checkboxes, function (checkbox) {
                    checkbox.checked = action === 'all';
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGroupSelection);
    } else {
        initGroupSelection();
    }
})();

(function ($) {
    if (!$) {
        return;
    }

    $(function () {
        // Обработчик формы тестовых уведомлений
        $('#test-notification-form').on('submit', function (e) {
            e.preventDefault();

            var $form = $(this);
            var $result = $('#test-notification-result');
            var $submitButton = $form.find('button[type="submit"]');
            var ajaxUrl = (window.schoolSchedule && window.schoolSchedule.ajaxurl) || window.ajaxurl;

            // Получаем данные формы
            var type = $('#notification-type').val();
            var chatId = $('#chat-id').val();

            // Блокируем кнопку и показываем индикатор загрузки
            $submitButton.prop('disabled', true).text('Отправка...');
            $result.html('<div class="notice notice-info"><p>Отправка уведомления...</p></div>');

            // Отправляем AJAX запрос
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'test_notification',
                    _ajax_nonce: $form.find('input[name="_wpnonce"]').val(),
                    type: type,
                    chat_id: chatId
                },
                success: function (response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' +
                            response.data.split('\n').join('<br>') + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>Ошибка: ' +
                            (response.data || 'Неизвестная ошибка') + '</p></div>');
                    }
                },
                error: function (xhr, status, error) {
                    $result.html('<div class="notice notice-error"><p>Ошибка сервера: ' + error + '</p></div>');
                },
                complete: function () {
                    // Разблокируем кнопку и возвращаем исходный текст
                    $submitButton.prop('disabled', false).text('Отправить тестовое уведомление');
                }
            });
        });
    });
})(window.jQuery);
