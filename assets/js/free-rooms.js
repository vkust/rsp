/* Сетка свободных кабинетов: переключение дней и подсветка текущего урока. */
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('freeRoomsGridPage');
    if (!root) {
        return;
    }

    const tabs = Array.from(root.querySelectorAll('[data-day-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-day-panel]'));
    if (!tabs.length || !panels.length) {
        return;
    }

    const todayId = String(new Date().getDay());
    const isWeekday = todayId >= '1' && todayId <= '5';
    let currentDay = '';

    function panelFor(dayId) {
        return panels.find(panel => panel.dataset.dayPanel === dayId) || null;
    }

    function dayHasLessons(dayId) {
        const panel = panelFor(dayId);
        return !!panel && panel.dataset.hasLessons === '1';
    }

    function nearestDayId() {
        const withLessons = tabs.filter(tab => dayHasLessons(tab.dataset.dayTab));
        const candidates = withLessons.length ? withLessons : tabs;
        let best = candidates[0] ? candidates[0].dataset.dayTab : '';
        let bestDistance = Infinity;

        candidates.forEach(tab => {
            const order = Number(tab.dataset.order) || 0;
            if (!order) {
                return;
            }
            const distance = (order - Number(todayId) + 7) % 7;
            if (distance < bestDistance) {
                bestDistance = distance;
                best = tab.dataset.dayTab;
            }
        });

        return best;
    }

    function defaultDayId() {
        if (isWeekday && dayHasLessons(todayId)) {
            return todayId;
        }
        return nearestDayId();
    }

    function timeToMinutes(value) {
        if (!value) {
            return null;
        }
        const parts = String(value).split(':').map(Number);
        if (parts.length < 2 || isNaN(parts[0]) || isNaN(parts[1])) {
            return null;
        }
        return parts[0] * 60 + parts[1];
    }

    function currentPeriodId(panel) {
        const headers = Array.from(panel.querySelectorAll('.rooms-grid-period-head'));
        const timed = headers
            .map(header => ({
                id: header.dataset.period,
                start: timeToMinutes(header.dataset.start),
                end: timeToMinutes(header.dataset.end)
            }))
            .filter(item => item.start !== null && item.end !== null);

        if (!timed.length) {
            return null;
        }

        const now = new Date();
        const minutes = now.getHours() * 60 + now.getMinutes();

        for (let i = 0; i < timed.length; i++) {
            const period = timed[i];
            if (minutes >= period.start && minutes <= period.end) {
                return period.id;
            }
            if (i < timed.length - 1) {
                const next = timed[i + 1];
                if (minutes > period.end && minutes < next.start) {
                    return next.id;
                }
            }
        }

        if (minutes < timed[0].start) {
            return timed[0].id;
        }

        return null;
    }

    function highlightCurrent() {
        root.querySelectorAll('.is-current').forEach(el => el.classList.remove('is-current'));
        if (currentDay !== todayId) {
            return;
        }

        const panel = panelFor(currentDay);
        if (!panel) {
            return;
        }

        const periodId = currentPeriodId(panel);
        if (!periodId) {
            return;
        }

        panel.querySelectorAll('[data-period="' + periodId + '"]').forEach(el => {
            el.classList.add('is-current');
        });
    }

    function activate(dayId, updateUrl) {
        if (!dayId) {
            return;
        }

        currentDay = dayId;

        tabs.forEach(tab => {
            const active = tab.dataset.dayTab === dayId;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });

        panels.forEach(panel => {
            panel.hidden = panel.dataset.dayPanel !== dayId;
        });

        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('day', dayId);
            window.history.replaceState(null, '', url.toString());
        }

        highlightCurrent();
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activate(tab.dataset.dayTab, true));

        tab.addEventListener('keydown', event => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }
            event.preventDefault();
            const index = tabs.indexOf(tab);
            const nextIndex = event.key === 'ArrowRight'
                ? (index + 1) % tabs.length
                : (index - 1 + tabs.length) % tabs.length;
            tabs[nextIndex].focus();
            activate(tabs[nextIndex].dataset.dayTab, true);
        });
    });

    const requestedDay = new URLSearchParams(window.location.search).get('day');
    const initialDay = requestedDay && tabs.some(tab => tab.dataset.dayTab === requestedDay)
        ? requestedDay
        : defaultDayId();

    activate(initialDay, false);

    window.setInterval(highlightCurrent, 60000);
});
