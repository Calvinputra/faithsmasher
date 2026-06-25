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

    // Overlay so user doesn't see the brief layout change
    const overlay = document.createElement('div');
    overlay.style.cssText = [
        'position:fixed', 'inset:0', 'background:rgba(255,255,255,0.92)',
        'z-index:99999', 'display:flex', 'align-items:center', 'justify-content:center',
    ].join(';');
    overlay.innerHTML = '<p style="font:600 15px Inter,sans-serif;color:#374151">Membuat gambar…</p>';
    document.body.appendChild(overlay);

    const toolbar   = document.querySelector('.bagan-share-toolbar');
    const stack     = exportRoot.querySelector('.bagan-preview-stack');
    const sections  = Array.from(exportRoot.querySelectorAll('.bagan-preview-section'));
    const tables    = Array.from(exportRoot.querySelectorAll('.bagan-preview-table'));
    const cols      = sections.length >= 5 ? 3 : 2;

    // Each bagan table needs ~900px to show all columns fully
    const colWidth  = 900;
    const gridGap   = 20;
    const padding   = 48;
    const totalWidth = (colWidth * cols) + (gridGap * (cols - 1)) + padding;

    // ---- Save original styles ----
    const savedToolbar     = toolbar?.style.display ?? null;
    const savedRootWidth   = exportRoot.style.width;
    const savedRootMaxW    = exportRoot.style.maxWidth;
    const savedRootPos     = exportRoot.style.position;
    const savedBodyOverflow = document.body.style.overflow;
    const savedStack = stack ? {
        display: stack.style.display,
        gridTemplateColumns: stack.style.gridTemplateColumns,
        gap: stack.style.gap,
        alignItems: stack.style.alignItems,
    } : null;
    const overflowEls    = Array.from(exportRoot.querySelectorAll('.overflow-x-auto'));
    const savedOverflows = overflowEls.map(el => el.style.overflow);
    const savedTableWidths = tables.map(t => t.style.minWidth);

    // ---- Apply export layout ----
    if (toolbar) toolbar.style.display = 'none';
    document.body.style.overflow = 'hidden';

    // Widen the export root so grid columns have room for full tables
    exportRoot.style.width    = totalWidth + 'px';
    exportRoot.style.maxWidth = 'none';

    if (stack) {
        stack.style.display = 'grid';
        stack.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
        stack.style.gap = gridGap + 'px';
        stack.style.alignItems = 'start';
    }

    // Force tables to use their full width & prevent overflow clipping
    overflowEls.forEach(el => (el.style.overflow = 'visible'));
    tables.forEach(t => (t.style.minWidth = '100%'));

    // Let browser repaint with the new layout
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

    try {
        const opts = { pixelRatio: 2, backgroundColor: '#ffffff', skipFonts: false };

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
        // ---- Restore everything ----
        if (toolbar && savedToolbar !== null) toolbar.style.display = savedToolbar;
        document.body.style.overflow = savedBodyOverflow;
        exportRoot.style.width    = savedRootWidth;
        exportRoot.style.maxWidth = savedRootMaxW;
        if (stack && savedStack) {
            stack.style.display              = savedStack.display;
            stack.style.gridTemplateColumns  = savedStack.gridTemplateColumns;
            stack.style.gap                  = savedStack.gap;
            stack.style.alignItems           = savedStack.alignItems;
        }
        overflowEls.forEach((el, i) => (el.style.overflow = savedOverflows[i]));
        tables.forEach((t, i) => (t.style.minWidth = savedTableWidths[i]));

        document.body.removeChild(overlay);
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
