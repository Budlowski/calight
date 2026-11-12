<?php $title = 'Aufmaß'; $contentClass = 'content-wide'; ?>
<div id="measurement-detail" class="record-detail-page" data-id="<?= $escape($measurementId) ?>" data-csrf="<?= $escape($csrfToken) ?>">
    <nav class="breadcrumb" aria-label="Brotkrümelnavigation">
        <ol>
            <li><a href="/orders">Aufträge</a></li>
            <li data-order-crumb></li>
            <li><a href="/measurements">Aufmaße</a></li>
            <li aria-current="page" data-current-crumb>Aufmaß</li>
        </ol>
    </nav>

    <section class="card order-header-card">
        <div class="page-heading order-detail-heading">
            <div class="order-heading-main">
                <span class="service-number" data-number>Aufmaß</span>
                <h1 data-title>Aufmaß wird geladen …</h1>
                <div class="document-status-line" data-status-line></div>
            </div>
            <div class="order-detail-actions" data-actions></div>
        </div>
        <dl class="project-facts">
            <div><dt>Auftrag</dt><dd data-order></dd></div>
            <div><dt>Kunde</dt><dd data-customer></dd></div>
            <div><dt>Nettosumme dieses Aufmaßes</dt><dd data-net-total>–</dd></div>
        </dl>
    </section>

    <div class="notice" data-message role="status" hidden></div>

    <form class="card form-stack" data-head-form novalidate>
        <div class="form-grid measurement-head-grid">
            <label><span class="field-label">Bezeichnung <span aria-hidden="true">*</span></span><input name="title" maxlength="200" required></label>
            <label><span class="field-label">Aufmaßdatum <span aria-hidden="true">*</span></span><input name="measurementDate" type="date" required></label>
        </div>
        <label><span class="field-label">Bemerkungen (optional)</span><textarea name="notes" rows="2" maxlength="5000"></textarea></label>
    </form>

    <section class="card measurement-positions">
        <div class="section-heading measurement-toolbar">
            <div><h2>Positionen</h2><span class="muted" data-position-summary></span></div>
            <div class="measurement-filters">
                <input type="search" data-position-search placeholder="Position oder Text suchen" aria-label="Positionen durchsuchen">
                <select data-position-filter aria-label="Positionen filtern">
                    <option value="all">Alle Positionen</option>
                    <option value="entered">Nur mit Menge in diesem Aufmaß</option>
                    <option value="open">Mit offener Menge</option>
                </select>
            </div>
        </div>
        <p class="muted measurement-hint" data-hint></p>
        <div data-sources aria-live="polite"></div>
    </section>

    <section class="card" data-totals-card>
        <div class="section-heading"><div><h2>Summen</h2><span class="muted">Bewertung zu den verbindlichen Einzelpreisen; Nachlass bzw. Aufschlag des jeweiligen Angebots anteilig auf Normalpositionen.</span></div></div>
        <div data-totals></div>
    </section>

    <div class="form-actions measurement-save-bar" data-save-bar hidden>
        <button class="button" type="button" data-save data-shortcut-save data-icon="save" title="Aufmaß speichern (Strg+S)">Aufmaß speichern</button>
        <span class="muted" data-dirty-hint hidden>Ungespeicherte Änderungen</span>
    </div>
</div>
<script src="/assets/measurement-detail.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/measurement-detail.js')) ?>" defer></script>
