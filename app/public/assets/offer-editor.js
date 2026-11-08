(() => {
    'use strict';

    const form = document.querySelector('#offer-form');
    const titleInput = document.querySelector('#offer-title');
    const status = document.querySelector('#save-status');
    const submitButton = form?.querySelector('button[type="submit"]');
    if (!form || !titleInput || !status || !submitButton) return;

    let offer = null;
    let etag = form.dataset.etag;
    let dirty = false;

    fetch(`/api/offers/${encodeURIComponent(form.dataset.offerId)}`, { credentials: 'same-origin' })
        .then(async (response) => {
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Entwurf konnte nicht geladen werden.');
            offer = result.offer;
            etag = result.etag;
            form.dataset.etag = etag;
            submitButton.disabled = false;
            status.textContent = '';
        })
        .catch((error) => {
            status.textContent = error instanceof Error ? error.message : 'Entwurf konnte nicht geladen werden.';
        });

    titleInput.addEventListener('input', () => {
        dirty = true;
        status.textContent = 'Ungespeicherte Änderungen';
    });

    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault();
        event.returnValue = '';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (offer === null) return;
        offer.title = titleInput.value.trim();
        offer.updatedAt = new Date().toISOString();
        status.textContent = 'Speichert …';

        try {
            const response = await fetch(`/api/offers/${encodeURIComponent(form.dataset.offerId)}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'If-Match': `"${etag}"`,
                    'X-CSRF-Token': form.dataset.csrf,
                },
                body: JSON.stringify(offer),
            });
            const result = await response.json();
            if (!response.ok) {
                const details = Array.isArray(result.details) ? ` ${result.details.join(' ')}` : '';
                throw new Error((result.error || 'Speichern fehlgeschlagen.') + details);
            }
            etag = result.etag;
            form.dataset.etag = etag;
            dirty = false;
            status.textContent = 'Gespeichert';
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Speichern fehlgeschlagen.';
        }
    });
})();
