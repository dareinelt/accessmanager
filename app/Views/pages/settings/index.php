<?php
/** @var array $info */
/** @var array $settings */
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Einstellungen</h1>
        <p class="page-sub">Anwendungskonfiguration und Synchronisationsparameter.</p>
    </div>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Anwendung</h2>
    <div class="detail-list">
        <div class="detail-row"><span class="detail-key">Name</span><span><?= e($info['app_name']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Debug-Modus</span><span><?= $info['debug'] ? '<span class="badge badge-amber">An</span>' : '<span class="badge badge-gray">Aus</span>' ?></span></div>
        <div class="detail-row"><span class="detail-key">Mock-Modus (Demo-Daten)</span><span><?= $info['mock'] ? '<span class="badge badge-blue">An</span>' : '<span class="badge badge-gray">Aus</span>' ?></span></div>
        <div class="detail-row"><span class="detail-key">Zeitzone</span><span><?= e($info['timezone']) ?></span></div>
        <div class="detail-row"><span class="detail-key">PHP-Version</span><span class="mono"><?= e($info['php_version']) ?></span></div>
    </div>
    <p class="muted mt">Hinweis: App-Name, Debug- und Mock-Modus werden über die Umgebungsvariablen (<code>.env</code>) gesteuert und können hier nicht geändert werden.</p>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Synchronisation</h2>
    <form data-json-form data-method="PUT" data-action="/api/settings">
        <label class="checkbox" style="margin-bottom:14px;">
            <input type="checkbox" name="sync_enabled" <?= !empty($settings['sync_enabled']) && $settings['sync_enabled'] !== '0' ? 'checked' : '' ?>>
            Automatische Synchronisation aktivieren (per Cron)
        </label>
        <label class="field">
            <span class="field-label">Synchronisationsintervall (Minuten)</span>
            <input class="input" type="number" name="sync_interval_minutes" min="1" value="<?= e($settings['sync_interval_minutes'] ?? '60') ?>">
        </label>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</div>
