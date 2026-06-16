document.addEventListener('DOMContentLoaded', () => {
    const pool = document.getElementById('player-pool');
    const form = document.getElementById('manual-form');
    const pairingsInput = document.getElementById('pairings-input');

    if (!pool || !form || typeof Sortable === 'undefined') {
        return;
    }

    const slots = document.querySelectorAll('.drop-slot');

    slots.forEach((slot) => {
        new Sortable(slot, {
            group: 'players',
            animation: 150,
            onAdd: enforceSingleChip,
            onUpdate: enforceSingleChip,
        });
    });

    new Sortable(pool, {
        group: 'players',
        animation: 150,
        sort: false,
    });

    function enforceSingleChip(evt) {
        const slot = evt.to;
        const chips = slot.querySelectorAll('.player-chip');

        if (chips.length > 1) {
            const extra = chips[0];
            pool.appendChild(extra);
        }

        updateWarnings();
    }

    function updateWarnings() {
        const counts = {};

        document.querySelectorAll('.drop-slot .player-chip').forEach((chip) => {
            const id = chip.dataset.id;
            counts[id] = (counts[id] || 0) + 1;
        });

        document.querySelectorAll('.player-chip').forEach((chip) => {
            const badge = chip.querySelector('.match-count');
            if (!badge) {
                return;
            }

            const count = counts[chip.dataset.id] || 0;

            if (count > 2) {
                badge.textContent = `${count}x`;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        });
    }

    form.addEventListener('submit', (event) => {
        const pairings = {};

        document.querySelectorAll('[data-match-id]').forEach((card) => {
            const matchId = card.dataset.matchId;
            const p1Slot = card.querySelector('[data-slot="p1"] .player-chip');
            const p2Slot = card.querySelector('[data-slot="p2"] .player-chip');

            pairings[matchId] = {
                p1: p1Slot ? p1Slot.dataset.id : '',
                p2: p2Slot ? p2Slot.dataset.id : '',
            };
        });

        pairingsInput.value = JSON.stringify(pairings);
    });

    updateWarnings();
});
