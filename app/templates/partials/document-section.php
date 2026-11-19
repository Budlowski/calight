<?php
/**
 * Dokumentbereich eines Geschäftsobjekts (Phase 3.4.2). Erwartet $documentTarget = [type, id],
 * $documentWritePermission und optional $documentContacts (Ansprechpartner beim Kunden).
 * Ohne documents.read erscheint nichts – Techniker sehen Dokumente in den Fachmodulen.
 */
if (\App\Http\Session::can('documents.read')):
    $documentContacts = $documentContacts ?? [];
?>
<section class="card form-stack document-section" data-document-section
    data-target-type="<?= $escape($documentTarget[0]) ?>" data-target-id="<?= $escape($documentTarget[1]) ?>"
    data-can-upload="<?= \App\Http\Session::can($documentWritePermission) ? '1' : '0' ?>"
    data-contacts="<?= $escape(json_encode($documentContacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"></section>
<script src="/assets/documents.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/documents.js')) ?>" defer></script>
<script src="/assets/document-section.js?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/document-section.js')) ?>" defer></script>
<?php endif; ?>
