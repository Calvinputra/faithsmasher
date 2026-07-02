document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('bagan-request-modal');
    const form = document.getElementById('bagan-request-form');
    const rowsContainer = document.getElementById('bagan-request-rows');
    const addRowBtn = document.getElementById('bagan-request-add-row');
    const hiddenInput = document.getElementById('bagan-request-input');
    const baganNumLabel = document.getElementById('bagan-request-modal-num');
    const rowTemplate = document.getElementById('bagan-request-row-template');

    if (!modal || !form || !rowsContainer || !rowTemplate) {
        return;
    }

    let participants = [];

    try {
        participants = JSON.parse(document.getElementById('bagan-participants-data')?.textContent || '[]');
    } catch (_error) {
        participants = [];
    }

    function openModal(baganNum, actionUrl) {
        form.action = actionUrl;
        baganNumLabel.textContent = String(baganNum);
        rowsContainer.innerHTML = '';
        addRequestRow();

        if (window.AppModal?.open) {
            window.AppModal.open('bagan-request-modal');
        } else {
            modal.classList.add('modal-open');
            document.body.classList.add('modal-scroll-lock');
            document.dispatchEvent(new CustomEvent('modal:open', { detail: { modalId: 'bagan-request-modal', modal } }));
        }
    }

    function participantOptions(selectedId = '') {
        return participants.map((participant) => {
            const label = `${participant.name} (${participant.rank})`;
            const selected = String(participant.id) === String(selectedId) ? ' selected' : '';

            return `<option value="${participant.id}"${selected}>${label}</option>`;
        }).join('');
    }

    function initRowSelects(row) {
        row.querySelectorAll('.bagan-request-select').forEach((select) => {
            const placeholder = select.querySelector('option')?.textContent || 'Pilih pemain';
            select.innerHTML = `<option value="">${placeholder}</option>${participantOptions()}`;

            if (window.FsSearchSelect?.init) {
                window.FsSearchSelect.init(select);
            }
        });
    }

    function renumberRows() {
        rowsContainer.querySelectorAll('.bagan-request-row').forEach((row, index) => {
            const label = row.querySelector('[data-row-num]');

            if (label) {
                label.textContent = String(index + 1);
            }
        });
    }

    function addRequestRow() {
        const fragment = rowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('.bagan-request-row');

        rowsContainer.appendChild(fragment);
        const appended = rowsContainer.lastElementChild;

        if (!(appended instanceof HTMLElement)) {
            return;
        }

        initRowSelects(appended);

        appended.querySelector('.bagan-request-row-remove')?.addEventListener('click', () => {
            if (rowsContainer.querySelectorAll('.bagan-request-row').length <= 1) {
                window.FSToast?.warning('Minimal 1 baris request.', 'Request match');
                return;
            }

            appended.remove();
            renumberRows();
        });

        renumberRows();
    }

    addRowBtn?.addEventListener('click', () => addRequestRow());

    document.querySelectorAll('.btn-bagan-request').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            openModal(button.dataset.bagan, button.dataset.requestUrl);
        });
    });

    form.addEventListener('submit', (event) => {
        const requests = [];
        const used = new Set();
        let hasError = false;

        rowsContainer.querySelectorAll('.bagan-request-row').forEach((row, index) => {
            const entry = {};
            const line = index + 1;

            row.querySelectorAll('.bagan-request-select').forEach((select) => {
                const field = select.dataset.field;

                if (field) {
                    entry[field] = select.value;
                }
            });

            const ids = [entry.p1, entry.p2, entry.p3, entry.p4];

            if (ids.some((id) => !id)) {
                hasError = true;
                window.FSToast?.error(`Match request #${line}: lengkapi ke-4 pemain.`, 'Request match');
                return;
            }

            for (const id of ids) {
                if (used.has(id)) {
                    hasError = true;
                    window.FSToast?.error(`Match request #${line}: pemain dobel antar request.`, 'Request match');
                    return;
                }

                used.add(id);
            }

            if (entry.p1 === entry.p2 || entry.p3 === entry.p4) {
                hasError = true;
                window.FSToast?.error(`Match request #${line}: partner dalam satu tim harus berbeda.`, 'Request match');
                return;
            }

            requests.push({
                p1: Number(entry.p1),
                p2: Number(entry.p2),
                p3: Number(entry.p3),
                p4: Number(entry.p4),
            });
        });

        if (hasError) {
            event.preventDefault();
            return;
        }

        hiddenInput.value = JSON.stringify(requests);
    });

    document.addEventListener('modal:open', (event) => {
        if (event.detail?.modalId !== 'bagan-request-modal') {
            return;
        }

        rowsContainer.querySelectorAll('.bagan-request-select').forEach((select) => {
            if (window.FsSearchSelect?.init) {
                window.FsSearchSelect.init(select);
            }
        });
    });
});
