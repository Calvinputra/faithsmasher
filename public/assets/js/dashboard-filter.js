document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('dashboard-filter-form');
    const pickerInput = document.getElementById('filter_date_picker');
    const hiddenInput = document.getElementById('filter_date');

    if (!form || !pickerInput || !hiddenInput || typeof flatpickr === 'undefined') {
        return;
    }

    let sessionDates = [];

    try {
        sessionDates = JSON.parse(form.dataset.sessionDates || '[]');
    } catch {
        sessionDates = [];
    }

    const sessionDateSet = new Set(sessionDates);

    const picker = flatpickr(pickerInput, {
        dateFormat: 'Y-m-d',
        altInput: false,
        disableMobile: true,
        allowInput: false,
        clickOpens: true,
        animate: true,
        monthSelectorType: 'dropdown',
        defaultDate: hiddenInput.value || null,
        locale: { firstDayOfWeek: 1 },
        onReady(_selectedDates, _dateStr, instance) {
            instance.calendarContainer.classList.add('fs-flatpickr', 'fs-flatpickr-filter');
        },
        onDayCreate(dObj, _dStr, fp, dayElem) {
            const key = fp.formatDate(dObj, 'Y-m-d');

            if (sessionDateSet.has(key)) {
                dayElem.classList.add('has-session');
            }
        },
        onChange(selectedDates) {
            if (selectedDates.length === 0) {
                hiddenInput.value = '';
                return;
            }

            hiddenInput.value = picker.formatDate(selectedDates[0], 'Y-m-d');
            form.requestSubmit();
        },
    });

    pickerInput.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' || event.key === 'Delete') {
            event.preventDefault();
            picker.clear();
            hiddenInput.value = '';
            form.requestSubmit();
        }
    });
});
