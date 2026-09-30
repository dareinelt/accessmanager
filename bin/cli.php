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
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\App;
use App\Core\Database;
use App\Repositories\AppUserRepository;
use App\Repositories\ConnectionRepository;

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
        ['admin', 'Administrator'],
        ['operator', 'Operator'],
        ['readonly', 'Nur Lesen'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO roles (slug, label) VALUES (:s, :l)');
    foreach ($roles as [$slug, $label]) {
        $stmt->execute(['s' => $slug, 'l' => $label]);
    }
    out('Rollen angelegt.');

    // Initial admin user
    $users = new AppUserRepository();
    if ($users->findByUsername((string) Config::get('ADMIN_USERNAME', 'admin')) === null) {
        $users->create(
            username: (string) Config::get('ADMIN_USERNAME', 'admin'),
            email: (string) Config::get('ADMIN_EMAIL', 'admin@example.com'),
            password: (string) Config::get('ADMIN_PASSWORD', 'admin1234'),
            role: 'admin',
        );
        out('Administrator angelegt (' . Config::get('ADMIN_USERNAME', 'admin') . ').');
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

function tlsState(): void
{
    $state = App::tls()->state();
    out('Modus: ' . ($state['mode'] === 'strict' ? 'streng (echtes Zertifikat)' : 'Fallback (selbstsigniert)'));
    out('Host: ' . $state['app_host']);
    if ($state['active'] !== null) {
        out('Aktiv: #' . $state['active']['id'] . ' ' . $state['active']['common_name'] . ' (gültig bis ' . date('d.m.Y H:i', $state['active']['not_after']) . ')');
    }
}

match ($command) {
    'migrate' => runMigrations(),
    'seed' => seed(),
    'sync' => runSync(),
    'create-admin' => createAdmin($argv),
    'tls:sync' => tlsSync(),
    'tls:state' => tlsState(),
    default => out("Verfügbare Befehle: migrate, seed, sync, create-admin, tls:sync, tls:state"),
};
