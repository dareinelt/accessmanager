<?php

declare(strict_types=1);

/**
 * Unified command-line runner for maintenance tasks.
 *
 * Usage:
 *   php bin/cli.php migrate
 *   php bin/cli.php seed
 *   php bin/cli.php sync
 *   php bin/cli.php create-admin <username> <email> <password> [role]
 *   php bin/cli.php tls:sync
 *   php bin/cli.php tls:state
 *   php bin/cli.php ad:sync
 *   php bin/cli.php backup:run
 *   php bin/cli.php encrypt:secret <wert>
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\App;
use App\Core\Database;
use App\Repositories\AppUserRepository;
use App\Repositories\ConnectionRepository;
use App\Security\Crypto;

$command = $argv[1] ?? 'help';

function out(string $msg): void
{
    echo $msg, PHP_EOL;
}

function fail(string $msg): never
{
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}

function runMigrations(): void
{
    $dir = dirname(__DIR__) . '/database/migrations';
    $files = glob($dir . '/*.sql');
    sort($files);
    $pdo = Database::connection();

    foreach ($files as $file) {
        $sql = (string) file_get_contents($file);
        // Strip comment-only lines, then split into statements.
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(trim($l), '--'));
        $statements = preg_split('/;\s*(\r?\n|$)/', implode("\n", $lines));
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
        out('Migriert: ' . basename($file));
    }
    out('Migrationen abgeschlossen.');
}

function seed(): void
{
    $pdo = Database::connection();

    // Roles
    $roles = [
        ['sysadmin', 'Systemadministrator'],
        ['admin', 'Administrator'],
        ['operator', 'Operator'],
        ['readonly', 'Nur Lesen'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO roles (slug, label) VALUES (:s, :l)');
    foreach ($roles as [$slug, $label]) {
        $stmt->execute(['s' => $slug, 'l' => $label]);
    }
    out('Rollen angelegt.');

    // Initial admin user – the default admin is always a sysadmin.
    $users = new AppUserRepository();
    $adminUsername = (string) Config::get('ADMIN_USERNAME', 'admin');
    $admin = $users->findByUsername($adminUsername);
    if ($admin === null) {
        $users->create(
            username: $adminUsername,
            email: (string) Config::get('ADMIN_EMAIL', 'admin@example.com'),
            password: (string) Config::get('ADMIN_PASSWORD', 'admin1234'),
            role: 'sysadmin',
        );
        out('Administrator angelegt (' . $adminUsername . ').');
    } elseif ($admin['role'] !== 'sysadmin') {
        $users->update((int) $admin['id'], ['role' => 'sysadmin']);
        out('Administrator zum Systemadministrator hochgestuft (' . $adminUsername . ').');
    } else {
        out('Administrator existiert bereits.');
    }

    // In mock mode, seed a demo connection + sync demo data.
    if (Config::isMock()) {
        $connections = new ConnectionRepository();
        if ($connections->count() === 0) {
            $connections->create(
                name: 'Demo Standort',
                host: 'demo-controller.local',
                port: 12445,
                token: 'mock-demo-token',
                verifySsl: false,
            );
            out('Demo-Standort angelegt.');
        }
        $result = App::sync()->syncAll();
        out("Synchronisation abgeschlossen: {$result['ok']} ok, {$result['failed']} fehlgeschlagen.");
    }
}

function runSync(): void
{
    $result = App::sync()->syncAll();
    out("Synchronisation abgeschlossen: {$result['ok']} ok, {$result['failed']} fehlgeschlagen.");
    foreach ($result['results'] as $r) {
        $status = $r['success'] ? 'OK' : 'FEHLER';
        out("  - {$r['name']}: {$status}" . (isset($r['error']) ? " ({$r['error']})" : ''));
    }
    if ($result['failed'] > 0) {
        exit(1);
    }
}

function createAdmin(array $args): void
{
    $username = $args[2] ?? '';
    $email = $args[3] ?? '';
    $password = $args[4] ?? '';
    $role = $args[5] ?? 'admin';
    if ($username === '' || $email === '' || $password === '') {
        fail('Verwendung: php bin/cli.php create-admin <username> <email> <password> [role]');
    }
    (new AppUserRepository())->create($username, $email, $password, $role);
    out("Benutzer '{$username}' angelegt.");
}

function tlsSync(): void
{
    App::tls()->ensureFallback();
    App::tls()->syncDisk();
    $live = App::tls()->live();
    out("TLS-Zertifikat bereitgestellt: {$live['label']} (Modus: {$live['mode']}).");
}

function encryptSecret(array $args): void
{
    $value = $args[2] ?? '';
    if ($value === '') {
        fail('Verwendung: php bin/cli.php encrypt:secret <wert>');
    }
    out('Verschlüsselt: ' . Crypto::encrypt($value));
}

function runAdSync(): void
{
    $summary = App::ad()->run();
    out("AD-Sync abgeschlossen (Quelle: {$summary['source']}):");
    out("  - AD-Benutzer geladen: {$summary['users_fetched']}");
    out("  - Zugeordnet: {$summary['matched']}");
    out("  - Neu angelegt: {$summary['created']}");
    out("  - Karten zugewiesen: {$summary['cards_assigned']}");
    out("  - Zutrittsgruppen geändert: {$summary['groups_changed']}");
    if ($summary['errors'] !== []) {
        out('  - Fehler: ' . count($summary['errors']));
        foreach (array_slice($summary['errors'], 0, 10) as $e) {
            $where = isset($e['connection']) ? "{$e['connection']}" : '';
            $who = isset($e['identifier']) && $e['identifier'] !== '' ? " ({$e['identifier']})" : '';
            out("    · {$where}{$who}: {$e['error']}");
        }
        exit(1);
    }
}

function tlsState(): void
{
    $state = App::tls()->state();
    out('Modus: ' . ($state['mode'] === 'strict' ? 'streng (echtes Zertifikat)' : 'Fallback (selbstsigniert)'));
    out('Host: ' . $state['app_host']);
    if ($state['active'] !== null) {
        out('Aktiv: #' . $state['active']['id'] . ' ' . $state['active']['common_name'] . ' (gültig bis ' . date('d.m.Y H:i', $state['active']['not_after']) . ')');
    }
}

function runBackup(): void
{
    $result = App::backup()->runScheduled();
    if ($result === null) {
        out('Kein Backup erforderlich (deaktiviert oder Intervall noch nicht erreicht).');
        return;
    }
    out('Backup erstellt: ' . $result['filename'] . ' (' . number_format((int) $result['size']) . ' Bytes)');
    if (($result['pruned'] ?? 0) > 0) {
        out('Alte Archive bereinigt: ' . $result['pruned']);
    }
}

match ($command) {
    'migrate' => runMigrations(),
    'seed' => seed(),
    'sync' => runSync(),
    'create-admin' => createAdmin($argv),
    'tls:sync' => tlsSync(),
    'tls:state' => tlsState(),
    'ad:sync' => runAdSync(),
    'backup:run' => runBackup(),
    'encrypt:secret' => encryptSecret($argv),
    default => out("Verfügbare Befehle: migrate, seed, sync, create-admin, tls:sync, tls:state, ad:sync, backup:run, encrypt:secret"),
};
