<?php
/** @var array $mappings */
/** @var array $connections */
/** @var string $adSource */
/** @var array $nonCompliant */
?>
<div class="page-head">
    <div>
        <h1 class="page-title">AD-Mappings</h1>
        <p class="page-sub">Active-Directory-Gruppen mit UniFi-Zutrittsgruppen verknüpfen. Quelle: <?= e($adSource) ?></p>
    </div>
    <div class="page-actions" style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-ghost btn-sm" id="btn-ad-sync">Jetzt synchronisieren</button>
        <a class="btn btn-ghost btn-sm" href="/export/ad-non-compliance">CSV-Export (Abweichungen)</a>
        <button class="btn btn-primary" id="btn-mapping-create">Neue Zuordnung</button>
    </div>
</div>

<?php if ($nonCompliant): ?>
    <div class="card" style="margin-bottom:16px;border-left:4px solid var(--warning);">
        <div style="padding:14px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <div>
                <strong><?= count($nonCompliant) ?> Abweichung(en)</strong>
                <div class="muted">Personen sind in Access berechtigt, fehlen aber in der gemappten AD-Gruppe.</div>
            </div>
            <a class="btn btn-sm" href="/export/ad-non-compliance">Details als CSV</a>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>AD-Gruppe</th><th>Zutrittsgruppe</th><th>Standort</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($mappings as $m): ?>
                    <tr>
                        <td>
                            <div class="cell-main"><?= e($m['ad_group_name'] ?? $m['ad_group_dn']) ?></div>
                            <div class="cell-sub mono"><?= e($m['ad_group_dn']) ?></div>
                        </td>
                        <td>
                            <div class="cell-main"><?= e($m['access_group_name'] ?? $m['access_group_id']) ?></div>
                            <div class="cell-sub mono"><?= e($m['access_group_id']) ?></div>
                        </td>
                        <td><?= e($m['connection_name']) ?></td>
                        <td class="nowrap">
                            <button class="btn btn-sm" data-mapping-id="<?= (int) $m['id'] ?>" onclick="editMapping(<?= (int) $m['id'] ?>)">Bearbeiten</button>
                            <button class="btn btn-sm btn-danger"
                                data-api="/api/ad/mappings/<?= (int) $m['id'] ?>"
                                data-method="DELETE"
                                data-confirm="Zuordnung „<?= e($m['ad_group_name'] ?? $m['ad_group_dn']) ?>“ löschen?"
                                data-success="Zuordnung gelöscht.">Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$mappings): ?>
                    <tr><td colspan="4" class="empty">Noch keine Zuordnungen angelegt.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
    .autocomplete { position: relative; }
    .ac-menu {
        position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 60;
        background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
        box-shadow: var(--shadow); max-height: 260px; overflow-y: auto; display: none;
    }
    .ac-menu.open { display: block; }
    .ac-item { padding: 9px 12px; cursor: pointer; display: flex; flex-direction: column; gap: 2px; }
    .ac-item:hover, .ac-item.active { background: #eff6ff; }
    .ac-item span { font-weight: 600; }
    .ac-item small { color: var(--muted); font-size: 12px; word-break: break-all; }
    .ac-empty { padding: 10px 12px; color: var(--muted); }
    .sync-result { margin: 0; }
    .sync-result .line { padding: 4px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; gap: 16px; }
    .sync-result .line:last-child { border-bottom: none; }
</style>

<script>
const connections = <?= json_encode(array_map(static fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']], $connections), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const esc = UAM.esc;

function connectionOptions(selectedId) {
    return connections.map(c =>
        '<option value="' + c.id + '"' + (c.id === selectedId ? ' selected' : '') + '>' + esc(c.name) + '</option>'
    ).join('');
}

/**
 * Autocomplete: opens a search menu below the input and filters the
 * suggestions by the BEGINNING (prefix) of the typed string.
 */
function autocomplete(searchInput, valueInput, nameInput, menu, getItems) {
    let items = [];
    let loaded = false;
    let active = -1;

    async function ensureLoaded() {
        if (loaded) { return; }
        try {
            items = (await getItems()).map(it => ({
                value: it.value,
                label: it.label,
                sub: it.sub || ''
            }));
            loaded = true;
        } catch (err) {
            UAM.toast(err.message, 'error');
        }
    }

    function render() {
        const q = searchInput.value.trim().toLowerCase();
        const matches = q === ''
            ? items
            : items.filter(it => it.label.toLowerCase().startsWith(q) || it.value.toLowerCase().startsWith(q));
        active = -1;
        menu.innerHTML = '';
        if (!matches.length) {
            menu.classList.remove('open');
            return;
        }
        matches.slice(0, 25).forEach((it, i) => {
            const el = document.createElement('div');
            el.className = 'ac-item';
            el.innerHTML = '<span>' + esc(it.label) + '</span>' + (it.sub ? '<small>' + esc(it.sub) + '</small>' : '');
            el.addEventListener('mousedown', e => { e.preventDefault(); choose(it); });
            el.addEventListener('mouseenter', () => setActive(i));
            menu.appendChild(el);
        });
        menu.classList.add('open');
    }

    function setActive(i) {
        active = i;
        Array.from(menu.children).forEach((el, idx) => el.classList.toggle('active', idx === i));
    }

    function choose(it) {
        valueInput.value = it.value;
        if (nameInput) { nameInput.value = it.label; }
        searchInput.value = it.label;
        menu.classList.remove('open');
    }

    searchInput.addEventListener('focus', async () => { await ensureLoaded(); render(); });
    searchInput.addEventListener('input', render);
    searchInput.addEventListener('keydown', e => {
        if (e.key === 'Escape') { menu.classList.remove('open'); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            const first = menu.querySelector('.ac-item');
            if (first) { first.click(); }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            const kids = menu.children;
            if (kids.length) { setActive(active + 1 >= kids.length ? 0 : active + 1); }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const kids = menu.children;
            if (kids.length) { setActive(active - 1 < 0 ? kids.length - 1 : active - 1); }
        }
    });
    document.addEventListener('click', e => {
        if (!menu.contains(e.target) && e.target !== searchInput) { menu.classList.remove('open'); }
    });

    return {
        reload: () => { loaded = false; items = []; },
        setValue: (label, value) => {
            searchInput.value = label;
            valueInput.value = value;
            if (nameInput) { nameInput.value = label; }
        }
    };
}

function openMappingForm(existing) {
    const isEdit = !!existing;
    const title = isEdit ? 'Zuordnung bearbeiten' : 'Neue Zuordnung';
    const connId = existing ? existing.connection_id : (connections[0] ? connections[0].id : 0);

    const html =
        '<form id="mapping-form">' +
        '  <label class="field"><span class="field-label">Standort</span>' +
        '    <select class="input" name="connection_id" id="mapping-connection">' + connectionOptions(connId) + '</select></label>' +
        '  <label class="field"><span class="field-label">AD-Gruppe</span>' +
        '    <div class="autocomplete" id="ac-ad">' +
        '      <input class="input" type="text" id="ac-ad-search" placeholder="Gruppe suchen…" autocomplete="off">' +
        '      <input type="hidden" name="ad_group_dn" id="ac-ad-value">' +
        '      <input type="hidden" name="ad_group_name" id="ac-ad-name">' +
        '      <div class="ac-menu" id="ac-ad-menu"></div>' +
        '    </div></label>' +
        '  <label class="field"><span class="field-label">Zutrittsgruppe</span>' +
        '    <div class="autocomplete" id="ac-access">' +
        '      <input class="input" type="text" id="ac-access-search" placeholder="Zutrittsgruppe suchen…" autocomplete="off">' +
        '      <input type="hidden" name="access_group_id" id="ac-access-value">' +
        '      <div class="ac-menu" id="ac-access-menu"></div>' +
        '    </div></label>' +
        '  <button type="submit" class="btn btn-primary btn-block mt">' + (isEdit ? 'Speichern' : 'Anlegen') + '</button>' +
        '</form>';

    const m = UAM.modal(title, html);
    const form = m.el.querySelector('#mapping-form');
    const connSelect = m.el.querySelector('#mapping-connection');

    const adAc = autocomplete(
        m.el.querySelector('#ac-ad-search'),
        m.el.querySelector('#ac-ad-value'),
        m.el.querySelector('#ac-ad-name'),
        m.el.querySelector('#ac-ad-menu'),
        async () => (await UAM.api('GET', '/api/ad/groups')).map(g => ({ value: g.dn, label: g.name || g.dn, sub: g.dn }))
    );

    const accessAc = autocomplete(
        m.el.querySelector('#ac-access-search'),
        m.el.querySelector('#ac-access-value'),
        null,
        m.el.querySelector('#ac-access-menu'),
        async () => (await UAM.api('GET', '/api/groups?connection_id=' + encodeURIComponent(connSelect.value))).map(g => ({ value: g.unifi_id, label: g.name || g.unifi_id, sub: g.unifi_id }))
    );

    connSelect.addEventListener('change', () => {
        accessAc.reload();
        m.el.querySelector('#ac-access-search').value = '';
        m.el.querySelector('#ac-access-value').value = '';
    });

    if (isEdit) {
        adAc.setValue(existing.ad_group_name || existing.ad_group_dn, existing.ad_group_dn);
        accessAc.setValue(existing.access_group_name || existing.access_group_id, existing.access_group_id);
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const adDn = form.querySelector('#ac-ad-value').value;
        const accessId = form.querySelector('#ac-access-value').value;
        const connectionId = connSelect.value;
        if (!connectionId) { UAM.toast('Bitte einen Standort wählen.', 'error'); return; }
        if (!adDn) { UAM.toast('Bitte eine AD-Gruppe auswählen.', 'error'); return; }
        if (!accessId) { UAM.toast('Bitte eine Zutrittsgruppe auswählen.', 'error'); return; }

        const body = {
            connection_id: Number(connectionId),
            ad_group_dn: adDn,
            ad_group_name: form.querySelector('#ac-ad-name').value || null,
            access_group_id: accessId,
        };
        try {
            if (isEdit) {
                await UAM.api('PUT', '/api/ad/mappings/' + existing.id, body);
            } else {
                await UAM.api('POST', '/api/ad/mappings', body);
            }
            UAM.toast('Gespeichert.');
            window.location.reload();
        } catch (err) {
            UAM.toast(err.message, 'error');
        }
    });
}

async function editMapping(id) {
    try {
        const mapping = await UAM.api('GET', '/api/ad/mappings?connection_id=');
        const all = Array.isArray(mapping) ? mapping : [];
        const found = all.find(x => String(x.id) === String(id));
        if (!found) { UAM.toast('Zuordnung nicht gefunden.', 'error'); return; }
        openMappingForm(found);
    } catch (err) {
        UAM.toast(err.message, 'error');
    }
}

async function runAdSync() {
    const btn = document.getElementById('btn-ad-sync');
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Synchronisiere…';
    try {
        const res = await UAM.api('POST', '/api/ad/sync', { connection_id: null });
        const lines = [
            ['Quelle', res.source || ''],
            ['AD-Benutzer', res.users_fetched],
            ['Zugeordnet', res.matched],
            ['Neu angelegt', res.created],
            ['Karten zugewiesen', res.cards_assigned],
            ['Gruppen geändert', res.groups_changed],
        ];
        let html = '<div class="sync-result">';
        lines.forEach(l => { html += '<div class="line"><span class="muted">' + esc(l[0]) + '</span><span>' + esc(String(l[1])) + '</span></div>'; });
        if (res.errors && res.errors.length) {
            html += '<div class="line"><span class="muted">Fehler</span><span>' + res.errors.length + '</span></div>';
            res.errors.slice(0, 5).forEach(err => {
                html += '<div class="line"><span class="muted">' + esc(err.connection ? err.connection + ' · ' + (err.identifier || '') : (err.identifier || '')) + '</span><span>' + esc(err.error) + '</span></div>';
            });
        }
        html += '</div>';
        UAM.modal('AD-Sync abgeschlossen', html);
        window.setTimeout(() => window.location.reload(), 3000);
    } catch (err) {
        UAM.toast(err.message, 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}

document.getElementById('btn-mapping-create').addEventListener('click', () => openMappingForm(null));
document.getElementById('btn-ad-sync').addEventListener('click', runAdSync);
</script>
