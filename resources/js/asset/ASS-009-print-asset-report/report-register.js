// Server renders a dedicated PDF layout (report-register-pdf.blade.php) via Dompdf,
// independent from the on-screen frame, so the file matches the report design exactly.
// PDF_TEST_MODE (server-side) only changes the Content-Disposition: inline means preview
// the blob in a popup, attachment means a real download. Same bytes either way.

// Guard against this module executing more than once (e.g. stale/duplicated bundle),
// which would otherwise register a second click listener and double the PDF output.
if (!window.__assetRegisterPrintBound) {
    window.__assetRegisterPrintBound = true;

    const printButton = document.querySelector('.print-btn');
    let isGeneratingPdf = false;

    const openPdfPreview = (objectUrl) => {
        const overlay = document.createElement('div');
        overlay.className = 'pdf-test-modal';
        overlay.innerHTML = `
            <div class="pdf-test-modal__dialog">
                <button type="button" class="pdf-test-modal__close">ปิด</button>
                <iframe class="pdf-test-modal__frame" src="${objectUrl}" title="PDF preview"></iframe>
            </div>
        `;

        const close = () => {
            overlay.remove();
            URL.revokeObjectURL(objectUrl);
        };

        overlay.querySelector('.pdf-test-modal__close').addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });

        document.body.appendChild(overlay);
    };

    const generateReportPdf = async (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        console.count(printButton?.dataset.pdfCounter || 'PRINT_HANDLER');

        const pdfUrl = printButton?.dataset.pdfUrl;
        if (!pdfUrl || isGeneratingPdf) {
            return;
        }

        const filename = printButton.dataset.pdfFilename || 'asset-register-report.pdf';

        isGeneratingPdf = true;
        printButton.disabled = true;
        try {
            const response = await fetch(pdfUrl, { cache: 'no-store' });
            if (!response.ok) {
                throw new Error(`PDF request failed with status ${response.status}`);
            }

            const isTestRequest = new URL(pdfUrl, window.location.href).searchParams.get('pdf_test') === '1';
            const isTestPreview = (response.headers.get('content-disposition') || '').includes('inline');
            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);

            if (isTestRequest && !isTestPreview) {
                URL.revokeObjectURL(objectUrl);
                throw new Error('Explicit PDF test mode did not return an inline preview. Download blocked.');
            }

            if (isTestPreview) {
                console.log('TEST MODE - preview PDF, no download:', blob.type, blob.size, 'bytes');
                openPdfPreview(objectUrl);
                return;
            }

            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = filename;
            link.click();
            URL.revokeObjectURL(objectUrl);
        } catch (error) {
            console.error('Failed to generate the asset register PDF:', error);
        } finally {
            isGeneratingPdf = false;
            printButton.disabled = false;
        }
    };

    printButton?.addEventListener('click', generateReportPdf);
}


