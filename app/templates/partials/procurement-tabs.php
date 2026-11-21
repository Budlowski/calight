<?php
// Beschaffung: Bestellungen und Materialbedarf als Reiter (organisatorische Zusammenfassung,
// getrennte Datentypen und Rechte). Reiter nur mit Leserecht; ohne Bestellrecht kein Reiter.
$procurementTab = $procurementTab ?? 'orders';
$procurementTabs = array_filter([
    'orders' => \App\Http\Session::can('purchases.read') ? ['/purchase-orders', 'Bestellungen'] : null,
    'materials' => \App\Http\Session::can('reports.read') ? ['/material-requests', 'Materialbedarf'] : null,
]);
if (count($procurementTabs) > 1): ?>
<nav class="tabs procurement-tabs" aria-label="Beschaffung">
    <?php foreach ($procurementTabs as $key => [$href, $label]): ?>
    <a class="tab-button<?= $key === $procurementTab ? ' active' : '' ?>" href="<?= $escape($href) ?>"<?= $key === $procurementTab ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
