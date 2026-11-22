<?php
$title = 'Berechtigungen';
$contentClass = 'content-wide';
// Nur Anzeige der festen Rollenrechte aus RolePermissions – keine eigene Rechteverwaltung.
$roles = \App\Security\RolePermissions::ROLES;
?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'permissions'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div class="overview-page">
    <div class="page-heading"><div><h1>Berechtigungen</h1><p class="muted">Calight verwendet drei feste Rollen. Die Rechte werden serverseitig geprüft; Module müssen zusätzlich aktiv sein.</p></div></div>
    <div class="card">
        <div class="overview-table-wrap">
            <table class="permission-matrix">
                <thead><tr><th scope="col">Recht</th><?php foreach ($roles as $label): ?><th scope="col" class="permission-role"><?= $escape($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (\App\Security\RolePermissions::DESCRIPTIONS as $group => $permissions): ?>
                    <tr class="permission-group"><th colspan="<?= $escape((string) (count($roles) + 1)) ?>" scope="rowgroup"><?= $escape($group) ?></th></tr>
                    <?php foreach ($permissions as $permission => $description): ?>
                        <tr>
                            <td><?= $escape($description) ?><br><small class="muted"><?= $escape($permission) ?></small></td>
                            <?php foreach (array_keys($roles) as $role): $allowed = \App\Security\RolePermissions::allows($role, $permission); ?>
                                <td class="permission-role"><span class="<?= $allowed ? 'permission-yes' : 'permission-no' ?>"><?= $allowed ? '✓' : '–' ?></span><span class="visually-hidden"><?= $allowed ? 'erlaubt' : 'nicht erlaubt' ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="muted operations-note">Monteure sehen Aufträge erst nach der Freigabe (nicht „Offen“), eigene Berichte immer und fremde nur an freigegebenen Aufträgen. Weitere Regeln je Datensatz prüfen die Fachmodule.</p>
</div>
</div>
</div>
