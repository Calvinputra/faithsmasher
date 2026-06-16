(() => {
    const NAV_KEY = 'fs-nav';
    const prefetched = new Set();

    const progressBar = document.getElementById('page-progress');
    const skeleton = document.getElementById('page-skeleton');
    const pageContent = document.querySelector('.page-content');

    function isInternalLink(anchor) {
        if (!(anchor instanceof HTMLAnchorElement)) {
            return false;
        }

        if (!anchor.href || anchor.target === '_blank' || anchor.hasAttribute('download')) {
            return false;
        }

        if (anchor.origin !== window.location.origin) {
            return false;
        }

        if (anchor.hasAttribute('data-no-nav')) {
            return false;
        }

        if (anchor.dataset.formOpen || anchor.getAttribute('href')?.startsWith('#')) {
            return false;
        }

        return anchor.pathname !== window.location.pathname || anchor.search !== window.location.search;
    }

    function startNavigation() {
        try {
            sessionStorage.setItem(NAV_KEY, '1');
        } catch (_error) {
            /* ignore */
        }

        document.documentElement.classList.add('fs-nav-loading');
        progressBar?.classList.add('is-active');

        if (skeleton) {
            skeleton.hidden = false;
            skeleton.setAttribute('aria-hidden', 'false');
        }
    }

    function finishNavigation() {
        document.documentElement.classList.remove('fs-nav-loading');
        progressBar?.classList.remove('is-active');

        if (skeleton) {
            skeleton.hidden = true;
            skeleton.setAttribute('aria-hidden', 'true');
        }

        pageContent?.classList.add('is-ready');

        try {
            sessionStorage.removeItem(NAV_KEY);
        } catch (_error) {
            /* ignore */
        }
    }

    function prefetch(url) {
        if (prefetched.has(url)) {
            return;
        }

        prefetched.add(url);

        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        link.as = 'document';
        document.head.appendChild(link);
    }

    function markButtonLoading(button) {
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }

        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
    }

    document.addEventListener('click', (event) => {
        const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (!anchor || !isInternalLink(anchor)) {
            return;
        }

        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        startNavigation();
    }, true);

    document.addEventListener('mouseover', (event) => {
        const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (anchor && isInternalLink(anchor)) {
            prefetch(anchor.href);
        }
    }, { passive: true });

    document.addEventListener('touchstart', (event) => {
        const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (anchor && isInternalLink(anchor)) {
            prefetch(anchor.href);
        }
    }, { passive: true });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.hasAttribute('data-form-modal') || form.hasAttribute('data-no-nav')) {
            return;
        }

        if (form.method.toLowerCase() === 'get') {
            startNavigation();
            return;
        }

        progressBar?.classList.add('is-active');
        const submitter = form.querySelector('[type="submit"]');

        if (submitter instanceof HTMLButtonElement) {
            markButtonLoading(submitter);
        }
    }, true);

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            finishNavigation();
            document.querySelectorAll('button.is-loading').forEach((button) => {
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');
                button.disabled = false;
            });
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', finishNavigation, { once: true });
    } else {
        finishNavigation();
    }
})();
