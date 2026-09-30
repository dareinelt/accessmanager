<?php
/** @var array $recent */
/** @var array|null $lastSuccess */
/** @var array|null $lastFailed */
$role = $currentUser['role'] ?? 'readonly';
$canRun = in_array($role, ['admin', 'operator'], true);
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Synchronisation</h1>
        <p class="page-sub">Daten aller UniFi-Controller in den lokalen Cache übernehmen.</p>
    </div>
    <?php if ($canRun): ?>
        <button class="btn btn-primary" id="sync-all" data-api="/api/sync" data-method="POST" data-confirm="Alle Standorte jetzt synchronisieren?" data-success="Synchronisation abgeschlossen.">Jetzt synchronisieren</button>
    <?php endif; ?>
</div>

<?php if ($lastSuccess): ?>
    <div class="flash flash-success">Letzte erfolgreiche Synchronisation: <?= e(format_date($lastSuccess['finished_at'])) ?></div>
<?php endif; ?>
<?php if ($lastFailed): ?>
    <div class="flash flash-error">Letzte fehlgeschlagene Synchronisation: <?= e(format_date($lastFailed['finished_at'])) ?> — <?= e($lastFailed['message'] ?? '') ?></div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title" style="margin-bottom:16px;">Verlauf</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Zeitpunkt</th><th>Standort</th><th>Status</th><th>Nachricht</th><th>Statistik</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $s): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($s['started_at'] ?? $s['created_at'])) ?></td>
                        <td><?= e($s['connection_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($s['status'] === 'success'): ?><span class="badge badge-green">Erfolgreich</span>
                            <?php elseif ($s['status'] === 'failed'): ?><span class="badge badge-red">Fehlgeschlagen</span>
                            <?php elseif ($s['status'] === 'running'): ?><span class="badge badge-blue">Läuft</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($s['status']) ?></span><?php endif; ?>
                        </td>
                        <td class="cell-sub"><?= e($s['message'] ?? '') ?></td>
                        <td class="cell-sub">
                            <?php if ($s['stats']): $st = json_decode((string) $s['stats'], true) ?: []; ?>
                                Personen <?= (int) ($st['users'] ?? 0) ?> · Karten <?= (int) ($st['credentials'] ?? 0) ?> · Gruppen <?= (int) ($st['access_groups'] ?? 0) ?> · Türen <?= (int) ($st['doors'] ?? 0) ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                    <tr><td colspan="5" class="empty">Noch keine Synchronisation protokolliert.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
