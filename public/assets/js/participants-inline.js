document.addEventListener('DOMContentLoaded', () => {
    const menu = document.getElementById('inline-edit-menu');
    const configNode = document.getElementById('inline-edit-config');

    if (!menu || !configNode) {
        return;
    }

    let config = {};

    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch {
        return;
    }

    let activeTrigger = null;
    let isSaving = false;

    const pillClasses = [
        'inline-pill-empty',
        'inline-pill-rank',
        'inline-pill-male',
        'inline-pill-female',
        'inline-pill-gms',
        'inline-pill-vip',
        'inline-pill-cp',
        'inline-pill-puri',
        'inline-pill-pluit',
        'inline-pill-gancit',
        'inline-pill-alsut',
    ];

    const gmsPillMap = {
        GMS: 'inline-pill-gms',
        VIP: 'inline-pill-vip',
        CP: 'inline-pill-cp',
        PURI: 'inline-pill-puri',
        PLUIT: 'inline-pill-pluit',
        GANCIT: 'inline-pill-gancit',
        ALSUT: 'inline-pill-alsut',
    };

    const closeMenu = () => {
        menu.classList.add('hidden');
        menu.innerHTML = '';
        activeTrigger?.setAttribute('aria-expanded', 'false');
        activeTrigger = null;
    };

    const positionMenu = (trigger) => {
        const rect = trigger.getBoundingClientRect();
        const menuWidth = Math.max(rect.width, 140);
        let left = rect.left;
        let top = rect.bottom + 6;

        if (left + menuWidth > window.innerWidth - 12) {
            left = window.innerWidth - menuWidth - 12;
        }

        if (top + 220 > window.innerHeight) {
            top = rect.top - 6;
            menu.style.transform = 'translateY(-100%)';
        } else {
            menu.style.transform = '';
        }

        menu.style.width = `${menuWidth}px`;
        menu.style.left = `${Math.max(12, left)}px`;
        menu.style.top = `${top}px`;
    };

    const applyPillClass = (pill, pillClass) => {
        pillClasses.forEach((cls) => pill.classList.remove(cls));
        pill.classList.add(pillClass || 'inline-pill-empty');
    };

    const buildOptionPillClass = (field, value) => {
        if (field === 'rank') {
            return 'inline-pill-rank';
        }

        if (field === 'gender') {
            return value === 'male' ? 'inline-pill-male' : value === 'female' ? 'inline-pill-female' : 'inline-pill-empty';
        }

        return gmsPillMap[value] || 'inline-pill-empty';
    };

    const renderMenu = (trigger) => {
        const field = trigger.dataset.field;
        const fieldConfig = config[field];

        if (!fieldConfig) {
            return;
        }

        menu.innerHTML = '';
        const currentValue = trigger.dataset.value || '';

        Object.entries(fieldConfig.options || {}).forEach(([value, label]) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'inline-edit-option';
            option.dataset.value = value;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', value === currentValue ? 'true' : 'false');

            const pill = document.createElement('span');
            pill.className = `inline-edit-pill ${buildOptionPillClass(field, value)}`;
            pill.textContent = label;
            option.appendChild(pill);

            if (value === currentValue) {
                option.classList.add('inline-edit-option-active');
            }

            option.addEventListener('click', () => saveValue(trigger, field, value));
            menu.appendChild(option);
        });

        if (fieldConfig.allowClear) {
            const clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'inline-edit-option inline-edit-option-clear';
            clearBtn.dataset.value = '';
            clearBtn.setAttribute('role', 'option');
            clearBtn.textContent = fieldConfig.clearLabel || '— Kosongkan';
            clearBtn.addEventListener('click', () => saveValue(trigger, field, ''));
            menu.appendChild(clearBtn);
        }
    };

    const saveValue = async (trigger, field, value) => {
        if (isSaving) {
            return;
        }

        const participantId = trigger.dataset.participantId;
        const previousValue = trigger.dataset.value || '';

        if (value === previousValue) {
            closeMenu();
            return;
        }

        isSaving = true;
        trigger.classList.add('inline-edit-btn-saving');

        try {
            const response = await fetch(`/participants/${participantId}/inline`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    Accept: 'application/json',
                },
                body: new URLSearchParams({ field, value }),
            });

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Gagal menyimpan');
            }

            trigger.dataset.value = data.value ?? '';
            const pill = trigger.querySelector('[data-inline-pill]');

            if (pill) {
                pill.textContent = data.label || '—';
                applyPillClass(pill, data.pillClass);
            }

            window.FSToast?.update(data.label ? `${data.label} disimpan` : 'Data dikosongkan', 'Diperbarui');
            closeMenu();
        } catch (error) {
            window.FSToast?.error(error.message || 'Gagal menyimpan perubahan', 'Error');
        } finally {
            isSaving = false;
            trigger.classList.remove('inline-edit-btn-saving');
        }
    };

    document.querySelectorAll('[data-inline-edit]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.stopPropagation();

            if (activeTrigger === trigger && !menu.classList.contains('hidden')) {
                closeMenu();
                return;
            }

            activeTrigger = trigger;
            renderMenu(trigger);
            menu.classList.remove('hidden');
            trigger.setAttribute('aria-expanded', 'true');
            positionMenu(trigger);
        });
    });

    document.addEventListener('click', (event) => {
        if (menu.contains(event.target) || event.target.closest('[data-inline-edit]')) {
            return;
        }

        closeMenu();
    });

    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
});
