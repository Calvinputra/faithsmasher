document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.getElementById('session_date');

    if (!dateInput || typeof flatpickr === 'undefined') {
        return;
    }

    flatpickr(dateInput, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd M Y',
        disableMobile: true,
        allowInput: false,
        clickOpens: true,
        animate: true,
        monthSelectorType: 'dropdown',
        prevArrow: '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>',
        nextArrow: '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>',
        locale: {
            firstDayOfWeek: 1,
        },
        onReady(_selectedDates, _dateStr, instance) {
            instance.calendarContainer.classList.add('fs-flatpickr');
        },
    });
});
