<?php
// Layout des mobilen Monteurs-Frontends: kompakter Kopf, Inhalt, feste Navigation unten.
// Ein- und Ausblenden ist Komfort; die Rechte prüfen AuthorizationPolicy und die Fachdienste.
$asset = static fn (string $file): string => '/assets/' . $file . '?v=' . (string) filemtime(dirname(__DIR__, 2) . '/public/assets/' . $file);
$features = $mobileFeatures ?? [];
$navigationItems = array_filter([
    ['key' => 'home', 'href' => '/mobile/', 'icon' => 'layout-dashboard', 'label' => 'Start', 'visible' => true],
    ['key' => 'orders', 'href' => '/mobile/orders', 'icon' => 'clipboard-list', 'label' => 'Aufträge', 'visible' => $features['orders'] ?? false],
    ['key' => 'times', 'href' => '/mobile/times', 'icon' => 'clock-3', 'label' => 'Zeiten', 'visible' => $features['times'] ?? false],
    ['key' => 'more', 'href' => '/mobile/more', 'icon' => 'menu', 'label' => 'Mehr', 'visible' => true],
], static fn (array $item): bool => $item['visible']);
$roleLabels = ['admin' => 'Administrator', 'office' => 'Büro', 'technician' => 'Monteur'];
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$pageModule = \App\Application\ModuleService::moduleForPath($requestPath);
$pageReadOnly = $pageModule !== null && !$moduleEnabled($pageModule);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#17324a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Calight">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= $escape(($title ?? 'Start') . ' · Calight') ?></title>
    <link rel="manifest" href="/mobile/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/app/calight-192.png">
    <link rel="apple-touch-icon" href="/assets/icons/app/calight-180.png">
    <link rel="stylesheet" href="<?= $escape($asset('app.css')) ?>">
    <link rel="stylesheet" href="<?= $escape($asset('mobile.css')) ?>">
    <script src="<?= $escape($asset('calight.js')) ?>" defer></script>
    <script src="<?= $escape($asset('modal.js')) ?>" defer></script>
    <script src="<?= $escape($asset('mobile.js')) ?>" defer></script>
</head>
<body class="mobile-app"<?= $currentUser !== null ? ' data-user-id="' . $escape((string) $currentUser['id']) . '"' : '' ?><?= $pageReadOnly ? ' data-module-readonly="1"' : '' ?>>
    <a class="mobile-skip-link" href="#mobile-main">Zum Inhalt springen</a>
    <header class="mobile-header">
        <a class="mobile-brand" href="/mobile/">Calight</a>
        <?php if ($currentUser !== null): ?>
        <details class="context-menu mobile-user-menu">
            <summary class="mobile-user-button" aria-label="Benutzermenü von <?= $escape($currentUser['displayName']) ?>">
                <span class="mobile-user-initials" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr(trim((string) $currentUser['displayName']), 0, 1)) ?: '?') ?></span>
                <span class="mobile-user-name"><?= $escape($currentUser['displayName']) ?></span>
            </summary>
            <div class="context-menu-panel mobile-user-panel">
                <span class="mobile-user-identity"><strong><?= $escape($currentUser['displayName']) ?></strong><small><?= $escape($roleLabels[$currentUser['role']] ?? (string) $currentUser['role']) ?></small></span>
                <?php if ($features['switchInterface'] ?? false): ?>
                <button type="submit" form="mobile-switch-office" data-icon="layout-dashboard">Zur Büroansicht</button>
                <?php endif; ?>
                <button type="submit" form="mobile-logout">Abmelden</button>
            </div>
        </details>
        <?php if ($features['switchInterface'] ?? false): ?>
        <form id="mobile-switch-office" action="/interface" method="post" hidden>
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="interface" value="office">
        </form>
        <?php endif; ?>
        <form id="mobile-logout" action="/logout" method="post" hidden>
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        </form>
        <?php endif; ?>
    </header>

    <div class="notice notice-warning mobile-offline" data-offline-notice role="status" hidden>
        Keine Verbindung zum Server. Inhalte können derzeit weder geladen noch gespeichert werden. Bitte die Verbindung prüfen.
    </div>

    <main class="mobile-main" id="mobile-main">
        <?php if ($pageReadOnly): ?>
            <div class="notice notice-warning" role="status"><?= $escape(\App\Application\ModuleService::disabledMessage($pageModule)) ?></div>
        <?php endif; ?>
        <?php foreach ($flashMessages as $flash): ?>
            <div class="notice notice-<?= $escape($flash['type']) ?>" role="status"><?= $escape($flash['message']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>

    <nav class="mobile-tabbar" aria-label="Hauptnavigation">
        <?php foreach ($navigationItems as $item): ?>
            <a href="<?= $escape($item['href']) ?>"<?= ($navigation ?? '') === $item['key'] ? ' aria-current="page"' : '' ?>>
                <span class="ui-icon icon-<?= $escape($item['icon']) ?>" aria-hidden="true"></span>
                <span><?= $escape($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<script src="<?= $escape($asset('ui.js')) ?>" defer></script>
</body>
</html>
