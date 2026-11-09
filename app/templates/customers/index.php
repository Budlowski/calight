<?php $title='Kunden'; $contentClass='content-wide'; ?>
<div id="customer-project-app" data-page="customers" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading"><div><h1>Kunden</h1><p class="muted">Kundendaten, Ansprechpartner und Adressen zentral verwalten.</p></div><label class="check-inline"><input id="show-archived" type="checkbox"> Archivierte anzeigen</label></div>
    <div id="admin-message" class="notice" hidden></div>
    <div class="master-toolbar management-toolbar customer-toolbar"><input id="customer-search" type="search" placeholder="Nummer, Name, Ort, Kontakt oder Adresse durchsuchen"><select id="customer-type-filter"><option value="">Alle Kundentypen</option><option value="company">Unternehmen</option><option value="private">Privatpersonen</option></select><select id="customer-sort"><option value="name">Name A–Z</option><option value="number">Kundennummer</option><option value="updated">Zuletzt geändert</option></select><span class="management-result-count" id="customer-result-count"></span><button class="button button-light" id="customer-reset" type="button" data-icon="refresh-cw">Zurücksetzen</button><button class="button" id="new-customer" type="button">Neuer Kunde</button></div>
    <div class="master-split management-split customer-project-split">
        <div id="customer-list" class="service-list management-list"></div>
        <form id="customer-form" class="card form-stack master-editor management-editor" hidden>
            <input name="id" type="hidden"><div class="editor-heading"><h2>Kunde</h2></div>
            <div class="form-grid customer-head-grid">
                <div class="service-number-field"><div class="service-number-heading"><label for="customer-number">Kundennummer</label><label class="check-inline auto-number-option"><input name="autoCustomerNumber" type="checkbox" checked> Automatisch</label></div><input id="customer-number" name="customerNumber" maxlength="100"></div>
                <label>Kundentyp<select name="customerType"><option value="company">Unternehmen</option><option value="private">Privatperson</option></select></label>
                <label data-company-field>Firmenname<input name="companyName" maxlength="200"></label>
                <label data-private-field>Vorname<input name="firstName" maxlength="100"></label><label data-private-field>Nachname<input name="lastName" maxlength="100"></label>
            </div>
            <h3>Hauptanschrift</h3><div class="address-grid"><label>Straße<input name="street" maxlength="200"></label><label>Hausnummer<input name="houseNumber" maxlength="30"></label><label>PLZ<input name="postalCode" maxlength="20"></label><label>Ort<input name="city" maxlength="120"></label><label>Land<input name="country" value="Deutschland" maxlength="100"></label></div>
            <h3>Kontakt und Steuerdaten</h3><div class="form-grid"><label>E-Mail<input name="email" type="email" maxlength="254"></label><label>Telefon<input name="phone" maxlength="80"></label><label>Website<input name="website" type="url" maxlength="500"></label><label>Umsatzsteuer-ID<input name="vatId" maxlength="40"></label></div>
            <details class="components-panel" open><summary>Ansprechpartner <span id="contact-count"></span></summary><div class="inline-toolbar"><button class="button button-light" id="add-contact" type="button">Ansprechpartner hinzufügen</button></div><div id="contact-list" class="inline-record-list"></div></details>
            <details class="components-panel" open><summary>Weitere Adressen <span id="address-count"></span></summary><div class="inline-toolbar"><button class="button button-light" id="add-address" type="button">Adresse hinzufügen</button></div><div id="address-list" class="inline-record-list"></div></details>
            <label>Interne Notizen<textarea name="notes" rows="4" maxlength="10000"></textarea></label>
            <div class="form-actions"><button class="button" type="submit">Kunde speichern</button><button class="button button-light" type="button" data-cancel>Abbrechen</button></div>
        </form>
    </div>
</div>
<script src="/assets/customer-projects.js?v=<?= $escape((string) filemtime(dirname(__DIR__,2).'/public/assets/customer-projects.js')) ?>" defer></script>
