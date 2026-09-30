<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

/**
 * JSON API for the AD-Gruppe <-> Zutrittsgruppe mappings plus the manual
 * AD sync trigger and the reconciliation (drift) report.
 */
final class AdMappingController extends ApiController
{
    private const MANAGE = [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR];

    public function list(Request $request): never
    {
        $this->authorize($request);
        $connectionId = $request->query('connection_id');
        $this->run(fn () => App::adMappings()->list($connectionId !== null && $connectionId !== '' ? (int) $connectionId : null));
    }

    /** AD groups for the autocomplete dropdown. */
    public function groups(Request $request): never
    {
        $this->authorize($request);
        try {
            $this->success(App::ldap()->getGroups());
        } catch (\Throwable $e) {
            $this->error('AD-Gruppen konnten nicht geladen werden: ' . $e->getMessage(), 'LDAP_ERROR', 502);
        }
    }

    public function create(Request $request): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();

        $this->run(function () use ($data, $userId, $username) {
            [$connectionId, $adGroupDn, $adGroupName, $accessGroupId] = $this->validate($data);
            if (App::adMappings()->exists($connectionId, $adGroupDn)) {
                throw new \InvalidArgumentException('Für diese AD-Gruppe existiert bereits eine Zuordnung.');
            }
            $id = App::adMappings()->create($connectionId, $adGroupDn, $accessGroupId, $adGroupName);
            App::audit()->log('ad_mapping.create', 'ad_mapping', (string) $id, $adGroupDn, connectionId: $connectionId, userId: $userId, username: $username);
            return App::adMappings()->find($id);
        });
    }

    public function update(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $id = (int) $params['id'];

        $this->run(function () use ($request, $id, $userId, $username) {
            $existing = App::adMappings()->find($id);
            if ($existing === null) {
                throw new \InvalidArgumentException('Zuordnung wurde nicht gefunden.');
            }
            $data = array_merge([
                'connection_id' => (int) $existing['connection_id'],
                'ad_group_dn' => (string) $existing['ad_group_dn'],
                'ad_group_name' => $existing['ad_group_name'],
                'access_group_id' => (string) $existing['access_group_id'],
            ], $request->all());
            [$connectionId, $adGroupDn, $adGroupName, $accessGroupId] = $this->validate($data);
            if (App::adMappings()->exists($connectionId, $adGroupDn, $id)) {
                throw new \InvalidArgumentException('Für diese AD-Gruppe existiert bereits eine Zuordnung.');
            }
            App::adMappings()->update($id, ['connection_id' => $connectionId, 'ad_group_dn' => $adGroupDn, 'ad_group_name' => $adGroupName, 'access_group_id' => $accessGroupId]);
            App::audit()->log('ad_mapping.update', 'ad_mapping', (string) $id, $adGroupDn, connectionId: $connectionId, userId: $userId, username: $username);
            return App::adMappings()->find($id);
        });
    }

    public function delete(Request $request, array $params): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $id = (int) $params['id'];

        $this->run(function () use ($id, $userId, $username) {
            $existing = App::adMappings()->find($id);
            if ($existing === null) {
                throw new \InvalidArgumentException('Zuordnung wurde nicht gefunden.');
            }
            App::adMappings()->delete($id);
            App::audit()->log('ad_mapping.delete', 'ad_mapping', (string) $id, (string) $existing['ad_group_dn'], connectionId: (int) $existing['connection_id'], userId: $userId, username: $username);
            return ['deleted' => true];
        });
    }

    /** Run a manual AD sync and return the summary. */
    public function sync(Request $request): never
    {
        $this->authorize($request, ...self::MANAGE);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $connectionId = $request->input('connection_id');

        $this->run(fn () => App::ad()->run(
            $connectionId !== null && $connectionId !== '' ? (int) $connectionId : null,
            $userId,
            $username,
        ));
    }

    /** Reconciliation report: Access-berechtigt, aber nicht in gemappter AD-Gruppe. */
    public function nonCompliant(Request $request): never
    {
        $this->authorize($request);
        $connectionId = $request->query('connection_id');
        $this->run(fn () => App::adMappings()->findNonCompliant($connectionId !== null && $connectionId !== '' ? (int) $connectionId : null));
    }

    /** @return array{0:int,1:string,2:?string,3:string} */
    private function validate(array $data): array
    {
        $connectionId = (int) ($data['connection_id'] ?? 0);
        if ($connectionId <= 0) {
            throw new \InvalidArgumentException('Bitte einen Standort wählen.');
        }
        $adGroupDn = trim((string) ($data['ad_group_dn'] ?? ''));
        if ($adGroupDn === '') {
            throw new \InvalidArgumentException('Bitte eine AD-Gruppe wählen.');
        }
        $accessGroupId = trim((string) ($data['access_group_id'] ?? ''));
        if ($accessGroupId === '') {
            throw new \InvalidArgumentException('Bitte eine Zutrittsgruppe wählen.');
        }
        $adGroupName = isset($data['ad_group_name']) ? trim((string) $data['ad_group_name']) : null;

        return [$connectionId, $adGroupDn, $adGroupName !== '' ? $adGroupName : null, $accessGroupId];
    }
}
