<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class SiteController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request);
        $this->run(fn () => App::sites()->list());
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->run(function () use ($params) {
            $site = App::sites()->get((int) $params['id']);
            $site['connection']['has_token'] = !empty($site['connection']['api_token_enc']);
            unset($site['connection']['api_token_enc']);
            return $site;
        });
    }

    public function create(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        $this->run(function () use ($data, $userId, $username) {
            $site = App::sites()->create($data, $userId, $username);
            $site['connection']['has_token'] = !empty($site['connection']['api_token_enc']);
            unset($site['connection']['api_token_enc']);
            return $site;
        });
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $request, $userId, $username) {
            $site = App::sites()->update((int) $params['id'], $request->all(), $userId, $username);
            $site['connection']['has_token'] = !empty($site['connection']['api_token_enc']);
            unset($site['connection']['api_token_enc']);
            return $site;
        });
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::sites()->delete((int) $params['id'], $userId, $username);
            return ['deleted' => true];
        });
    }

    public function test(Request $request, array $params): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        $this->run(fn () => App::sites()->test((int) $params['id']));
    }
}
