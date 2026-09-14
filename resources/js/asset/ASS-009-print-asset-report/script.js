class SearchableDropdown {
    constructor(inputSelector, suggestionsSelector, hiddenInputSelector, apiUrl, options = {}) {
        this.input = document.querySelector(inputSelector);
        this.suggestionsContainer = document.querySelector(suggestionsSelector);
        this.hiddenInput = document.querySelector(hiddenInputSelector);
        this.apiUrl = apiUrl;
        this.options = options;
        
        if (!this.input) return;
        
        this.debounceTimer = null;
        this.init();
    }
    
    init() {
        this.input.addEventListener('input', () => this.handleInput());
        this.input.addEventListener('focus', () => {
            if (this.input.value.length > 0) {
                this.suggestionsContainer.style.display = 'block';
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!e.target.closest('[class*="searchable"]')) {
                this.suggestionsContainer.style.display = 'none';
            }
        });
    }
    
    handleInput() {
        const query = this.input.value.trim();
        
        clearTimeout(this.debounceTimer);
        
        // Typing invalidates any previously picked item.
        this.hiddenInput.value = '';
        this.options.onChange?.();
        
        if (query.length === 0 || this.options.isBlocked?.()) {
            this.suggestionsContainer.style.display = 'none';
            return;
        }
        
        this.debounceTimer = setTimeout(() => {
            this.fetchSuggestions(query);
        }, 300);
    }
    
    fetchSuggestions(query) {
        const params = new URLSearchParams({ q: query, ...(this.options.extraParams?.() ?? {}) });
        
        fetch(`${this.apiUrl}?${params.toString()}`)
            .then(response => response.json())
            .then(payload => this.renderSuggestions(Array.isArray(payload) ? payload : payload?.data))
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                this.suggestionsContainer.style.display = 'none';
            });
    }
    
    renderSuggestions(data) {
        this.suggestionsContainer.innerHTML = '';
        
        if (!Array.isArray(data) || data.length === 0) {
            this.suggestionsContainer.style.display = 'none';
            return;
        }
        
        data.forEach(item => {
            const suggestionItem = document.createElement('div');
            suggestionItem.className = 'suggestion-item';
            suggestionItem.textContent = this.formatSuggestion(item);
            suggestionItem.addEventListener('click', () => this.selectItem(item));
            this.suggestionsContainer.appendChild(suggestionItem);
        });
        
        this.suggestionsContainer.style.display = 'block';
    }
    
    formatSuggestion(item) {
        if (item.code && item.name) {
            return `${item.code} - ${item.name}`;
        } else if (item.name) {
            return item.name;
        }
        return JSON.stringify(item);
    }
    
    selectItem(item) {
        this.input.value = this.formatSuggestion(item);
        this.hiddenInput.value = item.id;
        this.suggestionsContainer.style.display = 'none';
        this.options.onSelect?.(item);
    }
    
    clear() {
        if (!this.input) return;
        clearTimeout(this.debounceTimer);
        this.input.value = '';
        this.hiddenInput.value = '';
        this.suggestionsContainer.innerHTML = '';
        this.suggestionsContainer.style.display = 'none';
    }
}

// Report type selection
function initReportTypeSelection() {
    const registerBtn = document.querySelector('[data-report-type="register"]');
    const ledgerBtn = document.querySelector('[data-report-type="ledger"]');
    const registerConditions = document.getElementById('registerConditions');
    const ledgerConditions = document.getElementById('ledgerConditions');
    
    registerBtn?.addEventListener('click', () => {
        registerBtn.classList.add('active');
        ledgerBtn.classList.remove('active');
        registerConditions.style.display = 'block';
        ledgerConditions.style.display = 'none';
        clearLedgerFields();
    });
    
    ledgerBtn?.addEventListener('click', () => {
        ledgerBtn.classList.add('active');
        registerBtn.classList.remove('active');
        ledgerConditions.style.display = 'block';
        registerConditions.style.display = 'none';
        clearRegisterFields();
    });
}

// Clear field functions
function clearRegisterFields() {
    dropdowns.category?.clear();
    dropdowns.asset?.clear();
    syncAssetFieldState();
}

function clearLedgerFields() {
    document.getElementById('fiscalYear').value = '';
    dropdowns.org?.clear();
    dropdowns.subOrg?.clear();
}

// The asset-code field only searches once a category has been picked.
function syncAssetFieldState() {
    const hasCategory = Boolean(document.getElementById('categoryId')?.value);
    const assetInput = document.getElementById('assetSearch');
    const hint = document.getElementById('assetSearchHint');
    
    if (assetInput) assetInput.disabled = !hasCategory;
    if (hint) hint.style.display = hasCategory ? 'none' : '';
}

// Preview button
function initPreviewButton() {
    const previewBtn = document.getElementById('previewReportButton');
    const exportFormatInputs = document.querySelectorAll('input[name="exportFormat"]');
    const isExcelTestMode = new URLSearchParams(window.location.search).get('xlsx_test') === '1';

    const setActionLabel = () => {
        const isExcel = document.querySelector('input[name="exportFormat"]:checked')?.value === 'xlsx';
        previewBtn?.classList.toggle('download-btn', isExcel);
        previewBtn?.classList.toggle('preview-btn', !isExcel);
        const label = previewBtn?.querySelector('span');
        if (label) label.textContent = isExcel ? 'ดาวน์โหลด' : 'พรีวิว';
    };

    exportFormatInputs.forEach((input) => input.addEventListener('change', setActionLabel));
    setActionLabel();

    const openTestPopup = (html) => {
        const overlay = document.createElement('div');
        overlay.className = 'pdf-test-modal';
        overlay.innerHTML = `
            <div class="pdf-test-modal__dialog">
                <button type="button" class="pdf-test-modal__close">ปิด</button>
                <iframe class="pdf-test-modal__frame" title="Excel layout test"></iframe>
            </div>
        `;
        const frame = overlay.querySelector('iframe');
        frame.srcdoc = html;
        const close = () => overlay.remove();
        overlay.querySelector('.pdf-test-modal__close').addEventListener('click', close);
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay) close();
        });
        document.body.appendChild(overlay);
    };

    const requestExcel = async (url) => {
        if (!isExcelTestMode) {
            const link = document.createElement('a');
            link.href = url;
            link.rel = 'noopener';
            link.download = 'asset-report.xlsx';
            document.body.appendChild(link);
            link.click();
            link.remove();
            return;
        }

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
        });
        if (!response.ok) throw new Error(`Excel request failed with status ${response.status}`);

        openTestPopup(await response.text());
    };

    previewBtn?.addEventListener('click', async () => {
        const registerConditions = document.getElementById('registerConditions');
        const isRegisterType = registerConditions.style.display !== 'none';

        if (isRegisterType) {
            const categoryId = document.getElementById('categoryId').value;
            const assetId = document.getElementById('assetId').value;
            if (!categoryId || !assetId) return;

            const params = new URLSearchParams();
            params.set('category_id', categoryId);
            params.set('asset_id', assetId);

            const isExcel = document.querySelector('input[name="exportFormat"]:checked')?.value === 'xlsx';
            if (isExcel) {
                if (isExcelTestMode) params.set('xlsx_test', '1');
                try {
                    await requestExcel(`${previewBtn.dataset.registerExportUrl}?${params}`);
                } catch (error) {
                    console.error('Failed to generate the Excel export:', error);
                }
                return;
            }

            window.location.href = `${previewBtn.dataset.registerUrl}?${params}`;
        } else {
            const fiscalYear = document.getElementById('fiscalYear').value;
            const orgId = document.getElementById('orgId').value;
            const subOrgId = document.getElementById('subOrgId').value;
            if (!fiscalYear) return;

            const params = new URLSearchParams();
            params.append('fiscal_year', fiscalYear);
            if (orgId) params.append('org_id', orgId);
            if (subOrgId) params.append('sub_org_id', subOrgId);

            const isExcel = document.querySelector('input[name="exportFormat"]:checked')?.value === 'xlsx';
            if (isExcel) {
                if (isExcelTestMode) params.set('xlsx_test', '1');
                try {
                    await requestExcel(`${previewBtn.dataset.ledgerExportUrl}?${params}`);
                } catch (error) {
                    console.error('Failed to generate the ledger Excel export:', error);
                }
                return;
            }

            window.location.href = `${previewBtn.dataset.ledgerUrl}?${params}`;
        }
    });
}

// Initialize on page load
const dropdowns = {};

document.addEventListener('DOMContentLoaded', () => {
    initReportTypeSelection();
    
    // Initialize searchable dropdowns
    const resetAsset = () => {
        dropdowns.asset?.clear();
        syncAssetFieldState();
    };
    
    dropdowns.category = new SearchableDropdown('#categorySearch', '#categorySuggestions', '#categoryId', '/api/asset/categories/search', {
        onSelect: resetAsset,
        onChange: resetAsset,
    });
    dropdowns.asset = new SearchableDropdown('#assetSearch', '#assetSuggestions', '#assetId', '/api/asset/search', {
        isBlocked: () => !document.getElementById('categoryId').value,
        extraParams: () => ({ category_id: document.getElementById('categoryId').value }),
    });
    dropdowns.org = new SearchableDropdown('#orgSearch', '#orgSuggestions', '#orgId', '/api/asset/organizations/search', {
        onSelect: () => dropdowns.subOrg?.clear(),
        onChange: () => dropdowns.subOrg?.clear(),
    });
    dropdowns.subOrg = new SearchableDropdown('#subOrgSearch', '#subOrgSuggestions', '#subOrgId', '/api/asset/sub-organizations/search', {
        isBlocked: () => !document.getElementById('orgId').value,
        extraParams: () => ({ parent_org_id: document.getElementById('orgId').value }),
    });
    
    syncAssetFieldState();
    
    initPreviewButton();
});
