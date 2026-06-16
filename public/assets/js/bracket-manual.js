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
            draggable: '.player-chip',
            onAdd: (evt) => {
                removePlaceholder(slot);
                enforceSingleChip(evt);
            },
            onRemove: () => {
                ensurePlaceholder(slot);
            },
            onUpdate: enforceSingleChip,
        });
    });

    new Sortable(pool, {
        group: 'players',
        animation: 150,
        sort: true,
        draggable: '.player-chip',
    });

    function removePlaceholder(slot) {
        slot.querySelector('.drop-slot-placeholder')?.remove();
    }

    function ensurePlaceholder(slot) {
        if (slot.querySelector('.player-chip') || slot.querySelector('.drop-slot-bye')) {
            return;
        }

        if (!slot.querySelector('.drop-slot-placeholder')) {
            const placeholder = document.createElement('span');
            placeholder.className = 'drop-slot-placeholder';
            placeholder.textContent = 'Geser pemain…';
            slot.appendChild(placeholder);
        }
    }

    function enforceSingleChip(evt) {
        const slot = evt.to;
        const chips = slot.querySelectorAll('.player-chip');

        if (chips.length > 1) {
            const extra = chips[0];
            pool.appendChild(extra);
        }

        slots.forEach((dropSlot) => ensurePlaceholder(dropSlot));
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
                chip.classList.add('player-chip-warn');
            } else {
                badge.textContent = '';
                badge.classList.add('hidden');
                chip.classList.remove('player-chip-warn');
            }
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

    slots.forEach((slot) => ensurePlaceholder(slot));
    updateWarnings();
});
