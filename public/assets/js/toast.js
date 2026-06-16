(() => {
    const ICONS = {
        success: 'ri-checkbox-circle-line',
        create: 'ri-add-circle-line',
        update: 'ri-edit-circle-line',
        delete: 'ri-delete-bin-line',
        error: 'ri-error-warning-line',
        warning: 'ri-alert-line',
        info: 'ri-information-line',
    };

    const DEFAULT_DURATION = 5200;

    class ToastManager {
        constructor(container) {
            this.container = container;
        }

        show(options) {
            const type = options.type || 'info';
            const title = options.title || this.defaultTitle(type);
            const message = options.message || '';
            const duration = Number.isFinite(options.duration) ? options.duration : DEFAULT_DURATION;

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="toast-icon" aria-hidden="true">
                    <i class="${ICONS[type] || ICONS.info}"></i>
                </div>
                <div class="toast-body">
                    <p class="toast-title">${this.escape(title)}</p>
                    <p class="toast-message">${this.escape(message)}</p>
                </div>
                <button type="button" class="toast-close" aria-label="Tutup notifikasi">
                    <i class="ri-close-line"></i>
                </button>
                <span class="toast-progress" style="animation-duration: ${duration}ms"></span>
            `;

            const close = () => this.dismiss(toast);
            toast.querySelector('.toast-close')?.addEventListener('click', close);

            this.container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('toast-visible'));

            const timer = window.setTimeout(close, duration);
            toast.dataset.timer = String(timer);

            toast.addEventListener('mouseenter', () => {
                window.clearTimeout(Number(toast.dataset.timer));
                toast.querySelector('.toast-progress')?.classList.add('toast-progress-paused');
            });

            toast.addEventListener('mouseleave', () => {
                const progress = toast.querySelector('.toast-progress');
                progress?.classList.remove('toast-progress-paused');
                toast.dataset.timer = String(window.setTimeout(close, 1800));
            });
        }

        dismiss(toast) {
            if (!toast || toast.classList.contains('toast-leaving')) {
                return;
            }

            window.clearTimeout(Number(toast.dataset.timer));
            toast.classList.remove('toast-visible');
            toast.classList.add('toast-leaving');

            window.setTimeout(() => toast.remove(), 260);
        }

        defaultTitle(type) {
            const titles = {
                success: 'Berhasil',
                create: 'Berhasil dibuat',
                update: 'Berhasil diperbarui',
                delete: 'Berhasil dihapus',
                error: 'Terjadi kesalahan',
                warning: 'Perhatian',
                info: 'Informasi',
            };

            return titles[type] || titles.info;
        }

        escape(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }
    }

    function boot() {
        const container = document.getElementById('toast-container');

        if (!container) {
            return;
        }

        const manager = new ToastManager(container);
        window.FSToast = {
            show: (options) => manager.show(options),
            success: (message, title) => manager.show({ type: 'success', message, title }),
            create: (message, title) => manager.show({ type: 'create', message, title }),
            update: (message, title) => manager.show({ type: 'update', message, title }),
            delete: (message, title) => manager.show({ type: 'delete', message, title }),
            error: (message, title) => manager.show({ type: 'error', message, title }),
            warning: (message, title) => manager.show({ type: 'warning', message, title }),
            info: (message, title) => manager.show({ type: 'info', message, title }),
        };

        const flashNode = document.getElementById('fs-flash-data');

        if (flashNode?.textContent) {
            try {
                const flash = JSON.parse(flashNode.textContent);

                if (flash?.message) {
                    manager.show(flash);
                }
            } catch {
                // ignore invalid flash payload
            }
        }
    }

    document.addEventListener('DOMContentLoaded', boot);
})();
