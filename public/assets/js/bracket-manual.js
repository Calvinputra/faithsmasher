(() => {
    const sortablesByBagan = new Map();
    let savingBagan = null;

    function deactivateBagan(section) {
        const bagan = section.dataset.baganNum;
        sortablesByBagan.get(bagan)?.forEach((sortable) => sortable.option('disabled', true));

        section.classList.remove('is-editing');
        section.querySelector('.bagan-manual-table')?.classList.remove('is-editable');
        section.querySelector('[data-bagan-editing-badge]')?.classList.add('hidden');
        section.querySelector('.btn-bagan-save')?.classList.add('hidden');
        section.querySelector('.btn-bagan-manual-toggle')?.classList.remove('is-active');
    }

    function updateWarnings(section) {
        const scope = section || document;
        const sections = section instanceof HTMLElement
            ? [section]
            : document.querySelectorAll('.bagan-preview-section');

        sections.forEach((baganSection) => {
            const counts = {};

            baganSection.querySelectorAll('.drop-slot .player-chip').forEach((chip) => {
                const id = chip.dataset.id;
                counts[id] = (counts[id] || 0) + 1;
            });

            baganSection.querySelectorAll('.player-chip').forEach((chip) => {
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

    function swapChips(evt) {
        const newSlot = evt.to;
        const oldSlot = evt.from;
        const draggedChip = evt.item;
        const chips = Array.from(newSlot.querySelectorAll('.player-chip'));
        const existingChip = chips.find((chip) => chip !== draggedChip);

        if (existingChip && oldSlot) {
            oldSlot.appendChild(existingChip);
        }

        const section = newSlot.closest('.bagan-preview-section');
        updateWarnings(section instanceof HTMLElement ? section : null);
    }

    function initManualSection(section) {
        if (!(section instanceof HTMLElement) || typeof Sortable === 'undefined') {
            return;
        }

        const bagan = section.dataset.baganNum;

        if (!bagan) {
            return;
        }

        sortablesByBagan.get(bagan)?.forEach((sortable) => sortable.destroy());
        sortablesByBagan.delete(bagan);

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
        updateWarnings(section);
    }

    function destroyManualSection(section) {
        const bagan = section?.dataset?.baganNum;

        if (!bagan) {
            return;
        }

        sortablesByBagan.get(bagan)?.forEach((sortable) => sortable.destroy());
        sortablesByBagan.delete(bagan);
    }

    function bindManualControls() {
        document.querySelectorAll('.btn-bagan-save').forEach((button) => {
            if (button.dataset.manualBound === '1') {
                return;
            }

            button.dataset.manualBound = '1';
            button.addEventListener('click', () => {
                savingBagan = button.dataset.bagan || null;
            });
        });

        document.querySelectorAll('.btn-bagan-manual-toggle').forEach((button) => {
            if (button.dataset.manualBound === '1') {
                return;
            }

            button.dataset.manualBound = '1';
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
    }

    function prepareManualFormSubmit() {
        const form = document.getElementById('manual-form');
        const pairingsInput = document.getElementById('pairings-input');
        const baganInput = document.getElementById('manual-bagan-input');

        if (!form || !pairingsInput || form.dataset.manualSubmitBound === '1') {
            return;
        }

        form.dataset.manualSubmitBound = '1';

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
    }

    function initAllManualSections() {
        document.querySelectorAll('.bagan-preview-section[data-bagan-num]').forEach((section) => {
            initManualSection(section);
        });
    }

    window.FsBaganManual = {
        initSection: initManualSection,
        destroySection: destroyManualSection,
        deactivateBagan,
        bindControls: bindManualControls,
        initAll: initAllManualSections,
        getSavingBagan: () => savingBagan,
        resetSavingBagan: () => {
            savingBagan = null;
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        bindManualControls();
        prepareManualFormSubmit();
        initAllManualSections();
    });
})();
