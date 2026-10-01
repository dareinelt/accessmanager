<?php
/** @var array $users */
/** @var array<string,string> $assignableRoles */
/** @var bool $isSysadmin */
/** @var int $currentUserId */
$roleLabels = \App\Security\Role::labels();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Benutzer</h1>
        <p class="page-sub">Lokale Anmeldekonten für dieses Verwaltungssystem.</p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-user-create">Neuer Benutzer</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th scope="col">Benutzername</th><th scope="col">E-Mail</th><th scope="col">Rolle</th><th scope="col">Status</th><th scope="col">Letzter Login</th><th scope="col"><span class="sr-only">Aktionen</span></th></tr>
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
                            <?php
                            // SECURITY FIX: admins can no longer manage sysadmin accounts (enforced server-side, mirrored here).
                            $manageable = $isSysadmin || $u['role'] !== 'sysadmin';
                            $isSelf = (int) $u['id'] === $currentUserId;
                            ?>
                            <?php if ($manageable): ?>
                                <button type="button" class="btn btn-sm js-user-edit" data-user="<?= e(json_encode($u, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-self="<?= $isSelf ? '1' : '0' ?>">Bearbeiten</button>
                                <?php if (!$isSelf): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                        data-api="/api/users/<?= (int) $u['id'] ?>"
                                        data-method="DELETE"
                                        data-confirm="Benutzer „<?= e($u['username']) ?>“ löschen?"
                                        data-success="Benutzer gelöscht."
                                        data-redirect="/users">Löschen</button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">Nur Systemadministrator</span>
                            <?php endif; ?>
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
        <label class="field"><span class="field-label">Passwort (min. 10 Zeichen)</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password" required></label>
        <label class="field">
            <span class="field-label">Rolle</span>
            <select class="input" name="role">
                <?php foreach (array_reverse($assignableRoles, true) as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Anlegen</button>
    </form>
</template>

<script>
const ASSIGNABLE_ROLES = <?= json_encode(array_reverse($assignableRoles, true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
const esc = UAM.esc;

document.querySelectorAll('.js-user-edit').forEach(btn => {
    btn.addEventListener('click', () => editUser(JSON.parse(btn.dataset.user), btn.dataset.self === '1'));
});

document.getElementById('btn-user-create').addEventListener('click', () => {
    UAM.modal('Neuer Benutzer', document.getElementById('tpl-user-create').innerHTML);
});

function editUser(u, isSelf) {
    const roles = Object.entries(ASSIGNABLE_ROLES);
    // Own role / active state cannot be changed (enforced server-side).
    const lock = isSelf ? ' disabled' : '';
    const html =
        '<form id="user-edit-form">' +
        '  <label class="field"><span class="field-label">E-Mail</span><input class="input" type="email" name="email" required value="' + esc(u.email) + '"></label>' +
        '  <label class="field"><span class="field-label">Rolle</span><select class="input" name="role"' + lock + '>' +
        roles.map(r => '<option value="' + esc(r[0]) + '" ' + (u.role === r[0] ? 'selected' : '') + '>' + esc(r[1]) + '</option>').join('') +
        '  </select></label>' +
        '  <label class="field"><span class="field-label">Neues Passwort (leer lassen, um zu behalten)</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password"></label>' +
        '  <label class="checkbox"><input type="checkbox" name="is_active"' + lock + ' ' + (Number(u.is_active) === 1 ? 'checked' : '') + '> Konto aktiv</label>' +
        '  <button type="submit" class="btn btn-primary btn-block mt">Speichern</button>' +
        '</form>';
    const m = UAM.modal('Benutzer bearbeiten – ' + u.username, html);
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
