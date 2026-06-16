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
    if (!exportRoot || typeof html2canvas === 'undefined') {
        window.FSToast?.error('Fitur unduh belum siap. Muat ulang halaman.', 'Unduh gagal');
        return;
    }

    const originalText = button?.innerHTML;
    button.disabled = true;

    if (button) {
        button.innerHTML = 'Memproses…';
    }

    try {
        const canvas = await html2canvas(exportRoot, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false,
        });

        const link = document.createElement('a');
        link.download = exportRoot.dataset.filename || 'bagan.png';
        link.href = canvas.toDataURL('image/png');
        link.click();

        window.FSToast?.success('Gambar bagan berhasil diunduh.', 'Unduh selesai');
    } catch {
        window.FSToast?.error('Gagal membuat gambar. Coba lagi.', 'Unduh gagal');
    } finally {
        button.disabled = false;

        if (button && originalText) {
            button.innerHTML = originalText;
        }
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
