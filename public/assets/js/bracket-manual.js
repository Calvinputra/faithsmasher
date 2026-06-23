document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('manual-form');
    const pairingsInput = document.getElementById('pairings-input');

    if (!form || typeof Sortable === 'undefined') {
        return;
    }

    const slots = document.querySelectorAll('.drop-slot');

    slots.forEach((slot) => {
        new Sortable(slot, {
            group: 'players',
            animation: 150,
            draggable: '.player-chip',
            onAdd: (evt) => {
                swapChips(evt);
            },
        });
    });

    function swapChips(evt) {
        const newSlot = evt.to;
        const oldSlot = evt.from;
        const draggedChip = evt.item;

        // Find existing chip in the new slot that isn't the one we just dropped
        const chips = Array.from(newSlot.querySelectorAll('.player-chip'));
        const existingChip = chips.find(c => c !== draggedChip);

        if (existingChip && oldSlot) {
            oldSlot.appendChild(existingChip);
        }

        updateWarnings();
    }

    function updateWarnings() {
        document.querySelectorAll('.bagan-preview-section').forEach((section) => {
            const counts = {};

            section.querySelectorAll('.drop-slot .player-chip').forEach((chip) => {
                const id = chip.dataset.id;
                counts[id] = (counts[id] || 0) + 1;
            });

            section.querySelectorAll('.player-chip').forEach((chip) => {
                const badge = chip.querySelector('.match-count');
                if (!badge) return;

                const count = counts[chip.dataset.id] || 0;

                if (count > 1) {
                    badge.textContent = `Double (${count}x)`;
                    badge.classList.remove('hidden');
                    chip.classList.add('player-chip-warn');
                } else {
                    badge.textContent = '';
                    badge.classList.add('hidden');
                    chip.classList.remove('player-chip-warn');
                }
            });
        });
    }

    form.addEventListener('submit', () => {
        const pairings = {};

        document.querySelectorAll('tr[data-match-id]').forEach((row) => {
            const matchId = row.dataset.matchId;
            const p1Slot = row.querySelector('[data-slot="p1"] .player-chip');
            const p2Slot = row.querySelector('[data-slot="p2"] .player-chip');

            pairings[matchId] = {
                p1: p1Slot ? p1Slot.dataset.id : '',
                p2: p2Slot ? p2Slot.dataset.id : '',
            };
        });

        pairingsInput.value = JSON.stringify(pairings);
    });

    updateWarnings();
});
