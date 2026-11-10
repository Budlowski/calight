<?php $title='Einstellungen'; $contentClass='content-wide'; ?>
<div id="settings-app" data-csrf="<?= $escape($csrfToken) ?>">
    <div class="page-heading settings-heading">
        <div><h1>Einstellungen</h1><p class="muted">Firmendaten und Darstellung der Angebotsdokumente zentral verwalten.</p></div>
        <button class="button" type="submit" form="settings-form" data-icon="save">Einstellungen speichern</button>
    </div>
    <div id="settings-message" class="notice" role="status" hidden></div>

    <nav class="tabs settings-tabs" aria-label="Einstellungsbereiche" role="tablist">
        <button class="tab-button active" type="button" role="tab" aria-selected="true" aria-controls="settings-company" data-settings-tab="company">Firmendaten</button>
        <button class="tab-button" type="button" role="tab" aria-selected="false" aria-controls="settings-finance" data-settings-tab="finance">Steuern &amp; Bank</button>
        <button class="tab-button" type="button" role="tab" aria-selected="false" aria-controls="settings-document" data-settings-tab="document">Dokumentlayout</button>
        <button class="tab-button" type="button" role="tab" aria-selected="false" aria-controls="settings-terms" data-settings-tab="terms">Angebotsbedingungen</button>
        <a class="tab-button" href="/settings/employees" role="tab" aria-selected="false">Mitarbeiter</a>
    </nav>

    <form id="settings-form" class="settings-form">
        <section id="settings-company" class="tab-panel settings-panel" role="tabpanel" data-settings-panel="company">
            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Unternehmen</h2><p class="muted">Allgemeine Angaben des anbietenden Betriebs.</p></div></div>
                <div class="settings-grid settings-grid-company">
                    <label class="settings-span-2">Firmenname<input name="companyName" required></label>
                    <label>Rechtsform<input name="legalForm"></label>
                    <label>Inhaber / Ansprechpartner<input name="owner"></label>
                    <label>Telefon<input name="phone"></label>
                    <label>E-Mail<input name="email" type="email"></label>
                    <label>Website<input name="website"></label>
                    <label>Land<input name="country"></label>
                    <label class="settings-span-2">Handelsregister / weitere Angabe<input name="commercialRegister"></label>
                </div>
            </div>
            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Geschäftsanschrift</h2><p class="muted">Diese Anschrift wird als Absender in Dokumenten verwendet.</p></div></div>
                <div class="settings-grid settings-address-grid">
                    <label>Straße<input name="street"></label><label>Hausnummer<input name="houseNumber"></label>
                    <label>PLZ<input name="postalCode"></label><label>Ort<input name="city"></label>
                </div>
            </div>
        </section>

        <section id="settings-finance" class="tab-panel settings-panel" role="tabpanel" data-settings-panel="finance" hidden>
            <div class="settings-panel-grid">
                <div class="card settings-section">
                    <div class="settings-section-heading"><div><h2>Steuerangaben</h2><p class="muted">Steuerliche Behandlung und Angaben für die Dokumentausgabe.</p></div></div>
                    <div class="settings-grid">
                        <label>Steuernummer<input name="taxNumber"></label><label>Umsatzsteuer-ID<input name="vatId"></label>
                        <label>Steuerliche Behandlung<select name="taxMode"><option value="standard">Umsatzsteuer ausweisen</option><option value="exempt">Steuerfrei / kein Ausweis</option></select></label>
                        <label>Umsatzsteuer in %<input name="vatRate" inputmode="decimal"></label>
                        <label class="settings-span-2">Steuerhinweis<input name="taxNote" placeholder="Optionaler Hinweis bei steuerfreier Behandlung"></label>
                    </div>
                </div>
                <div class="card settings-section">
                    <div class="settings-section-heading"><div><h2>Bankverbindung</h2><p class="muted">Bankdaten für den Fußbereich der Angebotsdokumente.</p></div></div>
                    <div class="settings-grid">
                        <label class="settings-span-2">Bank<input name="bankName"></label>
                        <label class="settings-span-2">IBAN<input name="iban"></label><label>BIC<input name="bic"></label>
                    </div>
                </div>
            </div>
        </section>

        <section id="settings-document" class="tab-panel settings-panel" role="tabpanel" data-settings-panel="document" hidden>
            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Vorlage und Logo</h2><p class="muted">Grundlegendes Erscheinungsbild der Angebotsdokumente.</p></div></div>
                <div class="settings-logo-layout">
                    <div class="settings-grid">
                        <label>Aktive Vorlage<select name="activeTemplate"><option value="standard">Standardangebot</option></select></label>
                        <label>Akzentfarbe<input name="accentColor" type="color"></label>
                        <label>Firmenlogo<input id="company-logo" type="file" accept="image/png,image/jpeg"><small>PNG oder JPEG, maximal 1 MB</small></label>
                        <label>Logoposition<select name="logoPosition"><option value="right">Rechts</option><option value="left">Links</option></select></label>
                        <label>Maximale Logobreite (pt)<input name="logoWidth" type="number" min="40" max="260"></label>
                        <label>Maximale Logohöhe (pt)<input name="logoHeight" type="number" min="20" max="160"></label>
                    </div>
                    <div class="settings-logo-box"><span>Aktuelles Logo</span><div id="logo-preview"></div></div>
                </div>
            </div>

            <div class="settings-panel-grid">
                <div class="card settings-section">
                    <div class="settings-section-heading"><div><h2>Seitenlayout</h2><p class="muted">Schrift, Anschriftenfeld und Seitenränder in Punkt.</p></div></div>
                    <div class="settings-grid settings-page-grid">
                        <label>Anschriftenfeld<select name="addressMode"><option value="standard">Kompakt</option><option value="window">DIN-5008-orientiert</option></select></label>
                        <label>Grundschrift (pt)<input name="baseFontSize" type="number" min="7" max="11" step="0.5"></label>
                        <label>Rand links (pt)<input name="marginLeft" type="number" min="28" max="80"></label><label>Rand rechts (pt)<input name="marginRight" type="number" min="28" max="80"></label>
                        <label>Rand oben (pt)<input name="marginTop" type="number" min="28" max="90"></label><label>Rand unten (pt)<input name="marginBottom" type="number" min="70" max="120"></label>
                    </div>
                </div>
                <div class="card settings-section">
                    <div class="settings-section-heading"><div><h2>Dokumentinhalt</h2><p class="muted">Sichtbare Bereiche und zusätzliche Ausgaben festlegen.</p></div></div>
                    <div class="settings-switches">
                        <label class="settings-switch"><input name="showHeader" type="checkbox"><span><strong>Kopfbereich anzeigen</strong><small>Firmenname und Logo im Dokumentkopf</small></span></label>
                        <label class="settings-switch"><input name="showFooter" type="checkbox"><span><strong>Fußbereich anzeigen</strong><small>Firmen-, Steuer- und Bankangaben</small></span></label>
                        <label class="settings-switch"><input name="showProjectInfo" type="checkbox"><span><strong>Projektinformationen anzeigen</strong><small>Belegnummer, Datum, Projekt und Objekt</small></span></label>
                        <label class="settings-switch"><input name="showEmptyTitles" type="checkbox"><span><strong>Leere Titel anzeigen</strong><small>Titel ohne Positionen in die Ausgabe aufnehmen</small></span></label>
                        <label class="settings-switch"><input name="showTitleSummary" type="checkbox"><span><strong>Titelübersicht als Abschlussseite</strong><small>Alle Haupttitel und Summen am Dokumentende</small></span></label>
                    </div>
                </div>
            </div>

            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Datei und Fußtext</h2><p class="muted">Benennung heruntergeladener PDFs und optionaler Zusatztext.</p></div></div>
                <div class="settings-grid">
                    <label class="settings-span-2">Dateinamenvorlage<input name="fileNamePattern" maxlength="150" placeholder="{JJJJ}-{MM}-{TT}_{DOKUMENTTYP}_{DOKUMENTNUMMER}"><small>Platzhalter: {JJJJ}, {MM}, {TT}, {DOKUMENTTYP}, {DOKUMENTNUMMER}</small></label>
                    <label class="settings-span-2">Zusätzlicher Fußtext<textarea name="footerText" rows="2"></textarea></label>
                </div>
            </div>
        </section>

        <section id="settings-terms" class="tab-panel settings-panel" role="tabpanel" data-settings-panel="terms" hidden>
            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Gültigkeit</h2><p class="muted">Standardvorgabe für neu ausgegebene Angebote.</p></div></div>
                <div class="settings-grid settings-validity-grid">
                    <label>Gültigkeit<select name="validityMode"><option value="duration">Gültigkeitsdauer</option><option value="date">Festes Datum</option></select></label>
                    <label data-validity-duration>Gültigkeitsdauer in Tagen<input name="validityDays" type="number" min="1" max="3650"></label>
                    <label data-validity-date>Gültig bis<input name="validityDate" type="date"></label>
                </div>
            </div>
            <div class="card settings-section">
                <div class="settings-section-heading"><div><h2>Standardtexte</h2><p class="muted">Diese Texte können in einzelnen Angeboten überschrieben werden.</p></div></div>
                <div class="settings-text-grid">
                    <label>Einleitung<textarea name="introText" rows="4"></textarea></label><label>Schlusstext<textarea name="closingText" rows="4"></textarea></label>
                    <label>Zahlungsbedingungen<textarea name="paymentTerms" rows="3"></textarea></label><label>Zusätzliche Angebotsbedingungen / Hinweise<textarea name="additionalTerms" rows="3"></textarea></label>
                </div>
            </div>
        </section>
    </form>
</div>
<script src="/assets/settings.js?v=<?= $escape((string)filemtime(dirname(__DIR__,2).'/public/assets/settings.js')) ?>" defer></script>
