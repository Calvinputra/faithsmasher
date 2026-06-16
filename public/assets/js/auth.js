document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.password-toggle-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.dataset.target;
            const input = targetId ? document.getElementById(targetId) : null;

            if (!input) {
                return;
            }

            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            button.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            button.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');

            button.querySelector('.password-toggle-show')?.classList.toggle('hidden', isHidden);
            button.querySelector('.password-toggle-hide')?.classList.toggle('hidden', !isHidden);
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach((el) => {
        el.addEventListener('click', () => {
            const modal = el.closest('.modal');

            if (modal) {
                modal.classList.remove('modal-open');
            }
        });
    });
});
