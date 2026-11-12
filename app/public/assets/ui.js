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
        ['[data-trailing-edit], [data-supplier-edit], [data-cost-edit], [data-service-edit], [data-edit], [data-edit-order]', 'square-pen'],
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

    // Hauptnavigation auf schmalen Bildschirmen: seitliches Panel mit Menübutton.
    // Auf dem Desktop ist der Button ausgeblendet und die Sidebar immer sichtbar.
    const navToggle = document.querySelector('[data-nav-toggle]');
    const navigation = document.querySelector('#main-navigation');
    const navBackdrop = document.querySelector('[data-nav-backdrop]');
    if (navToggle && navigation) {
        const compactNavigation = matchMedia('(max-width: 1024px)');
        const isOpen = () => document.body.classList.contains('nav-open');
        const setOpen = (open, { restoreFocus = true } = {}) => {
            document.body.classList.toggle('nav-open', open);
            navToggle.setAttribute('aria-expanded', String(open));
            navToggle.setAttribute('aria-label', open ? 'Navigation schließen' : 'Navigation öffnen');
            if (navBackdrop) navBackdrop.hidden = !open;
            if (open) (navigation.querySelector('a[aria-current="page"]') || navigation.querySelector('a[href]:not([aria-disabled="true"])'))?.focus();
            else if (restoreFocus && navigation.contains(document.activeElement)) navToggle.focus();
        };
        navToggle.addEventListener('click', () => setOpen(!isOpen()));
        navBackdrop?.addEventListener('click', () => setOpen(false));
        navigation.addEventListener('click', event => {
            if (event.target.closest('a[href]') && compactNavigation.matches) setOpen(false, { restoreFocus: false });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isOpen()) setOpen(false);
        });
        // Beim Wechsel auf Desktopbreite darf kein offener Panelzustand zurückbleiben.
        compactNavigation.addEventListener('change', event => {
            if (!event.matches && isOpen()) setOpen(false, { restoreFocus: false });
        });
    }

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
        // Das Bestätigungsmodal ist bereits die Sicherheitsgrenze. Seine eigene
        // Löschaktion darf nicht erneut vom globalen Interceptor abgefangen werden.
        if (!control || control.closest('.global-modal') || control.disabled || !isDestructiveControl(control)) return;
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

        // Seiten mit eigenem Speichervorgang markieren ihren Button mit data-shortcut-save.
        const pageSave = document.querySelector('#save-offer, [data-shortcut-save]');
        if (visible(pageSave)) {
            event.preventDefault();
            if (!pageSave.disabled) pageSave.click();
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
