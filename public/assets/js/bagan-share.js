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
    if (button) button.innerHTML = 'Memproses…';

    // --- Temporarily rearrange the ACTUAL DOM (already painted = renderable) ---
    const toolbar = document.querySelector('.bagan-share-toolbar');
    const stack = exportRoot.querySelector('.bagan-preview-stack');
    const sections = exportRoot.querySelectorAll('.bagan-preview-section');
    const cols = sections.length >= 5 ? 3 : 2;

    // Save originals
    const origToolbarDisplay = toolbar ? toolbar.style.display : null;
    const origStackStyle = stack ? {
        display: stack.style.display,
        gridTemplateColumns: stack.style.gridTemplateColumns,
        gap: stack.style.gap,
        alignItems: stack.style.alignItems,
    } : null;

    // Apply compact grid on the live element
    if (toolbar) toolbar.style.display = 'none';
    if (stack) {
        stack.style.display = 'grid';
        stack.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
        stack.style.gap = '12px';
        stack.style.alignItems = 'start';
    }

    // Give browser one frame to repaint
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

    try {
        const opts = {
            pixelRatio: 2,
            backgroundColor: '#ffffff',
            skipFonts: false,
        };

        // First render warms up font/icon cache; second is the clean output
        await htmlToImage.toPng(exportRoot, opts);
        const dataUrl = await htmlToImage.toPng(exportRoot, opts);

        const link = document.createElement('a');
        link.download = exportRoot.dataset.filename || 'bagan.png';
        link.href = dataUrl;
        link.click();

        window.FSToast?.success('Gambar bagan berhasil diunduh.', 'Unduh selesai');
    } catch (err) {
        console.error('[bagan-share] download error:', err);
        window.FSToast?.error('Gagal membuat gambar. Coba lagi.', 'Unduh gagal');
    } finally {
        // Restore original layout
        if (toolbar && origToolbarDisplay !== null) toolbar.style.display = origToolbarDisplay;
        if (stack && origStackStyle) {
            stack.style.display = origStackStyle.display;
            stack.style.gridTemplateColumns = origStackStyle.gridTemplateColumns;
            stack.style.gap = origStackStyle.gap;
            stack.style.alignItems = origStackStyle.alignItems;
        }
        button.disabled = false;
        if (button && originalText) button.innerHTML = originalText;
    }
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
