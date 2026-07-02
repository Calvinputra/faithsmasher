document.addEventListener('DOMContentLoaded', () => {
    const exportRoot = document.getElementById('bagan-export-root');

    if (exportRoot?.dataset.canEdit === '0') {
        return;
    }

    const checkboxes = document.querySelectorAll('.match-checklist-cb');
    if (checkboxes.length === 0) return;

    // Load saved state from localStorage
    const savedState = JSON.parse(localStorage.getItem('faithsmasher_match_checklist') || '{}');

    function toggleRowStyle(checkbox) {
        const matchOrder = checkbox.dataset.matchOrder;
        const isChecked = checkbox.checked;
        
        // Sync all checkboxes and rows that share the same match order
        const relatedCheckboxes = document.querySelectorAll(`.match-checklist-cb[data-match-order="${matchOrder}"]`);
        
        relatedCheckboxes.forEach(cb => {
            cb.checked = isChecked;
            const row = cb.closest('tr');
            if (!row) return;

            if (isChecked) {
                row.classList.add('opacity-50');
                row.classList.remove('bg-white', 'bg-navy-50/40');
                row.classList.add('bg-green-50/60');
            } else {
                row.classList.remove('opacity-50', 'bg-green-50/60');
                // The underlying CSS classes will take over again
            }
        });
    }

    checkboxes.forEach(cb => {
        const matchOrder = cb.dataset.matchOrder;
        
        if (savedState[matchOrder]) {
            cb.checked = true;
            toggleRowStyle(cb);
        }

        cb.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            savedState[matchOrder] = isChecked;
            
            if (isChecked) {
                savedState[matchOrder] = true;
            } else {
                delete savedState[matchOrder];
            }
            
            localStorage.setItem('faithsmasher_match_checklist', JSON.stringify(savedState));
            toggleRowStyle(e.target);
        });
    });
});
