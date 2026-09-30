<?php
/** @var array $archives */
/** @var array $settings */
/** @var bool $scheduleDue */
/** @var array|null $preview */
/** @var array|null $pendingMeta */

$labels = [
    'roles' => 'Rollen',
    'app_settings' => 'Einstellungen',
    'users' => 'Benutzer',
    'unifi_connections' => 'Standorte',
    'system_secrets' => 'Systemgeheimnisse',
    'tls_certificates' => 'Zertifikate',
    'ad_group_mappings' => 'AD-Mappings',
    'unifi_users' => 'Personen (Cache)',
    'unifi_credentials' => 'Karten (Cache)',
    'unifi_access_groups' => 'Zutrittsgruppen (Cache)',
    'unifi_doors' => 'Türen (Cache)',
];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Backup &amp; Wiederherstellung</h1>
        <p class="page-sub">Sichern Sie alle Einstellungen sowie die bereits auf der Dream Machine vorhandenen Daten und spielen Sie sie bei Bedarf wieder ein.</p>
    </div>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Sofort-Backup</h2>
    <p class="muted">Lädt ein vollständiges JSON-Archiv als Download herunter – ohne es dauerhaft zu speichern. Alternativ wird ein Archiv im Ordner <code>storage/backups</code> abgelegt.</p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px;">
        <a class="btn btn-primary" href="/backup/download">Jetzt herunterladen</a>
        <form method="post" action="/backup/create">
            <?= \App\Security\Csrf::field() ?>
            <button type="submit" class="btn">Archiv speichern</button>
        </form>
    </div>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Automatische Backups</h2>
    <form data-json-form data-method="PUT" data-action="/api/settings">
        <label class="checkbox" style="margin-bottom:14px;">
            <input type="checkbox" name="backup_enabled" value="1" <?= ($settings['backup_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            Automatische Backups aktivieren (per Cron)
        </label>
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Backup-Intervall (Minuten)</span>
                <input class="input" type="number" name="backup_interval_minutes" min="5" value="<?= e($settings['backup_interval_minutes'] ?? '1440') ?>">
            </label>
            <label class="field">
                <span class="field-label">Aufbewahrung (Anzahl Archive)</span>
                <input class="input" type="number" name="backup_retention" min="1" value="<?= e($settings['backup_retention'] ?? '10') ?>">
            </label>
        </div>
        <button type="submit" class="btn btn-primary mt">Speichern</button>
        <span class="muted" style="margin-left:12px;">
            <?= $scheduleDue ? 'Ein Backup ist beim nächsten Cron-Lauf fällig.' : 'Kein Backup fällig (deaktiviert oder Intervall noch nicht erreicht).' ?>
        </span>
    </form>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Gespeicherte Archive</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Datei</th><th>Erstellt</th><th>Erstellt von</th><th>Größe</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($archives as $a): ?>
                    <tr>
                        <td class="mono cell-main"><?= e($a['filename']) ?></td>
                        <td class="nowrap"><?= e(format_date($a['created_at'])) ?></td>
                        <td><?= e($a['created_by']) ?></td>
                        <td class="nowrap"><?= e(number_format((int) $a['size'])) ?> B</td>
                        <td class="nowrap">
                            <a class="btn btn-sm" href="/backup/<?= e(rawurlencode($a['filename'])) ?>/download">Herunterladen</a>
                            <form method="post" action="/backup/delete" style="display:inline;" onsubmit="return confirm('Archiv „<?= e($a['filename']) ?>“ löschen?');">
                                <?= \App\Security\Csrf::field() ?>
                                <input type="hidden" name="filename" value="<?= e($a['filename']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$archives): ?>
                    <tr><td colspan="5" class="empty">Noch keine Archive gespeichert.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Wiederherstellung</h2>
    <p class="muted">Wählen Sie eine zuvor heruntergeladene Backup-Datei (.json) aus. Nach der Vorschau müssen Sie die Wiederherstellung ausdrücklich bestätigen – sie ersetzt die aktuellen Einstellungen und Cache-Daten vollständig.</p>

    <?php if ($preview !== null): ?>
        <div class="card card-inset" style="margin-top:16px;">
            <h3 class="card-title">Vorschau</h3>
            <div class="detail-list">
                <div class="detail-row"><span class="detail-key">Datei</span><span class="mono"><?= e($pendingMeta['filename'] ?? '') ?></span></div>
                <div class="detail-row"><span class="detail-key">Erstellt am</span><span><?= e(format_date($preview['created_at'])) ?></span></div>
                <div class="detail-row"><span class="detail-key">Quelle (App-Name)</span><span><?= e($preview['app_name'] ?? '') ?></span></div>
                <div class="detail-row"><span class="detail-key">Erstellt von</span><span><?= e($preview['created_by'] ?? '') ?></span></div>
                <div class="detail-row"><span class="detail-key">Datensätze gesamt</span><span><?= (int) $preview['total'] ?></span></div>
            </div>
            <div class="table-wrap" style="margin-top:12px;">
                <table class="table">
                    <thead><tr><th>Bereich</th><th>Datensätze</th></tr></thead>
                    <tbody>
                        <?php foreach ($preview['counts'] as $table => $count): ?>
                            <tr><td><?= e($labels[$table] ?? $table) ?></td><td><?= (int) $count ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" action="/backup/restore/confirm" class="mt" onsubmit="return confirm('Achtung: Alle aktuellen Einstellungen und Cache-Daten werden durch den Stand des Backups ersetzt. Fortfahren?');">
                <?= \App\Security\Csrf::field() ?>
                <button type="submit" class="btn btn-primary">Wiederherstellung bestätigen</button>
            </form>
            <form method="post" action="/backup/restore/discard" style="margin-top:8px;">
                <?= \App\Security\Csrf::field() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Abbrechen</button>
            </form>
        </div>
    <?php else: ?>
        <form method="post" action="/backup/restore/preview" enctype="multipart/form-data" class="mt">
            <?= \App\Security\Csrf::field() ?>
            <label class="field">
                <span class="field-label">Backup-Datei (.json)</span>
                <input class="input" type="file" name="backup_file" accept=".json,application/json" required>
            </label>
            <button type="submit" class="btn btn-primary">Vorschau anzeigen</button>
        </form>
    <?php endif; ?>
</div>
