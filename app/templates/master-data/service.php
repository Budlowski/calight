<?php
$title = 'Stammposition';
$contentClass = 'content-wide';
?>
<div id="master-service-app" class="record-detail-page" data-id="<?= $escape($serviceId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li><a href="/master-data#services">Stammdaten</a></li>
            <li><a href="/master-data#services">Positionsstamm</a></li>
            <li aria-current="page" data-current-crumb>Stammposition</li>
        </ol>
    </nav>

    <form id="service-form" class="form-stack" novalidate>
        <section class="card order-header-card">
            <div class="page-heading order-detail-heading">
                <div class="order-heading-main">
                    <span class="service-number" data-number>Neue Stammposition</span>
                    <h1 data-title>Stammposition</h1>
                    <div class="document-status-line" data-status-line></div>
                </div>
                <div class="order-detail-actions">
                    <span class="measurement-dirty-hint" data-dirty-hint hidden>Ungespeicherte Änderungen</span>
                    <a class="button button-light" href="/master-data#services">Zurück</a>
                    <button class="button" type="submit" data-shortcut-save data-icon="save" title="Speichern (Strg+S)">Speichern</button>
                </div>
            </div>
        </section>

        <div id="master-message" class="notice" role="status" hidden></div>

        <section class="card form-stack">
            <div class="step-section-heading">
                <span class="step-section-number">1</span>
                <div><h2>Allgemeine Angaben</h2><small>Positionsnummer, Texte, Einheit und Schlepptext</small></div>
            </div>
            <div class="form-grid service-header-grid">
                <div class="service-number-field">
                    <div class="service-number-heading">
                        <label for="service-article-number">Positionsnummer</label>
                        <label class="check-inline auto-number-option"><input type="checkbox" name="autoArticleNumber" checked> Automatisch vergeben</label>
                    </div>
                    <input id="service-article-number" name="articleNumber" maxlength="100">
                </div>
                <label><span class="field-label">Kurztext <span aria-hidden="true">*</span></span><input name="shortText" maxlength="250" required></label>
                <label><span class="field-label">Einheit <span aria-hidden="true">*</span></span><input name="unit" maxlength="40" required></label>
                <label><span class="field-label">Schlepptext</span><select name="trailingTextId"><option value="">Kein Schlepptext</option></select></label>
            </div>
            <label><span class="field-label">Langtext</span><textarea name="longText" rows="4" maxlength="10000"></textarea></label>
        </section>

        <section class="card form-stack">
            <div class="step-section-heading">
                <span class="step-section-number">2</span>
                <div><h2>Kalkulationsbausteine <span id="component-count"></span></h2><small>Aus dem Artikelstamm übernehmen oder manuell anlegen</small></div>
                <div class="step-section-actions">
                    <button class="button button-light button-small" type="button" id="add-manual-component" data-icon="plus">Manuell anlegen</button>
                    <button class="button button-light button-small" type="button" id="preview-master-prices" data-icon="refresh-cw">Preise aktualisieren</button>
                </div>
            </div>
            <div class="component-add-row">
                <input id="component-master-select" list="component-master-options" placeholder="Artikelstamm durchsuchen" autocomplete="off" aria-label="Stammartikel suchen"><datalist id="component-master-options"></datalist>
                <button class="button button-light" type="button" id="add-master-component" data-icon="plus">Übernehmen</button>
            </div>
            <div id="master-price-preview" class="price-update-preview" hidden></div>
            <div id="components-list" class="components-list"></div>
            <div class="service-totals" id="service-totals"></div>
        </section>
    </form>
</div>
<script src="/assets/master-data-service.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/master-data-service.js')) ?>" defer></script>
