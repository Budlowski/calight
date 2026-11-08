(() => {
    'use strict';

    const root = document.querySelector('#master-data-app');
    if (!root) return;

    const csrf = root.dataset.csrf;
    const state = { categories: [], suppliers: [], costItems: [], services: [], components: [] };
    const typeLabels = { material: 'Material', labor: 'Lohn', external: 'Fremdleistung', other: 'Sonstige Kosten' };
    const euro = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
    const number = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    const message = document.querySelector('#master-message');
    const showArchived = document.querySelector('#show-archived');
    const costForm = document.querySelector('#cost-item-form');
    const categoryForm = document.querySelector('#category-form');
    const supplierForm = document.querySelector('#supplier-form');
    const serviceForm = document.querySelector('#service-form');
    const masterPricePreview = document.querySelector('#master-price-preview');
    let previewTimer = 0;
    let dirty = false;
    let pendingPriceUpdates = [];

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[character]);
    const formatEuro = (value) => euro.format(Number(value || 0));
    const visible = (entry) => entry.active || showArchived.checked;
    const decimalValue = (value) => String(value ?? '').trim().replace(',', '.');
    const decimalInput = (value, fallback) => String(value ?? fallback).replace('.', ',');
    const activeClass = (entry) => entry.active ? '' : ' archived-row';

    function notify(text, type = 'success') {
        message.textContent = text;
        message.className = `notice notice-${type}`;
        message.hidden = false;
        window.clearTimeout(notify.timer);
        notify.timer = window.setTimeout(() => { message.hidden = true; }, 5000);
    }

    async function api(path, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrf;
            options.body = JSON.stringify(options.body);
        }
        const response = await fetch(path, { credentials: 'same-origin', ...options, headers });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error('Der Server hat keine gültige Antwort geliefert.');
        }
        if (!response.ok) {
            const details = Array.isArray(result.details) ? result.details.join(' ') : '';
            throw new Error(details || result.error || 'Anfrage fehlgeschlagen.');
        }
        return result.data ?? result;
    }

    async function load() {
        try {
            const data = await api('/api/master-data');
            Object.assign(state, data);
            renderAll();
        } catch (error) {
            notify(error.message, 'error');
        }
    }

    function renderAll() {
        renderCategoryOptions();
        renderCategories();
        renderSuppliers();
        renderCostItems();
        renderServices();
        renderMasterComponentOptions();
    }

    document.querySelectorAll('.tab-button').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.tab-button').forEach((item) => item.classList.toggle('active', item === button));
            document.querySelectorAll('.tab-panel').forEach((panel) => {
                const selected = panel.dataset.panel === button.dataset.tab;
                panel.classList.toggle('active', selected);
                panel.hidden = !selected;
            });
        });
    });
    showArchived.addEventListener('change', renderAll);
    root.addEventListener('input', (event) => {
        if (event.target.closest('#cost-item-form, #category-form, #supplier-form, #service-form')) dirty = true;
    });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });

    function flatCategories(excludedIds = new Set()) {
        const byParent = new Map();
        state.categories.filter(visible).forEach((category) => {
            const key = category.parentId || '';
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key).push(category);
        });
        const result = [];
        const visit = (parentId, depth, visited) => {
            (byParent.get(parentId) || []).sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name, 'de')).forEach((category) => {
                if (visited.has(category.id)) return;
                const nextVisited = new Set(visited).add(category.id);
                if (!excludedIds.has(category.id)) result.push({ ...category, depth });
                visit(category.id, depth + 1, nextVisited);
            });
        };
        visit('', 0, new Set());
        return result;
    }

    function descendantsOf(categoryId) {
        const result = new Set([categoryId]);
        let changed = true;
        while (changed) {
            changed = false;
            state.categories.forEach((category) => {
                if (category.parentId && result.has(category.parentId) && !result.has(category.id)) {
                    result.add(category.id);
                    changed = true;
                }
            });
        }
        return result;
    }

    function categoryOptions(selected = '', excludedIds = new Set(), emptyLabel = 'Keine Kategorie') {
        return `<option value="">${escapeHtml(emptyLabel)}</option>` + flatCategories(excludedIds).map((category) =>
            `<option value="${category.id}" ${category.id === selected ? 'selected' : ''}>${'&nbsp;'.repeat(category.depth * 4)}${escapeHtml(category.name)}${category.active ? '' : ' (archiviert)'}</option>`
        ).join('');
    }

    function renderCategoryOptions() {
        document.querySelector('#cost-category-filter').innerHTML = categoryOptions('', new Set(), 'Alle Kategorien');
        costForm.elements.categoryId.innerHTML = categoryOptions();
        categoryForm.elements.parentId.innerHTML = categoryOptions('', new Set(), 'Oberste Ebene');
        supplierOptions();
    }

    function renderCategories() {
        const query = document.querySelector('#category-search').value.trim().toLocaleLowerCase('de');
        const rows = flatCategories().filter((category) => !query || `${category.name} ${category.description}`.toLocaleLowerCase('de').includes(query));
        document.querySelector('#category-tree').innerHTML = rows.length ? rows.map((category) => `
            <div class="category-row${activeClass(category)}" style="--depth:${category.depth}">
                <button type="button" class="category-name" data-category-edit="${category.id}">${escapeHtml(category.name)}</button>
                <span class="muted">${escapeHtml(category.description)}</span>
                <button type="button" class="table-action" data-category-archive="${category.id}">${category.active ? 'Archivieren' : 'Reaktivieren'}</button>
            </div>`).join('') : '<p class="muted">Keine Kategorien gefunden.</p>';
    }

    document.querySelector('#category-search').addEventListener('input', renderCategories);
    document.querySelector('#new-category').addEventListener('click', () => openCategory());
    document.querySelector('#category-tree').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-category-edit]');
        const archive = event.target.closest('[data-category-archive]');
        if (edit) openCategory(state.categories.find((item) => item.id === edit.dataset.categoryEdit));
        if (archive) {
            const item = state.categories.find((entry) => entry.id === archive.dataset.categoryArchive);
            await mutate(`/api/master-data/categories/${item.id}/archive`, { archived: item.active }, item.active ? 'Kategorie archiviert.' : 'Kategorie reaktiviert.');
        }
    });

    function openCategory(item = null) {
        categoryForm.reset();
        categoryForm.elements.id.value = item?.id || '';
        categoryForm.elements.name.value = item?.name || '';
        categoryForm.elements.description.value = item?.description || '';
        categoryForm.elements.sortOrder.value = item?.sortOrder ?? 0;
        const excluded = item ? descendantsOf(item.id) : new Set();
        categoryForm.elements.parentId.innerHTML = categoryOptions(item?.parentId || '', excluded, 'Oberste Ebene');
        categoryForm.hidden = false;
        dirty = false;
        categoryForm.elements.name.focus();
    }

    categoryForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = categoryForm.elements.id.value;
        const saved = await mutate(id ? `/api/master-data/categories/${id}` : '/api/master-data/categories', {
            name: categoryForm.elements.name.value,
            description: categoryForm.elements.description.value,
            parentId: categoryForm.elements.parentId.value || null,
            sortOrder: Number(categoryForm.elements.sortOrder.value),
        }, id ? 'Kategorie gespeichert.' : 'Kategorie angelegt.', id ? 'PUT' : 'POST');
        if (!saved) return;
        dirty = false;
        categoryForm.hidden = true;
    });

    function supplierOptions(selected = '') {
        const options = '<option value="">Kein Lieferant</option>' + state.suppliers.filter((item) => item.active).map((supplier) =>
            `<option value="${supplier.id}" ${supplier.id === selected ? 'selected' : ''}>${escapeHtml(supplier.name)}</option>`
        ).join('');
        costForm.elements.supplierId.innerHTML = options;
    }

    function renderSuppliers() {
        const query = document.querySelector('#supplier-search').value.trim().toLocaleLowerCase('de');
        const items = state.suppliers.filter(visible).filter((item) => !query || `${item.supplierNumber || ''} ${item.name} ${item.contact}`.toLocaleLowerCase('de').includes(query));
        document.querySelector('#suppliers-body').innerHTML = items.length ? items.map((item) => `
            <tr class="${activeClass(item)}"><td>${escapeHtml(item.supplierNumber || '–')}</td><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.contact || '–')}</td>
            <td class="actions"><button type="button" class="table-action" data-supplier-edit="${item.id}">Bearbeiten</button><button type="button" class="table-action" data-supplier-archive="${item.id}">${item.active ? 'Archivieren' : 'Reaktivieren'}</button></td></tr>`).join('') : '<tr><td colspan="4" class="muted">Keine Lieferanten gefunden.</td></tr>';
    }

    document.querySelector('#supplier-search').addEventListener('input', renderSuppliers);
    document.querySelector('#new-supplier').addEventListener('click', () => openSupplier());
    document.querySelector('#suppliers-body').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-supplier-edit]');
        const archive = event.target.closest('[data-supplier-archive]');
        if (edit) openSupplier(state.suppliers.find((item) => item.id === edit.dataset.supplierEdit));
        if (archive) {
            const item = state.suppliers.find((entry) => entry.id === archive.dataset.supplierArchive);
            await mutate(`/api/master-data/suppliers/${item.id}/archive`, { archived: item.active }, item.active ? 'Lieferant archiviert.' : 'Lieferant reaktiviert.');
        }
    });

    function openSupplier(item = null) {
        supplierForm.reset();
        supplierForm.elements.id.value = item?.id || '';
        supplierForm.elements.supplierNumber.value = item?.supplierNumber || '';
        supplierForm.elements.name.value = item?.name || '';
        supplierForm.elements.contact.value = item?.contact || '';
        supplierForm.hidden = false;
        dirty = false;
        supplierForm.elements.name.focus();
    }

    supplierForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = supplierForm.elements.id.value;
        const saved = await mutate(id ? `/api/master-data/suppliers/${id}` : '/api/master-data/suppliers', {
            supplierNumber: supplierForm.elements.supplierNumber.value || null,
            name: supplierForm.elements.name.value,
            contact: supplierForm.elements.contact.value,
        }, id ? 'Lieferant gespeichert.' : 'Lieferant angelegt.', id ? 'PUT' : 'POST');
        if (!saved) return;
        dirty = false;
        supplierForm.hidden = true;
    });

    function renderCostItems() {
        const query = document.querySelector('#cost-search').value.trim().toLocaleLowerCase('de');
        const categoryId = document.querySelector('#cost-category-filter').value;
        const allowedCategories = categoryId ? descendantsOf(categoryId) : null;
        const type = document.querySelector('#cost-type-filter').value;
        const items = state.costItems.filter(visible).filter((item) => {
            const matchesText = !query || `${item.articleNumber || ''} ${item.name} ${item.description}`.toLocaleLowerCase('de').includes(query);
            return matchesText && (!allowedCategories || allowedCategories.has(item.categoryId)) && (!type || item.calculationType === type);
        });
        document.querySelector('#cost-items-body').innerHTML = items.length ? items.map((item) => `
            <tr class="${activeClass(item)}"><td>${escapeHtml(item.articleNumber || '–')}</td><td><strong>${escapeHtml(item.name)}</strong><br><span class="muted">${escapeHtml(item.categoryName || '')}</span></td>
            <td>${escapeHtml(typeLabels[item.calculationType])}</td><td>${escapeHtml(item.unit)}</td><td>${number.format(Number(item.costPrice))}</td><td>${formatEuro(item.salesPrice)}</td>
            <td class="actions"><button type="button" class="table-action" data-cost-edit="${item.id}">Bearbeiten</button><button type="button" class="table-action" data-cost-copy="${item.id}">Kopieren</button><button type="button" class="table-action" data-cost-archive="${item.id}">${item.active ? 'Archivieren' : 'Reaktivieren'}</button></td></tr>`).join('') : '<tr><td colspan="7" class="muted">Keine Stammartikel gefunden.</td></tr>';
    }

    ['#cost-search', '#cost-category-filter', '#cost-type-filter'].forEach((selector) => document.querySelector(selector).addEventListener('input', renderCostItems));
    document.querySelector('#new-cost-item').addEventListener('click', () => openCostItem());
    document.querySelector('#cost-items-body').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-cost-edit]');
        const copy = event.target.closest('[data-cost-copy]');
        const archive = event.target.closest('[data-cost-archive]');
        if (edit) openCostItem(state.costItems.find((item) => item.id === edit.dataset.costEdit));
        if (copy) await mutate(`/api/master-data/cost-items/${copy.dataset.costCopy}/copy`, {}, 'Stammartikel kopiert.');
        if (archive) {
            const item = state.costItems.find((entry) => entry.id === archive.dataset.costArchive);
            await mutate(`/api/master-data/cost-items/${item.id}/archive`, { archived: item.active }, item.active ? 'Stammartikel archiviert.' : 'Stammartikel reaktiviert.');
        }
    });

    function openCostItem(item = null) {
        costForm.reset();
        costForm.elements.id.value = item?.id || '';
        costForm.elements.articleNumber.value = item?.articleNumber || '';
        costForm.elements.name.value = item?.name || '';
        costForm.elements.description.value = item?.description || '';
        costForm.elements.calculationType.value = item?.calculationType || 'material';
        costForm.elements.unit.value = item?.unit || '';
        costForm.elements.categoryId.innerHTML = categoryOptions(item?.categoryId || '');
        supplierOptions(item?.supplierId || '');
        costForm.elements.costPrice.value = decimalInput(item?.costPrice, '0.0000');
        costForm.elements.markupPercent.value = decimalInput(item?.markupPercent, '0.00');
        costForm.elements.supplierArticleNumber.value = item?.supplierArticleNumber || '';
        document.querySelector('#cost-sales-price').textContent = formatEuro(item?.salesPrice || 0);
        costForm.hidden = false;
        dirty = false;
        costForm.elements.name.focus();
    }

    costForm.addEventListener('input', (event) => {
        if (['costPrice', 'markupPercent', 'calculationType'].includes(event.target.name)) scheduleCostPreview();
    });
    costForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = costForm.elements.id.value;
        const saved = await mutate(id ? `/api/master-data/cost-items/${id}` : '/api/master-data/cost-items', costPayload(), id ? 'Stammartikel gespeichert.' : 'Stammartikel angelegt.', id ? 'PUT' : 'POST');
        if (!saved) return;
        dirty = false;
        costForm.hidden = true;
    });

    function costPayload() {
        return {
            articleNumber: costForm.elements.articleNumber.value || null,
            name: costForm.elements.name.value,
            description: costForm.elements.description.value,
            categoryId: costForm.elements.categoryId.value || null,
            calculationType: costForm.elements.calculationType.value,
            unit: costForm.elements.unit.value,
            costPrice: decimalValue(costForm.elements.costPrice.value),
            markupPercent: decimalValue(costForm.elements.markupPercent.value),
            supplierId: costForm.elements.supplierId.value || null,
            supplierArticleNumber: costForm.elements.supplierArticleNumber.value,
        };
    }

    function scheduleCostPreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(async () => {
            if (!costForm.elements.costPrice.validity.valid || !costForm.elements.markupPercent.validity.valid) return;
            try {
                const preview = await api('/api/master-data/preview-price', { method: 'POST', body: { components: [{
                    calculationType: costForm.elements.calculationType.value,
                    quantity: '1.000', costPrice: decimalValue(costForm.elements.costPrice.value), markupPercent: decimalValue(costForm.elements.markupPercent.value),
                }] } });
                document.querySelector('#cost-sales-price').textContent = formatEuro(preview.calculatedUnitPrice);
            } catch (error) {
                document.querySelector('#cost-sales-price').textContent = error.message;
            }
        }, 180);
    }

    function renderServices() {
        const servicesList = document.querySelector('#services-list');
        const query = document.querySelector('#service-search').value.trim().toLocaleLowerCase('de');
        const items = state.services.filter(visible).filter((item) => !query || `${item.articleNumber || ''} ${item.shortText} ${item.longText}`.toLocaleLowerCase('de').includes(query));
        servicesList.innerHTML = items.length ? items.map((item) => `
            <article class="service-list-item${activeClass(item)}" data-service-card="${item.id}">
                <div class="service-list-main"><span class="service-number">${escapeHtml(item.articleNumber || 'Ohne Nr.')}</span><strong>${escapeHtml(item.shortText)}</strong><span class="muted">je ${escapeHtml(item.unit)} · ${item.components.length} ${item.components.length === 1 ? 'Baustein' : 'Bausteine'}</span></div>
                <strong class="service-list-price">${formatEuro(item.calculation.calculatedUnitPrice)}</strong>
                <div class="service-list-actions"><button type="button" class="table-action" data-service-edit="${item.id}">Bearbeiten</button><button type="button" class="table-action" data-service-copy="${item.id}">Kopieren</button><button type="button" class="table-action" data-service-archive="${item.id}">${item.active ? 'Archivieren' : 'Reaktivieren'}</button></div>
            </article>`).join('') : '<p class="card muted">Keine Stammpositionen gefunden.</p>';
    }

    document.querySelector('#service-search').addEventListener('input', renderServices);
    document.querySelector('#new-service').addEventListener('click', () => openService());
    document.querySelector('#services-list').addEventListener('click', async (event) => {
        const edit = event.target.closest('[data-service-edit]');
        const copy = event.target.closest('[data-service-copy]');
        const archive = event.target.closest('[data-service-archive]');
        if (edit) openService(state.services.find((item) => item.id === edit.dataset.serviceEdit));
        if (copy) await mutate(`/api/master-data/services/${copy.dataset.serviceCopy}/copy`, {}, 'Stammposition kopiert.');
        if (archive) {
            const item = state.services.find((entry) => entry.id === archive.dataset.serviceArchive);
            await mutate(`/api/master-data/services/${item.id}/archive`, { archived: item.active }, item.active ? 'Stammposition archiviert.' : 'Stammposition reaktiviert.');
        }
    });

    function openService(item = null) {
        serviceForm.reset();
        serviceForm.elements.id.value = item?.id || '';
        serviceForm.elements.articleNumber.value = item?.articleNumber || '';
        const canAssignAutomatically = item === null || !item.articleNumber;
        serviceForm.elements.autoArticleNumber.checked = canAssignAutomatically;
        serviceForm.elements.autoArticleNumber.closest('.check-inline').hidden = !canAssignAutomatically;
        serviceForm.elements.articleNumber.disabled = canAssignAutomatically;
        serviceForm.elements.shortText.value = item?.shortText || '';
        serviceForm.elements.longText.value = item?.longText || '';
        serviceForm.elements.unit.value = item?.unit || '';
        state.components = structuredClone(item?.components || []);
        masterPricePreview.hidden = true;
        pendingPriceUpdates = [];
        renderComponents();
        renderServiceTotals(item?.calculation || null);
        serviceForm.hidden = false;
        dirty = false;
        serviceForm.elements.shortText.focus();
    }

    function renderMasterComponentOptions() {
        document.querySelector('#component-master-select').innerHTML = '<option value="">Stammartikel auswählen …</option>' + state.costItems.filter((item) => item.active).map((item) =>
            `<option value="${item.id}">${escapeHtml(item.name)} · ${escapeHtml(typeLabels[item.calculationType])} · ${formatEuro(item.salesPrice)}</option>`
        ).join('');
    }

    function clearMasterPricePreview() {
        masterPricePreview.hidden = true;
        pendingPriceUpdates = [];
    }

    function renderComponents() {
        document.querySelector('#component-count').textContent = `(${state.components.length})`;
        document.querySelector('#components-list').innerHTML = state.components.length ? state.components.map((component, index) => `
            <div class="component-row" data-component-index="${index}">
                <div class="component-primary-grid">
                    <div class="component-origin"><span>Herkunft</span><strong>${component.masterDataId ? `Aus Artikelstamm${component.sourceArticleNumber ? ` · ${escapeHtml(component.sourceArticleNumber)}` : ' · ohne Artikelnummer'}` : 'Manuell angelegt'}</strong></div>
                    <label>Bezeichnung<input data-field="name" value="${escapeHtml(component.name)}" maxlength="250" required></label>
                    <label>Typ<select data-field="calculationType">${Object.entries(typeLabels).map(([value, label]) => `<option value="${value}" ${component.calculationType === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
                    <label>Einheit<input data-field="unit" value="${escapeHtml(component.unit)}" maxlength="40" required></label>
                    <div class="component-controls-actions"><button type="button" class="table-action" data-component-up ${index === 0 ? 'disabled' : ''} title="Nach oben" aria-label="Nach oben">↑</button><button type="button" class="table-action" data-component-down ${index === state.components.length - 1 ? 'disabled' : ''} title="Nach unten" aria-label="Nach unten">↓</button><button type="button" class="table-action" data-component-copy>Kopieren</button><button type="button" class="table-action danger-text" data-component-remove>Entfernen</button></div>
                </div>
                <div class="component-price-grid">
                    <label>Menge je Leistungseinheit<input data-field="quantity" value="${escapeHtml(decimalInput(component.quantity, '0.000'))}" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,3})?" required></label>
                    <label>Kosten €/Bausteineinheit<input data-field="costPrice" value="${escapeHtml(decimalInput(component.costPrice, '0.0000'))}" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" required></label>
                    <label>Zuschlag auf Kosten %<input data-field="markupPercent" value="${escapeHtml(decimalInput(component.markupPercent, '0.00'))}" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]{1,2})?" required></label>
                    <div class="component-sales"><span>VK-Anteil je Leistungseinheit</span><strong data-component-sales="${index}">${formatEuro(component.salesPrice || 0)}</strong></div>
                    <label>Preisbezug<select data-field="priceMode" ${component.masterDataId ? '' : 'disabled'}>${component.masterDataId ? `<option value="master" ${component.priceMode === 'master' ? 'selected' : ''}>Artikelstammpreis</option><option value="locked" ${component.priceMode === 'locked' ? 'selected' : ''}>Artikelstammpreis gesperrt</option>` : ''}<option value="manual" ${component.priceMode === 'manual' || !component.masterDataId ? 'selected' : ''}>Manueller Preis</option></select></label>
                </div>
            </div>`).join('') : '<p class="muted">Noch keine Kalkulationsbausteine. Wählen Sie einen Stammartikel oder legen Sie einen Baustein manuell an.</p>';
    }

    document.querySelector('#add-master-component').addEventListener('click', () => {
        const id = document.querySelector('#component-master-select').value;
        const item = state.costItems.find((entry) => entry.id === id);
        if (!item) return;
        // Neue IDs werden erst serverseitig erzeugt, damit das Hinzufügen auch
        // in Browsern ohne sichere HTTPS-Browserfunktionen im LAN funktioniert.
        state.components.push({ id: null, masterDataId: item.id, sourceArticleNumber: item.articleNumber, name: item.name, calculationType: item.calculationType, unit: item.unit, quantity: '1.000', costPrice: item.costPrice, markupPercent: item.markupPercent, priceMode: 'master', salesPrice: item.salesPrice });
        clearMasterPricePreview();
        dirty = true;
        renderComponents();
        scheduleServicePreview();
    });
    document.querySelector('#add-manual-component').addEventListener('click', () => {
        state.components.push({ id: null, masterDataId: null, sourceArticleNumber: null, name: '', calculationType: 'material', unit: 'Stk.', quantity: '1.000', costPrice: '0.0000', markupPercent: '0.00', priceMode: 'manual', salesPrice: '0.00' });
        clearMasterPricePreview();
        dirty = true;
        renderComponents();
        document.querySelector('#components-list .component-row:last-child input[data-field="name"]')?.focus();
        scheduleServicePreview();
    });
    document.querySelector('#preview-master-prices').addEventListener('click', async () => {
        try {
            const preview = await api('/api/master-data/preview-master-price-updates', {
                method: 'POST', body: { components: normalizedComponents() },
            });
            pendingPriceUpdates = preview.updates;
            const warnings = preview.warnings.map((warning) => `<li>${escapeHtml(warning)}</li>`).join('');
            const changes = preview.changes.map((change) => `<tr><td><strong>${escapeHtml(change.name)}</strong><br><span class="muted">${escapeHtml(change.articleNumber || 'ohne Artikelnummer')}</span></td><td>Kosten ${formatEuro(change.oldCostPrice)}<br>Zuschlag ${escapeHtml(decimalInput(change.oldMarkupPercent, '0.00'))} %<br>VK-Anteil ${formatEuro(change.oldSalesPrice)}</td><td>Kosten ${formatEuro(change.newCostPrice)}<br>Zuschlag ${escapeHtml(decimalInput(change.newMarkupPercent, '0.00'))} %<br>VK-Anteil ${formatEuro(change.newSalesPrice)}</td></tr>`).join('');
            masterPricePreview.innerHTML = `<h3>Vorschau der Preisaktualisierung</h3>${warnings ? `<ul class="notice notice-error">${warnings}</ul>` : ''}${changes ? `<div class="table-wrap"><table><thead><tr><th>Baustein</th><th>Bisher</th><th>Neu</th></tr></thead><tbody>${changes}</tbody></table></div><p class="muted">Gesperrte und manuelle Preise werden nicht verändert. Die angezeigten Änderungen werden zunächst nur in den Entwurf übernommen und erst mit „Stammposition speichern“ dauerhaft gespeichert.</p><div class="form-actions"><button type="button" class="button" data-apply-master-prices>Änderungen übernehmen</button><button type="button" class="button button-light" data-cancel-master-prices>Abbrechen</button></div>` : '<p>Alle aktualisierbaren Artikelstammpreise sind bereits auf dem aktuellen Stand. Gesperrte und manuelle Preise wurden nicht berücksichtigt.</p><button type="button" class="button button-light" data-cancel-master-prices>Schließen</button>'}`;
            masterPricePreview.hidden = false;
        } catch (error) {
            notify(error.message, 'error');
        }
    });
    masterPricePreview.addEventListener('click', (event) => {
        if (event.target.closest('[data-cancel-master-prices]')) {
            masterPricePreview.hidden = true;
            pendingPriceUpdates = [];
            return;
        }
        if (!event.target.closest('[data-apply-master-prices]')) return;
        pendingPriceUpdates.forEach((update) => {
            const component = state.components[update.index];
            if (!component) return;
            component.costPrice = update.costPrice;
            component.markupPercent = update.markupPercent;
            component.salesPrice = update.salesPrice;
            component.sourceArticleNumber = update.sourceArticleNumber;
        });
        pendingPriceUpdates = [];
        masterPricePreview.hidden = true;
        dirty = true;
        renderComponents();
        scheduleServicePreview();
        notify('Preisänderungen wurden in den ungespeicherten Entwurf übernommen.');
    });
    document.querySelector('#components-list').addEventListener('input', (event) => {
        const row = event.target.closest('[data-component-index]');
        if (!row || !event.target.dataset.field) return;
        const component = state.components[Number(row.dataset.componentIndex)];
        clearMasterPricePreview();
        const field = event.target.dataset.field;
        component[field] = event.target.value;
        if (component.masterDataId && ['costPrice', 'markupPercent'].includes(field)) {
            component.priceMode = 'manual';
            row.querySelector('[data-field="priceMode"]').value = 'manual';
        }
        if (field === 'priceMode' && event.target.value === 'master') {
            const master = state.costItems.find((item) => item.id === component.masterDataId);
            if (master) {
                component.costPrice = master.costPrice;
                component.markupPercent = master.markupPercent;
                row.querySelector('[data-field="costPrice"]').value = decimalInput(master.costPrice, '0.0000');
                row.querySelector('[data-field="markupPercent"]').value = decimalInput(master.markupPercent, '0.00');
            }
        }
        scheduleServicePreview();
    });
    document.querySelector('#components-list').addEventListener('click', (event) => {
        const row = event.target.closest('[data-component-index]');
        if (!row) return;
        const index = Number(row.dataset.componentIndex);
        if (event.target.closest('[data-component-remove]')) state.components.splice(index, 1);
        else if (event.target.closest('[data-component-copy]')) state.components.splice(index + 1, 0, { ...structuredClone(state.components[index]), id: null });
        else if (event.target.closest('[data-component-up]') && index > 0) [state.components[index - 1], state.components[index]] = [state.components[index], state.components[index - 1]];
        else if (event.target.closest('[data-component-down]') && index < state.components.length - 1) [state.components[index + 1], state.components[index]] = [state.components[index], state.components[index + 1]];
        else return;
        clearMasterPricePreview();
        dirty = true;
        renderComponents();
        scheduleServicePreview();
    });

    serviceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const id = serviceForm.elements.id.value;
        const saved = await mutate(id ? `/api/master-data/services/${id}` : '/api/master-data/services', {
            shortText: serviceForm.elements.shortText.value,
            articleNumber: serviceForm.elements.articleNumber.value || null,
            autoArticleNumber: serviceForm.elements.autoArticleNumber.checked,
            longText: serviceForm.elements.longText.value,
            unit: serviceForm.elements.unit.value,
            components: normalizedComponents(),
        }, id ? 'Stammposition gespeichert.' : 'Stammposition angelegt.', id ? 'PUT' : 'POST');
        if (!saved) return;
        dirty = false;
        serviceForm.hidden = true;
    });
    serviceForm.elements.autoArticleNumber.addEventListener('change', () => {
        const automatic = serviceForm.elements.autoArticleNumber.checked;
        serviceForm.elements.articleNumber.disabled = automatic;
        if (automatic) serviceForm.elements.articleNumber.value = '';
        else serviceForm.elements.articleNumber.focus();
    });
    serviceForm.elements.unit.addEventListener('input', scheduleServicePreview);

    function scheduleServicePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(async () => {
            if (!state.components.every((component) => component.name && component.unit && component.quantity && component.costPrice && component.markupPercent)) return;
            try {
                const preview = await api('/api/master-data/preview-price', { method: 'POST', body: { components: normalizedComponents() } });
                preview.componentSalesPrices.forEach((price, index) => {
                    state.components[index].salesPrice = price;
                    const output = document.querySelector(`[data-component-sales="${index}"]`);
                    if (output) output.textContent = formatEuro(price);
                });
                renderServiceTotals(preview);
            } catch (error) {
                document.querySelector('#service-totals').textContent = error.message;
            }
        }, 180);
    }

    function renderServiceTotals(calculation) {
        const target = document.querySelector('#service-totals');
        if (!calculation) {
            target.innerHTML = '<span class="muted">Noch keine Kalkulation.</span>';
            return;
        }
        const optionalCosts = [
            Number(calculation.externalCosts) !== 0 ? `<div><span>Fremdleistung EK</span><strong>${formatEuro(calculation.externalCosts)}</strong></div>` : '',
            Number(calculation.otherCosts) !== 0 ? `<div><span>Sonstige Kosten</span><strong>${formatEuro(calculation.otherCosts)}</strong></div>` : '',
        ].join('');
        const unit = serviceForm.elements.unit.value.trim();
        target.innerHTML = `<div><span>Material EK</span><strong>${formatEuro(calculation.materialCosts)}</strong></div><div><span>Lohnkosten</span><strong>${formatEuro(calculation.laborCosts)}</strong></div>${optionalCosts}<div><span>Selbstkosten gesamt</span><strong>${formatEuro(calculation.totalCosts)}</strong></div><div><span>Zuschläge gesamt</span><strong>${formatEuro(calculation.markupTotal ?? calculation.contributionMargin)}</strong></div><div class="total"><span>Verkaufspreis Position netto${unit ? ` je ${escapeHtml(unit)}` : ''}</span><strong>${formatEuro(calculation.calculatedUnitPrice)}</strong></div>`;
    }

    function normalizedComponents() {
        return state.components.map((component) => ({
            ...component,
            quantity: decimalValue(component.quantity),
            costPrice: decimalValue(component.costPrice),
            markupPercent: decimalValue(component.markupPercent),
        }));
    }

    async function mutate(path, body, successMessage, method = 'POST') {
        try {
            await api(path, { method, body });
            notify(successMessage);
            await load();
            return true;
        } catch (error) {
            notify(error.message, 'error');
            return false;
        }
    }

    function closeEditor(form) {
        if (dirty && !window.confirm('Ungespeicherte Änderungen verwerfen?')) return;
        dirty = false;
        form.hidden = true;
    }
    root.querySelectorAll('[data-close-form]').forEach((button) => button.addEventListener('click', () => closeEditor(button.closest('form'))));
    root.querySelectorAll('[data-close-service]').forEach((button) => button.addEventListener('click', () => closeEditor(serviceForm)));

    load();
})();
