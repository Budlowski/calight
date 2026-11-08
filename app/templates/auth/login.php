<?php $title = 'Anmelden'; ?>
<section class="card login-card">
    <h1>Anmelden</h1>
    <?php if (!$hasUsers): ?>
        <div class="notice notice-error">Es wurde noch kein Benutzer angelegt. Bitte zuerst den CLI-Befehl <code>user:create</code> ausführen.</div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="notice notice-error" role="alert"><?= $escape($error) ?></div>
    <?php endif; ?>
    <form action="/login" method="post" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <label>Benutzername
            <input name="username" autocomplete="username" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="button" type="submit">Anmelden</button>
    </form>
</section>
