<?php
/** @var array $result */
/** @var array $filters */
/** @var array $connections */
$items = $result['items'];
$total = $result['total'];
$page = (int) $filters['page'];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Karten &amp; Zugangsmedien</h1>
        <p class="page-sub"><?= (int) $total ?> Karten im lokalen Cache.</p>
    </div>
    <a class="btn" href="/export/credentials">CSV exportieren</a>
</div>

<div class="card">
    <form method="get" action="/credentials" class="filters">
        <select class="input" name="connection_id" onchange="this.form.submit()">
            <option value="">Alle Standorte</option>
            <?php foreach ($connections as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (string) ($filters['connection_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input search" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Kartennummer, Alias, Person …">
        <select class="input" name="card_filter">
            <option value="">Alle Karten</option>
            <option value="assigned" <?= ($filters['card_filter'] ?? '') === 'assigned' ? 'selected' : '' ?>>Zugewiesen</option>
            <option value="free" <?= ($filters['card_filter'] ?? '') === 'free' ? 'selected' : '' ?>>Frei</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtern</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Kartennummer</th><th>Alias</th><th>Typ</th><th>Status</th><th>Standort</th><th>Zugewiesen an</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $crd): ?>
                    <tr>
                        <td class="mono"><?= e($crd['display_id'] ?? $crd['unifi_token']) ?></td>
                        <td><?= e($crd['alias'] ?? '—') ?></td>
                        <td><?= e($crd['card_type'] ?? '—') ?></td>
                        <td>
                            <?php $st = strtolower((string) $crd['status']); ?>
                            <?php if ($st === 'active'): ?><span class="badge badge-green">Aktiv</span>
                            <?php elseif ($st === 'inactive'): ?><span class="badge badge-gray">Inaktiv</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($crd['status'] ?: 'Unbekannt') ?></span><?php endif; ?>
                        </td>
                        <td><?= e($crd['connection_name']) ?></td>
                        <td>
                            <?php if ($crd['user_unifi_id']): ?>
                                <a href="/persons/<?= (int) $crd['connection_id'] ?>/<?= e($crd['user_unifi_id']) ?>"><?= e($crd['person_name'] ?? $crd['user_unifi_id']) ?></a>
                            <?php else: ?>
                                <span class="muted">Frei</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="empty">Keine Karten gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= \App\Core\View::partial('partials/pagination', ['total' => $total, 'page' => $page, 'pageSize' => (int) $filters['page_size'], 'baseUrl' => '/credentials']) ?>
</div>
