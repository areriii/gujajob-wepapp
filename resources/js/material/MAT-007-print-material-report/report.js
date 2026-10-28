if (!window.__materialReportPrintBound) {
    window.__materialReportPrintBound = true;

    const printButton = document.querySelector('.material-balance-report-card .print-btn');
    let isGeneratingPdf = false;

    const openPdfPreview = (objectUrl) => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(15,23,42,.65)';
        overlay.innerHTML = `
            <div style="position:relative;width:min(960px,100%);height:min(90vh,760px);background:#fff">
                <button type="button" style="position:absolute;right:8px;top:8px;z-index:1;padding:5px 10px;border:0;background:#111827;color:#fff;cursor:pointer">ปิด</button>
                <iframe style="width:100%;height:100%;border:0" src="${objectUrl}" title="PDF preview"></iframe>
            </div>
        `;

        const close = () => {
            overlay.remove();
            URL.revokeObjectURL(objectUrl);
        };

        overlay.querySelector('button').addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
        document.body.appendChild(overlay);
    };

    printButton?.addEventListener('click', async (event) => {
        event.preventDefault();
        if (isGeneratingPdf) return;

        const pdfUrl = printButton.dataset.pdfUrl;
        if (!pdfUrl) return;

        isGeneratingPdf = true;
        printButton.disabled = true;
        try {
            const response = await fetch(pdfUrl);
            if (!response.ok) throw new Error(`PDF request failed with status ${response.status}`);

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const isTestPreview = new URL(pdfUrl, window.location.href).searchParams.get('pdf_test') === '1'
                || (response.headers.get('content-disposition') || '').includes('inline');

            if (isTestPreview) {
                openPdfPreview(objectUrl);
            } else {
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = printButton.dataset.pdfFilename || 'material-balance-report.pdf';
                link.click();
                URL.revokeObjectURL(objectUrl);
            }
        } catch (error) {
            console.error('Failed to generate the material report PDF:', error);
        } finally {
            isGeneratingPdf = false;
            printButton.disabled = false;
        }
    });
}
