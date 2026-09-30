<?php
/** @var array $stats */
$cards = [
    ['Standorte', (int) $stats['locations'], '/sites'],
    ['Personen', (int) $stats['persons'], '/persons'],
    ['Karten / Medien', (int) $stats['credentials'], '/credentials'],
    ['Freie Karten', (int) $stats['free_credentials'], '/credentials?card_filter=free'],
    ['Zutrittsgruppen', (int) $stats['access_groups'], '/groups'],
    ['Türen', (int) $stats['doors'], '/doors'],
    ['Lokale Benutzer', (int) $stats['app_users'], '/users'],
];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">Übersicht aller Standorte und Zugangsdaten.</p>
    </div>
</div>

<div class="stats-grid">
    <?php foreach ($cards as [$label, $value, $href]): ?>
        <a class="stat" href="<?= e($href) ?>" style="color:inherit;text-decoration:none;">
            <div class="stat-value"><?= (int) $value ?></div>
            <div class="stat-label"><?= e($label) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">Standorte</h2>
        <a class="btn btn-sm" href="/sites">Verwalten</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Standort</th><th>Personen</th><th>Karten</th><th>Türen</th><th>Gruppen</th></tr>
            </thead>
            <tbody>
                <?php foreach ($stats['locations_detail'] as $loc): ?>
                    <tr>
                        <td><a href="/persons?connection_id=<?= (int) $loc['id'] ?>"><?= e($loc['name']) ?></a></td>
                        <td><?= (int) $loc['users'] ?></td>
                        <td><?= (int) $loc['credentials'] ?></td>
                        <td><?= (int) $loc['doors'] ?></td>
                        <td><?= (int) $loc['groups'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stats['locations_detail']): ?>
                    <tr><td colspan="5" class="empty">Noch keine Standorte angelegt.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">Letzte Synchronisationen</h2>
        <a class="btn btn-sm" href="/sync">Details</a>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Zeitpunkt</th><th>Standort</th><th>Status</th><th>Ergebnis</th></tr>
            </thead>
            <tbody>
                <?php foreach ($stats['recent_syncs'] as $s): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($s['finished_at'] ?? $s['started_at'])) ?></td>
                        <td><?= e($s['connection_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($s['status'] === 'success'): ?><span class="badge badge-green">Erfolgreich</span>
                            <?php elseif ($s['status'] === 'failed'): ?><span class="badge badge-red">Fehlgeschlagen</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($s['status']) ?></span><?php endif; ?>
                        </td>
                        <td class="cell-sub">
                            <?php if ($s['status'] === 'success' && $s['stats']): ?>
                                <?php $st = json_decode((string) $s['stats'], true) ?: []; ?>
                                <?= (int) ($st['users'] ?? 0) ?> Personen, <?= (int) ($st['credentials'] ?? 0) ?> Karten
                            <?php else: ?>
                                <?= e($s['message'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$stats['recent_syncs']): ?>
                    <tr><td colspan="4" class="empty">Noch keine Synchronisation durchgeführt.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
