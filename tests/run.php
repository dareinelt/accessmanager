<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free test runner.
 *
 * Runs against the application's real code paths using the configured
 * database and API client (mock mode recommended). Execute with:
 *
 *   docker compose run --rm app php tests/run.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Controllers\ExportController;
use App\Core\App;
use App\Core\Request;
use App\Repositories\AdGroupMappingRepository;
use App\Repositories\AppUserRepository;
use App\Repositories\BackupRepository;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Repositories\SystemSecretRepository;
use App\Repositories\TlsCertificateRepository;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Security\Role;
use App\Services\AdSyncService;
use App\Services\Ldap\MockLdapClient;
use App\Services\PersonService;

$tests = [];
$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $tests, $passed, $failed;
    $tests[] = $name;
    try {
        $fn();
        $passed++;
        echo "  \033[32mPASS\033[0m  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  \033[31mFAIL\033[0m  {$name}\n";
        echo "        {$e->getMessage()} (line {$e->getLine()} in {$e->getFile()})\n";
    }
}

function assertTrue(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertNotEmpty(mixed $value, string $msg = 'expected non-empty value'): void
{
    if ($value === null || $value === '' || $value === []) {
        throw new RuntimeException($msg);
    }
}

/**
 * Signiert einen CSR zu einem Server-Zertifikat (kein CA, serverAuth EKU),
 * damit der Import-Flow testbar ist, ohne eine externe CA zu bemühen.
 */
function signServerCertificate(string $csr, string $privateKeyPem, string $san): string
{
    $conf = (string) tempnam(sys_get_temp_dir(), 'openssl-');
    file_put_contents($conf, "[ usr_cert ]\nbasicConstraints = CA:FALSE\nkeyUsage = digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\nsubjectAltName = DNS:" . $san . "\n");
    try {
        $key = openssl_pkey_get_private($privateKeyPem);
        $x509 = @openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'usr_cert']);
        if ($x509 === false) {
            throw new RuntimeException('openssl_csr_sign fehlgeschlagen');
        }
        $out = '';
        openssl_x509_export($x509, $out);

        return $out;
    } finally {
        @unlink($conf);
    }
}

echo "UniFi Access Manager – Testlauf\n";
echo str_repeat('=', 60) . "\n\n";

// ---------------------------------------------------------------------
// 1. Configuration parsing
// ---------------------------------------------------------------------
echo "[1] Konfiguration\n";
test('Config::get liefert konfigurierte Werte', function () {
    assertTrue(Config::get('DB_HOST') !== '', 'DB_HOST sollte gesetzt sein');
});

test('Config::bool parst "true"', function () {
    assertTrue(Config::bool('UNIFI_API_MOCK', false) === true, 'UNIFI_API_MOCK sollte true sein');
});

test('Config::int parst Zahlen', function () {
    assertSame(3306, Config::int('DB_PORT', 0), 'DB_PORT sollte 3306 sein');
});

// ---------------------------------------------------------------------
// 2. Crypto (AES-256-GCM)
// ---------------------------------------------------------------------
echo "\n[2] Verschlüsselung\n";
test('Crypto Roundtrip verschlüsselt und entschlüsselt korrekt', function () {
    $secret = 'geheimer-token-1234567890';
    $enc = Crypto::encrypt($secret);
    assertTrue($enc !== $secret, 'Verschlüsselung darf den Klartext nicht offenlegen');
    assertSame($secret, Crypto::decrypt($enc), 'Roundtrip sollte den Klartext zurückgeben');
});

test('Crypto erzeugt unterschiedliche Chiffrate (IV)', function () {
    $secret = 'geheimer-token-1234567890';
    assertTrue(Crypto::encrypt($secret) !== Crypto::encrypt($secret), 'IV sollte zufällig sein');
});

test('Crypto erkennt manipulierte Payloads', function () {
    $enc = Crypto::encrypt('original');
    $raw = base64_decode($enc, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';
    $tampered = base64_encode($raw);
    try {
        Crypto::decrypt($tampered);
        throw new RuntimeException('Manipulierte Payload hätte abgelehnt werden müssen');
    } catch (RuntimeException $e) {
        // erwartet
        assertTrue(true);
    }
});

// ---------------------------------------------------------------------
// 3. CSRF
// ---------------------------------------------------------------------
echo "\n[3] CSRF-Schutz\n";
test('Csrf::token erzeugt und validiert ein Token', function () {
    $_SESSION = [];
    $token = Csrf::token();
    assertSame(64, strlen($token), 'Token sollte 64 Hex-Zeichen haben');
    assertTrue(Csrf::validate($token), 'Eigenes Token sollte validieren');
});

test('Csrf::validate lehnt falsche Token ab', function () {
    $_SESSION = [];
    Csrf::token();
    assertTrue(!Csrf::validate('falsches-token'), 'Falsches Token sollte abgelehnt werden');
    assertTrue(!Csrf::validate(null), 'Null-Token sollte abgelehnt werden');
});

// ---------------------------------------------------------------------
// 4. Lokale Benutzer (Passwort-Hashing)
// ---------------------------------------------------------------------
echo "\n[4] Lokale Benutzer\n";
test('AppUserRepository legt Benutzer mit gehashtem Passwort an', function () {
    $repo = new AppUserRepository();
    $username = 'test_' . bin2hex(random_bytes(4));
    $id = $repo->create($username, $username . '@example.com', 's3cret-Pass', 'readonly');
    try {
        $user = $repo->findByUsername($username);
        assertNotEmpty($user, 'Benutzer sollte gefunden werden');
        assertTrue(password_verify('s3cret-Pass', (string) $user['password_hash']), 'Passwort-Hash sollte verifizieren');
        assertTrue($user['password_hash'] !== 's3cret-Pass', 'Passwort darf nicht im Klartext gespeichert sein');
    } finally {
        $repo->delete($id);
    }
});

test('Auth::hasRole respektiert die Rollenhierarchie', function () {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = Auth::ROLE_SYSADMIN;
    assertTrue(Auth::hasRole(Auth::ROLE_SYSADMIN), 'sysadmin sollte sysadmin erfüllen');
    assertTrue(Auth::hasRole(Auth::ROLE_ADMIN), 'sysadmin sollte admin erfüllen');
    assertTrue(Auth::hasRole(Auth::ROLE_OPERATOR), 'sysadmin sollte operator erfüllen');

    $_SESSION['role'] = Auth::ROLE_READONLY;
    assertTrue(Auth::hasRole(Auth::ROLE_READONLY), 'readonly sollte readonly erfüllen');
    assertTrue(!Auth::hasRole(Auth::ROLE_ADMIN), 'readonly sollte admin NICHT erfüllen');
    assertTrue(!Auth::hasRole(Auth::ROLE_SYSADMIN), 'readonly sollte sysadmin NICHT erfüllen');
});

test('AppUserRepository::findByUsername findet Benutzer über E-Mail', function () {
    $repo = new AppUserRepository();
    $username = 'test_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $id = $repo->create($username, $email, 'pw123', 'readonly');
    try {
        $user = $repo->findByUsername($email);
        assertNotEmpty($user, 'Benutzer sollte über E-Mail gefunden werden');
        assertSame($username, $user['username']);
    } finally {
        $repo->delete($id);
    }
});

// ---------------------------------------------------------------------
// 5. Standorte (Verbindungen) + Token-Verschlüsselung
// ---------------------------------------------------------------------
echo "\n[5] Standorte / Verbindungen\n";
test('ConnectionRepository verschlüsselt API-Token und liest es zurück', function () {
    $repo = new ConnectionRepository();
    $id = $repo->create('Test-Standort-' . bin2hex(random_bytes(3)), 'test.local', 12445, 'super-secret-token', false);
    try {
        $conn = $repo->find($id);
        assertNotEmpty($conn, 'Verbindung sollte gefunden werden');
        assertTrue($conn['api_token_enc'] !== 'super-secret-token', 'Token muss verschlüsselt gespeichert sein');
        assertSame('super-secret-token', Crypto::decrypt((string) $conn['api_token_enc']), 'Token sollte entschlüsselbar sein');
    } finally {
        $repo->delete($id);
    }
});

// ---------------------------------------------------------------------
// 6. Katalog (Cache-Lesezugriffe)
// ---------------------------------------------------------------------
echo "\n[6] Katalog / Cache\n";
test('CatalogRepository liefert synchronisierte Personen', function () {
    $catalog = new CatalogRepository();
    $result = $catalog->listPersons(['page' => 1, 'page_size' => 5]);
    assertTrue(isset($result['items'], $result['total']), 'Ergebnis braucht items + total');
    assertTrue($result['total'] > 0, 'Es sollten synchronisierte Personen vorhanden sein');
    assertSame(5, count($result['items']), 'page_size sollte respektiert werden');
});

test('CatalogRepository Personensuche mit Platzhaltern funktioniert', function () {
    $catalog = new CatalogRepository();
    $result = $catalog->listPersons(['search' => 'Lehmann', 'page_size' => 50]);
    assertTrue($result['total'] >= 1, 'Suche nach "Lehmann" sollte Treffer liefern');
});

test('CatalogRepository listAccessGroups liefert Gruppen', function () {
    $catalog = new CatalogRepository();
    $groups = $catalog->listAccessGroups();
    assertTrue(count($groups) > 0, 'Es sollten Zutrittsgruppen synchronisiert sein');
});

test('CatalogRepository listDoors liefert Türen mit Standortnamen', function () {
    $catalog = new CatalogRepository();
    $doors = $catalog->listDoors();
    assertTrue(count($doors) > 0, 'Es sollten Türen synchronisiert sein');
    assertTrue(isset($doors[0]['connection_name']), 'Türen sollten den Standortnamen enthalten');
});

// ---------------------------------------------------------------------
// 7. Services (Mock-API Roundtrip)
// ---------------------------------------------------------------------
echo "\n[7] Services (CRUD gegen Mock-API)\n";
test('PersonService legt eine Person an und löscht sie wieder', function () {
    $connectionId = (int) (new ConnectionRepository())->all()[0]['id'];
    $created = App::persons()->create($connectionId, [
        'first_name' => 'Test',
        'last_name' => 'Person',
        'user_email' => 'test.person@example.com',
        'status' => 'ACTIVE',
    ]);
    $unifiId = (string) $created['id'];
    assertNotEmpty($unifiId, 'Erstellte Person braucht eine ID');

    $detail = App::persons()->get($connectionId, $unifiId);
    assertSame('Test Person', $detail['person']['full_name'], 'Person sollte im Cache erscheinen');

    App::persons()->delete($connectionId, $unifiId);
    $catalog = new CatalogRepository();
    assertTrue($catalog->getPerson($connectionId, $unifiId) === null, 'Person sollte nach Löschung aus dem Cache verschwinden');
});

test('AccessGroupService legt eine Gruppe an und löscht sie wieder', function () {
    $connectionId = (int) (new ConnectionRepository())->all()[0]['id'];
    $created = App::groups()->create($connectionId, ['name' => 'Test-Gruppe-' . bin2hex(random_bytes(3))]);
    $unifiId = (string) $created['id'];
    assertNotEmpty($unifiId, 'Erstellte Gruppe braucht eine ID');

    App::groups()->delete($connectionId, $unifiId);
    $catalog = new CatalogRepository();
    assertTrue($catalog->getAccessGroup($connectionId, $unifiId) === null, 'Gruppe sollte nach Löschung verschwinden');
});

// ---------------------------------------------------------------------
// 8. Rate-Limiting
// ---------------------------------------------------------------------
echo "\n[8] Login-Rate-Limiting\n";
test('RateLimiter blockiert nach zu vielen Fehlversuchen', function () {
    $identifier = 'ratelimit_' . bin2hex(random_bytes(4));
    for ($i = 0; $i < 5; $i++) {
        \App\Auth\RateLimiter::record($identifier, '127.0.0.1', false);
    }
    assertTrue(\App\Auth\RateLimiter::tooManyAttempts($identifier), 'Nach 5 Fehlversuchen sollte gesperrt werden');
    \App\Auth\RateLimiter::clear($identifier);
});

// ---------------------------------------------------------------------
// 9. TLS-Zertifikate (CSR -> Import -> Aktivierung)
// ---------------------------------------------------------------------
echo "\n[9] TLS-Zertifikate\n";
test('createRequest erzeugt CSR und csrDownload liefert PEM', function () {
    $id = App::tls()->createRequest([
        'common_name' => 'test.local',
        'san' => 'test.local, 192.168.50.10',
        'organization' => 'Test GmbH',
        'organizational_unit' => 'IT',
        'locality' => 'Berlin',
        'state' => 'BE',
        'country' => 'DE',
        'email' => 'it@test.local',
        'key_type' => 'rsa2048',
    ], 'test-runner');
    try {
        $download = App::tls()->csrDownload($id);
        assertNotEmpty($download, 'csrDownload sollte einen CSR liefern');
        assertTrue(str_contains($download['content'], 'BEGIN CERTIFICATE REQUEST'), 'CSR muss PEM sein');
    } finally {
        (new TlsCertificateRepository())->delete($id);
    }
});

test('createRequest lehnt ungültigen Hostnamen ab', function () {
    try {
        App::tls()->createRequest(['common_name' => 'invalid host!'], 'test-runner');
        throw new RuntimeException('Ungültiger Hostname hätte abgelehnt werden müssen');
    } catch (InvalidArgumentException) {
        assertTrue(true);
    }
});

test('CSR -> Import -> Aktivierung -> live()', function () {
    $repo = new TlsCertificateRepository();
    $id = App::tls()->createRequest([
        'common_name' => 'localhost',
        'san' => 'localhost',
        'country' => 'DE',
        'key_type' => 'rsa2048',
    ], 'test-runner');
    try {
        $row = $repo->find($id);
        assertNotEmpty($row, 'Request sollte gefunden werden');
        $cert = signServerCertificate(
            (string) $row['csr_pem'],
            Crypto::decrypt((string) $row['private_key']),
            'localhost'
        );
        assertNotEmpty($cert, 'Selbstsigniertes Zertifikat sollte erzeugt werden');

        $preview = App::tls()->previewImport($cert);
        assertTrue($preview['usable'], 'Import-Vorschau sollte gültig sein');
        assertSame($id, $preview['request']['id'], 'Zertifikat sollte dem Request zugeordnet werden');

        App::tls()->confirmImport($preview['pending'], true, 'test-runner');
        $state = App::tls()->state();
        assertSame('strict', $state['mode'], 'Nach Aktivierung sollte strikter Modus aktiv sein');
        assertNotEmpty($state['active'], 'Es sollte ein aktives Zertifikat geben');

        $live = App::tls()->live();
        assertTrue(str_contains($live['cert'], 'BEGIN CERTIFICATE'), 'live() sollte ein Zertifikat liefern');
        assertTrue(str_contains($live['key'], 'PRIVATE KEY'), 'live() sollte den Schlüssel liefern');

        App::tls()->deactivate();
        assertSame('fallback', App::tls()->state()['mode'], 'Nach Deaktivierung sollte Fallback greifen');
    } finally {
        App::tls()->deactivate();
        $repo->delete($id);
        App::tls()->syncDisk();
    }
});

test('previewImport lehnt fremdes Zertifikat ab', function () {
    $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($config);
    $csr = openssl_csr_new(['commonName' => 'fremd.local'], $key, $config);
    openssl_csr_export($csr, $csrPem);
    openssl_pkey_export($key, $keyPem);
    $cert = signServerCertificate($csrPem, $keyPem, 'fremd.local');
    try {
        App::tls()->previewImport($cert);
        throw new RuntimeException('Fremdes Zertifikat hätte abgelehnt werden müssen');
    } catch (InvalidArgumentException) {
        assertTrue(true);
    }
});

// ---------------------------------------------------------------------
// 10. Systemgeheimnisse (verschlüsselte Systemdaten)
// ---------------------------------------------------------------------
echo "\n[10] Systemgeheimnisse\n";
test('SystemSecretRepository verschlüsselt Werte und liest sie zurück', function () {
    $repo = new SystemSecretRepository();
    $key = 'test.ad.server.' . bin2hex(random_bytes(3));
    $id = $repo->create($key, 'Test-Server', 'ad', 'ldaps://dc.example.local');
    try {
        $row = $repo->find($id);
        assertNotEmpty($row, 'Eintrag sollte gefunden werden');
        assertTrue($row['value_enc'] !== 'ldaps://dc.example.local', 'Wert muss verschlüsselt gespeichert sein');
        assertSame('ldaps://dc.example.local', Crypto::decrypt((string) $row['value_enc']), 'Wert sollte entschlüsselbar sein');
    } finally {
        $repo->delete($id);
    }
});

test('SystemSecretService lehnt doppelte Schlüssel ab', function () {
    $service = App::systemSecrets();
    $key = 'test.dup.' . bin2hex(random_bytes(3));
    $id = $service->create(['key' => $key, 'label' => 'A', 'category' => 'ad', 'value' => 'x'])['id'];
    try {
        try {
            $service->create(['key' => $key, 'label' => 'B', 'category' => 'ad', 'value' => 'y']);
            throw new RuntimeException('Doppelter Schlüssel hätte abgelehnt werden müssen');
        } catch (InvalidArgumentException) {
            assertTrue(true);
        }
    } finally {
        $service->delete($id);
    }
});

// ---------------------------------------------------------------------
// 11. AD-Integration (LDAP + Gruppen-Mapping + Sync)
// ---------------------------------------------------------------------
echo "\n[11] AD-Integration\n";
test('MockLdapClient liefert Benutzer und Gruppen', function () {
    $ldap = new MockLdapClient();
    $users = $ldap->getUsers();
    $groups = $ldap->getGroups();
    assertTrue(count($users) > 0, 'Mock-LDAP sollte Benutzer liefern');
    assertTrue(count($groups) > 0, 'Mock-LDAP sollte Gruppen liefern');
    assertTrue(isset($users[0]['identifier'], $users[0]['email']), 'Benutzer braucht identifier + email');
    assertTrue(isset($groups[0]['dn'], $groups[0]['name']), 'Gruppe braucht dn + name');
});

test('AdGroupMappingRepository CRUD und Abfragen', function () {
    $repo = new AdGroupMappingRepository();
    $connections = (new ConnectionRepository())->all();
    assertTrue(count($connections) > 0, 'Es sollte mindestens eine Verbindung geben');
    $connectionId = (int) $connections[0]['id'];

    $groups = App::groups()->list($connectionId);
    assertTrue(count($groups) > 0, 'Es sollten Zutrittsgruppen synchronisiert sein');
    $accessGroupId = (string) $groups[0]['unifi_id'];
    $dn = 'CN=IT,OU=Groups,DC=example,DC=com';

    $id = $repo->create($connectionId, $dn, $accessGroupId, 'IT');
    try {
        $found = $repo->find($id);
        assertNotEmpty($found, 'Zuordnung sollte gefunden werden');
        assertSame($dn, $found['ad_group_dn']);
        assertSame($accessGroupId, $found['access_group_id']);
        assertSame('IT', $found['ad_group_name']);
        assertSame($connectionId, (int) $found['connection_id']);
        assertTrue($repo->exists($connectionId, $dn), 'exists sollte true liefern');
        assertTrue(in_array($accessGroupId, $repo->mappedAccessGroupIds($connectionId), true), 'mappedAccessGroupIds sollte die Gruppe enthalten');
        assertTrue(count($repo->list($connectionId)) >= 1, 'list sollte die Zuordnung enthalten');

        $repo->update($id, ['connection_id' => $connectionId, 'ad_group_name' => 'IT-Abteilung']);
        assertSame('IT-Abteilung', $repo->find($id)['ad_group_name'], 'update sollte den Namen ändern');
        assertSame($connectionId, (int) $repo->find($id)['connection_id'], 'update sollte connection_id übernehmen');

        assertTrue(is_array($repo->findNonCompliant($connectionId)), 'findNonCompliant sollte ein Array liefern');
    } finally {
        $repo->delete($id);
    }
    assertTrue($repo->find($id) === null, 'Nach delete sollte die Zuordnung weg sein');
});

test('BackupService: Export/Restore-Roundtrip', function () {
    $service = App::backup();
    $before = $service->snapshot('test')['tables'];
    $json = $service->toJson('test');
    $snapshot = $service->decode($json);
    $preview = $service->preview($snapshot);
    assertTrue($preview['total'] > 0, 'Backup sollte Datensätze enthalten');
    assertSame('accessmanager-backup', $snapshot['format'], 'Format sollte gesetzt sein');

    $service->restore($snapshot, 0, 'test');
    $after = $service->snapshot()['tables'];
    foreach (BackupRepository::TABLES as $table) {
        assertSame(count($before[$table] ?? []), count($after[$table] ?? []), "Tabelle {$table} sollte nach Restore gleich viele Zeilen haben");
    }
});

test('AdSyncService::run liefert eine Zusammenfassung (Mock-LDAP)', function () {
    $sync = new AdSyncService(
        new MockLdapClient(),
        new AdGroupMappingRepository(),
        new CatalogRepository(),
        new CacheRepository(),
        new ConnectionRepository(),
        App::persons(),
        App::audit(),
    );
    $summary = $sync->run();
    foreach (['source', 'users_fetched', 'matched', 'created', 'cards_assigned', 'groups_changed', 'errors'] as $key) {
        assertTrue(array_key_exists($key, $summary), "Zusammenfassung braucht das Feld '{$key}'");
    }
    assertTrue($summary['users_fetched'] > 0, 'Mock-LDAP sollte Benutzer liefern');
    assertTrue(is_array($summary['errors']), 'errors sollte ein Array sein');
    assertTrue(in_array($summary['status'], ['success', 'partial', 'error'], true), 'status sollte gültig sein');
});

// ---------------------------------------------------------------------
// 12. Security-Hardening (Audit)
// ---------------------------------------------------------------------
echo "\n[12] Security-Hardening\n";

test('Role-Enum: Rangfolge und Auth::hasRole', function () {
    assertTrue(Role::Sysadmin->satisfies(Role::Admin), 'sysadmin erfüllt admin');
    assertTrue(!Role::Operator->satisfies(Role::Admin), 'operator erfüllt admin nicht');
    $_SESSION['role'] = 'sysadmin';
    assertTrue(Auth::hasRole(Auth::ROLE_OPERATOR), 'sysadmin darf Operator-Funktionen nutzen');
    $_SESSION['role'] = 'readonly';
    assertTrue(!Auth::hasRole(Auth::ROLE_OPERATOR), 'readonly darf keine Operator-Funktionen nutzen');
    unset($_SESSION['role']);
});

test('Auth::hashPassword erzeugt Argon2id/Bcrypt-Hash', function () {
    $hash = Auth::hashPassword('Sehr-Geheim-123');
    assertTrue(password_verify('Sehr-Geheim-123', $hash), 'Hash muss verifizierbar sein');
    assertTrue(str_starts_with($hash, '$argon2id$') || str_starts_with($hash, '$2y$'), 'Argon2id oder Bcrypt erwartet');
});

test('AppUserService verhindert Rechteausweitung durch Admins', function () {
    $svc = App::appUsers();
    $suffix = bin2hex(random_bytes(3));
    $expectFail = function (callable $fn, string $msg): void {
        try {
            $fn();
        } catch (InvalidArgumentException) {
            return;
        }
        throw new RuntimeException($msg);
    };

    $expectFail(fn () => $svc->create(['username' => 'esc_' . $suffix, 'email' => "esc_{$suffix}@example.test", 'password' => 'Passwort-1234', 'role' => 'sysadmin'], 1, 'admin', Role::Admin),
        'Admin darf keinen Sysadmin anlegen');

    $sys = $svc->create(['username' => 'sys_' . $suffix, 'email' => "sys_{$suffix}@example.test", 'password' => 'Passwort-1234', 'role' => 'sysadmin'], null, null, Role::Sysadmin);
    $adm = $svc->create(['username' => 'adm_' . $suffix, 'email' => "adm_{$suffix}@example.test", 'password' => 'Passwort-1234', 'role' => 'admin'], null, null, Role::Sysadmin);
    $sysId = (int) $sys['id'];
    $admId = (int) $adm['id'];

    try {
        $expectFail(fn () => $svc->update($sysId, ['password' => 'Neues-Passwort-99'], $admId, 'adm', Role::Admin), 'Admin darf Sysadmin-Passwort nicht ändern');
        $expectFail(fn () => $svc->delete($sysId, $admId, 'adm', Role::Admin), 'Admin darf Sysadmin nicht löschen');
        $expectFail(fn () => $svc->update($admId, ['role' => 'sysadmin'], $sysId, 'sys', Role::Admin), 'Admin darf niemanden zum Sysadmin befördern');
        $expectFail(fn () => $svc->update($admId, ['role' => 'readonly'], $admId, 'adm', Role::Admin), 'Eigene Rolle darf nicht geändert werden');
        $expectFail(fn () => $svc->update($admId, ['is_active' => false], $admId, 'adm', Role::Admin), 'Eigenes Konto darf nicht deaktiviert werden');
        $expectFail(fn () => $svc->update($admId, ['email' => 'kein-mail'], $sysId, 'sys', Role::Sysadmin), 'Ungültige E-Mail muss abgelehnt werden');
        $expectFail(fn () => $svc->create(['username' => 'adm_' . $suffix, 'email' => "x_{$suffix}@example.test", 'password' => 'Passwort-1234', 'role' => 'readonly'], null, null, Role::Sysadmin),
            'Doppelter Benutzername muss als Validierungsfehler gemeldet werden');

        $svc->update($admId, ['role' => 'operator'], $sysId, 'sys', Role::Sysadmin);
        assertSame('operator', (new AppUserRepository())->find($admId)['role'], 'Sysadmin darf Rollen ändern');
    } finally {
        $svc->delete($admId, null, null, null);
        $svc->delete($sysId, null, null, null);
    }
});

test('Standard-Administrator ist nicht löschbar', function () {
    $admin = (new AppUserRepository())->findByUsername((string) Config::get('ADMIN_USERNAME', 'admin'));
    assertTrue($admin !== null, 'Standard-Admin sollte existieren (seed)');
    try {
        App::appUsers()->delete((int) $admin['id'], null, null, Role::Sysadmin);
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('Standard-Admin wurde gelöscht');
});

test('Request::queryInt / queryString normalisieren Filter', function () {
    $backup = $_GET;
    try {
        $_GET = ['a' => '', 'b' => '7', 'c' => 'abc', 'd' => '0', 'e' => ['x'], 'f' => '  text '];
        $r = new Request();
        assertSame(null, $r->queryInt('a'), '"Alle Standorte" (leer) muss null sein');
        assertSame(7, $r->queryInt('b'));
        assertSame(null, $r->queryInt('c'));
        assertSame(null, $r->queryInt('d'));
        assertSame(null, $r->queryString('e'), 'Arrays werden verworfen');
        assertSame('text', $r->queryString('f'));
    } finally {
        $_GET = $backup;
    }
});

test('Request::clientIp ignoriert X-Forwarded-For ohne Trusted Proxy', function () {
    $ip = Request::clientIp(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
    assertSame('10.0.0.5', $ip, 'Gefälschter XFF-Header darf nicht übernommen werden');
});

test('CSV-Export neutralisiert Formeln', function () {
    assertSame("'=HYPERLINK(\"x\")", ExportController::csvCell('=HYPERLINK("x")'));
    assertSame("'+1", ExportController::csvCell('+1'));
    assertSame("'@SUM(A1)", ExportController::csvCell('@SUM(A1)'));
    assertSame('Max Mustermann', ExportController::csvCell('Max Mustermann'));
    assertSame('', ExportController::csvCell(null));
});

test('LIKE-Platzhalter werden maskiert', function () {
    assertSame('100\\%\\_a\\\\b', CatalogRepository::escapeLike('100%_a\\b'));
});

test('RateLimiter sperrt pro IP-Adresse', function () {
    $ip = '198.51.100.' . random_int(1, 254);
    $ids = [];
    for ($i = 0; $i < 20; $i++) {
        $ids[] = $id = 'spray_' . bin2hex(random_bytes(4));
        \App\Auth\RateLimiter::record($id, $ip, false);
    }
    try {
        assertTrue(\App\Auth\RateLimiter::tooManyAttemptsFromIp($ip), 'Nach 20 Fehlversuchen von einer IP sollte gesperrt werden');
    } finally {
        foreach ($ids as $id) {
            \App\Auth\RateLimiter::clear($id);
        }
    }
});

test('PersonService::redactForReadonly entfernt PIN/Rohdaten', function () {
    $detail = ['person' => ['full_name' => 'X', 'raw_json' => '{"pin_code":"1234"}'], 'raw' => ['pin_code' => '1234']];
    $red = PersonService::redactForReadonly($detail);
    assertSame([], $red['raw']);
    assertTrue(!isset($red['person']['raw_json']), 'raw_json muss entfernt werden');
});

// ---------------------------------------------------------------------
// Zusammenfassung
// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
echo sprintf("Ergebnis: %d Tests, %d bestanden, %d fehlgeschlagen\n", count($tests), $passed, $failed);
exit($failed > 0 ? 1 : 0);
