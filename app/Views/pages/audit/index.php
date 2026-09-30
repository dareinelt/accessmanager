<?php
/** @var array $result */
/** @var array $filters */
$items = $result['items'];
$total = $result['total'];
$page = (int) $filters['page'];
$search = (string) $filters['search'];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Audit-Log</h1>
        <p class="page-sub">Nachvollziehbare Änderungen und Zugriffe.</p>
    </div>
    <a class="btn" href="/export/audit">CSV exportieren</a>
</div>

<div class="card">
    <form method="get" action="/audit" class="filters">
        <input class="input search" type="search" name="search" value="<?= e($search) ?>" placeholder="Aktion, Person, Benutzer …">
        <button type="submit" class="btn btn-primary">Suchen</button>
        <?php if ($search !== ''): ?><a class="btn btn-ghost" href="/audit">Zurücksetzen</a><?php endif; ?>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Zeitpunkt</th><th>Benutzer</th><th>Aktion</th><th>Objekt</th><th>Ergebnis</th><th>Details</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $a): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($a['created_at'])) ?></td>
                        <td><?= e($a['username'] ?? '—') ?></td>
                        <td><span class="mono"><?= e($a['action']) ?></span></td>
                        <td>
                            <?php if ($a['entity_type']): ?>
                                <span class="cell-main"><?= e($a['entity_label'] ?? $a['entity_id']) ?></span>
                                <div class="cell-sub"><?= e($a['entity_type']) ?> · <?= e($a['entity_id'] ?? '') ?></div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['result'] === 'success'): ?><span class="badge badge-green">OK</span>
                            <?php elseif ($a['result'] === 'failed'): ?><span class="badge badge-red">Fehler</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($a['result']) ?></span><?php endif; ?>
                        </td>
                        <td class="cell-sub mono"><?= e($a['details'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="empty">Keine Einträge gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= \App\Core\View::partial('partials/pagination', ['total' => $total, 'page' => $page, 'pageSize' => 50, 'baseUrl' => '/audit']) ?>
</div>
