<?php
/** @var string $appName */
/** @var array|null $flash */
/** @var string $csrf */
?>
<div class="auth-card">
    <div class="auth-logo">U</div>
    <h1 class="auth-title"><?= e($appName) ?></h1>
    <p class="auth-sub">Zentrale Verwaltung für UniFi Access</p>

    <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="auth-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="field">
            <span class="field-label">Benutzername</span>
            <input class="input" type="text" name="username" autocomplete="username" required autofocus>
        </label>
        <label class="field">
            <span class="field-label">Passwort</span>
            <input class="input" type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
    </form>
</div>
