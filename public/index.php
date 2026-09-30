<?php

declare(strict_types=1);

/**
 * Front controller. All requests are routed through here.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$router = new Router();

// ------------------------------------------------------------------ Web
$router->get('/', fn () => Response::redirect('/dashboard'));
$router->get('/login', 'App\Controllers\AuthController@loginForm');
$router->post('/login', 'App\Controllers\AuthController@login');
$router->post('/logout', 'App\Controllers\AuthController@logout');

$router->get('/dashboard', 'App\Controllers\PageController@dashboard');
$router->get('/persons', 'App\Controllers\PageController@persons');
$router->get('/persons/{connection_id}/{unifi_id}', 'App\Controllers\PageController@personDetail');
$router->get('/credentials', 'App\Controllers\PageController@credentials');
$router->get('/groups', 'App\Controllers\PageController@groups');
$router->get('/doors', 'App\Controllers\PageController@doors');
$router->get('/sites', 'App\Controllers\PageController@sites');
$router->get('/sync', 'App\Controllers\PageController@sync');
$router->get('/ad-mappings', 'App\Controllers\PageController@adMappings');
$router->get('/audit', 'App\Controllers\PageController@audit');
$router->get('/users', 'App\Controllers\PageController@users');
$router->get('/settings', 'App\Controllers\PageController@settings');
$router->get('/system-secrets', 'App\Controllers\PageController@systemSecrets');

$router->get('/backup', 'App\Controllers\BackupController@index');
$router->get('/backup/download', 'App\Controllers\BackupController@download');
$router->get('/backup/{filename}/download', 'App\Controllers\BackupController@downloadFile');
$router->post('/backup/create', 'App\Controllers\BackupController@create');
$router->post('/backup/delete', 'App\Controllers\BackupController@delete');
$router->post('/backup/restore/preview', 'App\Controllers\BackupController@previewRestore');
$router->post('/backup/restore/confirm', 'App\Controllers\BackupController@confirmRestore');
$router->post('/backup/restore/discard', 'App\Controllers\BackupController@discardRestore');

$router->get('/certificates', 'App\Controllers\CertificateController@index');
$router->post('/certificates/request', 'App\Controllers\CertificateController@createRequest');
$router->get('/certificates/csr', 'App\Controllers\CertificateController@downloadCsr');
$router->post('/certificates/import', 'App\Controllers\CertificateController@previewImport');
$router->post('/certificates/import/confirm', 'App\Controllers\CertificateController@confirmImport');
$router->post('/certificates/import/discard', 'App\Controllers\CertificateController@discardImport');
$router->post('/certificates/activate', 'App\Controllers\CertificateController@activate');
$router->post('/certificates/deactivate', 'App\Controllers\CertificateController@deactivate');
$router->post('/certificates/delete', 'App\Controllers\CertificateController@delete');

$router->get('/export/persons', 'App\Controllers\ExportController@persons');
$router->get('/export/credentials', 'App\Controllers\ExportController@credentials');
$router->get('/export/audit', 'App\Controllers\ExportController@audit');
$router->get('/export/ad-non-compliance', 'App\Controllers\ExportController@adNonCompliance');

// ------------------------------------------------------------------ API
$router->get('/api/dashboard', 'App\Controllers\Api\DashboardController@stats');

$router->get('/api/persons', 'App\Controllers\Api\PersonController@list');
$router->get('/api/persons/{connection_id}/{unifi_id}', 'App\Controllers\Api\PersonController@detail');
$router->post('/api/persons', 'App\Controllers\Api\PersonController@create');
$router->put('/api/persons/{connection_id}/{unifi_id}', 'App\Controllers\Api\PersonController@update');
$router->delete('/api/persons/{connection_id}/{unifi_id}', 'App\Controllers\Api\PersonController@delete');
$router->put('/api/persons/{connection_id}/{unifi_id}/card', 'App\Controllers\Api\PersonController@assignCard');
$router->delete('/api/persons/{connection_id}/{unifi_id}/card', 'App\Controllers\Api\PersonController@unassignCard');
$router->put('/api/persons/{connection_id}/{unifi_id}/groups', 'App\Controllers\Api\PersonController@setGroups');

$router->get('/api/credentials', 'App\Controllers\Api\CredentialController@list');
$router->get('/api/credentials/{connection_id}/{token}', 'App\Controllers\Api\CredentialController@detail');

$router->get('/api/groups', 'App\Controllers\Api\AccessGroupController@list');
$router->get('/api/groups/{connection_id}/{unifi_id}', 'App\Controllers\Api\AccessGroupController@detail');
$router->post('/api/groups', 'App\Controllers\Api\AccessGroupController@create');
$router->put('/api/groups/{connection_id}/{unifi_id}', 'App\Controllers\Api\AccessGroupController@update');
$router->delete('/api/groups/{connection_id}/{unifi_id}', 'App\Controllers\Api\AccessGroupController@delete');

$router->get('/api/doors', 'App\Controllers\Api\DoorController@list');
$router->post('/api/doors/{connection_id}/{unifi_id}/unlock', 'App\Controllers\Api\DoorController@unlock');

$router->get('/api/sites', 'App\Controllers\Api\SiteController@list');
$router->get('/api/sites/{id}', 'App\Controllers\Api\SiteController@detail');
$router->post('/api/sites', 'App\Controllers\Api\SiteController@create');
$router->put('/api/sites/{id}', 'App\Controllers\Api\SiteController@update');
$router->delete('/api/sites/{id}', 'App\Controllers\Api\SiteController@delete');
$router->post('/api/sites/{id}/test', 'App\Controllers\Api\SiteController@test');

$router->get('/api/sync', 'App\Controllers\Api\SyncController@status');
$router->post('/api/sync', 'App\Controllers\Api\SyncController@run');

$router->get('/api/audit', 'App\Controllers\Api\AuditController@list');

$router->get('/api/users', 'App\Controllers\Api\UserController@list');
$router->post('/api/users', 'App\Controllers\Api\UserController@create');
$router->put('/api/users/{id}', 'App\Controllers\Api\UserController@update');
$router->delete('/api/users/{id}', 'App\Controllers\Api\UserController@delete');

$router->get('/api/settings', 'App\Controllers\Api\SettingsController@show');
$router->put('/api/settings', 'App\Controllers\Api\SettingsController@update');

$router->get('/api/system-secrets', 'App\Controllers\Api\SystemSecretController@list');
$router->post('/api/system-secrets', 'App\Controllers\Api\SystemSecretController@create');
$router->put('/api/system-secrets/{id}', 'App\Controllers\Api\SystemSecretController@update');
$router->delete('/api/system-secrets/{id}', 'App\Controllers\Api\SystemSecretController@delete');
$router->post('/api/system-secrets/{id}/reveal', 'App\Controllers\Api\SystemSecretController@reveal');

$router->get('/api/ad/mappings', 'App\Controllers\Api\AdMappingController@list');
$router->post('/api/ad/mappings', 'App\Controllers\Api\AdMappingController@create');
$router->put('/api/ad/mappings/{id}', 'App\Controllers\Api\AdMappingController@update');
$router->delete('/api/ad/mappings/{id}', 'App\Controllers\Api\AdMappingController@delete');
$router->get('/api/ad/groups', 'App\Controllers\Api\AdMappingController@groups');
$router->get('/api/ad/non-compliant', 'App\Controllers\Api\AdMappingController@nonCompliant');
$router->post('/api/ad/sync', 'App\Controllers\Api\AdMappingController@sync');

// -------------------------------------------------------------- Dispatch
try {
    $router->dispatch(new Request());
} catch (Throwable $e) {
    Logger::error('router', $e->getMessage());
    if (str_starts_with((new Request())->path(), '/api/')) {
        Response::error('Unerwarteter Serverfehler', 'SERVER_ERROR', 500);
    }
    http_response_code(500);
    echo '<!doctype html><html lang="de"><body style="font-family:sans-serif;padding:2rem"><h1>Serverfehler</h1><p>Es ist ein unerwarteter Fehler aufgetreten.</p></body></html>';
}
