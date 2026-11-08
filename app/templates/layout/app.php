<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title ?? 'Kalkulationssoftware') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= $escape((string) filemtime(dirname(__DIR__, 2) . '/public/assets/app.css')) ?>">
</head>
<body>
<?php if ($currentUser !== null): ?>
    <header class="topbar">
        <a class="brand" href="/dashboard">Calight</a>
        <div class="user-area">
            <span><?= $escape($currentUser['displayName']) ?></span>
            <form action="/logout" method="post">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-secondary" type="submit">Abmelden</button>
            </form>
        </div>
    </header>
    <div class="shell">
        <nav class="sidebar" aria-label="Hauptnavigation">
            <a href="/dashboard">Übersicht</a>
            <a href="/master-data">Stammdaten</a>
            <a href="/customers">Kunden</a>
            <a href="/projects">Projekte</a>
            <a href="/offers">Angebote</a>
            <a href="#" aria-disabled="true">Aufmaß</a>
            <a href="#" aria-disabled="true">Materialbedarf</a>
            <a href="#" aria-disabled="true">Kosten</a>
            <a href="#" aria-disabled="true">Zeiten</a>
            <a href="#" aria-disabled="true">Nachkalkulation</a>
        </nav>
        <main class="content <?= $escape($contentClass ?? '') ?>">
<?php else: ?>
        <main class="content content-narrow">
<?php endif; ?>
            <?php foreach ($flashMessages as $flash): ?>
                <div class="notice notice-<?= $escape($flash['type']) ?>" role="status"><?= $escape($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
<?php if ($currentUser !== null): ?>
    </div>
<?php endif; ?>
</body>
</html>
