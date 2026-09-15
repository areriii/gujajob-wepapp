import { SearchAutocomplete } from '../../components/search-autocomplete.js';

const sidebarStorageKey = 'gujajob.sidebar.groupState';

function getSidebarState() {
    try {
        const storedState = localStorage.getItem(sidebarStorageKey);

        if (!storedState) {
            return {
                material: true,
                asset: true,
            };
        }

        return {
            material: true,
            asset: true,
            ...JSON.parse(storedState),
        };
    } catch (error) {
        return {
            material: true,
            asset: true,
        };
    }
}

function saveSidebarState(state) {
    try {
        localStorage.setItem(sidebarStorageKey, JSON.stringify(state));
    } catch (error) {
        console.warn('Cannot save sidebar state.');
    }
}

function applySidebarGroupState(groupElement, isOpen) {
    const toggleButton = groupElement.querySelector('.menu-group-toggle');

    groupElement.classList.toggle('is-collapsed', !isOpen);

    if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', String(isOpen));
    }
}

function initializeSidebarGroups() {
    const state = getSidebarState();
    const groups = document.querySelectorAll('[data-sidebar-group]');

    groups.forEach((groupElement) => {
        const groupName = groupElement.dataset.sidebarGroup;
        const toggleButton = groupElement.querySelector('.menu-group-toggle');
        const isOpen = state[groupName] !== false;

        applySidebarGroupState(groupElement, isOpen);

        if (!toggleButton) {
            return;
        }

        toggleButton.addEventListener('click', () => {
            const currentState = getSidebarState();
            const nextIsOpen = groupElement.classList.contains('is-collapsed');

            currentState[groupName] = nextIsOpen;

            applySidebarGroupState(groupElement, nextIsOpen);
            saveSidebarState(currentState);
        });
    });
}

function preventDisabledMenuReload() {
    document.querySelectorAll('.disabled-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
        });
    });
}

function initializeAssetRegistrationSearch() {
    const searchTypeEl  = document.getElementById('assetSearchType');
    const searchInputEl = document.getElementById('assetSearchInput');

    if (!searchTypeEl || !searchInputEl) {
        return;
    }

    const ac = new SearchAutocomplete({
        inputEl:      searchInputEl,
        searchTypeEl: searchTypeEl,
        endpoint:     '/search/suggestions',
        extraParams:  { entity: 'asset' },
        minChars:     1,
        debounceMs:   300,
        maxResults:   15,
        onSelect: (item) => {
            searchInputEl.value = item.code;
            searchInputEl.closest('form')?.submit();
        },
    });

    searchTypeEl.addEventListener('change', () => {
        searchInputEl.value = '';
        ac.clear?.();
    });
}

function initializeDeleteModal() {
    const overlay       = document.getElementById('deleteAssetOverlay');
    const cancelButton  = document.getElementById('cancelDeleteAssetButton');
    const confirmButton = document.getElementById('confirmDeleteAssetButton');
    const deleteForm    = document.getElementById('deleteAssetForm');
    const messageEl     = document.getElementById('deleteAssetMessage');

    if (!overlay || !deleteForm) {
        return;
    }

    document.querySelectorAll('[data-delete-url]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const code = btn.dataset.deleteCode || '';
            deleteForm.action = btn.dataset.deleteUrl;
            if (messageEl && code) {
                messageEl.innerHTML = `คุณแน่ใจหรือไม่ว่าต้องการลบครุภัณฑ์ : '${code}'<br>การดำเนินการนี้ไม่สามารถเรียกคืนได้`;
            }
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
        });
    });

    function closeModal() {
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
    }

    cancelButton?.addEventListener('click', closeModal);
    confirmButton?.addEventListener('click', () => {
        deleteForm.submit();
    });

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            closeModal();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initializeSidebarGroups();
    preventDisabledMenuReload();
    initializeAssetRegistrationSearch();
    initializeDeleteModal();
});