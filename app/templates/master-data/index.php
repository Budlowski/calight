<?php
$title = 'Stammdaten';
$contentClass = 'content-wide';
?>
<div id="master-data-app" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading">
        <div>
            <h1>Stammdaten</h1>
            <p class="muted">Artikelstamm, Positionsstamm, Kategorien und Lieferanten zentral verwalten.</p>
        </div>
        <label class="check-inline"><input type="checkbox" id="show-archived"> Archivierte anzeigen</label>
    </div>

    <div id="master-message" class="notice" role="status" hidden></div>

    <nav class="tabs" aria-label="Stammdatenbereiche">
        <button class="tab-button active" type="button" data-tab="cost-items">Artikelstamm</button>
        <button class="tab-button" type="button" data-tab="services">Positionsstamm</button>
        <button class="tab-button" type="button" data-tab="trailing-texts">Schlepptexte</button>
        <button class="tab-button" type="button" data-tab="categories">Kategorien</button>
        <button class="tab-button" type="button" data-tab="suppliers">Lieferanten</button>
    </nav>

    <section class="tab-panel active" data-panel="cost-items">
        <div class="master-toolbar">
            <input id="cost-search" type="search" placeholder="Artikelnummer, Bezeichnung oder Beschreibung suchen" aria-label="Artikelstamm durchsuchen">
            <select id="cost-category-filter" aria-label="Kategorie filtern"><option value="">Alle Kategorien</option></select>
            <select id="cost-type-filter" aria-label="Kalkulationstyp filtern">
                <option value="">Alle Typen</option><option value="material">Material</option><option value="labor">Lohn</option>
                <option value="external">Fremdleistung</option><option value="other">Sonstige Kosten</option>
            </select>
            <div class="master-exchange-actions"><button class="button" type="button" id="new-cost-item">Neuer Stammartikel</button><button class="button button-light" type="button" data-icon="file-down" data-master-export="cost-items">JSON exportieren</button><button class="button button-light" type="button" data-icon="file-up" data-master-import="cost-items">JSON importieren</button></div>
        </div>
        <div class="master-split">
            <div class="table-wrap">
                <table><thead><tr><th>Artikel-Nr.</th><th>Bezeichnung</th><th>Typ</th><th>Einheit</th><th>EK/Kostensatz</th><th>VK netto</th><th></th></tr></thead><tbody id="cost-items-body"></tbody></table>
            </div>
            <form id="cost-item-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Stammartikel</h2></div>
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

    <section class="tab-panel" data-panel="trailing-texts" hidden>
        <div class="master-toolbar master-toolbar-actions-only"><div class="master-exchange-actions"><button class="button" type="button" id="new-trailing-text">Neuer Schlepptext</button><button class="button button-light" type="button" data-icon="file-down" data-master-export="trailing-texts">JSON exportieren</button><button class="button button-light" type="button" data-icon="file-up" data-master-import="trailing-texts">JSON importieren</button></div></div>
        <div class="master-split"><div class="table-wrap"><table><thead><tr><th>Kürzel</th><th>Bezeichnung</th><th></th></tr></thead><tbody id="trailing-texts-body"></tbody></table></div><form id="trailing-text-form" class="card form-stack master-editor" hidden><input type="hidden" name="id"><div class="editor-heading"><h2>Schlepptext</h2></div><label>Kürzel<input name="code" maxlength="30" required></label><label>Bezeichnung und Ausgabetext<input name="name" maxlength="500" required></label><div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-trailing-text>Abbrechen</button></div></form></div>
    </section>

    <section class="tab-panel" data-panel="services" hidden>
        <div class="master-toolbar">
            <input id="service-search" type="search" placeholder="Positionsnummer oder Bezeichnung durchsuchen" aria-label="Positionsstamm durchsuchen">
            <div class="master-exchange-actions"><button class="button" type="button" id="new-service">Neue Stammposition</button><button class="button button-light" type="button" data-icon="file-down" data-master-export="services">JSON exportieren</button><button class="button button-light" type="button" data-icon="file-up" data-master-import="services">JSON importieren</button></div>
        </div>
        <div class="master-split service-master-split">
            <div id="services-list" class="service-list"></div>
            <form id="service-form" class="card form-stack service-editor" hidden>
            <input type="hidden" name="id">
            <div class="editor-heading"><h2>Stammposition</h2></div>
            <div class="form-grid service-header-grid">
                <div class="service-number-field">
                    <div class="service-number-heading">
                        <label for="service-article-number">Positionsnummer</label>
                        <label class="check-inline auto-number-option"><input type="checkbox" name="autoArticleNumber" checked> Automatisch vergeben</label>
                    </div>
                    <input id="service-article-number" name="articleNumber" maxlength="100">
                </div>
                <label>Kurztext<input name="shortText" maxlength="250" required></label>
                <label>Einheit<input name="unit" maxlength="40" required></label>
                <label>Schlepptext<select name="trailingTextId"><option value="">Kein Schlepptext</option></select></label>
            </div>
            <label>Langtext<textarea name="longText" rows="4" maxlength="10000"></textarea></label>
            <details class="components-panel" open>
                <summary>Kalkulationsbausteine <span id="component-count"></span></summary>
                <div class="component-add-row">
                    <input id="component-master-select" list="component-master-options" placeholder="Artikelnummer oder Bezeichnung suchen" autocomplete="off"><datalist id="component-master-options"></datalist>
                    <button class="button button-light" type="button" id="add-master-component">Übernehmen</button>
                    <button class="button button-light" type="button" id="add-manual-component">Manuell anlegen</button>
                    <button class="button button-light" type="button" id="preview-master-prices">Preise aktualisieren</button>
                </div>
                <div id="master-price-preview" class="price-update-preview" hidden></div>
                <div id="components-list" class="components-list"></div>
            </details>
            <div class="service-totals" id="service-totals"></div>
            <div class="form-actions"><button class="button" type="submit">Stammposition speichern</button><button class="button button-light" type="button" data-close-service>Abbrechen</button></div>
            </form>
        </div>
    </section>

    <section class="tab-panel" data-panel="categories" hidden>
        <div class="master-toolbar"><input id="category-search" type="search" placeholder="Kategorien durchsuchen"><div class="master-exchange-actions"><button class="button" type="button" id="new-category">Neue Kategorie</button><button class="button button-light" type="button" data-icon="file-down" data-master-export="categories">JSON exportieren</button><button class="button button-light" type="button" data-icon="file-up" data-master-import="categories">JSON importieren</button></div></div>
        <div class="master-split">
            <div id="category-tree" class="category-tree card"></div>
            <form id="category-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Kategorie</h2></div>
                <label>Bezeichnung<input name="name" maxlength="150" required></label>
                <label>Übergeordnete Kategorie<select name="parentId"><option value="">Oberste Ebene</option></select></label>
                <label>Sortierung<input name="sortOrder" type="number" min="0" step="1" value="0" required></label>
                <label>Beschreibung<textarea name="description" rows="3" maxlength="2000"></textarea></label>
                <div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-form>Abbrechen</button></div>
            </form>
        </div>
    </section>

    <section class="tab-panel" data-panel="suppliers" hidden>
        <div class="master-toolbar"><input id="supplier-search" type="search" placeholder="Lieferanten durchsuchen"><div class="master-exchange-actions"><button class="button" type="button" id="new-supplier">Neuer Lieferant</button><button class="button button-light" type="button" data-icon="file-down" data-master-export="suppliers">JSON exportieren</button><button class="button button-light" type="button" data-icon="file-up" data-master-import="suppliers">JSON importieren</button></div></div>
        <div class="master-split">
            <div class="table-wrap"><table><thead><tr><th>Nummer</th><th>Name</th><th>Kontakt</th><th></th></tr></thead><tbody id="suppliers-body"></tbody></table></div>
            <form id="supplier-form" class="card form-stack master-editor" hidden>
                <input type="hidden" name="id">
                <div class="editor-heading"><h2>Lieferant</h2></div>
                <label>Lieferantennummer<input name="supplierNumber" maxlength="80"></label>
                <label>Name<input name="name" maxlength="200" required></label>
                <label>Kontakt und Bemerkungen<textarea name="contact" rows="5" maxlength="2000"></textarea></label>
                <div class="form-actions"><button class="button" type="submit">Speichern</button><button class="button button-light" type="button" data-close-form>Abbrechen</button></div>
            </form>
        </div>
    </section>
</div>
<input id="master-import-file" type="file" accept="application/json,.json" hidden>
<script src="/assets/master-data.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/master-data.js')) ?>" defer></script>
