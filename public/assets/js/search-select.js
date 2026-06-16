(() => {
    const DEFAULT_OPTIONS = {
        allowEmptyOption: true,
        create: false,
        maxOptions: null,
        searchField: ['text'],
        sortField: { field: 'text', direction: 'asc' },
        dropdownParent: 'body',
        onDropdownOpen(dropdown) {
            dropdown.classList.add('fs-tomselect-dropdown');
        },
    };

    function getPlaceholder(select) {
        const emptyOption = select.querySelector('option[value=""]');

        return emptyOption?.textContent?.trim() || 'Pilih...';
    }

    function initSelect(select) {
        if (!(select instanceof HTMLSelectElement) || select.tomselect || select.dataset.searchSelectSkip !== undefined) {
            return select.tomselect ?? null;
        }

        if (typeof TomSelect === 'undefined') {
            return null;
        }

        const instance = new TomSelect(select, {
            ...DEFAULT_OPTIONS,
            placeholder: getPlaceholder(select),
            plugins: ['dropdown_input'],
        });

        instance.wrapper.classList.add('fs-tomselect');

        return instance;
    }

    function initAll(root = document) {
        root.querySelectorAll('select[data-search-select]').forEach((select) => {
            initSelect(select);
        });
    }

    function setValue(select, value) {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const normalized = value ?? '';

        if (select.tomselect) {
            select.tomselect.setValue(normalized, true);
            return;
        }

        select.value = normalized;
    }

    function syncFormSelects(form, values) {
        Object.entries(values).forEach(([field, value]) => {
            const select = form.querySelector(`select[data-field="${field}"]`);

            if (select) {
                setValue(select, value);
            }
        });
    }

    window.FsSearchSelect = {
        init: initSelect,
        initAll,
        setValue,
        syncFormSelects,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initAll();
    });

    document.addEventListener('modal:open', (event) => {
        const modal = event.detail?.modal;

        if (modal) {
            initAll(modal);
        }
    });
})();
