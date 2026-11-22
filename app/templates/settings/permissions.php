<?php
$title = 'Rollen & Rechte';
$contentClass = 'content-wide';
// Nur Anzeige der festen Rollenrechte aus RolePermissions – keine eigene Rechteverwaltung.
$roles = \App\Security\RolePermissions::ROLES;
?>
<div class="admin-shell">
<?php $adminArea = 'settings'; $adminActive = 'permissions'; require __DIR__ . '/../partials/admin-nav.php'; ?>
<div class="admin-content">
<div class="overview-page permission-page">
    <div class="page-heading">
        <div><h1>Rollen &amp; Rechte</h1><p class="muted">Calight verwendet drei feste Rollen. Die Rechte prüft der Server; Module müssen zusätzlich aktiv sein. Diese Übersicht dient nur der Information.</p></div>
        <label class="check-inline"><input type="checkbox" data-show-keys> Technische Schlüssel anzeigen</label>
    </div>
    <div class="permission-groups">
        <?php foreach (\App\Security\RolePermissions::DESCRIPTIONS as $group => $permissions): ?>
            <?php $counts = array_map(static fn (string $role): int => count(array_filter(array_keys($permissions), static fn (string $permission): bool => \App\Security\RolePermissions::allows($role, $permission))), array_keys($roles)); ?>
            <details class="card permission-group-card" open>
                <summary><span><?= $escape($group) ?></span><small><?= $escape(implode(' · ', array_map(static fn (string $label, int $count): string => $label . ' ' . $count . '/' . count($permissions), $roles, $counts))) ?></small></summary>
                <table class="permission-matrix">
                    <thead><tr><th scope="col">Recht</th><?php foreach ($roles as $label): ?><th scope="col" class="permission-role"><?= $escape($label) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($permissions as $permission => $description): ?>
                        <tr>
                            <td><strong><?= $escape($description) ?></strong><span class="permission-key"><?= $escape($permission) ?></span></td>
                            <?php foreach ($roles as $role => $label): $allowed = \App\Security\RolePermissions::allows($role, $permission); ?>
                                <td class="permission-role<?= $allowed ? ' is-allowed' : '' ?>"><span class="<?= $allowed ? 'permission-yes' : 'permission-no' ?>" aria-hidden="true"><?= $allowed ? '✓' : '–' ?></span><span class="permission-role-mobile"><?= $escape($label) ?></span><span class="visually-hidden"><?= $escape($label) ?>: <?= $allowed ? 'erlaubt' : 'nicht erlaubt' ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endforeach; ?>
    </div>
    <p class="muted operations-note">Monteure sehen Aufträge erst nach der Freigabe (nicht „Offen“), eigene Berichte immer und fremde nur an freigegebenen Aufträgen. Weitere Regeln je Datensatz prüfen die Fachmodule.</p>
</div>
</div>
</div>
