document.addEventListener('DOMContentLoaded', function() {
    // Инициализация переменных
    const toggleBtn = document.getElementById('toggleView');
    const exportBtn = document.getElementById('exportExcel');
    const isPanel = window.scheduleConfig?.isPanel || false;
    const filters = {
        teacher: document.getElementById('teacherFilter'),
        subject: document.getElementById('subjectFilter'),
        class: document.getElementById('classFilter'),
        day: document.getElementById('dayFilter'),
        classroom: document.getElementById('classroomFilter'),
        category: document.getElementById('categoryFilter')?.value || ''
    };

    let isVertical = localStorage.getItem('scheduleView') !== 'horizontal';
    let keepDayFilter = false;

    // Данные для модального окна «Свободные кабинеты».
    let freeRoomsData = null;
    try {
        const freeRoomsDataEl = document.getElementById('freeRoomsData');
        if (freeRoomsDataEl) {
            freeRoomsData = JSON.parse(freeRoomsDataEl.textContent);
        }
    } catch (e) {
        console.error('Не удалось прочитать данные свободных кабинетов:', e);
        freeRoomsData = null;
    }
    let freeRoomsManual = false;
    let freeRoomsAutoHint = '';
    let freeRoomsModalInitialized = false;
    let freeRoomsGridInitialized = false;
    let freeRoomsGridDay = '';
    let roomDetailTrigger = null;

    // Добавляем функцию updateScrollVisibility в глобальную область видимости
    function updateScrollVisibility() {
        const wrapper = document.querySelector('.schedule-table-wrapper');
        const bodyScroll = document.querySelector('.schedule-table-body');
        const table = bodyScroll?.querySelector('.schedule-table');
        
        if (table && wrapper) {
            const needsScroll = table.offsetWidth > wrapper.offsetWidth;
            
            wrapper.classList.toggle('has-scroll', needsScroll);
            const headerScroll = document.querySelector('.schedule-table-header-scroll');
            if (headerScroll && needsScroll) {
                headerScroll.firstElementChild.style.width = `${table.offsetWidth}px`;
            } else if (headerScroll) {
                headerScroll.firstElementChild.style.width = '0';
            }
        }
    }

    // Функция инициализации фильтров
    function initializeFilters() {
        try {
            // Проверяем, является ли устройство мобильным
            const isMobile = window.matchMedia('(max-width: 768px)').matches;

            if (!isMobile && typeof jQuery !== 'undefined') {
                // На десктопах используем jQuery UI selectmenu
                jQuery('.filter-select').each(function() {
                    try {
                        if (jQuery(this).selectmenu('instance')) {
                            jQuery(this).selectmenu('destroy');
                        }
                    } catch (e) {
                        // Игнорируем ошибку, если selectmenu еще не был инициализирован
                    }
                });

                jQuery('.filter-select').selectmenu({
                    change: function() {
                        filterAll();
                    }
                });
            } else {
                // На мобильных используем нативные селекты
                document.querySelectorAll('.filter-select').forEach(select => {
                    select.classList.add('native-select');
                    if (select.dataset.filterBound !== '1') {
                        select.addEventListener('change', filterAll);
                        select.dataset.filterBound = '1';
                    }
                });
            }
        } catch (e) {
            console.error('Ошибка инициализации фильтров:', e);
        }
    }

    // Функция инициализации всех компонентов
    function initializeAll() {
        // Инициализация бегущей строки
        const marquee = document.querySelector('.schedule-marquee');
        if (marquee && marquee.classList.contains('scroll')) {
            const content = marquee.querySelector('.marquee-content');
            if (content) {
                // Получаем скорость из data-атрибута
                const speed = parseInt(marquee.dataset.speed) || 20;
                
                // Создаем один клон для бесконечной анимации
                const clone = content.cloneNode(true);
                marquee.appendChild(clone);

                // Добавляем анимацию к элементам
                const elements = marquee.querySelectorAll('.marquee-content');
                elements.forEach((el, index) => {
                    el.style.animation = `marquee ${speed}s linear infinite`;
                    // Добавляем небольшую задержку для второго элемента
                    if (index === 1) {
                        el.style.animationDelay = `-${speed/2}s`;
                    }
                });
            }
        }
        
        // Инициализация фильтров
        initializeFilters();

        // Инициализация кнопки переключения вида
        if (toggleBtn) {
            updateButtonText();
            if (isVertical) {
                renderSchedule(true);
            }

            toggleBtn.addEventListener('click', function() {
                isVertical = !isVertical;
                renderSchedule(isVertical);
                setTimeout(filterAll, 350);
            });
        }

        // Инициализация фильтров
        Object.values(filters).forEach(filter => {
            if (filter) {
                filter.addEventListener('change', filterAll);
            }
        });

        // Инициализация кнопки экспорта
        if (exportBtn && !isPanel) {
            exportBtn.addEventListener('click', exportToExcel);
        } else if (exportBtn) {
            exportBtn.style.display = 'none';
        }

        // Инициализация кнопки сброса фильтров
        const resetBtn = document.getElementById('resetFilters');
        if (resetBtn) {
            resetBtn.addEventListener('click', resetFilters);
        }

        // Быстрый фильтр по сегодняшнему дню
        const todayBtn = document.getElementById('todayFilter');
        if (todayBtn) {
            todayBtn.addEventListener('click', applyTodayFilter);
        }

        // Свободные кабинеты
        const freeRoomsBtn = document.getElementById('freeRoomsBtn');
        if (freeRoomsBtn && freeRoomsData) {
            freeRoomsBtn.addEventListener('click', openFreeRoomsModal);
        }

        const freeRoomsGridBtn = document.getElementById('freeRoomsGridBtn');
        if (freeRoomsGridBtn && freeRoomsData) {
            freeRoomsGridBtn.addEventListener('click', openFreeRoomsGridModal);
        }

        // Запуск начальной инициализации
        loadFilters();
        updateCurrentTime();
        
        // Создаем постоянный интервал обновления
        const timeInterval = setInterval(updateCurrentTime, 1000);

        // Добавляем обработчик для очистки интервала при уходе со страницы
        window.addEventListener('beforeunload', function() {
            clearInterval(timeInterval);
        });

        // Инициализация скроллбаров
        initializeScrollbars();

        // Обработка изменения размера окна
        window.addEventListener('resize', debounce(() => {
            initializeFilters();
            hideEmptyDays(); // Обновляем видимость дней при изменении размера окна
        }, 250));

        // Добавляем вызов hideEmptyDays после загрузки
        hideEmptyDays();
        initStickyScheduleHeader();
    }

    /**
     * Закреплённая шапка таблицы при прокрутке страницы.
     * Копия thead показывается fixed-слоем, когда оригинал уходит за верх экрана.
     */
    function initStickyScheduleHeader() {
        const source = document.querySelector('.schedule-table');
        const body = document.querySelector('.schedule-table-body');
        if (!source || !body) {
            return;
        }

        const container = document.createElement('div');
        container.className = 'schedule-sticky-header';
        container.setAttribute('aria-hidden', 'true');

        const table = document.createElement('table');
        table.className = 'schedule-table schedule-sticky-header-table';
        container.appendChild(table);
        document.body.appendChild(container);

        stickyScheduleHeaderSource = source;
        stickyScheduleHeaderBody = body;
        stickyScheduleHeader = container;
        stickyScheduleHeaderTable = table;

        refreshStickyScheduleHeader();

        window.addEventListener('scroll', requestStickyScheduleHeaderUpdate, { passive: true });
        window.addEventListener('resize', debounce(function() {
            refreshStickyScheduleHeader();
            requestStickyScheduleHeaderUpdate();
        }, 150));
        window.addEventListener('load', function() {
            refreshStickyScheduleHeader();
            requestStickyScheduleHeaderUpdate();
        }, { once: true });
        body.addEventListener('scroll', requestStickyScheduleHeaderUpdate, { passive: true });

        updateStickyScheduleHeader();
    }

    let stickyScheduleHeader = null;
    let stickyScheduleHeaderTable = null;
    let stickyScheduleHeaderSource = null;
    let stickyScheduleHeaderBody = null;
    let stickyHeaderTicking = false;

    function requestStickyScheduleHeaderUpdate() {
        if (stickyHeaderTicking) {
            return;
        }
        stickyHeaderTicking = true;
        window.requestAnimationFrame(function() {
            stickyHeaderTicking = false;
            updateStickyScheduleHeader();
        });
    }

    function refreshStickyScheduleHeader() {
        if (!stickyScheduleHeaderSource || !stickyScheduleHeaderTable) {
            return;
        }

        const thead = stickyScheduleHeaderSource.querySelector('thead');
        if (!thead) {
            return;
        }

        stickyScheduleHeaderTable.className = stickyScheduleHeaderSource.className + ' schedule-sticky-header-table';
        stickyScheduleHeaderTable.innerHTML = '';
        stickyScheduleHeaderTable.appendChild(thead.cloneNode(true));
        syncStickyScheduleHeaderWidths();
    }

    function syncStickyScheduleHeaderWidths() {
        if (!stickyScheduleHeaderSource || !stickyScheduleHeaderTable) {
            return;
        }

        const sourceThs = stickyScheduleHeaderSource.querySelectorAll('thead th');
        const cloneThs = stickyScheduleHeaderTable.querySelectorAll('thead th');
        const tableWidth = stickyScheduleHeaderSource.offsetWidth;

        stickyScheduleHeaderTable.style.width = tableWidth + 'px';

        sourceThs.forEach(function(th, index) {
            const cloneTh = cloneThs[index];
            if (!cloneTh) {
                return;
            }
            const rect = th.getBoundingClientRect();
            cloneTh.style.width = rect.width + 'px';
            cloneTh.style.minWidth = rect.width + 'px';
            cloneTh.style.maxWidth = rect.width + 'px';
            cloneTh.className = th.className;
        });
    }

    function syncStickyScheduleHeaderClasses() {
        if (!stickyScheduleHeaderSource || !stickyScheduleHeaderTable) {
            return;
        }

        const sourceThs = stickyScheduleHeaderSource.querySelectorAll('thead th');
        const cloneThs = stickyScheduleHeaderTable.querySelectorAll('thead th');

        sourceThs.forEach(function(th, index) {
            if (cloneThs[index]) {
                cloneThs[index].className = th.className;
            }
        });
    }

    function updateStickyScheduleHeader() {
        if (!stickyScheduleHeader || !stickyScheduleHeaderSource || !stickyScheduleHeaderBody) {
            return;
        }

        const thead = stickyScheduleHeaderSource.querySelector('thead');
        if (!thead) {
            stickyScheduleHeader.style.display = 'none';
            return;
        }

        const bodyRect = stickyScheduleHeaderBody.getBoundingClientRect();
        const headRect = thead.getBoundingClientRect();
        const headerHeight = headRect.height;
        const shouldShow = headRect.top < 0 && bodyRect.bottom > headerHeight + 4;

        if (!shouldShow) {
            stickyScheduleHeader.style.display = 'none';
            return;
        }

        stickyScheduleHeader.style.display = 'block';
        stickyScheduleHeader.style.left = bodyRect.left + 'px';
        stickyScheduleHeader.style.width = bodyRect.width + 'px';

        const cloneHeight = stickyScheduleHeaderTable.getBoundingClientRect().height || headerHeight;
        stickyScheduleHeader.style.height = cloneHeight + 'px';
        stickyScheduleHeaderTable.style.transform = 'translateX(' + (-stickyScheduleHeaderBody.scrollLeft) + 'px)';

        syncStickyScheduleHeaderClasses();
    }

    // Вспомогательная функция debounce
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Функция инициализации скроллбаров
    function initializeScrollbars() {
        const headerScroll = document.querySelector('.schedule-table-header-scroll');
        const bodyScroll = document.querySelector('.schedule-table-body');

        if (headerScroll && bodyScroll) {
            // Синхронизация скроллбаров
            headerScroll.addEventListener('scroll', () => {
                bodyScroll.scrollLeft = headerScroll.scrollLeft;
            });

            bodyScroll.addEventListener('scroll', () => {
                headerScroll.scrollLeft = bodyScroll.scrollLeft;
            });

            window.addEventListener('resize', updateScrollVisibility);
            updateScrollVisibility();
        }
    }

    // Ждем полной загрузки jQuery UI перед инициализацией
    if (typeof jQuery !== 'undefined' && typeof jQuery.ui !== 'undefined') {
        initializeAll();
    } else {
        const checkJQuery = setInterval(function() {
            if (typeof jQuery !== 'undefined' && typeof jQuery.ui !== 'undefined') {
                clearInterval(checkJQuery);
                initializeAll();
            }
        }, 100);

        // Таймаут на случай, если jQuery UI не загрузится
        setTimeout(function() {
            clearInterval(checkJQuery);
            console.error('jQuery UI не был загружен вовремя');
        }, 5000);
    }

    // Функция обновления текста кнопки переключения вида
    function updateButtonText() {
        const viewBtn = toggleBtn.querySelector('.view-icon');
        if (!viewBtn) {
            toggleBtn.innerHTML = `
                <svg class="view-icon ${isVertical ? 'vertical' : ''}" viewBox="0 0 24 24">
                    <path class="icon" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            `;
        } else {
            viewBtn.classList.toggle('vertical', isVertical);
        }
    }

    // Функция фильтрации
    function filterAll() {
        const currentFilters = {
            teacher: document.getElementById('teacherFilter').value,
            subject: document.getElementById('subjectFilter').value,
            class: document.getElementById('classFilter').value,
            day: document.getElementById('dayFilter').value,
            classroom: document.getElementById('classroomFilter').value,
            category: document.getElementById('categoryFilter')?.value || ''
        };
        
        const lessonCells = document.querySelectorAll('.lesson-cell');
        
        lessonCells.forEach(cell => {
            const teachers = cell.getAttribute('data-teachers').split(',');
            const subjects = cell.getAttribute('data-subject');
            const classes = cell.getAttribute('data-classes').split(',');
            const day = cell.getAttribute('data-day');
            const classroom = cell.getAttribute('data-classroom');
            const category = cell.getAttribute('data-category');
            
            const matches = (
                (currentFilters.teacher === '' || teachers.includes(currentFilters.teacher)) &&
                (currentFilters.subject === '' || subjects.includes(currentFilters.subject)) &&
                (currentFilters.class === '' || classes.includes(currentFilters.class)) &&
                (currentFilters.day === '' || day === currentFilters.day) &&
                (currentFilters.classroom === '' || classroom === currentFilters.classroom) &&
                (currentFilters.category === '' || category === currentFilters.category)
            );

            cell.style.display = matches ? '' : 'none';
        });

        updateFilterOptions();
        saveFilters();
        hideEmptyDays(); // Вызываем после фильтрации
        refreshStickyScheduleHeader();
        updateStickyScheduleHeader();
    }

    // Быстрый фильтр «Сегодня»
    function applyTodayFilter() {
        const dayFilter = document.getElementById('dayFilter');
        if (!dayFilter) {
            return;
        }

        // JS: 0 — воскресенье, 1 — понедельник, ..., 6 — суббота.
        // В расписании дни 1–5 (пн–пт).
        const jsDay = new Date().getDay();
        if (jsDay < 1 || jsDay > 5) {
            alert('Сегодня выходной — занятий нет.');
            return;
        }

        const dayId = String(jsDay);
        const option = Array.from(dayFilter.options).find(o => o.value === dayId);
        if (!option) {
            alert('В расписании нет данных на сегодня.');
            return;
        }

        dayFilter.value = dayId;

        // Обновляем отображение jQuery UI selectmenu.
        if (typeof jQuery !== 'undefined' && jQuery(dayFilter).selectmenu('instance')) {
            jQuery(dayFilter).selectmenu('refresh');
        }

        // Не даём updateFilterOptions сбросить выбранный день,
        // даже если на сегодня нет уроков.
        keepDayFilter = true;
        filterAll();
        keepDayFilter = false;
    }

    /* ------------------------------------------------------------------ */
    /* Свободные кабинеты                                                  */
    /* ------------------------------------------------------------------ */

    function freeRoomsTimeToMinutes(value) {
        if (!value) {
            return null;
        }
        const parts = String(value).split(':').map(Number);
        if (parts.length < 2 || isNaN(parts[0]) || isNaN(parts[1])) {
            return null;
        }
        return parts[0] * 60 + parts[1];
    }

    function freeRoomsSortedEntries(data, field) {
        return Object.entries(data).sort((a, b) => {
            return (Number(a[1][field]) || 0) - (Number(b[1][field]) || 0);
        });
    }

    function freeRoomsDaysWithLessons() {
        return freeRoomsSortedEntries(freeRoomsData.days, 'order')
            .filter(([id]) => Object.keys(freeRoomsData.occupied[id] || {}).length > 0)
            .map(([id]) => id);
    }

    // Ближайший учебный день по кругу: после пятницы идёт понедельник.
    function freeRoomsNearestDayId(jsDay, candidates) {
        let bestId = candidates[0] || '';
        let bestDistance = Infinity;

        candidates.forEach(id => {
            const order = Number(freeRoomsData.days[id]?.order) || 0;
            if (!order) {
                return;
            }
            const distance = (order - jsDay + 7) % 7;
            if (distance < bestDistance) {
                bestDistance = distance;
                bestId = id;
            }
        });

        return bestId;
    }

    function freeRoomsDefaultDay() {
        const jsDay = new Date().getDay();
        const todayId = String(jsDay);
        const daysWithLessons = freeRoomsDaysWithLessons();

        // Сегодня будний день и в расписании есть уроки — показываем сегодня.
        if (
            jsDay >= 1 && jsDay <= 5 &&
            freeRoomsData.days[todayId] &&
            (!daysWithLessons.length || daysWithLessons.includes(todayId))
        ) {
            return todayId;
        }

        const candidates = daysWithLessons.length
            ? daysWithLessons
            : freeRoomsSortedEntries(freeRoomsData.days, 'order').map(([id]) => id);

        return freeRoomsNearestDayId(jsDay, candidates);
    }

    function freeRoomsPeriodInfo() {
        const periods = freeRoomsSortedEntries(freeRoomsData.periods, 'order');
        if (!periods.length) {
            return { id: '', mode: 'first' };
        }

        const now = new Date();
        const currentMinutes = now.getHours() * 60 + now.getMinutes();
        const timed = periods
            .map(([id, period]) => ({
                id,
                start: freeRoomsTimeToMinutes(period.start),
                end: freeRoomsTimeToMinutes(period.end)
            }))
            .filter(p => p.start !== null && p.end !== null);

        if (!timed.length) {
            return { id: periods[0][0], mode: 'first' };
        }

        for (let i = 0; i < timed.length; i++) {
            const period = timed[i];
            if (currentMinutes >= period.start && currentMinutes <= period.end) {
                return { id: period.id, mode: 'current' };
            }
            if (i < timed.length - 1) {
                const next = timed[i + 1];
                if (currentMinutes > period.end && currentMinutes < next.start) {
                    return { id: next.id, mode: 'next' };
                }
            }
        }

        if (currentMinutes < timed[0].start) {
            return { id: timed[0].id, mode: 'before' };
        }

        if (currentMinutes > timed[timed.length - 1].end) {
            return { id: timed[timed.length - 1].id, mode: 'after' };
        }

        return { id: timed[0].id, mode: 'first' };
    }

    function populateFreeRoomsSelectors() {
        const daySelect = document.getElementById('freeRoomsDay');
        const periodSelect = document.getElementById('freeRoomsPeriod');
        if (!daySelect || !periodSelect || daySelect.options.length) {
            return;
        }

        freeRoomsSortedEntries(freeRoomsData.days, 'order').forEach(([id, day]) => {
            const option = document.createElement('option');
            option.value = id;
            option.textContent = day.name;
            daySelect.appendChild(option);
        });

        freeRoomsSortedEntries(freeRoomsData.periods, 'order').forEach(([id, period]) => {
            const option = document.createElement('option');
            option.value = id;
            option.textContent = period.start && period.end
                ? `${period.name} урок (${period.start}–${period.end})`
                : `${period.name} урок`;
            periodSelect.appendChild(option);
        });
    }

    function renderFreeRooms() {
        const daySelect = document.getElementById('freeRoomsDay');
        const periodSelect = document.getElementById('freeRoomsPeriod');
        const summary = document.getElementById('freeRoomsSummary');
        const list = document.getElementById('freeRoomsList');
        const hint = document.getElementById('freeRoomsHint');
        if (!daySelect || !periodSelect || !summary || !list || !hint) {
            return;
        }

        const dayId = daySelect.value;
        const periodId = periodSelect.value;
        const day = freeRoomsData.days[dayId] || { name: '—' };
        const period = freeRoomsData.periods[periodId] || { name: '—' };
        const occupied = (freeRoomsData.occupied[dayId] || {})[periodId] || {};

        const rooms = Object.entries(freeRoomsData.rooms)
            .sort((a, b) => String(a[1]).localeCompare(String(b[1]), 'ru'));
        const free = rooms.filter(([id]) => !occupied[id]);

        summary.textContent = `Свободно ${free.length} ${declOfNum(free.length, ['кабинет', 'кабинета', 'кабинетов'])} из ${rooms.length} · ${day.name}, ${period.name} урок`;

        list.innerHTML = '';
        if (!free.length) {
            const empty = document.createElement('div');
            empty.className = 'free-rooms-empty';
            empty.textContent = 'Все кабинеты заняты';
            list.appendChild(empty);
        } else {
            free.forEach(([id, name]) => {
                const badge = document.createElement('span');
                badge.className = 'free-room-badge';
                badge.textContent = name;
                badge.title = 'Кабинет ' + name;
                list.appendChild(badge);
            });
        }

        hint.textContent = freeRoomsManual
            ? 'День и урок выбраны вручную.'
            : freeRoomsAutoHint;
    }

    function initFreeRoomsModal() {
        const modal = document.getElementById('freeRoomsModal');
        if (!modal || !freeRoomsData) {
            return;
        }

        populateFreeRoomsSelectors();

        if (freeRoomsModalInitialized) {
            return;
        }
        freeRoomsModalInitialized = true;

        const daySelect = document.getElementById('freeRoomsDay');
        const periodSelect = document.getElementById('freeRoomsPeriod');
        const close = () => closeFreeRoomsModal();

        modal.querySelectorAll('[data-free-rooms-close]').forEach(el => {
            el.addEventListener('click', close);
        });

        if (daySelect) {
            daySelect.addEventListener('change', () => {
                freeRoomsManual = true;
                renderFreeRooms();
            });
        }
        if (periodSelect) {
            periodSelect.addEventListener('change', () => {
                freeRoomsManual = true;
                renderFreeRooms();
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeFreeRoomsModal();
            }
        });
    }

    function openFreeRoomsModal() {
        if (!freeRoomsData) {
            return;
        }

        const modal = document.getElementById('freeRoomsModal');
        const daySelect = document.getElementById('freeRoomsDay');
        const periodSelect = document.getElementById('freeRoomsPeriod');
        if (!modal || !daySelect || !periodSelect) {
            return;
        }

        initFreeRoomsModal();
        populateFreeRoomsSelectors();

        const info = freeRoomsPeriodInfo();
        const defaultDay = freeRoomsDefaultDay();
        const jsDay = new Date().getDay();
        const todayId = String(jsDay);
        const isToday = defaultDay === todayId;

        freeRoomsManual = false;
        if (!isToday) {
            freeRoomsAutoHint = (jsDay < 1 || jsDay > 5)
                ? 'Сегодня выходной — показан ближайший учебный день.'
                : 'На сегодня уроков нет — показан ближайший учебный день.';
        } else if (info.mode === 'next') {
            freeRoomsAutoHint = 'Сейчас перемена — показан следующий урок.';
        } else if (info.mode === 'before') {
            freeRoomsAutoHint = 'Уроки ещё не начались — показан первый урок.';
        } else if (info.mode === 'after') {
            freeRoomsAutoHint = 'Уроки на сегодня закончились — показан последний урок.';
        } else {
            freeRoomsAutoHint = 'Показан текущий урок.';
        }

        daySelect.value = defaultDay;
        periodSelect.value = info.id;
        renderFreeRooms();

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('free-rooms-open');

        const closeBtn = modal.querySelector('.free-rooms-close');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeFreeRoomsModal() {
        const modal = document.getElementById('freeRoomsModal');
        if (!modal) {
            return;
        }
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('free-rooms-open');
    }

    /* ------------------------------------------------------------------ */
    /* Сетка свободных кабинетов (модальное окно)                          */
    /* ------------------------------------------------------------------ */

    function freeRoomsGridUsedPeriods() {
        const used = new Set();
        Object.keys(freeRoomsData.occupied || {}).forEach(dayId => {
            Object.keys(freeRoomsData.occupied[dayId] || {}).forEach(periodId => {
                used.add(periodId);
            });
        });

        const all = freeRoomsSortedEntries(freeRoomsData.periods, 'order');
        const filtered = all.filter(([id]) => used.size === 0 || used.has(id));
        return filtered.length ? filtered : all;
    }

    function freeRoomsGridSortedRooms() {
        return Object.keys(freeRoomsData.rooms).sort((a, b) => {
            return String(freeRoomsData.rooms[a]).localeCompare(
                String(freeRoomsData.rooms[b]),
                'ru',
                { numeric: true, sensitivity: 'base' }
            );
        });
    }

    function freeRoomsGridRoomData(roomId) {
        const name = String(freeRoomsData.rooms[roomId] ?? roomId);
        const short = String(
            (freeRoomsData.roomShorts && freeRoomsData.roomShorts[roomId]) || name
        );
        return { name, short };
    }

    function renderFreeRoomsGridTabs() {
        const wrap = document.getElementById('roomsGridDays');
        if (!wrap) {
            return;
        }

        wrap.textContent = '';

        freeRoomsSortedEntries(freeRoomsData.days, 'order').forEach(([dayId, day]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rooms-day-tab' + (dayId === freeRoomsGridDay ? ' is-active' : '');
            button.dataset.dayTab = dayId;
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', dayId === freeRoomsGridDay ? 'true' : 'false');
            button.tabIndex = dayId === freeRoomsGridDay ? 0 : -1;

            const full = document.createElement('span');
            full.className = 'rooms-day-full';
            full.textContent = day.name;

            const short = document.createElement('span');
            short.className = 'rooms-day-short';
            short.textContent = day.short || day.name;

            button.appendChild(full);
            button.appendChild(short);
            button.addEventListener('click', () => selectFreeRoomsGridDay(dayId));

            wrap.appendChild(button);
        });
    }

    function renderFreeRoomsGrid(dayId) {
        const table = document.getElementById('roomsGrid');
        const subtitle = document.getElementById('roomsGridSubtitle');
        if (!table) {
            return;
        }

        const day = freeRoomsData.days[dayId] || { name: '—' };
        const periods = freeRoomsGridUsedPeriods();
        const rooms = freeRoomsGridSortedRooms();
        const occupied = freeRoomsData.occupied[dayId] || {};
        const todayId = String(new Date().getDay());
        const currentPeriod = dayId === todayId ? freeRoomsPeriodInfo().id : '';

        table.textContent = '';
        table.setAttribute('aria-label', 'Занятость кабинетов: ' + day.name);

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');

        const corner = document.createElement('th');
        corner.className = 'rooms-grid-corner';
        corner.scope = 'col';
        corner.textContent = 'Кабинет';
        headRow.appendChild(corner);

        periods.forEach(([periodId, period]) => {
            const busy = Object.keys(occupied[periodId] || {}).length;
            const free = Math.max(0, rooms.length - busy);

            const th = document.createElement('th');
            th.className = 'rooms-grid-period-head' + (periodId === currentPeriod ? ' is-current' : '');
            th.dataset.period = periodId;
            th.dataset.start = period.start || '';
            th.dataset.end = period.end || '';
            th.scope = 'col';

            const name = document.createElement('span');
            name.className = 'rooms-grid-period';
            name.textContent = period.name + ' урок';
            th.appendChild(name);

            if (period.start && period.end) {
                const time = document.createElement('span');
                time.className = 'rooms-grid-time';
                time.textContent = period.start + '–' + period.end;
                th.appendChild(time);
            }

            const freeCount = document.createElement('span');
            freeCount.className = 'rooms-grid-free';
            freeCount.textContent = 'свободно ' + free;
            th.appendChild(freeCount);

            headRow.appendChild(th);
        });

        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');

        rooms.forEach(roomId => {
            const room = freeRoomsGridRoomData(roomId);
            const row = document.createElement('tr');

            const roomCell = document.createElement('th');
            roomCell.className = 'rooms-grid-room';
            roomCell.scope = 'row';

            const full = document.createElement('span');
            full.className = 'room-name-full';
            full.textContent = room.name;

            const short = document.createElement('span');
            short.className = 'room-name-short';
            short.textContent = room.short;

            roomCell.appendChild(full);
            roomCell.appendChild(short);
            row.appendChild(roomCell);

            periods.forEach(([periodId]) => {
                const lesson = occupied[periodId] ? occupied[periodId][roomId] : null;
                const cell = document.createElement('td');
                cell.dataset.period = periodId;
                cell.className = 'rooms-cell ' + (lesson ? 'is-busy' : 'is-free');
                if (periodId === currentPeriod) {
                    cell.classList.add('is-current');
                }

                if (lesson) {
                    const classes = Array.isArray(lesson.classes) ? lesson.classes : [];
                    const classesFull = Array.isArray(lesson.classesFull) ? lesson.classesFull : [];
                    const subjects = Array.isArray(lesson.subjects) ? lesson.subjects : [];
                    const label = classes.length ? classes.join(', ') : 'Занят';
                    const titleParts = [];
                    if (subjects.length) {
                        titleParts.push(subjects.join(', '));
                    }
                    if (classes.length) {
                        titleParts.push(classes.join(', '));
                    }
                    cell.title = 'Занят: ' + (titleParts.length ? titleParts.join(' · ') : label);
                    cell.classList.add('is-clickable');
                    cell.tabIndex = 0;
                    cell.setAttribute('role', 'button');
                    const ariaClasses = classesFull.length ? classesFull : classes;
                    cell.setAttribute('aria-label', (subjects.length ? subjects.join(', ') + '. ' : '') + (ariaClasses.length ? ariaClasses.join(', ') : 'Занят') + '. Подробности');

                    const text = document.createElement('span');
                    text.className = 'rooms-cell-text';
                    text.textContent = label;
                    cell.appendChild(text);

                    const showDetails = () => openRoomDetailModal(dayId, periodId, roomId, lesson);
                    cell.addEventListener('click', showDetails);
                    cell.addEventListener('keydown', event => {
                        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                            event.preventDefault();
                            showDetails();
                        }
                    });
                } else {
                    cell.title = 'Свободен';

                    const mark = document.createElement('span');
                    mark.className = 'rooms-cell-mark';
                    mark.setAttribute('aria-label', 'Свободен');
                    mark.textContent = '✓';
                    cell.appendChild(mark);
                }

                row.appendChild(cell);
            });

            tbody.appendChild(row);
        });

        table.appendChild(tbody);

        if (subtitle) {
            subtitle.textContent = dayId === todayId
                ? day.name + ' · текущий урок подсвечен'
                : day.name;
        }
    }

    function selectFreeRoomsGridDay(dayId) {
        freeRoomsGridDay = dayId;
        renderFreeRoomsGridTabs();
        renderFreeRoomsGrid(dayId);
    }

    function initFreeRoomsGridModal() {
        const modal = document.getElementById('freeRoomsGridModal');
        if (!modal || freeRoomsGridInitialized) {
            return;
        }
        freeRoomsGridInitialized = true;

        modal.querySelectorAll('[data-rooms-grid-close]').forEach(el => {
            el.addEventListener('click', closeFreeRoomsGridModal);
        });

        const detail = document.getElementById('roomLessonModal');
        if (detail) {
            detail.querySelectorAll('[data-room-detail-close]').forEach(el => {
                el.addEventListener('click', closeRoomDetailModal);
            });
        }

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') {
                return;
            }

            const detailModal = document.getElementById('roomLessonModal');
            if (detailModal && detailModal.classList.contains('is-open')) {
                closeRoomDetailModal();
                return;
            }

            if (modal.classList.contains('is-open')) {
                closeFreeRoomsGridModal();
            }
        });
    }

    function openFreeRoomsGridModal() {
        if (!freeRoomsData) {
            return;
        }

        const modal = document.getElementById('freeRoomsGridModal');
        if (!modal) {
            return;
        }

        initFreeRoomsGridModal();

        const daySelect = document.getElementById('freeRoomsDay');
        const selectedDay = daySelect ? daySelect.value : '';
        let dayId = selectedDay && freeRoomsData.days[selectedDay]
            ? selectedDay
            : freeRoomsDefaultDay();

        if (!dayId || !freeRoomsData.days[dayId]) {
            dayId = freeRoomsSortedEntries(freeRoomsData.days, 'order')[0]?.[0] || '';
        }

        closeFreeRoomsModal();

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('free-rooms-open');

        selectFreeRoomsGridDay(dayId);

        const closeBtn = modal.querySelector('.free-rooms-close');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeFreeRoomsGridModal() {
        const modal = document.getElementById('freeRoomsGridModal');
        if (!modal) {
            return;
        }
        closeRoomDetailModal();
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('free-rooms-open');
    }

    function openRoomDetailModal(dayId, periodId, roomId, lesson) {
        const modal = document.getElementById('roomLessonModal');
        const title = document.getElementById('roomDetailTitle');
        const list = document.getElementById('roomDetailList');
        if (!modal || !title || !list) {
            return;
        }

        const day = freeRoomsData.days[dayId] || {};
        const period = freeRoomsData.periods[periodId] || {};
        const room = freeRoomsGridRoomData(roomId);
        roomDetailTrigger = document.activeElement;

        const classes = Array.isArray(lesson.classesFull) && lesson.classesFull.length
            ? lesson.classesFull
            : (Array.isArray(lesson.classes) ? lesson.classes : []);
        const subjects = Array.isArray(lesson.subjects) ? lesson.subjects : [];
        const teachers = Array.isArray(lesson.teachers) ? lesson.teachers : [];

        title.textContent = 'Кабинет ' + room.name;
        list.textContent = '';

        const time = period.start && period.end
            ? ' · ' + period.start + '–' + period.end
            : '';
        const rows = [
            ['День', day.name || '—'],
            ['Урок', (period.name ? period.name + ' урок' : '—') + time],
            ['Предмет', subjects.length ? subjects.join(', ') : '—'],
            ['Класс', classes.length ? classes.join(', ') : '—'],
            ['Учитель', teachers.length ? teachers.join(', ') : '—']
        ];

        rows.forEach(([label, value]) => {
            const dt = document.createElement('dt');
            dt.textContent = label;

            const dd = document.createElement('dd');
            dd.textContent = value;

            list.appendChild(dt);
            list.appendChild(dd);
        });

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');

        const closeBtn = modal.querySelector('.free-rooms-close');
        if (closeBtn) {
            closeBtn.focus();
        }
    }

    function closeRoomDetailModal() {
        const modal = document.getElementById('roomLessonModal');
        if (!modal) {
            return;
        }
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');

        if (roomDetailTrigger && typeof roomDetailTrigger.focus === 'function') {
            roomDetailTrigger.focus();
        }
        roomDetailTrigger = null;
    }

    // Функция обновления опций фильтров
    function updateFilterOptions() {
        const currentFilters = {
            teacher: filters.teacher.value,
            subject: filters.subject.value,
            class: filters.class.value,
            day: filters.day.value,
            classroom: filters.classroom.value
        };

        // Получаем все видимые карточки уроков
        const visibleCards = Array.from(document.querySelectorAll('.lesson-cell'))
            .filter(card => card.style.display !== 'none');

        // Собираем доступные значения для каждого фильтра
        const availableValues = {
            teacher: new Set(),
            subject: new Set(),
            class: new Set(),
            classroom: new Set(),
            day: new Set()
        };

        // Собираем значения из видимых карточек
        visibleCards.forEach(card => {
            card.getAttribute('data-teachers')?.split(',').forEach(id => availableValues.teacher.add(id));
            availableValues.subject.add(card.getAttribute('data-subject'));
            card.getAttribute('data-classes')?.split(',').forEach(id => availableValues.class.add(id));
            availableValues.classroom.add(card.getAttribute('data-classroom'));
            availableValues.day.add(card.getAttribute('data-day'));
        });

        // Обновляем опции для каждого селекта
        Object.entries(filters).forEach(([filterName, select]) => {
            if (!select || filterName === 'category') return;

            // Сохраняем текущее значение
            const currentValue = select.value;
            
            // Получаем все опции
            const options = Array.from(select.options);
            
            // Первая опция (placeholder) всегда активна
            options.slice(1).forEach(option => {
                const isAvailable = availableValues[filterName].has(option.value);
                
                // Для нативных селектов используем атрибут disabled
                option.disabled = !isAvailable;
                
                // Добавляем/удаляем класс для стилизации
                if (isAvailable) {
                    option.classList.remove('disabled-option');
                } else {
                    option.classList.add('disabled-option');
                }
            });

            // Если текущее значение больше недоступно, сбрасываем фильтр.
            // Исключение: день, выбранный кнопкой «Сегодня».
            if (currentValue && !availableValues[filterName].has(currentValue)
                && !(filterName === 'day' && keepDayFilter)) {
                select.value = '';
            }

            // Обновляем jQuery UI selectmenu только на десктопах
            if (window.innerWidth > 768 && typeof jQuery !== 'undefined' && jQuery(select).selectmenu('instance')) {
                jQuery(select).selectmenu('refresh');
            }
        });
    }

    // Функции сохранения и загрузки фильтров
    function saveFilters() {
        const filterValues = {};
        Object.keys(filters).forEach(key => {
            if (filters[key]) {
                filterValues[key] = filters[key].value;
            }
        });
        localStorage.setItem('scheduleFilters', JSON.stringify(filterValues));
    }

    function loadFilters() {
        const savedFilters = localStorage.getItem('scheduleFilters');
        if (savedFilters) {
            const filterValues = JSON.parse(savedFilters);
            Object.keys(filterValues).forEach(key => {
                if (filters[key]) {
                    filters[key].value = filterValues[key];
                }
            });
            filterAll();
        }
    }

    // Функция сброса фильтров
    function resetFilters() {
        Object.values(filters).forEach(filter => {
            if (filter) {
                filter.value = '';
                // Добавляем сброс для selectmenu, если используется jQuery UI
                if (typeof jQuery !== 'undefined' && jQuery(filter).selectmenu('instance')) {
                    jQuery(filter).selectmenu('refresh');
                }
            }
        });
        localStorage.removeItem('scheduleFilters');
        filterAll();
    }

    // Выносим функцию updateCurrentTime за пределы DOMContentLoaded
    function updateCurrentTime() {
        const timeElement = document.getElementById('currentTime');
        if (!timeElement) return; // Добавляем проверку на существование элемента

        const now = new Date();
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        
        // Очищаем предыдущую подсветку
        document.querySelectorAll('.current-period').forEach(el => {
            el.classList.remove('current-period');
        });
        
        // Получаем все периоды из таблицы
        const periods = [];
        document.querySelectorAll('.period-time').forEach(periodElement => {
            const timeText = periodElement.textContent;
            const [start, end] = timeText.split('—').map(t => t.trim());
            periods.push({ start, end });
        });

        // Функция для преобразования времени в минуты
        const timeToMinutes = (timeStr) => {
            const [hours, minutes] = timeStr.split(':').map(Number);
            return hours * 60 + minutes;
        };

        // Текущее время в минутах
        const currentMinutes = timeToMinutes(`${hours}:${minutes}`);

        // Определяем текущий статус
        let currentStatus = {
            type: 'none',
            text: 'Уроков нет'
        };

        for (let i = 0; i < periods.length; i++) {
            const period = periods[i];
            const startMinutes = timeToMinutes(period.start);
            const endMinutes = timeToMinutes(period.end);

            if (currentMinutes >= startMinutes && currentMinutes <= endMinutes) {
                const remainingMinutes = endMinutes - currentMinutes;
                
                if (remainingMinutes === 0 && i < periods.length - 1) {
                    const nextPeriod = periods[i + 1];
                    const nextStartMinutes = timeToMinutes(nextPeriod.start);
                    const breakMinutes = nextStartMinutes - currentMinutes;
                    currentStatus = {
                        type: 'break',
                        text: `Перемена, ${breakMinutes} ${declOfNum(breakMinutes, ['минута', 'минуты', 'минут'])}<br>до ${i + 2}-го урока`
                    };
                } else {
                    currentStatus = {
                        type: 'lesson',
                        text: `${i + 1}-й урок, осталось ${remainingMinutes} ${declOfNum(remainingMinutes, ['минута', 'минуты', 'минут'])}`
                    };
                }
                break;
            } else if (i < periods.length - 1) {
                const nextStartMinutes = timeToMinutes(periods[i + 1].start);
                if (currentMinutes > endMinutes && currentMinutes < nextStartMinutes) {
                    const remainingMinutes = nextStartMinutes - currentMinutes;
                    currentStatus = {
                        type: 'break',
                        text: `Перемена, осталось ${remainingMinutes} ${declOfNum(remainingMinutes, ['минута', 'минуты', 'минут'])}<br>до ${i + 2}-го урока`
                    };
                    break;
                }
            }
        }

        // После определения текущего периода добавляем подсветку
        if (currentStatus.type === 'lesson' || currentStatus.type === 'break') {
            const periodIndex = currentStatus.type === 'lesson' 
                ? parseInt(currentStatus.text) - 1 
                : parseInt(currentStatus.text.match(/до (\d+)-го/)[1]) - 1;
            const isVertical = localStorage.getItem('scheduleView') === 'vertical';
            
            // Очищаем предыдущие подсветки
            document.querySelectorAll('.current-period, .current-period-header, .current-break, .current-break-header').forEach(el => {
                el.classList.remove('current-period', 'current-period-header', 'current-break', 'current-break-header');
            });
            
            // Определяем классы для текущего статуса
            const cellClass = currentStatus.type === 'lesson' ? 'current-period' : 'current-break';
            const headerClass = currentStatus.type === 'lesson' ? 'current-period-header' : 'current-break-header';
            
            if (isVertical) {
                // Подсветка для вертикального вида
                const headers = document.querySelectorAll('.schedule-table thead th');
                const periodHeader = headers[periodIndex + 1];
                if (periodHeader) {
                    periodHeader.classList.add(headerClass);
                }
                
                // Подсветка ячеек
                document.querySelectorAll(`.schedule-table tbody td:nth-child(${periodIndex + 2})`).forEach(cell => {
                    cell.classList.add(cellClass);
                });
            } else {
                // Подсветка для горизонтального вида
                const rows = document.querySelectorAll('.schedule-table tbody tr');
                if (rows[periodIndex]) {
                    // Подсветка строки с уроками
                    rows[periodIndex].querySelectorAll('td').forEach(cell => {
                        cell.classList.add(cellClass);
                    });
                    // Подсветка заголовка периода
                    const periodHeader = rows[periodIndex].querySelector('td:first-child');
                    if (periodHeader) {
                        periodHeader.classList.add(headerClass);
                    }
                }
            }
        }

        // Обновляем HTML
        timeElement.innerHTML = `<div class="time-main-large">${hours}:${minutes}</div>`;
        
        // Обновляем статус
        const statusElement = document.querySelector('.time-status-large');
        if (statusElement) {
            statusElement.className = `time-status-large status-${currentStatus.type}`;
            statusElement.innerHTML = `
                <svg class="time-icon" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 6v6l4 2"/>
                </svg>
                <div class="status-text">${currentStatus.text}</div>
            `;
        }

        syncStickyScheduleHeaderClasses();
    }

    // Функция для склонения числительных
    function declOfNum(number, words) {
        return words[(number % 100 > 4 && number % 100 < 20) ? 2 : 
            [2, 0, 1, 1, 1, 2][(number % 10 < 5) ? number % 10 : 5]];
    }

    // Функция рендеринга расписания
    function renderSchedule(vertical = false) {
        const wrapper = document.querySelector('.schedule-table-wrapper');
        const table = document.querySelector('.schedule-table');
        const tbody = table.querySelector('tbody');
        const thead = table.querySelector('thead tr');
        
        if (!tbody || !thead) return;
        
        // Добавляем/убираем класс vertical для таблицы
        table.classList.toggle('vertical', vertical);
        
        wrapper.scrollLeft = 0;
        tbody.style.opacity = '0';
        
        setTimeout(() => {
            if (vertical) {
                const days = Array.from(thead.children).slice(1);
                const periods = Array.from(tbody.children);
                
                let newHtml = '';
                days.forEach((day, dayIndex) => {
                    const dayId = day.getAttribute('data-day-id');
                    newHtml += `<tr>
                        <td class="day-cell" data-day-id="${dayId}">
                            <div class="day-header">
                                <span class="day-name">${day.textContent}</span>
                            </div>
                        </td>
                        ${periods.map(period => {
                            const cells = Array.from(period.children);
                            const timeCell = cells[0];
                            const lessonCell = cells[dayIndex + 1];
                            
                            return `<td data-time="${timeCell.textContent}">${lessonCell ? lessonCell.innerHTML : ''}</td>`;
                        }).join('')}
                    </tr>`;
                });
                
                tbody.innerHTML = newHtml;
                
                thead.innerHTML = `
                    <th>День/Урок</th>
                    ${periods.map((period, index) => {
                        const timeCell = period.children[0];
                        const periodTime = timeCell.querySelector('.period-time')?.textContent || '';
                        
                        return `<th>
                            <div class="period-header">
                                <span class="period-number">${index + 1} урок</span>
                                <span class="period-time">${periodTime}</span>
                            </div>
                        </th>`;
                    }).join('')}
                `;
                
            } else {
                const days = Array.from(tbody.children);
                const periods = Array.from(days[0].children).slice(1);
                
                let newHtml = '';
                periods.forEach((_, periodIndex) => {
                    const timeCell = thead.children[periodIndex + 1];
                    const periodTime = timeCell.querySelector('.period-time')?.textContent || '';
                    
                    newHtml += `<tr>
                        <td>
                            <div class="period-header">
                                <span class="period-number">${periodIndex + 1} урок</span>
                                <span class="period-time">${periodTime}</span>
                            </div>
                        </td>
                        ${days.map(day => {
                            const cells = Array.from(day.children);
                            const dayCell = cells[0];
                            const lessonCell = cells[periodIndex + 1];
                            return `<td data-day-id="${dayCell.getAttribute('data-day-id')}">${lessonCell ? lessonCell.innerHTML : ''}</td>`;
                        }).join('')}
                    </tr>`;
                });
                
                tbody.innerHTML = newHtml;
                
                thead.innerHTML = `
                    <th>Урок/День</th>
                    ${days.map(day => {
                        const dayCell = day.children[0];
                        return `<th data-day-id="${dayCell.getAttribute('data-day-id')}">${dayCell.textContent}</th>`;
                    }).join('')}
                `;
            }
            
            setTimeout(() => {
                tbody.style.opacity = '1';
                filterAll();
                updateScrollVisibility();
            }, 50);
        }, 300);
        
        localStorage.setItem('scheduleView', vertical ? 'vertical' : 'horizontal');
        updateButtonText();
    }

    // ExcelJS загружается только при первом экспорте, а не при открытии страницы.
    function ensureExcelJS() {
        if (window.ExcelJS) {
            return Promise.resolve();
        }
        if (window.__excelJsPromise) {
            return window.__excelJsPromise;
        }

        window.__excelJsPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/exceljs@4.3.0/dist/exceljs.min.js';
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Не удалось загрузить библиотеку ExcelJS'));
            document.head.appendChild(script);
        });

        return window.__excelJsPromise;
    }

    // Функция экспорта в Excel
    async function exportToExcel() {
        try {
            console.log('Начало экспорта...');
            await ensureExcelJS();
            
            // Получаем текущие фильтры
            const currentFilters = {
                teacher: document.getElementById('teacherFilter'),
                subject: document.getElementById('subjectFilter'),
                class: document.getElementById('classFilter'),
                day: document.getElementById('dayFilter'),
                classroom: document.getElementById('classroomFilter'),
                category: document.getElementById('categoryFilter')
            };

            const table = document.querySelector('.schedule-table');
            const workbook = new ExcelJS.Workbook();
            const worksheet = workbook.addWorksheet('Расписание');
            
            // Получаем заголовки
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                // Обрабатываем заголовки периодов
                const periodNumber = th.querySelector('.period-number')?.textContent.trim() || '';
                const periodTime = th.querySelector('.period-time')?.textContent.trim() || '';
                
                if (periodNumber && periodTime) {
                    headers.push(`${periodNumber}\n${periodTime}`);
                } else {
                    headers.push(th.textContent.trim());
                }
            });
            
            const headerRow = worksheet.addRow(headers);
            
            // Изменяем стили для заголовков
            const headerStyle = {
                font: { bold: true, size: 12, color: { argb: 'FFFFFFFF' } },
                fill: {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: 'FF3B82F6' }
                },
                border: {
                    top: { style: 'thin' },
                    left: { style: 'thin' },
                    bottom: { style: 'thin' },
                    right: { style: 'thin' }
                },
                alignment: {
                    vertical: 'middle',
                    horizontal: 'center',
                    wrapText: true
                }
            };

            // Применяем стили к заголовкам
            headerRow.eachCell((cell) => {
                Object.assign(cell, headerStyle);
            });

            // Добавляем данные
            console.log('Добавление данных...');
            table.querySelectorAll('tbody tr').forEach((tr) => {
                const rowData = [];
                tr.querySelectorAll('td').forEach((td, cellIndex) => {
                    let cellContent = '';
                    
                    if (cellIndex === 0) {
                        // Получаем элементы напрямую из DOM структуры
                        const periodNumber = td.querySelector('.period-number')?.textContent.trim() || '';
                        const periodTime = td.querySelector('.period-time')?.textContent.trim() || '';
                        
                        if (periodNumber && periodTime) {
                            // Формируем строку с переносом без лишних пробелов
                            cellContent = `${periodNumber}\n${periodTime}`;
                        } else {
                            // Запасной вариант, если структура отличается
                            const periodText = td.textContent.trim();
                            const matches = periodText.match(/(\d+)\s*урок\s*\(([^)]+)\)/i);
                            if (matches) {
                                const lessonNumber = matches[1].trim();
                                const timeRange = matches[2]
                                    .replace(/[\(\)]/g, '')
                                    .replace(/\s+—\s+/g, '—')
                                    .replace(/\s+/g, '')
                                    .trim();
                                cellContent = `${lessonNumber} урок\n${timeRange}`;
                            } else {
                                cellContent = periodText.replace(/\s+/g, ' ').trim();
                            }
                        }
                    } else {
                        const lessons = td.querySelectorAll('.lesson-cell');
                        if (lessons.length > 0) {
                            const lessonTexts = [];
                            lessons.forEach(lesson => {
                                if (lesson.style.display !== 'none') {
                                    const lines = lesson.innerText
                                        .split('\n')
                                        .map(line => line.trim())
                                        .filter(Boolean);
                                    
                                    if (lines.length) {
                                        lessonTexts.push(lines.join('\n'));
                                    }
                                }
                            });
                            cellContent = lessonTexts.join('\n\n');
                        } else {
                            cellContent = td.textContent.trim();
                        }
                    }
                    rowData.push(cellContent);
                });
                
                if (rowData.some(cell => cell)) {
                    const row = worksheet.addRow(rowData);
                    row.height = 100; // Увеличиваем высоту строки
                    
                    // Применяем стили к ячейкам
                    row.eachCell((cell, colNumber) => {
                        // Базовые стили для всех ячеек
                        cell.alignment = {
                            vertical: 'middle',
                            horizontal: 'center',
                            wrapText: true
                        };
                        cell.border = {
                            top: { style: 'thin' },
                            left: { style: 'thin' },
                            bottom: { style: 'thin' },
                            right: { style: 'thin' }
                        };

                        // Если это ячейка с уроком (не первый столбец)
                        if (colNumber > 1 && cell.value) {
                            // Разбиваем текст ячейки на строки
                            const lines = cell.value.split('\n');
                            if (lines.length > 0) {
                                // Делаем первую строку (название предмета) жирной
                                cell.value = {
                                    richText: [
                                        { text: lines[0] + '\n', font: { bold: true } },
                                        { text: lines.slice(1).join('\n') }
                                    ]
                                };
                            }
                        }

                        // Специальные стили для первого столбца
                        if (colNumber === 1) {
                            Object.assign(cell, headerStyle);
                        }
                    });
                }
            });

            // Добавляем информацию о фильтрах
            console.log('Добавление информации о фильтрах...');
            worksheet.addRow([]);
            worksheet.addRow(['Примененные фильтры:']);

            const filterLabels = {
                teacher: 'Преподаватель:',
                subject: 'Предмет:',
                class: 'Класс:',
                day: 'День:',
                classroom: 'Кабинет:',
                category: 'Категория:'
            };

            Object.entries(currentFilters).forEach(([key, filter]) => {
                if (filter && filter.value) {
                    worksheet.addRow([
                        filterLabels[key],
                        filter.options[filter.selectedIndex].text
                    ]);
                }
            });

            // Устанавливаем ширину колонок
            worksheet.columns.forEach((column, index) => {
                if (index === 0) {
                    column.width = 15; // Ширина для первого столбца
                } else {
                    column.width = 35; // Увеличенная ширина для остальных столбцов
                }
            });

            // Сохранение файла
            console.log('Сохранение файла...');
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], { 
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' 
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `Расписание_${new Date().toLocaleDateString('ru-RU').replace(/\./g, '-')}.xlsx`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            console.log('Экспорт завершен успешно');
        } catch (error) {
            console.error('Подробная ошибка пи экспорте:', error);
            console.error('Stack trace:', error.stack);
            alert(`Произошла ошибка при экспорте в Excel: ${error.message}`);
        }
    }

    // Обработка кнопки "Вверх"
    const scrollToTopBtn = document.querySelector('.scroll-to-top');

    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollToTopBtn.classList.add('visible');
        } else {
            scrollToTopBtn.classList.remove('visible');
        }
    });

    scrollToTopBtn.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    // Добавляем стили для неактивных опций
    const style = document.createElement('style');
    style.textContent = `
        .disabled-option {
            color: var(--gray-400) !important;
            font-style: italic;
        }

        .ui-selectmenu-menu .ui-menu .ui-menu-item-wrapper.disabled-option {
            color: var(--gray-400) !important;
            background: var(--gray-50) !important;
            cursor: not-allowed;
        }
    `;
    document.head.appendChild(style);

    // Функция скрытия пустых дней
    function hideEmptyDays() {
        const table = document.querySelector('.schedule-table');
        if (!table) return;

        const isVertical = localStorage.getItem('scheduleView') === 'vertical';

        // Функция для проверки наличия уроков в ячейке
        function hasVisibleLessons(cell) {
            const lessonCells = cell.querySelectorAll('.lesson-cell');
            return Array.from(lessonCells).some(lesson => lesson.style.display !== 'none');
        }

        if (isVertical) {
            // Для вертикального вида
            const days = table.querySelectorAll('tbody tr');
            const periods = table.querySelectorAll('thead th');

            // Находим последний период с уроками для каждого дня
            let lastPeriodIndex = 0;
            days.forEach(day => {
                const cells = Array.from(day.children).slice(1); // Пропускаем первую ячейку (время)
                
                // Проверяем наличие уроков в дне
                const hasLessons = cells.some(cell => hasVisibleLessons(cell));
                
                if (hasLessons) {
                    day.style.display = '';
                    // Находим последний период с уроками
                    cells.forEach((cell, index) => {
                        if (hasVisibleLessons(cell)) {
                            lastPeriodIndex = Math.max(lastPeriodIndex, index);
                        }
                    });
                } else {
                    day.style.display = 'none';
                }
            });

            // Скрываем пустые периоды после последнего урока
            periods.forEach((period, index) => {
                if (index > lastPeriodIndex + 1) { // +1 потому что первая ячейка - это заголовок "Время"
                    period.style.display = 'none';
                    table.querySelectorAll(`tbody tr`).forEach(row => {
                        const cell = row.children[index];
                        if (cell) cell.style.display = 'none';
                    });
                } else {
                    period.style.display = '';
                    table.querySelectorAll(`tbody tr`).forEach(row => {
                        const cell = row.children[index];
                        if (cell) cell.style.display = '';
                    });
                }
            });

        } else {
            // Для горизонтального вида
            const days = table.querySelectorAll('th[data-day-id]');
            const rows = table.querySelectorAll('tbody tr');
            const dayIndexes = new Map();

            days.forEach((day, index) => {
                dayIndexes.set(day.getAttribute('data-day-id'), index + 1);
            });

            // Находим последний период с уроками для каждого дня
            let lastRowWithLessons = 0;
            rows.forEach((row, rowIndex) => {
                const cells = Array.from(row.children).slice(1); // Пропускаем ячейку времени
                const hasAnyLessons = cells.some(cell => hasVisibleLessons(cell));
                if (hasAnyLessons) {
                    lastRowWithLessons = rowIndex;
                }
            });

            // Скрываем пустые дни
            days.forEach(day => {
                const dayId = day.getAttribute('data-day-id');
                const columnIndex = dayIndexes.get(dayId);
                const cells = table.querySelectorAll(`td:nth-child(${columnIndex + 1})`);
                const hasLessons = Array.from(cells).some(cell => hasVisibleLessons(cell));
                
                if (!hasLessons) {
                    day.style.display = 'none';
                    cells.forEach(cell => {
                        cell.style.display = 'none';
                    });
                } else {
                    day.style.display = '';
                    cells.forEach(cell => {
                        cell.style.display = '';
                    });
                }
            });

            // Скрываем пустые периоды после последнего урока
            rows.forEach((row, index) => {
                if (index > lastRowWithLessons) {
                    row.style.display = 'none';
                } else {
                    row.style.display = '';
                }
            });
        }
    }

    // Вызываем функцию после загрузки расписания
    document.addEventListener('DOMContentLoaded', function() {
        // ... existing code ...
        hideEmptyDays();
    });

    // Добавляем вызов после фильтрации
    function applyFilters() {
        // ... existing filtering code ...
        hideEmptyDays(); // Вызываем после применения фильтров
    }
});