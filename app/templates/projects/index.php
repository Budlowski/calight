<?php $title = 'Projekte'; ?>
<h1>Projekte</h1>
<div class="grid-two">
    <section class="card">
        <h2>Neues Projekt</h2>
        <form action="/projects" method="post" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <label>Projektbezeichnung
                <input name="name" maxlength="200" required>
            </label>
            <label>Kunde
                <input name="customerName" maxlength="200">
            </label>
            <label>Baustellenanschrift
                <textarea name="siteAddress" rows="3"></textarea>
            </label>
            <button class="button" type="submit">Projekt anlegen</button>
        </form>
    </section>
    <section class="card">
        <h2>Vorhandene Projekte</h2>
        <?php if ($projects === []): ?>
            <p>Noch keine Projekte vorhanden.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Bezeichnung</th><th>Kunde</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($projects as $project): ?>
                    <tr>
                        <td><a href="/projects/<?= $escape(rawurlencode($project['id'])) ?>"><?= $escape($project['name']) ?></a></td>
                        <td><?= $escape($project['customer_name']) ?></td>
                        <td><?= $escape($project['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
