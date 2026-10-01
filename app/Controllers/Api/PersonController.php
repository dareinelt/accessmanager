<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;
use App\Services\PersonService;

final class PersonController extends ApiController
{
    private const MANAGE = [Auth::ROLE_OPERATOR];

    public function list(Request $request): never
    {
        $this->authorize($request);
        $filters = $this->personFilters($request);
        $this->run(fn () => App::persons()->list($filters));
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request);
        $this->run(function () use ($params) {
            $detail = App::persons()->get((int) $params['connection_id'], (string) $params['unifi_id']);
            // SECURITY FIX: no PIN codes / raw payload for read-only users.
            return Auth::hasRole(...self::MANAGE) ? $detail : PersonService::redactForReadonly($detail);
        });
    }

    public function create(Request $request): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        // FIX: a missing connection_id raised an "undefined array key" warning.
        $connectionId = filter_var($data['connection_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($connectionId === false) {
            $this->error('Bitte einen Standort auswählen.', 'VALIDATION', 422);
        }
        $this->run(fn () => App::persons()->create($connectionId, $data, $userId, $username));
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
            App::persons()->setGroups((int) $params['connection_id'], (string) $params['unifi_id'], array_values(array_map('strval', array_filter($policyIds, 'is_scalar'))), $userId, $username);
            return ['updated' => true];
        });
    }
}
