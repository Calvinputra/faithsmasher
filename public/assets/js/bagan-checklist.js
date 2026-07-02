(() => {
    const STORAGE_KEY = 'faithsmasher_match_checklist';

    function loadSavedState() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        } catch (_error) {
            return {};
        }
    }

    function toggleRowStyle(checkbox, savedState) {
        const checklistKey = checkbox.dataset.checklistKey || checkbox.dataset.matchOrder;
        const isChecked = checkbox.checked;
        const bagan = checkbox.dataset.bagan;
        const selector = bagan
            ? `.match-checklist-cb[data-checklist-key="${checklistKey}"][data-bagan="${bagan}"]`
            : `.match-checklist-cb[data-checklist-key="${checklistKey}"]`;

        document.querySelectorAll(selector).forEach((cb) => {
            cb.checked = isChecked;
            const row = cb.closest('tr');

            if (!row) {
                return;
            }

            if (isChecked) {
                row.classList.add('opacity-50');
                row.classList.remove('bg-green-50/60');
                row.classList.add('bagan-row--done');
            } else {
                row.classList.remove('opacity-50', 'bagan-row--done', 'bg-green-50/60');
            }
        });

        if (isChecked) {
            savedState[checklistKey] = true;
        } else {
            delete savedState[checklistKey];
        }

        localStorage.setItem(STORAGE_KEY, JSON.stringify(savedState));
    }

    function initChecklistSection(section) {
        const exportRoot = document.getElementById('bagan-export-root');

        if (exportRoot?.dataset.canEdit === '0') {
            return;
        }

        const scope = section instanceof HTMLElement ? section : document;
        const checkboxes = scope.querySelectorAll('.match-checklist-cb');

        if (checkboxes.length === 0) {
            return;
        }

        const savedState = loadSavedState();

        checkboxes.forEach((cb) => {
            if (cb.dataset.checklistBound === '1') {
                return;
            }

            cb.dataset.checklistBound = '1';
            const checklistKey = cb.dataset.checklistKey || cb.dataset.matchOrder;

            if (savedState[checklistKey]) {
                cb.checked = true;
                toggleRowStyle(cb, savedState);
            }

            cb.addEventListener('change', (event) => {
                toggleRowStyle(event.target, savedState);
            });
        });
    }

    window.FsBaganChecklist = {
        initSection: initChecklistSection,
        initAll: () => initChecklistSection(document),
    };

    document.addEventListener('DOMContentLoaded', () => {
        window.FsBaganChecklist.initAll();
    });
})();
