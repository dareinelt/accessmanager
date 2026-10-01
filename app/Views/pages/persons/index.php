<?php
/** @var array $result */
/** @var array $filters */
/** @var array $connections */
/** @var array $groups */
// FIX: role check by rank (sysadmin previously lost these controls).
$canManage = \App\Security\Auth::hasRole(\App\Security\Auth::ROLE_OPERATOR);
$items = $result['items'];
$total = $result['total'];
$page = (int) $filters['page'];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Personen</h1>
        <p class="page-sub"><?= (int) $total ?> Personen im lokalen Cache.</p>
    </div>
    <div class="flex">
        <a class="btn" href="/export/persons">CSV exportieren</a>
        <?php if ($canManage): ?><button class="btn btn-primary" id="btn-person-create">Neue Person</button><?php endif; ?>
    </div>
</div>

<div class="card">
    <form method="get" action="/persons" class="filters">
        <select class="input" name="connection_id" onchange="this.form.submit()">
            <option value="">Alle Standorte</option>
            <?php foreach ($connections as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (string) ($filters['connection_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input search" type="search" name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Name, E-Mail, Nummer, Karte …">
        <select class="input" name="status">
            <option value="">Status: Alle</option>
            <?php foreach (['ACTIVE' => 'Aktiv', 'PENDING' => 'Ausstehend', 'DEACTIVATED' => 'Deaktiviert'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($filters['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="card_filter">
            <option value="">Karte: Alle</option>
            <option value="assigned" <?= ($filters['card_filter'] ?? '') === 'assigned' ? 'selected' : '' ?>>Mit Karte</option>
            <option value="free" <?= ($filters['card_filter'] ?? '') === 'free' ? 'selected' : '' ?>>Ohne Karte</option>
        </select>
        <select class="input" name="sort">
            <option value="">Sortierung: Name</option>
            <option value="status" <?= ($filters['sort'] ?? '') === 'status' ? 'selected' : '' ?>>Status</option>
            <option value="email" <?= ($filters['sort'] ?? '') === 'email' ? 'selected' : '' ?>>E-Mail</option>
            <option value="newest" <?= ($filters['sort'] ?? '') === 'newest' ? 'selected' : '' ?>>Zuletzt aktualisiert</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtern</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>E-Mail</th><th>Standort</th><th>Mitarbeiternr.</th><th>Karten</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $p): ?>
                    <tr>
                        <td>
                            <a class="cell-main" href="/persons/<?= (int) $p['connection_id'] ?>/<?= e($p['unifi_id']) ?>"><?= e($p['full_name'] ?: trim($p['first_name'] . ' ' . $p['last_name']) ?: '(ohne Name)') ?></a>
                        </td>
                        <td><?= e($p['email'] ?? '—') ?></td>
                        <td><?= e($p['connection_name']) ?></td>
                        <td class="mono"><?= e($p['employee_number'] ?? '—') ?></td>
                        <td><?= e($p['cards'] ?? '—') ?></td>
                        <td>
                            <?php if ($p['status'] === 'ACTIVE'): ?><span class="badge badge-green">Aktiv</span>
                            <?php elseif ($p['status'] === 'PENDING'): ?><span class="badge badge-amber">Ausstehend</span>
                            <?php elseif ($p['status'] === 'DEACTIVATED'): ?><span class="badge badge-gray">Deaktiviert</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($p['status'] ?: 'Unbekannt') ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="6" class="empty">Keine Personen gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= \App\Core\View::partial('partials/pagination', ['total' => $total, 'page' => $page, 'pageSize' => (int) $filters['page_size'], 'baseUrl' => '/persons']) ?>
</div>

<?php if ($canManage): ?>
<template id="tpl-person-create">
    <form data-json-form data-method="POST" data-action="/api/persons">
        <label class="field">
            <span class="field-label">Standort</span>
            <select class="input" name="connection_id" required>
                <option value="">Bitte wählen …</option>
                <?php foreach ($connections as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (string) ($filters['connection_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Vorname</span>
                <input class="input" name="first_name">
            </label>
            <label class="field">
                <span class="field-label">Nachname</span>
                <input class="input" name="last_name">
            </label>
        </div>
        <label class="field">
            <span class="field-label">E-Mail</span>
            <input class="input" type="email" name="user_email">
        </label>
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Mitarbeiternummer</span>
                <input class="input" name="employee_number">
            </label>
            <label class="field">
                <span class="field-label">PIN-Code</span>
                <input class="input" name="pin_code">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Status</span>
            <select class="input" name="status">
                <option value="ACTIVE">Aktiv</option>
                <option value="PENDING">Ausstehend</option>
                <option value="DEACTIVATED">Deaktiviert</option>
            </select>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Anlegen</button>
    </form>
</template>
<script>
document.getElementById('btn-person-create').addEventListener('click', () => {
    UAM.modal('Neue Person', document.getElementById('tpl-person-create').innerHTML);
});
</script>
<?php endif; ?>
