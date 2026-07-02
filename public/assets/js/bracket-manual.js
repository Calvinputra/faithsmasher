document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('manual-form');
    const pairingsInput = document.getElementById('pairings-input');
    const baganInput = document.getElementById('manual-bagan-input');

    if (!form || typeof Sortable === 'undefined') {
        return;
    }

    let savingBagan = null;

    document.querySelectorAll('.btn-bagan-save').forEach((button) => {
        button.addEventListener('click', () => {
            savingBagan = button.dataset.bagan || null;
        });
    });

    const sortablesByBagan = new Map();

    document.querySelectorAll('.bagan-preview-section[data-bagan-num]').forEach((section) => {
        const bagan = section.dataset.baganNum;
        const sortables = [];

        section.querySelectorAll('.drop-slot').forEach((slot) => {
            sortables.push(new Sortable(slot, {
                group: `players-bagan-${bagan}`,
                animation: 150,
                disabled: true,
                draggable: '.player-chip',
                onAdd: (evt) => swapChips(evt),
            }));
        });

        sortablesByBagan.set(bagan, sortables);
    });

    function deactivateBagan(section) {
        const bagan = section.dataset.baganNum;
        sortablesByBagan.get(bagan)?.forEach((sortable) => sortable.option('disabled', true));

        section.classList.remove('is-editing');
        section.querySelector('.bagan-manual-table')?.classList.remove('is-editable');
        section.querySelector('[data-bagan-editing-badge]')?.classList.add('hidden');
        section.querySelector('.btn-bagan-save')?.classList.add('hidden');
        section.querySelector('.btn-bagan-manual-toggle')?.classList.remove('is-active');
    }

    document.querySelectorAll('.btn-bagan-manual-toggle').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();

            const bagan = button.dataset.bagan;
            const section = document.querySelector(`.bagan-preview-section[data-bagan-num="${bagan}"]`);

            if (!section) {
                return;
            }

            const willActivate = !section.classList.contains('is-editing');

            document.querySelectorAll('.bagan-preview-section.is-editing').forEach((activeSection) => {
                if (activeSection !== section) {
                    deactivateBagan(activeSection);
                }
            });

            if (willActivate) {
                sortablesByBagan.get(bagan)?.forEach((sortable) => sortable.option('disabled', false));
                section.classList.add('is-editing');
                section.querySelector('.bagan-manual-table')?.classList.add('is-editable');
                section.querySelector('[data-bagan-editing-badge]')?.classList.remove('hidden');
                section.querySelector('.btn-bagan-save')?.classList.remove('hidden');
                button.classList.add('is-active');
                section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                deactivateBagan(section);
            }
        });
    });

    function swapChips(evt) {
        const newSlot = evt.to;
        const oldSlot = evt.from;
        const draggedChip = evt.item;
        const chips = Array.from(newSlot.querySelectorAll('.player-chip'));
        const existingChip = chips.find((chip) => chip !== draggedChip);

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

                if (!badge) {
                    return;
                }

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
        const baganScope = savingBagan
            ? `.bagan-preview-section[data-bagan-num="${savingBagan}"] tr[data-match-id]`
            : 'tr[data-match-id]';

        document.querySelectorAll(baganScope).forEach((row) => {
            const matchId = row.dataset.matchId;
            const p1Slot = row.querySelector('[data-slot="p1"] .player-chip');
            const p2Slot = row.querySelector('[data-slot="p2"] .player-chip');

            pairings[matchId] = {
                p1: p1Slot ? p1Slot.dataset.id : '',
                p2: p2Slot ? p2Slot.dataset.id : '',
            };
        });

        pairingsInput.value = JSON.stringify(pairings);

        if (baganInput) {
            baganInput.value = savingBagan || '';
        }
    });

    updateWarnings();
});
