<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SyncLogRepository;
use App\Security\Auth;
use App\Security\Role;
use App\Services\AppUserService;
use App\Services\PersonService;
use App\Services\SystemSecretService;

/**
 * Server-rendered page controllers. Each section page is rendered here with
 * the data it needs; interactive behaviour is layered on top with Vanilla JS.
 */
final class PageController extends BaseController
{
    public function dashboard(Request $request): void
    {
        Auth::requireLogin();
        $this->render('dashboard/index', 'dashboard', [
            'stats' => App::dashboard()->stats(),
        ]);
    }

    public function persons(Request $request): void
    {
        Auth::requireLogin();
        $filters = $this->personFilters($request);
        $this->render('persons/index', 'persons', [
            'result' => App::persons()->list($filters),
            'filters' => $filters,
            'connections' => App::sites()->list(),
            'groups' => App::groups()->list(),
        ]);
    }

    public function personDetail(Request $request, array $params): void
    {
        Auth::requireLogin();
        $connectionId = (int) ($params['connection_id'] ?? 0);
        $unifiId = (string) ($params['unifi_id'] ?? '');
        try {
            $detail = App::persons()->get($connectionId, $unifiId);
        } catch (\RuntimeException $exception) {
            // FIX: an unknown person ID used to end in an uncaught exception (HTTP 500).
            $this->setFlash('error', 'Die Person wurde nicht gefunden. Möglicherweise wurde sie inzwischen gelöscht.');
            Response::redirect('/persons');
        }
        // SECURITY FIX: PIN codes and raw controller payloads are only shown
        // to roles that are allowed to manage persons.
        if (!Auth::hasRole(Auth::ROLE_OPERATOR)) {
            $detail = PersonService::redactForReadonly($detail);
        }
        $this->render('persons/detail', 'persons', [
            'detail' => $detail,
            'groups' => App::groups()->list($connectionId),
            'freeCards' => App::credentials()->list(['connection_id' => $connectionId, 'card_filter' => 'free', 'page_size' => 1000])['items'],
        ]);
    }

    public function credentials(Request $request): void
    {
        Auth::requireLogin();
        $filters = $this->credentialFilters($request);
        $this->render('cards/index', 'cards', [
            'result' => App::credentials()->list($filters),
            'filters' => $filters,
            'connections' => App::sites()->list(),
        ]);
    }

    public function groups(Request $request): void
    {
        Auth::requireLogin();
        // FIX: "Alle Standorte" (connection_id='') was cast to 0 and showed an empty list.
        $connectionId = $request->queryInt('connection_id');
        $this->render('groups/index', 'groups', [
            'groups' => App::groups()->list($connectionId),
            'connections' => App::sites()->list(),
            'doors' => App::doors()->list($connectionId),
            'selectedConnection' => $connectionId,
        ]);
    }

    public function doors(Request $request): void
    {
        Auth::requireLogin();
        $connectionId = $request->queryInt('connection_id');
        $this->render('doors/index', 'doors', [
            'doors' => App::doors()->list($connectionId),
            'connections' => App::sites()->list(),
            'selectedConnection' => $connectionId,
        ]);
    }

    public function sites(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->render('sites/index', 'sites', [
            'sites' => App::sites()->list(),
        ]);
    }

    public function sync(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_OPERATOR);
        $log = new SyncLogRepository();
        $this->render('sync/index', 'sync', [
            'recent' => $log->recent(25),
            'lastSuccess' => $log->lastSuccessful(),
            'lastFailed' => $log->lastFailed(),
        ]);
    }

    public function audit(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $page = $request->queryInt('page') ?? 1;
        $search = (string) $request->queryString('search');
        $this->render('audit/index', 'audit', [
            'result' => App::auditRepository()->list($page, 50, $search !== '' ? $search : null),
            'filters' => ['page' => $page, 'search' => $search],
        ]);
    }

    public function users(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->render('settings/users', 'settings', [
            'users' => App::appUsers()->list(),
            'assignableRoles' => AppUserService::assignableRoles(Auth::roleEnum()),
            'isSysadmin' => Auth::roleEnum() === Role::Sysadmin,
            'currentUserId' => Auth::id(),
        ]);
    }

    public function settings(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->render('settings/index', 'settings', [
            'info' => [
                'app_name' => Config::appName(),
                'debug' => Config::isDebug(),
                'mock' => Config::isMock(),
                'timezone' => date_default_timezone_get(),
                'php_version' => PHP_VERSION,
            ],
            'settings' => App::settings()->all(),
        ]);
    }

    public function systemSecrets(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_SYSADMIN);
        $this->render('system-secrets/index', 'system-secrets', [
            'secrets' => App::systemSecrets()->list(),
            'categories' => SystemSecretService::CATEGORIES,
        ]);
    }

    public function adMappings(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_OPERATOR);
        $this->render('ad_mappings/index', 'ad-mappings', [
            'mappings' => App::adMappings()->list(),
            'connections' => App::sites()->list(),
            'accessGroups' => App::groups()->list(),
            'adSource' => App::ldap()->label(),
            'nonCompliant' => App::adMappings()->findNonCompliant(),
        ]);
    }

    private function render(string $template, string $section, array $data = []): void
    {
        $this->view('pages/' . $template, array_merge($data, [
            'appName' => Config::appName(),
            'currentUser' => $this->currentUser(),
            'section' => $section,
            'flash' => $this->flash(),
        ]));
    }
}
