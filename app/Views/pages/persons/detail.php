<?php
/** @var array $detail */
/** @var array $groups */
/** @var array $freeCards */
// FIX: role check by rank (sysadmin previously lost these controls).
$canManage = \App\Security\Auth::hasRole(\App\Security\Auth::ROLE_OPERATOR);
$isAdmin = \App\Security\Auth::hasRole(\App\Security\Auth::ROLE_ADMIN);

$person = $detail['person'];
$credentials = $detail['credentials'];
$policyIds = $detail['access_policy_ids'];
$raw = $detail['raw'];

$connectionId = (int) $person['connection_id'];
$unifiId = (string) $person['unifi_id'];
$base = '/api/persons/' . $connectionId . '/' . rawurlencode($unifiId);
$name = $person['full_name'] ?: trim($person['first_name'] . ' ' . $person['last_name']) ?: '(ohne Name)';
$pin = $raw['pin_code'] ?? '';
?>
<div class="page-head">
    <div>
        <h1 class="page-title"><?= e($name) ?></h1>
        <p class="page-sub"><?= e($person['connection_name']) ?> · <?= e($person['email'] ?? 'keine E-Mail') ?></p>
    </div>
    <div class="flex">
        <a class="btn" href="/persons">Zurück</a>
        <?php if ($canManage): ?>
            <button class="btn" id="btn-person-edit">Bearbeiten</button>
            <button class="btn btn-danger"
                data-api="<?= e($base) ?>"
                data-method="DELETE"
                data-confirm="Person „<?= e($name) ?>“ wirklich löschen?"
                data-success="Person gelöscht."
                data-redirect="/persons">Löschen</button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <h2 class="card-title" style="margin-bottom:12px;">Stammdaten</h2>
        <div class="detail-list">
            <div class="detail-row"><span class="detail-key">Vorname</span><span><?= e($person['first_name'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-key">Nachname</span><span><?= e($person['last_name'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-key">E-Mail</span><span><?= e($person['email'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-key">Mitarbeiternummer</span><span class="mono"><?= e($person['employee_number'] ?? '—') ?></span></div>
            <div class="detail-row"><span class="detail-key">PIN-Code</span><span class="mono"><?php if (!empty($detail['redacted'])): ?><span class="muted">verborgen</span><?php else: ?><?= $pin !== '' && $pin !== null ? e((string) $pin) : '—' ?><?php endif; ?></span></div>
            <div class="detail-row">
                <span class="detail-key">Status</span>
                <span>
                    <?php if ($person['status'] === 'ACTIVE'): ?><span class="badge badge-green">Aktiv</span>
                    <?php elseif ($person['status'] === 'PENDING'): ?><span class="badge badge-amber">Ausstehend</span>
                    <?php elseif ($person['status'] === 'DEACTIVATED'): ?><span class="badge badge-gray">Deaktiviert</span>
                    <?php else: ?><span class="badge badge-gray"><?= e($person['status'] ?: 'Unbekannt') ?></span><?php endif; ?>
                </span>
            </div>
            <div class="detail-row"><span class="detail-key">Zuletzt synchronisiert</span><span><?= e(format_date($person['last_synced_at'])) ?></span></div>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title" style="margin-bottom:12px;">Zutrittsgruppen</h2>
        <?php if ($canManage): ?>
            <form id="groups-form">
                <?php foreach ($groups as $g): $gid = (string) $g['unifi_id']; ?>
                    <label class="checkbox" style="margin-bottom:8px;">
                        <input type="checkbox" name="gid" value="<?= e($gid) ?>" <?= in_array($gid, $policyIds, true) ? 'checked' : '' ?>>
                        <?= e($g['name']) ?>
                    </label>
                <?php endforeach; ?>
                <?php if (!$groups): ?><p class="muted">Keine Gruppen vorhanden.</p><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm mt">Gruppen speichern</button>
            </form>
        <?php else: ?>
            <?php if ($detail['access_policy_names']): ?>
                <?php foreach ($detail['access_policy_names'] as $n): ?><span class="badge badge-blue" style="margin:0 6px 6px 0;"><?= e($n) ?></span><?php endforeach; ?>
            <?php else: ?><p class="muted">Keine Zutrittsgruppen zugewiesen.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 class="card-title" style="margin-bottom:12px;">Karten / Zugangsmedien</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Kartennummer</th><th>Alias</th><th>Typ</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($credentials as $cr): ?>
                    <tr>
                        <td class="mono"><?= e($cr['display_id'] ?? $cr['unifi_token']) ?></td>
                        <td><?= e($cr['alias'] ?? '—') ?></td>
                        <td><?= e($cr['card_type'] ?? '—') ?></td>
                        <td>
                            <?php if (strtolower((string) $cr['status']) === 'active'): ?><span class="badge badge-green">Aktiv</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($cr['status'] ?? 'Unbekannt') ?></span><?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="nowrap">
                                <button class="btn btn-sm btn-danger"
                                    data-api="<?= e($base) ?>/card"
                                    data-method="DELETE"
                                    data-body="<?= e(json_encode(['token' => (string) $cr['unifi_token']], JSON_UNESCAPED_UNICODE)) ?>"
                                    data-confirm="Karte „<?= e($cr['display_id'] ?? $cr['unifi_token']) ?>“ entfernen?"
                                    data-success="Karte entfernt.">Entfernen</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$credentials): ?>
                    <tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="empty">Keine Karten zugewiesen.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canManage): ?>
        <div class="flex mt">
            <form data-json-form data-method="PUT" data-action="<?= e($base) ?>/card" class="flex" style="flex:1;">
                <select class="input" name="token" required style="flex:1;">
                    <option value="">Freie Karte auswählen …</option>
                    <?php foreach ($freeCards as $fc): ?>
                        <option value="<?= e($fc['unifi_token']) ?>"><?= e($fc['display_id'] ?? $fc['unifi_token']) ?><?= $fc['alias'] ? ' – ' . e($fc['alias']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Karte zuweisen</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin && !empty($raw)): ?>
<details class="card">
    <summary style="cursor:pointer;font-weight:700;">Rohdaten (UniFi)</summary>
    <pre class="mono" style="overflow:auto;max-height:300px;"><?= e(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</details>
<?php endif; ?>

<?php if ($canManage): ?>
<template id="tpl-person-edit">
    <form data-json-form data-method="PUT" data-action="<?= e($base) ?>">
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Vorname</span>
                <input class="input" name="first_name" value="<?= e($person['first_name'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Nachname</span>
                <input class="input" name="last_name" value="<?= e($person['last_name'] ?? '') ?>">
            </label>
        </div>
        <label class="field">
            <span class="field-label">E-Mail</span>
            <input class="input" type="email" name="user_email" value="<?= e($person['email'] ?? '') ?>">
        </label>
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Mitarbeiternummer</span>
                <input class="input" name="employee_number" value="<?= e($person['employee_number'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">PIN-Code</span>
                <input class="input" name="pin_code" value="<?= e((string) $pin) ?>">
            </label>
        </div>
        <label class="field">
            <span class="field-label">Status</span>
            <select class="input" name="status">
                <?php foreach (['ACTIVE' => 'Aktiv', 'PENDING' => 'Ausstehend', 'DEACTIVATED' => 'Deaktiviert'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $person['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Speichern</button>
    </form>
</template>
<script>
document.getElementById('btn-person-edit').addEventListener('click', () => {
    UAM.modal('Person bearbeiten', document.getElementById('tpl-person-edit').innerHTML);
});
document.getElementById('groups-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const ids = Array.from(e.target.querySelectorAll('input[name="gid"]:checked')).map(i => i.value);
    try {
        await UAM.api('PUT', <?= json_encode($base) ?> + '/groups', { access_policy_ids: ids });
        UAM.toast('Gruppen gespeichert.');
        window.location.reload();
    } catch (err) { UAM.toast(err.message, 'error'); }
});
</script>
<?php endif; ?>
