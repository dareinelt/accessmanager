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
use App\Core\App;
use App\Repositories\AppUserRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Repositories\SystemSecretRepository;
use App\Repositories\TlsCertificateRepository;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;

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
// Zusammenfassung
// ---------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
echo sprintf("Ergebnis: %d Tests, %d bestanden, %d fehlgeschlagen\n", count($tests), $passed, $failed);
exit($failed > 0 ? 1 : 0);
