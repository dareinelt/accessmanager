<?php
/** @var array $sites */
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Standorte</h1>
        <p class="page-sub">Ein Standort entspricht einem UniFi-Controller.</p>
    </div>
    <button class="btn btn-primary" id="btn-site-create">Neuer Standort</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Standort</th><th>Host</th><th>Status</th><th>Personen</th><th>Karten</th><th>Türen</th><th>Gruppen</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $s): ?>
                    <tr>
                        <td class="cell-main"><?= e($s['name']) ?></td>
                        <td class="mono"><?= e($s['host']) ?>:<?= (int) $s['port'] ?></td>
                        <td>
                            <?php if (!empty($s['is_active'])): ?><span class="badge badge-green">Aktiv</span>
                            <?php else: ?><span class="badge badge-gray">Inaktiv</span><?php endif; ?>
                        </td>
                        <td><?= (int) $s['users'] ?></td>
                        <td><?= (int) $s['credentials'] ?></td>
                        <td><?= (int) $s['doors'] ?></td>
                        <td><?= (int) $s['groups'] ?></td>
                        <td class="nowrap">
                            <button class="btn btn-sm" data-site-id="<?= (int) $s['id'] ?>" onclick="testSite(<?= (int) $s['id'] ?>)">Test</button>
                            <button class="btn btn-sm" data-site-id="<?= (int) $s['id'] ?>" onclick="editSite(<?= (int) $s['id'] ?>)">Bearbeiten</button>
                            <button class="btn btn-sm btn-danger"
                                data-api="/api/sites/<?= (int) $s['id'] ?>"
                                data-method="DELETE"
                                data-confirm="Standort „<?= e($s['name']) ?>“ löschen? Alle gecachten Daten werden entfernt."
                                data-success="Standort gelöscht."
                                data-redirect="/sites">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sites): ?>
                    <tr><td colspan="8" class="empty">Noch keine Standorte angelegt.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tpl-site-create">
    <form data-json-form data-method="POST" data-action="/api/sites">
        <label class="field"><span class="field-label">Name</span><input class="input" name="name" required></label>
        <label class="field"><span class="field-label">Host / IP-Adresse</span><input class="input" name="host" required placeholder="192.168.1.10"></label>
        <label class="field"><span class="field-label">Port</span><input class="input" type="number" name="port" value="12445" required></label>
        <label class="field"><span class="field-label">UniFi-API-Token</span><input class="input" name="api_token" required></label>
        <label class="checkbox"><input type="checkbox" name="verify_ssl"> SSL-Zertifikat verifizieren</label>
        <button type="submit" class="btn btn-primary btn-block mt">Anlegen</button>
    </form>
</template>

<script>
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

document.getElementById('btn-site-create').addEventListener('click', () => {
    UAM.modal('Neuer Standort', document.getElementById('tpl-site-create').innerHTML);
});

async function editSite(id) {
    try {
        const data = await UAM.api('GET', '/api/sites/' + id);
        const c = data.connection;
        const html =
            '<form id="site-edit-form">' +
            '  <label class="field"><span class="field-label">Name</span><input class="input" name="name" required value="' + esc(c.name) + '"></label>' +
            '  <label class="field"><span class="field-label">Host / IP-Adresse</span><input class="input" name="host" required value="' + esc(c.host) + '"></label>' +
            '  <label class="field"><span class="field-label">Port</span><input class="input" type="number" name="port" required value="' + (c.port ?? 12445) + '"></label>' +
            '  <label class="field"><span class="field-label">UniFi-API-Token ' + (c.has_token ? '(gespeichert – leer lassen, um zu behalten)' : '(erforderlich)') + '</span><input class="input" name="api_token" ' + (c.has_token ? '' : 'required') + '></label>' +
            '  <label class="checkbox"><input type="checkbox" name="verify_ssl" ' + (c.verify_ssl ? 'checked' : '') + '> SSL-Zertifikat verifizieren</label>' +
            '  <label class="checkbox"><input type="checkbox" name="is_active" ' + (c.is_active ? 'checked' : '') + '> Standort aktiv</label>' +
            '  <button type="submit" class="btn btn-primary btn-block mt">Speichern</button>' +
            '</form>';
        const m = UAM.modal('Standort bearbeiten', html);
        const form = m.el.querySelector('#site-edit-form');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const body = UAM.formObject(form);
            try {
                await UAM.api('PUT', '/api/sites/' + id, body);
                UAM.toast('Gespeichert.');
                window.location.reload();
            } catch (err) { UAM.toast(err.message, 'error'); }
        });
    } catch (err) {
        UAM.toast(err.message, 'error');
    }
}

async function testSite(id) {
    try {
        const res = await UAM.api('POST', '/api/sites/' + id + '/test');
        if (res.success) {
            UAM.modal('Verbindungstest', '<p class="flash flash-success" style="margin:0;">Verbindung erfolgreich — ' + (res.doors ?? 0) + ' Türen gefunden.</p>');
        } else {
            UAM.modal('Verbindungstest', '<p class="flash flash-error" style="margin:0;">' + esc(res.error || 'Unbekannter Fehler') + '</p>');
        }
    } catch (err) {
        UAM.toast(err.message, 'error');
    }
}
</script>
