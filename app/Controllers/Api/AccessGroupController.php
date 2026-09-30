<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class AccessGroupController extends ApiController
{
    private const MANAGE = [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR];

    public function list(Request $request): never
    {
        $this->authorize($request);
        $connectionId = $request->query('connection_id');
        $this->run(fn () => App::groups()->list($connectionId !== null ? (int) $connectionId : null));
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request);
        $this->run(fn () => App::groups()->get((int) $params['connection_id'], (string) $params['unifi_id']));
    }

    public function create(Request $request): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        $this->run(fn () => App::groups()->create((int) $data['connection_id'], $data, $userId, $username));
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => App::groups()->update((int) $params['connection_id'], (string) $params['unifi_id'], $request->all(), $userId, $username));
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::groups()->delete((int) $params['connection_id'], (string) $params['unifi_id'], $userId, $username);
            return ['deleted' => true];
        });
    }
}
