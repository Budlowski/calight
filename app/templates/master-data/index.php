<?php $title = 'Stammdaten'; ?>
<div id="master-data-app" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Stammdaten</h1>
            <p class="muted">Kategorien, Kostenstamm, Leistungspositionen und Lieferanten zentral verwalten.</p>
        </div>
        <label class="check-inline"><input type="checkbox" id="show-archived"> Archivierte anzeigen</label>
    </div>

    <div id="master-message" class="notice" role="status" hidden></div>

    <nav class="tabs" aria-label="Stammdatenbereiche">
        <button class="tab-button active" type="button" data-tab="cost-items">Kostenstamm</button>
        <button class="tab-button" type="button" data-tab="services">Leistungspositionen</button>
        <button class="tab-button" type="button" data-tab="categories">Kategorien</button>
        <button class="tab-button" type="button" data-tab="suppliers">Lieferanten</button>
    </nav>

    <section class="tab-panel active" data-panel="cost-items">
        <div class="master-toolbar">
            <input id="cost-search" type="search" placeholder="Artikelnummer, Bezeichnung oder Beschreibung suchen" aria-label="Kostenstamm durchsuchen">
            <select id="cost-category-filter" aria-label="Kategorie filtern"><option value="">Alle Kategorien</option></select>
            <select id="cost-type-filter" aria-label="Kalkulationstyp filtern">
                <option value="">Alle Typen</option><option value="material">Material</option><option value="labor">Lohn</option>
                <option value="external">Fremdleistung</option><option value="other">Sonstige Kosten</option>
            </select>
            <button class="button" type="button" id="new-cost-item">Neuer Kostensatz</button>
        </div>
        <div class="master-split">
            <div class="table-wrap">
                <table><thead><tr><th>Artikel-Nr.</th><th>Bezeichnung</th><th>Typ</th><th>Einheit</th><th>EK/Kostensatz</th><th>VK netto</th><th></th></tr></thead><tbody id="cost-items-body"></tbody></table>
            </div>
            <form id="cost-item-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Kostensatz</h2><button type="button" class="icon-button" data-close-form aria-label="Formular schließen">×</button></div>
                <div class="form-grid">
                    <label>Artikelnummer<input name="articleNumber" maxlength="100"></label>
                    <label>Bezeichnung<input name="name" maxlength="250" required></label>
                    <label>Kalkulationstyp<select name="calculationType" required><option value="material">Material</option><option value="labor">Lohn</option><option value="external">Fremdleistung</option><option value="other">Sonstige Kosten</option></select></label>
                    <label>Einheit<input name="unit" maxlength="40" required placeholder="Stk., h, m …"></label>
                    <label>Kategorie<select name="categoryId"><option value="">Keine Kategorie</option></select></label>
                    <label>Lieferant<select name="supplierId"><option value="">Kein Lieferant</option></select></label>
                    <label>Netto-Einkaufspreis / Kostensatz<input name="costPrice" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" required value="0.0000"></label>
                    <label>Zuschlag in %<input name="markupPercent" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]{1,2})?" required value="0.00"></label>
                    <label>Lieferantenartikelnummer<input name="supplierArticleNumber" maxlength="100"></label>
                    <div class="price-preview"><span>Verkaufspreis netto</span><strong id="cost-sales-price">0,00 €</strong></div>
                </div>
                <label>Beschreibung<textarea name="description" rows="3" maxlength="5000"></textarea></label>
                <div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-form>Abbrechen</button></div>
            </form>
        </div>
    </section>

    <section class="tab-panel" data-panel="services" hidden>
        <div class="master-toolbar">
            <input id="service-search" type="search" placeholder="Leistungspositionen durchsuchen" aria-label="Leistungspositionen durchsuchen">
            <button class="button" type="button" id="new-service">Neue Leistungsposition</button>
        </div>
        <div id="services-list" class="service-list"></div>
        <form id="service-form" class="card form-stack service-editor" hidden>
            <input type="hidden" name="id">
            <div class="editor-heading"><h2>Leistungsposition</h2><button type="button" class="icon-button" data-close-service aria-label="Formular schließen">×</button></div>
            <div class="form-grid">
                <label>Kurztext<input name="shortText" maxlength="250" required></label>
                <label>Einheit<input name="unit" maxlength="40" required></label>
            </div>
            <label>Langtext<textarea name="longText" rows="4" maxlength="10000"></textarea></label>
            <details class="components-panel" open>
                <summary>Kalkulationsbausteine <span id="component-count"></span></summary>
                <div class="component-add-row">
                    <select id="component-master-select"><option value="">Aus Kostenstamm auswählen …</option></select>
                    <button class="button button-light" type="button" id="add-master-component">Übernehmen</button>
                    <button class="button button-light" type="button" id="add-manual-component">Manuell anlegen</button>
                </div>
                <div id="components-list" class="components-list"></div>
            </details>
            <div class="service-totals" id="service-totals"></div>
            <div class="form-actions"><button class="button" type="submit">Leistungsposition speichern</button><button class="button button-light" type="button" data-close-service>Abbrechen</button></div>
        </form>
    </section>

    <section class="tab-panel" data-panel="categories" hidden>
        <div class="master-toolbar"><input id="category-search" type="search" placeholder="Kategorien durchsuchen"><button class="button" type="button" id="new-category">Neue Kategorie</button></div>
        <div class="master-split">
            <div id="category-tree" class="category-tree card"></div>
            <form id="category-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Kategorie</h2><button type="button" class="icon-button" data-close-form>×</button></div>
                <label>Bezeichnung<input name="name" maxlength="150" required></label>
                <label>Übergeordnete Kategorie<select name="parentId"><option value="">Oberste Ebene</option></select></label>
                <label>Sortierung<input name="sortOrder" type="number" min="0" step="1" value="0" required></label>
                <label>Beschreibung<textarea name="description" rows="3" maxlength="2000"></textarea></label>
                <div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-form>Abbrechen</button></div>
            </form>
        </div>
    </section>

    <section class="tab-panel" data-panel="suppliers" hidden>
        <div class="master-toolbar"><input id="supplier-search" type="search" placeholder="Lieferanten durchsuchen"><button class="button" type="button" id="new-supplier">Neuer Lieferant</button></div>
        <div class="master-split">
            <div class="table-wrap"><table><thead><tr><th>Nummer</th><th>Name</th><th>Kontakt</th><th></th></tr></thead><tbody id="suppliers-body"></tbody></table></div>
            <form id="supplier-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Lieferant</h2><button type="button" class="icon-button" data-close-form>×</button></div>
                <label>Lieferantennummer<input name="supplierNumber" maxlength="80"></label>
                <label>Name<input name="name" maxlength="200" required></label>
                <label>Kontakt und Bemerkungen<textarea name="contact" rows="5" maxlength="2000"></textarea></label>
                <div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-form>Abbrechen</button></div>
            </form>
        </div>
    </section>
</div>
<script src="/assets/master-data.js" defer></script>
