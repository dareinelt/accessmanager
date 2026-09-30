<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class UserController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->run(fn () => App::appUsers()->list());
    }

    public function create(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        $this->run(fn () => App::appUsers()->create($data, $userId, $username));
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $request, $userId, $username) {
            App::appUsers()->update((int) $params['id'], $request->all(), $userId, $username);
            return ['updated' => true];
        });
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::appUsers()->delete((int) $params['id'], $userId, $username);
            return ['deleted' => true];
        });
    }
}
