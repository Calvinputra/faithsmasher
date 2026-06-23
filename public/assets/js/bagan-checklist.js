document.addEventListener('DOMContentLoaded', () => {
    const checkboxes = document.querySelectorAll('.match-checklist-cb');
    if (checkboxes.length === 0) return;

    // Load saved state from localStorage
    const savedState = JSON.parse(localStorage.getItem('faithsmasher_match_checklist') || '{}');

    function toggleRowStyle(checkbox) {
        const row = checkbox.closest('tr');
        if (!row) return;

        if (checkbox.checked) {
            row.classList.add('opacity-40');
            row.classList.remove('bg-white', 'bg-navy-50/40');
            row.classList.add('bg-navy-50/50');
        } else {
            row.classList.remove('opacity-40', 'bg-navy-50/50');
            
            // Restore original background class based on the row's template bgClass
            // Since we don't know the exact original bgClass easily here, we let the existing classes handle it
            // Actually, we can just remove opacity-40 and let the underlying classes show through.
        }
    }

    checkboxes.forEach(cb => {
        const matchId = cb.dataset.matchId;
        
        if (savedState[matchId]) {
            cb.checked = true;
            toggleRowStyle(cb);
        }

        cb.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            savedState[matchId] = isChecked;
            
            if (isChecked) {
                // Keep only true values to save space
                savedState[matchId] = true;
            } else {
                delete savedState[matchId];
            }
            
            localStorage.setItem('faithsmasher_match_checklist', JSON.stringify(savedState));
            toggleRowStyle(e.target);
        });
    });
});
