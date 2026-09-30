<?php
/** @var array $users */
$roleLabels = ['sysadmin' => 'Systemadministrator', 'admin' => 'Administrator', 'operator' => 'Operator', 'readonly' => 'Nur Lesen'];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Benutzer</h1>
        <p class="page-sub">Lokale Anmeldekonten für dieses Verwaltungssystem.</p>
    </div>
    <button class="btn btn-primary" id="btn-user-create">Neuer Benutzer</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Benutzername</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="cell-main"><?= e($u['username']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($roleLabels[$u['role']] ?? $u['role']) ?></td>
                        <td>
                            <?php if (!empty($u['is_active'])): ?><span class="badge badge-green">Aktiv</span>
                            <?php else: ?><span class="badge badge-gray">Deaktiviert</span><?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(format_date($u['last_login_at'])) ?></td>
                        <td class="nowrap">
                            <button class="btn btn-sm" data-user='<?= e(json_encode($u, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>' onclick="editUser(JSON.parse(this.dataset.user))">Bearbeiten</button>
                            <button class="btn btn-sm btn-danger"
                                data-api="/api/users/<?= (int) $u['id'] ?>"
                                data-method="DELETE"
                                data-confirm="Benutzer „<?= e($u['username']) ?>“ löschen?"
                                data-success="Benutzer gelöscht."
                                data-redirect="/users">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="6" class="empty">Keine Benutzer gefunden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tpl-user-create">
    <form data-json-form data-method="POST" data-action="/api/users">
        <label class="field"><span class="field-label">Benutzername</span><input class="input" name="username" required></label>
        <label class="field"><span class="field-label">E-Mail</span><input class="input" type="email" name="email" required></label>
        <label class="field"><span class="field-label">Passwort (min. 10 Zeichen)</span><input class="input" type="password" name="password" minlength="10" required></label>
        <label class="field">
            <span class="field-label">Rolle</span>
            <select class="input" name="role">
                <option value="readonly">Nur Lesen</option>
                <option value="operator">Operator</option>
                <option value="admin">Administrator</option>
                <option value="sysadmin">Systemadministrator</option>
            </select>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Anlegen</button>
    </form>
</template>

<script>
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

document.getElementById('btn-user-create').addEventListener('click', () => {
    UAM.modal('Neuer Benutzer', document.getElementById('tpl-user-create').innerHTML);
});

function editUser(u) {
    const roles = [['readonly','Nur Lesen'],['operator','Operator'],['admin','Administrator'],['sysadmin','Systemadministrator']];
    const html =
        '<form id="user-edit-form">' +
        '  <label class="field"><span class="field-label">E-Mail</span><input class="input" type="email" name="email" required value="' + esc(u.email) + '"></label>' +
        '  <label class="field"><span class="field-label">Rolle</span><select class="input" name="role">' +
        roles.map(r => '<option value="' + r[0] + '" ' + (u.role === r[0] ? 'selected' : '') + '>' + r[1] + '</option>').join('') +
        '  </select></label>' +
        '  <label class="field"><span class="field-label">Neues Passwort (leer lassen, um zu behalten)</span><input class="input" type="password" name="password" minlength="10"></label>' +
        '  <label class="checkbox"><input type="checkbox" name="is_active" ' + (Number(u.is_active) === 1 ? 'checked' : '') + '> Konto aktiv</label>' +
        '  <button type="submit" class="btn btn-primary btn-block mt">Speichern</button>' +
        '</form>';
    const m = UAM.modal('Benutzer bearbeiten – ' + esc(u.username), html);
    const form = m.el.querySelector('#user-edit-form');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await UAM.api('PUT', '/api/users/' + u.id, UAM.formObject(form));
            UAM.toast('Gespeichert.');
            window.location.reload();
        } catch (err) { UAM.toast(err.message, 'error'); }
    });
}
</script>
