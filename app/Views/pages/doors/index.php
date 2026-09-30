<?php
/** @var array $doors */
/** @var array $connections */
$role = $currentUser['role'] ?? 'readonly';
$canUnlock = in_array($role, ['admin', 'operator'], true);
$selected = isset($_GET['connection_id']) ? (int) $_GET['connection_id'] : null;
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Türen</h1>
        <p class="page-sub">Türen und Öffnungssteuerung pro Standort.</p>
    </div>
</div>

<div class="card">
    <form method="get" action="/doors" class="filters">
        <select class="input" name="connection_id" onchange="this.form.submit()">
            <option value="">Alle Standorte</option>
            <?php foreach ($connections as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $selected === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Tür</th><th>Standort</th><th>Typ</th><th>Schlossstatus</th><?php if ($canUnlock): ?><th></th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($doors as $d): ?>
                    <tr>
                        <td>
                            <span class="cell-main"><?= e($d['name']) ?></span>
                            <?php if ($d['full_name'] && $d['full_name'] !== $d['name']): ?><div class="cell-sub"><?= e($d['full_name']) ?></div><?php endif; ?>
                        </td>
                        <td><?= e($d['connection_name']) ?></td>
                        <td><?= e($d['door_type'] ?? '—') ?></td>
                        <td>
                            <?php $status = strtolower((string) $d['lock_status']); ?>
                            <?php if ($status === 'locked'): ?><span class="badge badge-red">Verriegelt</span>
                            <?php elseif ($status === 'unlocked'): ?><span class="badge badge-green">Entriegelt</span>
                            <?php else: ?><span class="badge badge-gray"><?= e($d['lock_status'] ?: 'Unbekannt') ?></span><?php endif; ?>
                        </td>
                        <?php if ($canUnlock): ?>
                            <td class="nowrap">
                                <button class="btn btn-sm btn-primary"
                                    data-api="/api/doors/<?= (int) $d['connection_id'] ?>/<?= e($d['unifi_id']) ?>/unlock"
                                    data-method="POST"
                                    data-confirm="Tür „<?= e($d['name']) ?>“ öffnen?"
                                    data-success="Tür wurde geöffnet.">Öffnen</button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$doors): ?>
                    <tr><td colspan="<?= $canUnlock ? 5 : 4 ?>" class="empty">Keine Türen gefunden. Bitte zuerst synchronisieren.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
