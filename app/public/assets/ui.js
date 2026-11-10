(() => {
    'use strict';

    document.querySelectorAll('.form-actions button[type="submit"]').forEach(button => {
        button.dataset.icon ||= 'save';
        button.title ||= `${button.textContent.trim()} (Strg+S)`;
        button.setAttribute('aria-keyshortcuts', 'Control+S Meta+S');
    });
    document.querySelectorAll('#new-customer, #new-project, #new-cost-item, #new-service, #new-category, #new-supplier, #add-contact, #add-address').forEach(button => {
        button.dataset.icon ||= 'plus';
    });

    const inferredIcons = [
        ['[data-up]', 'arrow-up'],
        ['[data-down]', 'arrow-down'],
        ['[data-component-copy], [data-copy]', 'copy'],
        ['[data-component-remove], [data-remove]', 'trash'],
        ['[data-add-manual], [data-add-cost], [data-save-new]', 'plus'],
        ['[data-preview-prices]', 'refresh-cw'],
        ['[data-save-master], [data-update-existing]', 'save']
    ];

    const inferIcons = root => inferredIcons.forEach(([selector, icon]) => {
        const elements = [];
        if (root instanceof Element && root.matches(selector)) elements.push(root);
        elements.push(...root.querySelectorAll?.(selector) ?? []);
        elements.forEach(element => element.dataset.icon ||= icon);
    });

    const decorateIcons = (root = document) => {
        inferIcons(root);
        const elements = [];
        if (root instanceof Element && root.matches('[data-icon]')) elements.push(root);
        elements.push(...root.querySelectorAll?.('[data-icon]:not(.has-ui-icon)') ?? []);
        elements.forEach(element => {
            if (element.classList.contains('has-ui-icon')) return;
            const icon = document.createElement('span');
            icon.className = `ui-icon icon-${element.dataset.icon}`;
            icon.setAttribute('aria-hidden', 'true');
            element.prepend(icon);
            element.classList.add('has-ui-icon');
        });
    };

    decorateIcons();
    new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
        if (node instanceof Element) decorateIcons(node);
    }))).observe(document.body, {childList: true, subtree: true});

    // Globales Aktionsmenü: Auswahl, Außenklick und Escape schließen offene Menüs.
    document.addEventListener('click', event => document.querySelectorAll('details.context-menu[open]').forEach(menu => {
        if (!menu.contains(event.target) || event.target.closest('.context-menu-panel a, .context-menu-panel button')) menu.removeAttribute('open');
    }));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') document.querySelectorAll('details.context-menu[open]').forEach(menu => menu.removeAttribute('open'));
    });

    // Alle destruktiven Aktionen laufen zentral durch dieselbe Sicherheitsabfrage.
    // Der Text-Fallback erfasst auch serverseitig gerenderte Löschbuttons, während
    // die Datenattribute dynamisch erzeugte Editor-Aktionen eindeutig markieren.
    const destructiveSelector = [
        '[data-remove]', '[data-component-remove]', '[data-remove-contact]',
        '[data-remove-address]', '[data-trailing-delete]', '[data-delete-offer]'
    ].join(',');
    const confirmedForms = new WeakSet();
    const confirmedControls = new WeakSet();
    const isDestructiveControl = element => element.matches(destructiveSelector)
        || /^(Löschen|Entfernen)$/i.test(element.textContent.trim());
    const confirmDestructive = element => CalightModal.confirm({
        title: 'Löschen bestätigen',
        content: element.dataset.confirmMessage || 'Element wirklich löschen beziehungsweise entfernen?',
        confirmLabel: 'Löschen',
        danger: true,
    });

    document.addEventListener('click', async event => {
        const control = event.target.closest('button, a');
        if (!control || control.disabled || !isDestructiveControl(control)) return;
        if (confirmedControls.has(control)) {
            confirmedControls.delete(control);
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!await confirmDestructive(control)) return;
        confirmedControls.add(control);
        if (control.form) confirmedForms.add(control.form);
        control.click();
    }, true);

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (confirmedForms.has(form)) {
            confirmedForms.delete(form);
            return;
        }
        const destructive = /\/delete(?:$|[/?#])/i.test(form.action)
            || form.querySelector('button[type="submit"]')?.textContent.trim().match(/^(Löschen|Entfernen)$/i);
        if (!destructive) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (!await confirmDestructive(form)) return;
        confirmedForms.add(form);
        form.requestSubmit();
    }, true);

    const visible = element => element && !element.hidden && element.getClientRects().length > 0;
    const editorForms = [
        '#cost-item-form', '#service-form', '#category-form', '#supplier-form',
        '#customer-form', '#project-form', '#settings-form', '#employee-form', '#daily-report-form'
    ];

    document.addEventListener('keydown', event => {
        if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 's') return;

        const offerSave = document.querySelector('#save-offer');
        if (visible(offerSave)) {
            event.preventDefault();
            if (!offerSave.disabled) offerSave.click();
            return;
        }

        const focusedForm = document.activeElement?.closest('form');
        const form = editorForms.map(selector => document.querySelector(selector)).find(visible);
        const target = focusedForm && editorForms.some(selector => focusedForm.matches(selector)) ? focusedForm : form;
        if (!target) return;
        event.preventDefault();
        target.requestSubmit();
    });
})();
