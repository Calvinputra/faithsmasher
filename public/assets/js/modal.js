(() => {
    const BODY_LOCK_CLASS = 'modal-scroll-lock';

    function getModal(id) {
        return document.getElementById(id);
    }

    function openModal(id) {
        const modal = getModal(id);

        if (!modal) {
            return null;
        }

        modal.classList.add('modal-open');
        document.body.classList.add(BODY_LOCK_CLASS);

        const focusTarget = modal.querySelector('[data-field], input, select, textarea, button');

        if (focusTarget instanceof HTMLElement) {
            window.setTimeout(() => focusTarget.focus(), 50);
        }

        document.dispatchEvent(new CustomEvent('modal:open', { detail: { modalId: id, modal } }));

        return modal;
    }

    function closeModal(modal) {
        if (!(modal instanceof HTMLElement)) {
            return;
        }

        modal.classList.remove('modal-open');

        if (!document.querySelector('.modal.modal-open')) {
            document.body.classList.remove(BODY_LOCK_CLASS);
        }

        document.dispatchEvent(new CustomEvent('modal:close', { detail: { modalId: modal.id, modal } }));
    }

    function closeModalById(id) {
        const modal = getModal(id);

        if (modal) {
            closeModal(modal);
        }
    }

    function initModalCloseHandlers() {
        document.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const closeTrigger = target.closest('[data-modal-close]');

            if (!closeTrigger) {
                return;
            }

            const modal = closeTrigger.closest('.modal');

            if (modal) {
                event.preventDefault();
                closeModal(modal);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            const openModalEl = document.querySelector('.modal.modal-open');

            if (openModalEl instanceof HTMLElement) {
                closeModal(openModalEl);
            }
        });
    }

    window.AppModal = {
        open: openModal,
        close: closeModal,
        closeById: closeModalById,
    };

    document.addEventListener('DOMContentLoaded', initModalCloseHandlers);
})();
