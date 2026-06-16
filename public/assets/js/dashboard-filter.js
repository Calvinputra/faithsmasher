document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('dashboard-filter-form');
    const pickerInput = document.getElementById('filter_date_picker');
    const hiddenInput = document.getElementById('filter_date');
    const trigger = document.getElementById('date-filter-trigger');
    const dateWrap = document.querySelector('.date-filter-wrap');

    if (!form || !pickerInput || !hiddenInput) {
        return;
    }

    const submitForm = () => {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    };

    const enableNativeDateFallback = () => {
        pickerInput.removeAttribute('readonly');
        pickerInput.type = 'date';
        pickerInput.name = 'date';
        pickerInput.id = 'filter_date_picker';
        hiddenInput.remove();

        pickerInput.addEventListener('change', () => submitForm());
        trigger?.addEventListener('click', (event) => {
            if (event.target === pickerInput) {
                return;
            }
            event.preventDefault();
            pickerInput.showPicker?.();
            pickerInput.focus();
        });
    };

    const initFlatpickr = () => {
        if (typeof flatpickr === 'undefined') {
            enableNativeDateFallback();
            return;
        }

        let sessionDates = [];

        try {
            sessionDates = JSON.parse(form.dataset.sessionDates || '[]');
        } catch {
            sessionDates = [];
        }

        const sessionDateSet = new Set(
            sessionDates.map((value) => String(value).slice(0, 10)),
        );

        const markSessionDays = (instance) => {
            instance.calendarContainer
                .querySelectorAll('.flatpickr-day:not(.flatpickr-disabled)')
                .forEach((dayElem) => {
                    const date = dayElem.dateObj;
                    if (!(date instanceof Date)) {
                        return;
                    }

                    const key = instance.formatDate(date, 'Y-m-d');
                    dayElem.classList.toggle('has-session', sessionDateSet.has(key));
                });
        };

        let picker;

        try {
            picker = flatpickr(pickerInput, {
                dateFormat: 'Y-m-d',
                altInput: false,
                disableMobile: false,
                allowInput: false,
                clickOpens: true,
                animate: true,
                static: true,
                monthSelectorType: 'dropdown',
                defaultDate: hiddenInput.value || null,
                locale: { firstDayOfWeek: 1 },
                onReady(_selectedDates, _dateStr, instance) {
                    instance.calendarContainer.classList.add('fs-flatpickr', 'fs-flatpickr-filter');
                    markSessionDays(instance);
                },
                onDayCreate(_selectedDates, _dateStr, instance, dayElem) {
                    const date = dayElem.dateObj;
                    if (!(date instanceof Date)) {
                        return;
                    }

                    const key = instance.formatDate(date, 'Y-m-d');
                    if (sessionDateSet.has(key)) {
                        dayElem.classList.add('has-session');
                    }
                },
                onMonthChange(_selectedDates, _dateStr, instance) {
                    markSessionDays(instance);
                },
                onYearChange(_selectedDates, _dateStr, instance) {
                    markSessionDays(instance);
                },
                onChange(selectedDates, _dateStr, instance) {
                    if (selectedDates.length === 0) {
                        hiddenInput.value = '';
                        submitForm();
                        return;
                    }

                    hiddenInput.value = instance.formatDate(selectedDates[0], 'Y-m-d');
                    submitForm();
                },
            });
        } catch {
            enableNativeDateFallback();
            return;
        }

        const openPicker = (event) => {
            event?.preventDefault();
            picker.open();
        };

        pickerInput.addEventListener('click', openPicker);
        pickerInput.addEventListener('focus', openPicker);

        trigger?.addEventListener('click', (event) => {
            if (event.target.closest('.date-filter-clear-btn')) {
                return;
            }
            openPicker(event);
        });

        dateWrap?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                openPicker(event);
            }
        });

        pickerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' || event.key === 'Delete') {
                event.preventDefault();
                picker.clear();
                hiddenInput.value = '';
                submitForm();
            }
        });
    };

    if (window.FsFlatpickr) {
        window.FsFlatpickr.load().then(initFlatpickr).catch(() => enableNativeDateFallback());
    } else {
        initFlatpickr();
    }
});
