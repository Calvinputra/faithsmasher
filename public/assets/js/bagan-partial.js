(() => {
    const PARTIAL_HEADER = 'X-Bagan-Partial';

    function partialHeaders() {
        return {
            [PARTIAL_HEADER]: '1',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
    }

    function getExportRoot() {
        return document.getElementById('bagan-export-root');
    }

    function replaceBaganSection(baganNum, html) {
        const current = document.querySelector(`.bagan-preview-section[data-bagan-num="${baganNum}"]`);

        if (!current || !html) {
            return null;
        }

        window.FsBaganManual?.destroySection(current);

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const next = template.content.firstElementChild;

        if (!(next instanceof HTMLElement)) {
            return null;
        }

        current.replaceWith(next);
        initBaganSection(next);

        return next;
    }

    function initBaganSection(section) {
        if (!(section instanceof HTMLElement)) {
            return;
        }

        window.FsBaganManual?.bindControls?.();
        window.FsBaganManual?.initSection(section);
        window.FsBaganChecklist?.initSection(section);
        window.FsBaganPartial?.bindRegenerateForms(section);
    }

    function initAllBaganSections() {
        document.querySelectorAll('.bagan-preview-section[data-bagan-num]').forEach((section) => {
            initBaganSection(section);
        });
    }

    function showToast(payload) {
        if (!payload?.message) {
            return;
        }

        const type = payload.type === 'error' ? 'error' : payload.type === 'warning' ? 'warning' : 'success';
        window.FSToast?.[type]?.(payload.message, payload.title || 'Bagan');
    }

    async function handlePartialResponse(response) {
        const rawBody = await response.text();
        let payload = null;

        try {
            payload = JSON.parse(rawBody);
        } catch (_error) {
            const fallbackMessage = response.ok
                ? 'Server mengirim response yang tidak valid.'
                : 'Server error saat memperbarui bagan. Cek log / debug detail.';

            throw new Error(fallbackMessage);
        }

        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Gagal memperbarui bagan.');
        }

        if (payload.baganNum && payload.html) {
            replaceBaganSection(String(payload.baganNum), payload.html);
        }

        showToast(payload);

        return payload;
    }

    async function submitPartialForm(form, baganNum) {
        const submitButton = form.querySelector('[type="submit"]');

        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
        }

        try {
            const response = await fetch(form.action, {
                method: form.method || 'POST',
                headers: partialHeaders(),
                body: new FormData(form),
            });

            return await handlePartialResponse(response);
        } finally {
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = false;
                submitButton.classList.remove('is-loading');
            }
        }
    }

    function bindRegenerateForms(scope) {
        (scope || document).querySelectorAll('[data-bagan-regenerate-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.partialBound === '1') {
                return;
            }

            form.dataset.partialBound = '1';

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                event.stopPropagation();

                const confirmMessage = form.querySelector('[data-confirm]')?.dataset.confirm;

                if (confirmMessage && !window.confirm(confirmMessage)) {
                    return;
                }

                const baganNum = form.dataset.bagan || form.querySelector('[name="target_bagan"]')?.value;

                try {
                    await submitPartialForm(form, baganNum);
                } catch (error) {
                    window.FSToast?.error(error.message || 'Generate bagan gagal.', 'Generate bagan');
                }
            });
        });
    }

    function collectManualPairings(baganNum) {
        const pairings = {};
        const baganScope = baganNum
            ? `.bagan-preview-section[data-bagan-num="${baganNum}"] tr[data-match-id]`
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

        return pairings;
    }

    function bindManualForm() {
        const form = document.getElementById('manual-form');

        if (!(form instanceof HTMLFormElement) || form.dataset.partialBound === '1') {
            return;
        }

        form.dataset.partialBound = '1';

        form.addEventListener('submit', async (event) => {
            if (!getExportRoot()) {
                return;
            }

            event.preventDefault();

            const baganNum = window.FsBaganManual?.getSavingBagan?.();
            const pairingsInput = document.getElementById('pairings-input');
            const baganInput = document.getElementById('manual-bagan-input');
            const pairings = collectManualPairings(baganNum);

            if (pairingsInput) {
                pairingsInput.value = JSON.stringify(pairings);
            }

            if (baganInput) {
                baganInput.value = baganNum || '';
            }

            const formData = new FormData(form);
            formData.set('pairings', JSON.stringify(pairings));

            if (baganNum) {
                formData.set('bagan_num', baganNum);
            }

            const saveButton = baganNum
                ? document.querySelector(`.btn-bagan-save[data-bagan="${baganNum}"]`)
                : null;

            if (saveButton instanceof HTMLButtonElement) {
                saveButton.disabled = true;
                saveButton.classList.add('is-loading');
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: partialHeaders(),
                    body: formData,
                });

                const payload = await handlePartialResponse(response);

                if (payload.baganNum) {
                    const section = document.querySelector(
                        `.bagan-preview-section[data-bagan-num="${payload.baganNum}"]`,
                    );

                    if (section instanceof HTMLElement) {
                        window.FsBaganManual?.deactivateBagan(section);
                    }
                }

                window.FsBaganManual?.resetSavingBagan?.();
            } catch (error) {
                window.FSToast?.error(error.message || 'Gagal menyimpan bagan.', 'Manual setup');
            } finally {
                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.disabled = false;
                    saveButton.classList.remove('is-loading');
                }
            }
        });
    }

    function bindRequestForm() {
        const form = document.getElementById('bagan-request-form');

        if (!(form instanceof HTMLFormElement) || form.dataset.partialBound === '1') {
            return;
        }

        form.dataset.partialBound = '1';

        form.addEventListener('submit', async (event) => {
            if (!getExportRoot()) {
                return;
            }

            if (!window.FsBaganRequest?.validateAndSerialize()) {
                event.preventDefault();
                return;
            }

            event.preventDefault();

            const submitButton = form.querySelector('[type="submit"]');

            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = true;
                submitButton.classList.add('is-loading');
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: partialHeaders(),
                    body: new FormData(form),
                });

                await handlePartialResponse(response);
                window.AppModal?.closeById('bagan-request-modal');
            } catch (error) {
                window.FSToast?.error(error.message || 'Request match gagal.', 'Request match');
            } finally {
                if (submitButton instanceof HTMLButtonElement) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('is-loading');
                }
            }
        });
    }

    window.FsBaganPartial = {
        initSection: initBaganSection,
        initAll: initAllBaganSections,
        bindRegenerateForms,
        replaceBaganSection,
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (!getExportRoot()) {
            return;
        }

        bindRegenerateForms(document);
        bindManualForm();
        bindRequestForm();
        initAllBaganSections();
    });
})();
