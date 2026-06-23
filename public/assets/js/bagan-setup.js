document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('bagan-setup-form');

    if (!form) {
        return;
    }

    const countInput = form.querySelector('#bagan_count');
    const globalPanel = form.querySelector('[data-bagan-global-panel]');
    const perPanel = form.querySelector('[data-bagan-per-panel]');
    const perGrid = form.querySelector('#bagan-per-grid');
    const scopeRadios = form.querySelectorAll('[data-bagan-scope-toggle]');
    const globalModeSelect = form.querySelector('#bagan_pairing_mode');

    let pairingModes = { rank: 'Per Rank (skill serupa)', gender: 'Per Gender' };

    if (perGrid?.dataset.pairingModes) {
        try {
            pairingModes = JSON.parse(perGrid.dataset.pairingModes);
        } catch {
            // keep defaults
        }
    }

    const currentScope = () => {
        const checked = form.querySelector('[data-bagan-scope-toggle]:checked');

        return checked?.value === 'per_bagan' ? 'per_bagan' : 'global';
    };

    const syncScopePanels = () => {
        const isPerBagan = currentScope() === 'per_bagan';
        const hint = form.querySelector('[data-bagan-scope-hint]');

        globalPanel?.classList.toggle('hidden', isPerBagan);
        perPanel?.classList.toggle('hidden', !isPerBagan);

        if (hint) {
            hint.textContent = isPerBagan
                ? 'Anda dapat mengatur aturan yang berbeda untuk setiap bagan.'
                : 'Semua bagan akan menggunakan aturan yang sama.';
        }
    };

    const buildPerBaganRows = () => {
        if (!perGrid || !countInput) {
            return;
        }

        const count = Math.max(1, Math.min(20, parseInt(countInput.value, 10) || 1));
        const defaultMode = globalModeSelect?.value || 'rank';
        const existing = {};

        perGrid.querySelectorAll('[data-bagan-per-row]').forEach((row) => {
            const select = row.querySelector('select');
            const label = row.querySelector('.bagan-per-label')?.textContent?.match(/\d+/);

            if (select && label) {
                existing[label[0]] = select.value;
            }
        });

        perGrid.innerHTML = '';

        for (let i = 1; i <= count; i += 1) {
            const row = document.createElement('div');
            row.className = 'bagan-per-row';
            row.dataset.baganPerRow = 'true';

            const label = document.createElement('span');
            label.className = 'bagan-per-label';
            label.textContent = `Bagan ${i}`;

            const select = document.createElement('select');
            select.name = `bagan_modes[${i}]`;
            select.className = 'input-field bagan-per-select';
            select.dataset.searchSelect = 'true';

            Object.entries(pairingModes).forEach(([value, text]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = text;

                if ((existing[i] || defaultMode) === value) {
                    option.selected = true;
                }

                select.appendChild(option);
            });

            row.appendChild(label);
            row.appendChild(select);
            perGrid.appendChild(row);
        }

        window.FsSearchSelect?.initAll(perGrid);
    };

    scopeRadios.forEach((radio) => {
        radio.addEventListener('change', syncScopePanels);
    });

    countInput?.addEventListener('change', buildPerBaganRows);
    countInput?.addEventListener('input', buildPerBaganRows);

    globalModeSelect?.addEventListener('change', () => {
        if (currentScope() === 'per_bagan') {
            buildPerBaganRows();
        }
    });

    syncScopePanels();
});
