(() => {
    const textarea = document.getElementById('bulk_paste');
    const preview = document.getElementById('assign-paste-preview');
    const table = document.getElementById('assign-participant-table');

    if (!textarea) {
        return;
    }

    function normalizeName(value) {
        return value.trim().toLowerCase();
    }

    function extractNames(raw) {
        const names = new Set();

        raw.split('\n').forEach((line) => {
            const trimmed = line.trim();

            if (trimmed === '') {
                return;
            }

            const delimiter = trimmed.includes('\t') ? '\t' : (trimmed.includes(';') ? ';' : ',');
            const cells = trimmed.split(delimiter).map((cell) => cell.trim()).filter(Boolean);

            if (cells.length === 0) {
                return;
            }

            const header = cells.join(' ').toLowerCase();

            if (/^(no|#|nomor|nama|name|rank|peserta)\b/.test(header) && cells.length <= 3) {
                return;
            }

            let name = cells[0];

            if (/^\d+$/.test(cells[0]) && cells.length >= 2) {
                name = cells[1];
            } else if (cells.length >= 2 && /^[ABC][+-]?$/i.test(cells[1])) {
                name = cells[0];
            }

            const key = normalizeName(name);

            if (key !== '') {
                names.add(key);
            }
        });

        return names;
    }

    function syncCheckboxes() {
        const names = extractNames(textarea.value);
        let matched = 0;

        if (table) {
            table.querySelectorAll('[data-participant-name]').forEach((row) => {
                const key = row.getAttribute('data-participant-name') || '';
                const checkbox = row.querySelector('input[type="checkbox"]');

                if (!(checkbox instanceof HTMLInputElement)) {
                    return;
                }

                const isMatch = names.has(key);

                checkbox.checked = isMatch;

                row.classList.toggle('assign-row-matched', isMatch);

                if (isMatch) {
                    matched += 1;
                }
            });
        }

        if (!preview) {
            return;
        }

        if (names.size === 0) {
            preview.classList.add('hidden');
            preview.textContent = '';
            return;
        }

        preview.classList.remove('hidden');
        preview.textContent = `${names.size} nama terdeteksi · ${matched} cocok di tabel ini (sisanya diproses saat submit)`;
    }

    textarea.addEventListener('input', syncCheckboxes);
    textarea.addEventListener('paste', () => window.setTimeout(syncCheckboxes, 0));
})();
