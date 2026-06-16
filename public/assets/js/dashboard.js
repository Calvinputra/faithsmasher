document.addEventListener('DOMContentLoaded', () => {
    const openBtn = document.getElementById('mobile-menu-btn');
    const closeBtn = document.getElementById('mobile-drawer-close');
    const drawer = document.getElementById('mobile-drawer');
    const overlay = document.getElementById('mobile-drawer-overlay');

    if (!openBtn || !drawer || !overlay) {
        return;
    }

    const navLinks = () => drawer.querySelectorAll('.sidebar-link');

    const setLinkDelays = (open) => {
        navLinks().forEach((link, index) => {
            link.style.setProperty('--drawer-delay', open ? `${60 + index * 40}ms` : '0ms');
        });
    };

    const open = () => {
        overlay.setAttribute('aria-hidden', 'false');
        drawer.setAttribute('aria-hidden', 'false');
        setLinkDelays(true);
        overlay.classList.add('is-open');
        drawer.classList.add('is-open');
        document.body.classList.add('mobile-drawer-open');
        openBtn.setAttribute('aria-expanded', 'true');
    };

    const close = () => {
        setLinkDelays(false);
        overlay.classList.remove('is-open');
        drawer.classList.remove('is-open');
        document.body.classList.remove('mobile-drawer-open');
        openBtn.setAttribute('aria-expanded', 'false');

        window.setTimeout(() => {
            if (!drawer.classList.contains('is-open')) {
                overlay.setAttribute('aria-hidden', 'true');
                drawer.setAttribute('aria-hidden', 'true');
            }
        }, 320);
    };

    openBtn.setAttribute('aria-expanded', 'false');
    openBtn.setAttribute('aria-controls', 'mobile-drawer');

    openBtn.addEventListener('click', open);
    closeBtn?.addEventListener('click', close);
    overlay.addEventListener('click', close);

    drawer.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
            close();
        }
    });
});
