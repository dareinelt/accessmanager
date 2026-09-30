<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

/**
 * Systemgeheimnisse (AD-Daten, DNs, API-Endpunkte). Zugriff ausschließlich
 * für die Rolle "sysadmin".
 */
final class SystemSecretController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_SYSADMIN);
        $this->run(fn () => App::systemSecrets()->list());
    }

    public function reveal(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_SYSADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => ['value' => App::systemSecrets()->reveal((int) $params['id'], $userId, $username)]);
    }

    public function create(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_SYSADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => App::systemSecrets()->create($request->all(), $userId, $username));
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_SYSADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => App::systemSecrets()->update((int) $params['id'], $request->all(), $userId, $username));
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_SYSADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::systemSecrets()->delete((int) $params['id'], $userId, $username);
            return ['deleted' => true];
        });
    }
}
