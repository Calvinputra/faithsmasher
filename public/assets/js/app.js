document.addEventListener('DOMContentLoaded', () => {
    // Global Smooth Collapse Animation for all <details> elements
    document.querySelectorAll('details').forEach((details) => {
        const summary = details.querySelector('summary');
        if (!summary) return;
        
        let isAnimating = false;

        summary.addEventListener('click', (e) => {
            if (isAnimating) {
                e.preventDefault();
                return;
            }

            e.preventDefault();
            const isOpen = details.hasAttribute('open');
            isAnimating = true;

            if (isOpen) {
                // Animate closing
                const startHeight = `${details.offsetHeight}px`;
                details.style.height = startHeight;
                details.style.overflow = 'hidden';
                
                const animation = details.animate({
                    height: [startHeight, `${summary.offsetHeight}px`]
                }, {
                    duration: 250,
                    easing: 'cubic-bezier(0.4, 0.0, 0.2, 1)' // Tailwind ease-out
                });

                animation.onfinish = () => {
                    details.removeAttribute('open');
                    details.style.height = '';
                    details.style.overflow = '';
                    isAnimating = false;
                };
            } else {
                // Animate opening
                details.setAttribute('open', '');
                const endHeight = `${details.offsetHeight}px`;
                const startHeight = `${summary.offsetHeight}px`;
                
                details.style.height = startHeight;
                details.style.overflow = 'hidden';
                
                const animation = details.animate({
                    height: [startHeight, endHeight]
                }, {
                    duration: 250,
                    easing: 'cubic-bezier(0.4, 0.0, 0.2, 1)'
                });

                animation.onfinish = () => {
                    details.style.height = '';
                    details.style.overflow = '';
                    isAnimating = false;
                };
            }
        });
    });
});
