(() => {
    const CSS_HREF = '/assets/vendor/flatpickr/flatpickr.min.css';
    const JS_SRC = '/assets/vendor/flatpickr/flatpickr.min.js';

    let loadPromise = null;

    function loadStylesheet(href) {
        if (document.querySelector(`link[href="${href}"]`)) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.onload = () => resolve();
            link.onerror = () => reject(new Error('Failed to load stylesheet'));
            document.head.appendChild(link);
        });
    }

    function loadScript(src) {
        if (typeof flatpickr !== 'undefined') {
            return Promise.resolve();
        }

        const existing = document.querySelector(`script[src="${src}"]`);

        if (existing) {
            return new Promise((resolve, reject) => {
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('Failed to load script')), { once: true });
            });
        }

        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.defer = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load script'));
            document.head.appendChild(script);
        });
    }

    window.FsFlatpickr = {
        load() {
            if (typeof flatpickr !== 'undefined') {
                return Promise.resolve();
            }

            if (!loadPromise) {
                loadPromise = loadStylesheet(CSS_HREF)
                    .then(() => loadScript(JS_SRC))
                    .catch((error) => {
                        loadPromise = null;
                        throw error;
                    });
            }

            return loadPromise;
        },
    };
})();
