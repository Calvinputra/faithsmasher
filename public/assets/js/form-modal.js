(() => {
    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function formatAuditDate(value) {
        if (!value) {
            return '—';
        }

        const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
        const date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString('id-ID', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function renderFormModalAudit(form, data, mode) {
        const auditEl = form.querySelector('[data-form-modal-audit]');

        if (!auditEl) {
            return;
        }

        if (mode !== 'edit' || !data?.created_at) {
            auditEl.classList.add('hidden');
            auditEl.innerHTML = '';
            return;
        }

        const creator = data.created_by_name || '—';
        const createdAt = formatAuditDate(data.created_at);
        const hasUpdate = Boolean(
            data.updated_by_name
            && data.updated_at
            && (data.updated_by_name !== data.created_by_name || data.updated_at !== data.created_at),
        );

        let html = `<div class="audit-trail audit-trail-compact"><div class="audit-trail-items${hasUpdate ? ' audit-trail-items--multi' : ''}"><div class="audit-trail-item"><span class="audit-trail-item-icon audit-trail-item-icon-create" aria-hidden="true"><i class="ri-user-add-line"></i></span><div class="audit-trail-item-body"><span class="audit-trail-item-label">Dibuat</span><span class="audit-trail-item-user">${escapeHtml(creator)}</span><time class="audit-trail-item-time">${escapeHtml(createdAt)}</time></div></div>`;

        if (hasUpdate) {
            html += `<div class="audit-trail-item"><span class="audit-trail-item-icon audit-trail-item-icon-update" aria-hidden="true"><i class="ri-edit-circle-line"></i></span><div class="audit-trail-item-body"><span class="audit-trail-item-label">Terakhir diupdate</span><span class="audit-trail-item-user">${escapeHtml(data.updated_by_name)}</span><time class="audit-trail-item-time">${escapeHtml(formatAuditDate(data.updated_at))}</time></div></div>`;
        }

        html += '</div></div>';
        auditEl.innerHTML = html;
        auditEl.classList.remove('hidden');
    }

    const populators = {
        participant(form, payload, mode) {
            const data = payload || {};

            form.action = mode === 'edit' && data.id ? `/participants/${data.id}` : '/participants';
            form.querySelector('[data-field="name"]').value = data.name || '';
            form.querySelector('[data-field="phone"]').value = data.phone || '';

            window.FsSearchSelect?.syncFormSelects(form, {
                rank: data.rank || '',
                gender: data.gender || '',
                gms_source: data.gms_source || '',
            });

            const title = form.closest('.form-modal')?.querySelector('.form-modal-title');
            const submit = form.querySelector('[data-form-modal-submit]');

            if (title) {
                title.textContent = mode === 'edit' ? 'Edit Peserta' : 'Tambah Peserta';
            }

            if (submit) {
                submit.textContent = mode === 'edit' ? 'Simpan Perubahan' : 'Simpan';
            }
        },

        session(form, payload, mode) {
            const data = payload || {};

            form.action = mode === 'edit' && data.id ? `/sessions/${data.id}` : '/sessions';
            form.querySelector('[data-field="name"]').value = data.name || '';
            form.querySelector('[data-field="location"]').value = data.location || '';
            form.querySelector('[data-field="court_count"]').value = data.court_count ?? 1;

            const dateInput = form.querySelector('[data-field="session_date"]');

            if (dateInput) {
                dateInput.value = data.session_date || '';

                if (dateInput._flatpickr) {
                    if (data.session_date) {
                        dateInput._flatpickr.setDate(data.session_date, false);
                    } else {
                        dateInput._flatpickr.clear();
                    }
                }
            }

            const title = form.closest('.form-modal')?.querySelector('.form-modal-title');
            const submit = form.querySelector('[data-form-modal-submit]');

            if (title) {
                title.textContent = mode === 'edit' ? 'Edit Session' : 'Session Baru';
            }

            if (submit) {
                submit.textContent = mode === 'edit' ? 'Simpan Perubahan' : 'Buat Session';
            }
        },

        rule(form, payload, mode) {
            const data = payload || {};
            const sessionId = form.dataset.sessionId;

            if (!sessionId) {
                return;
            }

            form.action = mode === 'edit' && data.id
                ? `/sessions/${sessionId}/rules/${data.id}`
                : `/sessions/${sessionId}/rules`;

            form.querySelector('[data-field="name"]').value = data.name || '';
            form.querySelector('[data-field="win_points"]').value = data.win_points ?? 21;
            form.querySelector('[data-field="lose_points"]').value = data.lose_points ?? 0;

            const title = form.closest('.form-modal')?.querySelector('.form-modal-title');
            const submit = form.querySelector('[data-form-modal-submit]');

            if (title) {
                title.textContent = mode === 'edit' ? 'Edit Game Rule' : 'Tambah Game Rule';
            }

            if (submit) {
                submit.textContent = mode === 'edit' ? 'Simpan Perubahan' : 'Simpan';
            }
        },
    };

    function clearFormErrors(form) {
        form.querySelectorAll('[data-error-for]').forEach((el) => {
            el.textContent = '';
            el.classList.add('hidden');
        });

        form.querySelectorAll('.input-error').forEach((el) => el.classList.remove('input-error'));
        form.querySelectorAll('.ts-control.input-error').forEach((el) => el.classList.remove('input-error'));

        const globalError = form.querySelector('[data-form-modal-error]');

        if (globalError) {
            globalError.textContent = '';
            globalError.classList.add('hidden');
        }
    }

    function showFormErrors(form, errors, message) {
        clearFormErrors(form);

        Object.entries(errors || {}).forEach(([field, text]) => {
            const errorEl = form.querySelector(`[data-error-for="${field}"]`);
            const input = form.querySelector(`[data-field="${field}"], [name="${field}"]`);

            if (errorEl) {
                errorEl.textContent = text;
                errorEl.classList.remove('hidden');
            }

            if (input) {
                input.classList.add('input-error');

                if (input.tomselect?.wrapper) {
                    input.tomselect.wrapper.querySelector('.ts-control')?.classList.add('input-error');
                }
            }
        });

        const globalError = form.querySelector('[data-form-modal-error]');

        if (globalError && message) {
            globalError.textContent = message;
            globalError.classList.remove('hidden');
        }
    }

    function setSubmitting(form, submitting) {
        const submit = form.querySelector('[data-form-modal-submit]');

        if (submit instanceof HTMLButtonElement) {
            submit.disabled = submitting;
            submit.dataset.loading = submitting ? 'true' : 'false';
        }
    }

    async function submitFormModal(form) {
        clearFormErrors(form);
        setSubmitting(form, true);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                showFormErrors(form, data.errors || {}, data.message || 'Gagal menyimpan data.');
                return;
            }

            if (data.redirect) {
                window.location.assign(data.redirect);
            } else {
                window.location.reload();
            }
        } catch (_error) {
            showFormErrors(form, {}, 'Terjadi kesalahan jaringan. Coba lagi.');
        } finally {
            setSubmitting(form, false);
        }
    }

    function openFormModal(modalId, formType, mode, payload) {
        const modal = document.getElementById(modalId);

        if (!modal) {
            return;
        }

        const form = modal.querySelector('[data-form-modal]');

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        clearFormErrors(form);

        const populator = populators[formType];

        if (populator) {
            populator(form, payload, mode);
        }

        renderFormModalAudit(form, payload, mode);

        window.AppModal?.open(modalId);
    }

    function initFormModalTriggers() {
        document.querySelectorAll('[data-form-open]').forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();

                const modalId = trigger.getAttribute('data-form-open');
                const formType = trigger.getAttribute('data-form-type');
                const mode = trigger.getAttribute('data-form-mode') || 'create';
                let payload = null;

                const payloadRaw = trigger.getAttribute('data-form-payload');

                if (payloadRaw) {
                    try {
                        payload = JSON.parse(payloadRaw);
                    } catch (_error) {
                        payload = null;
                    }
                }

                if (modalId && formType) {
                    openFormModal(modalId, formType, mode, payload);
                }
            });
        });
    }

    function initFormModalSubmits() {
        document.querySelectorAll('[data-form-modal]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                submitFormModal(form);
            });
        });
    }

    function initSessionFlatpickr() {
        document.addEventListener('modal:open', (event) => {
            const modal = event.detail?.modal;

            if (!modal || modal.dataset.formModalRoot !== 'session') {
                return;
            }

            const dateInput = modal.querySelector('#session_form_date');

            if (!(dateInput instanceof HTMLInputElement) || dateInput._flatpickr) {
                return;
            }

            const initPicker = () => {
                if (typeof flatpickr === 'undefined' || dateInput._flatpickr) {
                    return;
                }

                flatpickr(dateInput, {
                    dateFormat: 'Y-m-d',
                    altInput: true,
                    altFormat: 'd M Y',
                    disableMobile: true,
                    allowInput: false,
                    clickOpens: true,
                    animate: true,
                    monthSelectorType: 'dropdown',
                    locale: { firstDayOfWeek: 1 },
                    onReady(_selectedDates, _dateStr, instance) {
                        instance.calendarContainer.classList.add('fs-flatpickr');
                    },
                });
            };

            window.FsFlatpickr?.load().then(initPicker).catch(() => {
                dateInput.removeAttribute('readonly');
                dateInput.type = 'date';
            });
        });
    }

    function initDeepLinks() {
        const params = new URLSearchParams(window.location.search);
        const modalId = params.get('modal');
        const editId = params.get('edit');

        window.setTimeout(() => {
            if (editId) {
                const trigger = document.querySelector(`[data-form-edit-id="${editId}"]`);
                trigger?.click();
            } else if (modalId) {
                const trigger = document.querySelector(`[data-form-open="${modalId}"][data-form-mode="create"]`);

                if (trigger) {
                    trigger.click();
                } else {
                    window.AppModal?.open(modalId);
                }
            }
        }, 0);

        if (modalId || editId) {
            params.delete('modal');
            params.delete('edit');
            const query = params.toString();
            const nextUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;
            window.history.replaceState({}, '', nextUrl);
        }
    }

    window.AppFormModal = {
        open: openFormModal,
    };

    document.addEventListener('DOMContentLoaded', () => {
        initFormModalTriggers();
        initFormModalSubmits();
        initSessionFlatpickr();
        initDeepLinks();
    });
})();
