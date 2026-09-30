<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

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
        $connectionId = $request->query('connection_id');
        $filters = [
            'connection_id' => $connectionId,
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => min(100, max(1, (int) $request->query('page_size', 25))),
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'group_id' => $request->query('group_id'),
            'card_filter' => $request->query('card_filter'),
            'sort' => $request->query('sort'),
        ];
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
        $connectionId = (int) $params['connection_id'];
        $unifiId = (string) $params['unifi_id'];
        $this->render('persons/detail', 'persons', [
            'detail' => App::persons()->get($connectionId, $unifiId),
            'groups' => App::groups()->list($connectionId),
            'freeCards' => App::credentials()->list(['connection_id' => $connectionId, 'card_filter' => 'free', 'page_size' => 1000])['items'],
        ]);
    }

    public function credentials(Request $request): void
    {
        Auth::requireLogin();
        $filters = [
            'connection_id' => $request->query('connection_id'),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => min(100, max(1, (int) $request->query('page_size', 25))),
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'card_filter' => $request->query('card_filter'),
        ];
        $this->render('cards/index', 'cards', [
            'result' => App::credentials()->list($filters),
            'filters' => $filters,
            'connections' => App::sites()->list(),
        ]);
    }

    public function groups(Request $request): void
    {
        Auth::requireLogin();
        $connectionId = $request->query('connection_id');
        $this->render('groups/index', 'groups', [
            'groups' => App::groups()->list($connectionId !== null ? (int) $connectionId : null),
            'connections' => App::sites()->list(),
            'doors' => App::doors()->list($connectionId !== null ? (int) $connectionId : null),
            'selectedConnection' => $connectionId !== null ? (int) $connectionId : null,
        ]);
    }

    public function doors(Request $request): void
    {
        Auth::requireLogin();
        $connectionId = $request->query('connection_id');
        $this->render('doors/index', 'doors', [
            'doors' => App::doors()->list($connectionId !== null ? (int) $connectionId : null),
            'connections' => App::sites()->list(),
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
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR);
        $this->render('sync/index', 'sync', [
            'recent' => (new \App\Repositories\SyncLogRepository())->recent(25),
            'lastSuccess' => (new \App\Repositories\SyncLogRepository())->lastSuccessful(),
            'lastFailed' => (new \App\Repositories\SyncLogRepository())->lastFailed(),
        ]);
    }

    public function audit(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $page = max(1, (int) $request->query('page', 1));
        $search = (string) $request->query('search', '');
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

    public function adMappings(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR);
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
