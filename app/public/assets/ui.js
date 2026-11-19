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
    // Menüs bleiben im Bildschirm: Panel bei Platzmangel linksbündig statt rechtsbündig,
    // bei zu wenig Höhe scrollbar. Zustand wird für Screenreader über aria-expanded gemeldet.
    const menuItems = menu => [...menu.querySelectorAll('.context-menu-panel > a, .context-menu-panel > button:not(:disabled)')];
    document.addEventListener('toggle', event => {
        const menu = event.target;
        if (!(menu instanceof HTMLDetailsElement) || !menu.classList.contains('context-menu')) return;
        menu.querySelector(':scope > summary')?.setAttribute('aria-expanded', String(menu.open));
        const panel = menu.querySelector('.context-menu-panel');
        if (!menu.open || !panel) return;
        // Ausrichtung nach Lage des Menüknopfs: linke Bildschirmhälfte → linksbündig öffnen.
        const summaryRect = menu.querySelector(':scope > summary')?.getBoundingClientRect();
        panel.classList.toggle('context-menu-panel-start', !!summaryRect && summaryRect.left + summaryRect.width / 2 < innerWidth / 2);
        panel.style.maxHeight = '';
        const rect = panel.getBoundingClientRect();
        const available = innerHeight - rect.top - 8;
        if (rect.height > available && available > 120) panel.style.maxHeight = `${Math.floor(available)}px`;
    }, true);
    document.querySelectorAll('details.context-menu > summary').forEach(summary => summary.setAttribute('aria-expanded', 'false'));
    new MutationObserver(() => document.querySelectorAll('details.context-menu > summary:not([aria-expanded])').forEach(summary => summary.setAttribute('aria-expanded', 'false')))
        .observe(document.body, {childList: true, subtree: true});

    // Tastatur: Pfeiltasten bewegen sich durch die Einträge, Escape schließt und
    // setzt den Fokus zurück auf den Menüknopf, Tab verlässt das Menü.
    document.addEventListener('keydown', event => {
        const menu = event.target instanceof Element ? event.target.closest('details.context-menu') : null;
        if (event.key === 'Escape') {
            const open = [...document.querySelectorAll('details.context-menu[open]')];
            open.forEach(item => item.removeAttribute('open'));
            if (menu && open.includes(menu)) menu.querySelector(':scope > summary')?.focus();
            return;
        }
        if (!menu) return;
        const items = menuItems(menu);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!menu.open) menu.open = true;
            const index = items.indexOf(document.activeElement);
            const next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index <= 0 ? items.length - 1 : index - 1);
            items[next]?.focus();
        } else if (event.key === 'Tab' && menu.open && items.includes(document.activeElement)) {
            menu.removeAttribute('open');
        }
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
