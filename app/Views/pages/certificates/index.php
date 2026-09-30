<?php
/**
 * @var array<string,mixed> $state
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string> $keyTypes
 * @var array<string,string> $csrValues
 * @var array<string,mixed>|null $preview
 */

use App\Services\Tls\CertificateInspector;

/** Format a Unix timestamp for display. */
$certDate = static fn (?int $ts): string => $ts !== null && $ts > 0 ? date('d.m.Y H:i', $ts) : '–';

/** Map an inspector status to a badge. */
$statusBadge = static function (string $status): string {
    return match ($status) {
        CertificateInspector::STATUS_VALID => '<span class="badge badge-green">Gültig</span>',
        CertificateInspector::STATUS_EXPIRING => '<span class="badge badge-amber">Läuft bald ab</span>',
        CertificateInspector::STATUS_EXPIRED => '<span class="badge badge-red">Abgelaufen</span>',
        CertificateInspector::STATUS_NOT_YET_VALID => '<span class="badge badge-blue">Noch nicht gültig</span>',
        default => '<span class="badge badge-gray">Kein Zertifikat</span>',
    };
};
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Zertifikate</h1>
        <p class="page-sub">HTTPS-Zertifikate: CSR erstellen, signiertes Zertifikat importieren und aktivieren. Solange kein gültiges Zertifikat aktiv ist, wird automatisch ein selbstsigniertes Notfall-Zertifikat verwendet – HTTPS ist damit immer aktiv.</p>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Status</h2>
    <div class="detail-list">
        <div class="detail-row">
            <span class="detail-key">Auslieferung</span>
            <span>
                <?php if ($state['mode'] === 'strict'): ?>
                    <span class="badge badge-green">Echtes Zertifikat</span>
                <?php else: ?>
                    <span class="badge badge-amber">Notfall-Zertifikat (selbstsigniert)</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="detail-row"><span class="detail-key">Hostname (APP_URL)</span><span class="mono"><?= e($state['app_host']) ?></span></div>
        <?php if ($state['active'] !== null): ?>
            <div class="detail-row"><span class="detail-key">Aktives Zertifikat</span><span><?= e('#' . $state['active']['id'] . ' – ' . $state['active']['common_name']) ?></span></div>
            <div class="detail-row"><span class="detail-key">Aussteller</span><span><?= e($state['active']['issuer_cn']) ?></span></div>
            <div class="detail-row"><span class="detail-key">Gültig bis</span><span><?= $certDate($state['active']['not_after']) ?> <?= $statusBadge($state['active_status']) ?></span></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($preview !== null): ?>
<div class="card">
    <h2 class="card-title">Import bestätigen</h2>
    <div class="detail-list">
        <div class="detail-row"><span class="detail-key">Request</span><span><?= e('#' . $preview['request']['id'] . ' – ' . $preview['request']['common_name']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Inhaber (Subject)</span><span class="mono"><?= e($preview['details']['subject']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Aussteller (Issuer)</span><span class="mono"><?= e($preview['details']['issuer']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Seriennummer</span><span class="mono"><?= e($preview['details']['serial']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Fingerabdruck (SHA-256)</span><span class="mono"><?= e(chunk_split($preview['details']['fingerprint'], 32, ' ')) ?></span></div>
        <div class="detail-row"><span class="detail-key">Schlüsseltyp</span><span><?= e($preview['details']['key_type']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Gültig von – bis</span><span><?= $certDate($preview['details']['not_before']) ?> – <?= $certDate($preview['details']['not_after']) ?> <?= $statusBadge($preview['status']) ?></span></div>
        <div class="detail-row"><span class="detail-key">Alternative Namen</span><span><?= e(implode(', ', $preview['details']['san'])) ?></span></div>
        <?php if ($preview['chain'] !== []): ?>
            <div class="detail-row"><span class="detail-key">Zertifikatskette</span>
                <span>
                    <?php foreach ($preview['chain'] as $i => $chain): ?>
                        <div class="mono"><?= e(($i + 1) . '. ' . $chain['subject']) ?> <span class="muted">(bis <?= $certDate($chain['not_after']) ?>)</span></div>
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php foreach ($preview['warnings'] as $warning): ?>
        <div class="flash flash-error" style="margin-top:12px;"><?= e($warning) ?></div>
    <?php endforeach; ?>

    <form method="post" action="/certificates/import/confirm" class="mt">
        <?= \App\Security\Csrf::field() ?>
        <?php if ($preview['usable']): ?>
            <label class="checkbox" style="margin-bottom:12px;">
                <input type="checkbox" name="activate" value="1" checked>
                Zertifikat nach dem Import aktivieren
            </label>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Import bestätigen</button>
    </form>
    <form method="post" action="/certificates/import/discard" style="margin-top:8px;">
        <?= \App\Security\Csrf::field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Abbrechen</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Neuen Request erstellen</h2>
    <form method="post" action="/certificates/request">
        <?= \App\Security\Csrf::field() ?>
        <div class="form-grid">
            <label class="field">
                <span class="field-label">Hostname (Common Name) *</span>
                <input class="input" name="common_name" required value="<?= e($csrValues['common_name'] ?? '') ?>" placeholder="access.firma.local">
            </label>
            <label class="field">
                <span class="field-label">Alternative Namen (SAN, durch Komma getrennt)</span>
                <input class="input" name="san" value="<?= e($csrValues['san'] ?? '') ?>" placeholder="access.firma.local, 192.168.1.10">
            </label>
            <label class="field">
                <span class="field-label">Organisation</span>
                <input class="input" name="organization" value="<?= e($csrValues['organization'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Abteilung</span>
                <input class="input" name="organizational_unit" value="<?= e($csrValues['organizational_unit'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Ort</span>
                <input class="input" name="locality" value="<?= e($csrValues['locality'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Bundesland</span>
                <input class="input" name="state" value="<?= e($csrValues['state'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Land (zweistellig)</span>
                <input class="input" name="country" maxlength="2" value="<?= e($csrValues['country'] ?? 'DE') ?>">
            </label>
            <label class="field">
                <span class="field-label">E-Mail</span>
                <input class="input" type="email" name="email" value="<?= e($csrValues['email'] ?? '') ?>">
            </label>
            <label class="field">
                <span class="field-label">Schlüsseltyp</span>
                <select class="input" name="key_type">
                    <?php foreach ($keyTypes as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($csrValues['key_type'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit" class="btn btn-primary mt">Request erstellen</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Zertifikat importieren</h2>
    <p class="muted">Importieren Sie das von Ihrer Zertifizierungsstelle signierte Zertifikat (PEM mit vollständiger Kette). Datei hochladen oder Inhalt einfügen.</p>
    <form method="post" action="/certificates/import" enctype="multipart/form-data" class="mt">
        <?= \App\Security\Csrf::field() ?>
        <label class="field">
            <span class="field-label">Datei (.pem, .crt, .cer)</span>
            <input class="input" type="file" name="certificate_file" accept=".pem,.crt,.cer">
        </label>
        <label class="field">
            <span class="field-label">… oder PEM-Inhalt einfügen</span>
            <textarea class="input" name="certificate_text" rows="6" placeholder="-----BEGIN CERTIFICATE-----&#10;…"></textarea>
        </label>
        <button type="submit" class="btn btn-primary mt">Vorschau anzeigen</button>
    </form>
</div>

<div class="card">
    <h2 class="card-title">Requests &amp; Zertifikate</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Typ</th><th>Status</th><th>Gültig bis</th><th>Aussteller</th><th>Kette</th><th>Verwendung</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="cell-main"><?= e($row['common_name']) ?><?= $row['in_use'] ? ' <span class="badge badge-blue">In Verwendung</span>' : '' ?></td>
                        <td><?= $row['kind'] === 'csr' ? 'Request' : 'Notfall' ?></td>
                        <td><?= $statusBadge($row['status']) ?></td>
                        <td><?= $certDate($row['cert_not_after']) ?><?= $row['days_left'] !== null && $row['days_left'] < 0 ? ' <span class="badge badge-red">' . (int) $row['days_left'] . ' Tage</span>' : '' ?></td>
                        <td><?= e($row['cert_issuer_cn']) ?></td>
                        <td><?= $row['chain_count'] > 0 ? (int) $row['chain_count'] : '–' ?></td>
                        <td>
                            <?php if ($row['active']): ?><span class="badge badge-green">Aktiv</span><?php else: ?><span class="badge badge-gray">Inaktiv</span><?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <?php if ($row['has_csr']): ?>
                                <a class="btn btn-sm" href="/certificates/csr?id=<?= (int) $row['id'] ?>">CSR</a>
                            <?php endif; ?>
                            <?php if ($row['can_activate']): ?>
                                <form method="post" action="/certificates/activate" style="display:inline;">
                                    <?= \App\Security\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm">Aktivieren</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($row['active']): ?>
                                <form method="post" action="/certificates/deactivate" style="display:inline;">
                                    <?= \App\Security\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm">Deaktivieren</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($row['can_delete']): ?>
                                <form method="post" action="/certificates/delete" style="display:inline;">
                                    <?= \App\Security\Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="empty">Noch keine Requests angelegt. Erstellen Sie oben einen Request, um einen CSR zu erzeugen.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
