<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class DoorController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request);
        $connectionId = $request->queryInt('connection_id');
        $this->run(fn () => App::doors()->list($connectionId));
    }

    public function unlock(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_OPERATOR);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::doors()->unlock((int) $params['connection_id'], (string) $params['unifi_id'], $userId, $username);
            return ['unlocked' => true];
        });
    }
}
