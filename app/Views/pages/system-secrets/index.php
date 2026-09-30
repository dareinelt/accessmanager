<?php
/** @var array $secrets */
/** @var array $categories */
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Systemgeheimnisse</h1>
        <p class="page-sub">Vertrauliche Systemdaten (Active Directory, DNs, API-Endpunkte). Werte werden verschlüsselt gespeichert und sind nur für Systemadministratoren sichtbar.</p>
    </div>
    <button class="btn btn-primary" id="btn-secret-create">Neuer Eintrag</button>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Schlüssel</th><th>Bezeichnung</th><th>Kategorie</th><th>Status</th><th>Aktualisiert</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($secrets as $s): ?>
                    <tr>
                        <td class="mono cell-main"><?= e($s['key']) ?></td>
                        <td><?= e($s['label']) ?></td>
                        <td><?= e($categories[$s['category']] ?? $s['category']) ?></td>
                        <td>
                            <?php if (!empty($s['has_value'])): ?><span class="badge badge-green">Gespeichert</span>
                            <?php else: ?><span class="badge badge-gray">Leer</span><?php endif; ?>
                        </td>
                        <td class="nowrap"><?= e(format_date($s['updated_at'])) ?></td>
                        <td class="nowrap">
                            <button class="btn btn-sm" onclick="revealSecret(<?= (int) $s['id'] ?>, '<?= e($s['key']) ?>')">Anzeigen</button>
                            <button class="btn btn-sm" onclick="editSecret(<?= (int) $s['id'] ?>)">Bearbeiten</button>
                            <button class="btn btn-sm btn-danger"
                                data-api="/api/system-secrets/<?= (int) $s['id'] ?>"
                                data-method="DELETE"
                                data-confirm="Eintrag „<?= e($s['label']) ?>“ löschen?"
                                data-success="Eintrag gelöscht."
                                data-redirect="/system-secrets">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$secrets): ?>
                    <tr><td colspan="6" class="empty">Noch keine Einträge angelegt.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tpl-secret-create">
    <form data-json-form data-method="POST" data-action="/api/system-secrets">
        <label class="field"><span class="field-label">Schlüssel (z. B. ad.ldap.server)</span><input class="input" name="key" required placeholder="ad.ldap.server"></label>
        <label class="field"><span class="field-label">Bezeichnung</span><input class="input" name="label" required placeholder="LDAP-Server"></label>
        <label class="field">
            <span class="field-label">Kategorie</span>
            <select class="input" name="category">
                <?php foreach ($categories as $slug => $label): ?>
                    <option value="<?= e($slug) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span class="field-label">Wert (wird verschlüsselt gespeichert)</span><textarea class="input mono" name="value" required rows="3"></textarea></label>
        <button type="submit" class="btn btn-primary btn-block mt">Anlegen</button>
    </form>
</template>

<script>
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const categories = <?= json_attr($categories) ?>;

document.getElementById('btn-secret-create').addEventListener('click', () => {
    UAM.modal('Neuer Eintrag', document.getElementById('tpl-secret-create').innerHTML);
});

async function revealSecret(id, key) {
    try {
        const res = await UAM.api('POST', '/api/system-secrets/' + id + '/reveal');
        UAM.modal('Wert von „' + esc(key) + '“',
            '<label class="field"><span class="field-label">Entschlüsselter Wert</span><textarea class="input mono" rows="5" readonly>' + esc(res.value) + '</textarea></label>' +
            '<p class="muted">Der Zugriff wird im Audit-Log protokolliert.</p>');
    } catch (err) {
        UAM.toast(err.message, 'error');
    }
}

async function editSecret(id) {
    try {
        const list = await UAM.api('GET', '/api/system-secrets');
        const s = list.find(x => Number(x.id) === Number(id));
        if (!s) throw new Error('Eintrag nicht gefunden');
        const opts = Object.entries(categories).map(([slug, label]) =>
            '<option value="' + esc(slug) + '" ' + (s.category === slug ? 'selected' : '') + '>' + esc(label) + '</option>').join('');
        const html =
            '<form id="secret-edit-form">' +
            '  <label class="field"><span class="field-label">Schlüssel</span><input class="input" value="' + esc(s.key) + '" disabled></label>' +
            '  <label class="field"><span class="field-label">Bezeichnung</span><input class="input" name="label" required value="' + esc(s.label) + '"></label>' +
            '  <label class="field"><span class="field-label">Kategorie</span><select class="input" name="category">' + opts + '</select></label>' +
            '  <label class="field"><span class="field-label">Neuer Wert (leer lassen, um zu behalten)</span><textarea class="input mono" name="value" rows="3"></textarea></label>' +
            '  <button type="submit" class="btn btn-primary btn-block mt">Speichern</button>' +
            '</form>';
        const m = UAM.modal('Eintrag bearbeiten', html);
        const form = m.el.querySelector('#secret-edit-form');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await UAM.api('PUT', '/api/system-secrets/' + id, UAM.formObject(form));
                UAM.toast('Gespeichert.');
                window.location.reload();
            } catch (err) { UAM.toast(err.message, 'error'); }
        });
    } catch (err) {
        UAM.toast(err.message, 'error');
    }
}
</script>
