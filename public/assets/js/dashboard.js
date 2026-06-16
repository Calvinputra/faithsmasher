document.addEventListener('DOMContentLoaded', () => {
    const openBtn = document.getElementById('mobile-menu-btn');
    const closeBtn = document.getElementById('mobile-drawer-close');
    const drawer = document.getElementById('mobile-drawer');
    const overlay = document.getElementById('mobile-drawer-overlay');

    if (!openBtn || !drawer || !overlay) {
        return;
    }

    const open = () => {
        drawer.classList.remove('hidden');
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    const close = () => {
        drawer.classList.add('hidden');
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    };

    openBtn.addEventListener('click', open);
    closeBtn?.addEventListener('click', close);
    overlay.addEventListener('click', close);
});
