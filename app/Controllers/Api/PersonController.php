<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class PersonController extends ApiController
{
    private const MANAGE = [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR];

    public function list(Request $request): never
    {
        $this->authorize($request);
        $filters = [
            'connection_id' => $request->query('connection_id'),
            'page' => (int) $request->query('page', 1),
            'page_size' => (int) $request->query('page_size', 25),
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'group_id' => $request->query('group_id'),
            'card_filter' => $request->query('card_filter'),
            'sort' => $request->query('sort'),
        ];
        $this->run(fn () => App::persons()->list($filters));
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request);
        $this->run(fn () => App::persons()->get((int) $params['connection_id'], (string) $params['unifi_id']));
    }

    public function create(Request $request): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        $this->run(fn () => App::persons()->create((int) $data['connection_id'], $data, $userId, $username));
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => App::persons()->update((int) $params['connection_id'], (string) $params['unifi_id'], $request->all(), $userId, $username));
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(function () use ($params, $userId, $username) {
            App::persons()->delete((int) $params['connection_id'], (string) $params['unifi_id'], $userId, $username);
            return ['deleted' => true];
        });
    }

    public function assignCard(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $token = (string) $request->input('token', '');
        $this->run(function () use ($params, $token, $userId, $username) {
            App::persons()->assignCard((int) $params['connection_id'], (string) $params['unifi_id'], $token, $userId, $username);
            return ['assigned' => true];
        });
    }

    public function unassignCard(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $token = (string) $request->input('token', '');
        $this->run(function () use ($params, $token, $userId, $username) {
            App::persons()->unassignCard((int) $params['connection_id'], (string) $params['unifi_id'], $token, $userId, $username);
            return ['unassigned' => true];
        });
    }

    public function setGroups(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $policyIds = (array) $request->input('access_policy_ids', []);
        $this->run(function () use ($params, $policyIds, $userId, $username) {
            App::persons()->setGroups((int) $params['connection_id'], (string) $params['unifi_id'], array_values(array_map('strval', $policyIds)), $userId, $username);
            return ['updated' => true];
        });
    }
}
