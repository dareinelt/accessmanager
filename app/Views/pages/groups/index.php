<?php
/** @var array $groups */
/** @var array $connections */
/** @var array $doors */
/** @var int|null $selectedConnection */
$role = $currentUser['role'] ?? 'readonly';
$canManage = in_array($role, ['admin', 'operator'], true);
$connNames = array_column($connections, 'name', 'id');
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Zutrittsgruppen</h1>
        <p class="page-sub">UniFi-Zugriffsrichtlinien (access policies).</p>
    </div>
    <?php if ($canManage): ?><button class="btn btn-primary" id="btn-group-create">Neue Gruppe</button><?php endif; ?>
</div>

<div class="card">
    <form method="get" action="/groups" class="filters">
        <select class="input" name="connection_id" onchange="this.form.submit()">
            <option value="">Alle Standorte</option>
            <?php foreach ($connections as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $selectedConnection === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Standort</th><th>Mitglieder</th><th>Türen</th><?php if ($canManage): ?><th></th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td class="cell-main"><?= e($g['name']) ?></td>
                        <td><?= e($connNames[$g['connection_id']] ?? '—') ?></td>
                        <td><span class="badge badge-blue"><?= (int) $g['member_count'] ?></span></td>
                        <td>
                            <?php if ($g['door_names']): ?>
                                <?php foreach ($g['door_names'] as $n): ?><span class="badge badge-gray" style="margin:0 4px 4px 0;"><?= e($n) ?></span><?php endforeach; ?>
                            <?php else: ?><span class="muted">Keine</span><?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td class="nowrap">
                                <button class="btn btn-sm" data-group='<?= e(json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>' onclick="openGroupForm(JSON.parse(this.dataset.group))">Bearbeiten</button>
                                <button class="btn btn-sm btn-danger"
                                    data-api="/api/groups/<?= (int) $g['connection_id'] ?>/<?= e($g['unifi_id']) ?>"
                                    data-method="DELETE"
                                    data-confirm="Gruppe „<?= e($g['name']) ?>“ löschen?"
                                    data-success="Gruppe gelöscht."
                                    data-redirect="/groups">Löschen</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$groups): ?>
                    <tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="empty">Keine Gruppen gefunden. Bitte zuerst synchronisieren.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canManage): ?>
<script>
const GROUP_CONNECTIONS = <?= json_attr($connections) ?>;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function loadDoorChecks(container, cid, selectedIds) {
    container.innerHTML = '<span class="muted">Türen werden geladen …</span>';
    try {
        const doors = await UAM.api('GET', '/api/doors' + (cid ? '?connection_id=' + cid : ''));
        container.innerHTML = doors.length
            ? doors.map(d =>
                '<label class="checkbox" style="margin-bottom:6px;">' +
                '<input type="checkbox" name="door_id" value="' + esc(d.unifi_id) + '" ' + (selectedIds.includes(String(d.unifi_id)) ? 'checked' : '') + '> ' +
                esc(d.name) + '</label>'
            ).join('')
            : '<p class="muted">Keine Türen für diesen Standort vorhanden.</p>';
    } catch (err) {
        container.innerHTML = '<p class="muted">' + esc(err.message) + '</p>';
    }
}

function openGroupForm(group) {
    const isEdit = !!group;
    const cid = group ? String(group.connection_id) : (String(GROUP_CONNECTIONS[0]?.id ?? ''));
    const resources = isEdit ? (group.resources || []) : [];
    const selectedIds = resources.filter(r => r && r.type === 'door').map(r => String(r.id));

    const html =
        '<form id="group-form">' +
        '  <label class="field"><span class="field-label">Standort</span>' +
        '    <select class="input" name="connection_id" ' + (isEdit ? 'disabled' : '') + '>' +
        GROUP_CONNECTIONS.map(c => '<option value="' + c.id + '" ' + (String(c.id) === cid ? 'selected' : '') + '>' + esc(c.name) + '</option>').join('') +
        '    </select></label>' +
        '  <label class="field"><span class="field-label">Name</span>' +
        '    <input class="input" name="name" required value="' + esc(group?.name ?? '') + '"></label>' +
        '  <label class="field"><span class="field-label">Zeitplan-ID (optional)</span>' +
        '    <input class="input" name="schedule_id" value="' + esc(group?.schedule_id ?? '') + '"></label>' +
        '  <div class="field"><span class="field-label">Türen</span><div id="door-list"></div></div>' +
        '  <button type="submit" class="btn btn-primary btn-block">Speichern</button>' +
        '</form>';

    const m = UAM.modal(isEdit ? 'Gruppe bearbeiten' : 'Neue Zutrittsgruppe', html);
    const form = m.el.querySelector('#group-form');
    const connSelect = form.querySelector('select[name="connection_id"]');
    const doorList = form.querySelector('#door-list');

    loadDoorChecks(doorList, isEdit ? cid : connSelect.value, selectedIds);
    if (!isEdit) {
        connSelect.addEventListener('change', () => loadDoorChecks(doorList, connSelect.value, []));
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const doorIds = Array.from(form.querySelectorAll('input[name="door_id"]:checked')).map(i => i.value);
        const body = {
            connection_id: isEdit ? Number(group.connection_id) : Number(connSelect.value),
            name: form.querySelector('input[name="name"]').value,
            schedule_id: form.querySelector('input[name="schedule_id"]').value || null,
            resources: doorIds.map(id => ({ type: 'door', id: id })),
        };
        const url = isEdit
            ? '/api/groups/' + group.connection_id + '/' + encodeURIComponent(group.unifi_id)
            : '/api/groups';
        try {
            await UAM.api(isEdit ? 'PUT' : 'POST', url, body);
            UAM.toast('Gespeichert.');
            window.location.reload();
        } catch (err) { UAM.toast(err.message, 'error'); }
    });
}

document.getElementById('btn-group-create').addEventListener('click', () => openGroupForm(null));
</script>
<?php endif; ?>
