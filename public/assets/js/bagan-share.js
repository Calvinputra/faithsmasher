document.addEventListener('DOMContentLoaded', () => {
    const downloadBtn = document.getElementById('bagan-download-btn');
    const copyBtn = document.getElementById('bagan-copy-link-btn');
    const shareInput = document.getElementById('bagan-share-url');
    const exportRoot = document.getElementById('bagan-export-root');

    downloadBtn?.addEventListener('click', () => {
        void downloadBaganImage(exportRoot, downloadBtn);
    });

    copyBtn?.addEventListener('click', () => {
        void copyShareLink(shareInput, copyBtn);
    });
});

async function downloadBaganImage(exportRoot, button) {
    if (!exportRoot || typeof htmlToImage === 'undefined') {
        window.FSToast?.error('Fitur unduh belum siap. Muat ulang halaman.', 'Unduh gagal');
        return;
    }

    const originalText = button?.innerHTML;
    button.disabled = true;

    if (button) {
        button.innerHTML = 'Memproses…';
    }

    // Build a temporary grid-layout clone so the exported image is compact (2–3 cols)
    const clone = buildGridClone(exportRoot);
    document.body.appendChild(clone);

    try {
        const opts = {
            pixelRatio: 2,
            backgroundColor: '#ffffff',
            skipFonts: false,
        };

        // First render warms up font/icon cache; second render is the clean output
        await htmlToImage.toPng(clone, opts);
        const dataUrl = await htmlToImage.toPng(clone, opts);

        const link = document.createElement('a');
        link.download = exportRoot.dataset.filename || 'bagan.png';
        link.href = dataUrl;
        link.click();

        window.FSToast?.success('Gambar bagan berhasil diunduh.', 'Unduh selesai');
    } catch (err) {
        console.error('[bagan-share] download error:', err);
        window.FSToast?.error('Gagal membuat gambar. Coba lagi.', 'Unduh gagal');
    } finally {
        document.body.removeChild(clone);
        button.disabled = false;

        if (button && originalText) {
            button.innerHTML = originalText;
        }
    }
}

/**
 * Clones the export root and re-arranges the bagan sections into a grid
 * of 2 columns (≤4 bagans) or 3 columns (≥5 bagans) so the image is not
 * excessively tall.
 */
function buildGridClone(exportRoot) {
    const sections = exportRoot.querySelectorAll('.bagan-preview-section');
    const cols = sections.length >= 5 ? 3 : 2;

    // Wrapper positioned off-screen so it doesn't affect the visible page
    const wrapper = document.createElement('div');
    wrapper.style.cssText = [
        'position:fixed',
        'top:0',
        'left:-99999px',
        'width:1400px',
        'background:#ffffff',
        'padding:24px',
        'box-sizing:border-box',
        'font-family:Inter,sans-serif',
    ].join(';');

    // Copy the export header (title + meta)
    const header = exportRoot.querySelector('.bagan-export-header');
    if (header) {
        const headerClone = header.cloneNode(true);
        headerClone.style.marginBottom = '16px';
        // Remove edit buttons that are hidden in share view anyway
        headerClone.querySelectorAll('[data-html2canvas-ignore]').forEach(el => el.remove());
        wrapper.appendChild(headerClone);
    }

    // Grid container for the bagan cards
    const grid = document.createElement('div');
    grid.style.cssText = [
        `display:grid`,
        `grid-template-columns:repeat(${cols},1fr)`,
        `gap:12px`,
        `align-items:start`,
    ].join(';');

    sections.forEach(sec => {
        const card = sec.cloneNode(true);
        // Make sure the card doesn't overflow its column
        card.style.width = '100%';
        card.style.boxSizing = 'border-box';
        // Remove "Generate Ulang" buttons (irrelevant on share view)
        card.querySelectorAll('[data-html2canvas-ignore]').forEach(el => el.remove());
        grid.appendChild(card);
    });

    wrapper.appendChild(grid);
    return wrapper;
}

async function copyShareLink(shareInput, button) {
    const url = shareInput?.value?.trim();

    if (!url) {
        window.FSToast?.warning('Link share belum tersedia.', 'Salin link');
        return;
    }

    try {
        await navigator.clipboard.writeText(url);
        showCopyFeedback(button);
        window.FSToast?.success('Link share disalin ke clipboard.', 'Tersalin');
    } catch {
        shareInput.focus();
        shareInput.select();

        try {
            document.execCommand('copy');
            showCopyFeedback(button);
            window.FSToast?.success('Link share disalin ke clipboard.', 'Tersalin');
        } catch {
            window.FSToast?.error('Salin manual dari kotak link.', 'Salin gagal');
        }
    }
}

function showCopyFeedback(button) {
    if (!button) {
        return;
    }

    const original = button.innerHTML;
    button.innerHTML = '<i class="ri-check-line" aria-hidden="true"></i> Tersalin';
    window.setTimeout(() => {
        button.innerHTML = original;
    }, 1800);
}
