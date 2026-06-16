document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('dashboard-filter-form');
    const pickerInput = document.getElementById('filter_date_picker');
    const hiddenInput = document.getElementById('filter_date');
    const trigger = document.getElementById('date-filter-trigger');

    if (!form || !pickerInput || !hiddenInput) {
        return;
    }

    if (typeof flatpickr === 'undefined') {
        pickerInput.removeAttribute('readonly');
        pickerInput.type = 'date';
        pickerInput.name = 'date';
        hiddenInput.remove();

        if (trigger) {
            trigger.addEventListener('click', () => pickerInput.showPicker?.());
        }

        return;
    }

    let sessionDates = [];

    try {
        sessionDates = JSON.parse(form.dataset.sessionDates || '[]');
    } catch {
        sessionDates = [];
    }

    const sessionDateSet = new Set(sessionDates);

    const submitForm = () => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    const picker = flatpickr(pickerInput, {
        dateFormat: 'Y-m-d',
        altInput: false,
        disableMobile: true,
        allowInput: false,
        clickOpens: true,
        animate: true,
        appendTo: document.body,
        monthSelectorType: 'dropdown',
        defaultDate: hiddenInput.value || null,
        locale: { firstDayOfWeek: 1 },
        onReady(_selectedDates, _dateStr, instance) {
            instance.calendarContainer.classList.add('fs-flatpickr', 'fs-flatpickr-filter');
            instance.calendarContainer.style.zIndex = '9999';
        },
        onDayCreate(dObj, _dStr, fp, dayElem) {
            const key = fp.formatDate(dObj, 'Y-m-d');

            if (sessionDateSet.has(key)) {
                dayElem.classList.add('has-session');
            }
        },
        onChange(selectedDates, _dateStr, instance) {
            if (selectedDates.length === 0) {
                hiddenInput.value = '';
                return;
            }

            hiddenInput.value = instance.formatDate(selectedDates[0], 'Y-m-d');
            submitForm();
        },
    });

    trigger?.addEventListener('click', (event) => {
        if (event.target === pickerInput) {
            return;
        }

        event.preventDefault();
        picker.open();
    });

    pickerInput.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' || event.key === 'Delete') {
            event.preventDefault();
            picker.clear();
            hiddenInput.value = '';
            submitForm();
        }
    });
});
