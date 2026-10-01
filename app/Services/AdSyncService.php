<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiException;
use App\Config\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\AdGroupMappingRepository;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Services\Ldap\LdapClientInterface;
use App\Services\Ldap\MockLdapClient;

/**
 * Active Directory -> UniFi Access synchronisation.
 *
 * For every connection and every enabled AD user this service:
 *   1. matches (or creates) the corresponding UniFi person,
 *   2. assigns the RFID card read from the configured AD attribute,
 *   3. enforces the AD-group <-> access-group mappings (grant/revoke),
 *   4. persists the AD identity + memberships for later reconciliation.
 *
 * Persons that were linked to an AD account before (ad_identifier set) but
 * whose account is now disabled or deleted lose their mapped access groups
 * and are deactivated in UniFi. They are re-activated automatically when the
 * AD account returns – but only if the AD sync deactivated them.
 *
 * All mutations go through PersonService (audited + cache-refreshing).
 */
final class AdSyncService
{
    private const PAGE_SIZE = 100000;
    private const UNIFI_PAGE_SIZE = 100;
    private const DEFAULT_REVOKE_LIMIT = 10;

    private readonly int $revokeLimit;

    public function __construct(
        private readonly LdapClientInterface $ldap,
        private readonly AdGroupMappingRepository $mappings,
        private readonly CatalogRepository $catalog,
        private readonly CacheRepository $cache,
        private readonly ConnectionRepository $connections,
        private readonly PersonService $persons,
        private readonly AuditService $audit,
        ?int $revokeLimit = null,
    ) {
        $this->revokeLimit = max(0, $revokeLimit ?? Config::int('AD_SYNC_REVOKE_LIMIT', self::DEFAULT_REVOKE_LIMIT));
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $connectionId = null, ?int $userId = null, ?string $username = null): array
    {
        $summary = $this->emptySummary($this->ldap->label());

        try {
            if ($this->ldap instanceof MockLdapClient && !Config::isMock()) {
                throw new \RuntimeException('Mock-AD-Daten dürfen nicht gegen echte UniFi-Controller synchronisiert werden (UNIFI_API_MOCK=false).');
            }
            $adUsers = $this->ldap->getUsers();
            $adGroups = $this->ldap->getGroups();
        } catch (\Throwable $e) {
            Logger::error('ad_sync', 'LDAP-Abfrage fehlgeschlagen: ' . $e->getMessage());
            $summary['status'] = 'error';
            $summary['errors'][] = ['error' => $e->getMessage()];
            $this->audit->log(
                action: 'ad.sync.run',
                entityType: 'ad_sync',
                details: ['status' => 'error', 'error' => $e->getMessage()],
                userId: $userId,
                username: $username,
                result: 'failed',
            );
            return $summary;
        }

        // Disabled accounts are treated exactly like deleted ones.
        $adUsers = array_values(array_filter(
            $adUsers,
            static fn (array $u): bool => ($u['enabled'] ?? true) !== false,
        ));

        $summary['users_fetched'] = count($adUsers);
        $summary['groups_fetched'] = count($adGroups);

        $connections = $this->connections->all();
        if ($connectionId !== null) {
            $connections = array_values(array_filter(
                $connections,
                static fn (array $c): bool => (int) $c['id'] === $connectionId,
            ));
        }

        foreach ($connections as $connection) {
            $cid = (int) $connection['id'];
            $lock = SyncService::lockName($cid);
            if (!Database::acquireLock($lock, SyncService::LOCK_TIMEOUT)) {
                $summary['errors'][] = ['connection' => (string) $connection['name'], 'error' => 'Für diesen Standort läuft bereits eine Synchronisation.'];
                continue;
            }
            try {
                $this->syncConnection($cid, (string) $connection['name'], $adUsers, $summary, $userId, $username);
            } catch (\Throwable $e) {
                Logger::error('ad_sync', "AD-Sync fehlgeschlagen für {$connection['name']}: {$e->getMessage()}");
                $summary['errors'][] = ['connection' => (string) $connection['name'], 'error' => $e->getMessage()];
            } finally {
                Database::releaseLock($lock);
            }
        }

        $summary['status'] = $summary['errors'] === [] ? 'success' : 'partial';

        $this->audit->log(
            action: 'ad.sync.run',
            entityType: 'ad_sync',
            details: [
                'status' => $summary['status'],
                'users_fetched' => $summary['users_fetched'],
                'matched' => $summary['matched'],
                'created' => $summary['created'],
                'cards_assigned' => $summary['cards_assigned'],
                'groups_changed' => $summary['groups_changed'],
                'revoked' => $summary['revoked'],
                'reactivated' => $summary['reactivated'],
                'errors' => count($summary['errors']),
            ],
            userId: $userId,
            username: $username,
            result: $summary['errors'] === [] ? 'success' : 'failed',
        );

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $adUsers enabled AD users only
     * @param array<string,mixed> $summary
     */
    private function syncConnection(
        int $connectionId,
        string $connectionName,
        array $adUsers,
        array &$summary,
        ?int $userId,
        ?string $username,
    ): void {
        $mapping = $this->mappings->byConnection($connectionId);
        $managed = $this->mappings->mappedAccessGroupIds($connectionId);

        $persons = $this->cache->personsForAdSync($connectionId);
        $credentials = $this->catalog->listCredentials(['connection_id' => $connectionId, 'page' => 1, 'page_size' => self::PAGE_SIZE])['items'];

        $personsByAdId = [];
        $personsByEmail = [];
        $personsByEmployee = [];
        $index = static function (array $p) use (&$personsByAdId, &$personsByEmail, &$personsByEmployee): void {
            if (!empty($p['ad_identifier'])) {
                $personsByAdId[(string) $p['ad_identifier']] = $p;
            }
            if (!empty($p['email'])) {
                $personsByEmail[mb_strtolower((string) $p['email'])] = $p;
            }
            if (!empty($p['employee_number'])) {
                $personsByEmployee[(string) $p['employee_number']] = $p;
            }
        };
        foreach ($persons as $p) {
            $index($p);
        }

        // Free cards indexed by token and by display id for card assignment.
        $freeByToken = [];
        $freeByDisplay = [];
        // Cards currently held per person.
        $personCards = [];
        foreach ($credentials as $cr) {
            if (empty($cr['user_unifi_id'])) {
                $freeByToken[(string) $cr['unifi_token']] = $cr;
                if (!empty($cr['display_id'])) {
                    $freeByDisplay[(string) $cr['display_id']] = $cr;
                }
            } else {
                $personCards[(string) $cr['user_unifi_id']][] = $cr;
            }
        }

        /** @var array<string,true> $seen UniFi ids handled for an enabled AD account */
        $seen = [];
        $activeIdentifiers = [];
        /** @var array{email:array<string,array<string,mixed>>,employee:array<string,array<string,mixed>>}|null $live */
        $live = null;

        foreach ($adUsers as $ad) {
            $identifier = (string) ($ad['identifier'] ?? '');
            if ($identifier !== '') {
                $activeIdentifiers[$identifier] = true;
            }
            $email = isset($ad['email']) && $ad['email'] !== '' ? (string) $ad['email'] : null;
            $employee = isset($ad['employee_number']) && $ad['employee_number'] !== '' ? (string) $ad['employee_number'] : null;
            $cardNumber = $ad['card_number'] ?? null;
            $memberOf = array_values(array_map('strval', $ad['member_of'] ?? []));

            // 1. Match existing person (cache first, then live UniFi data).
            $person = null;
            if ($identifier !== '' && isset($personsByAdId[$identifier])) {
                $person = $personsByAdId[$identifier];
            } elseif ($email !== null && isset($personsByEmail[mb_strtolower($email)])) {
                $person = $personsByEmail[mb_strtolower($email)];
            } elseif ($employee !== null && isset($personsByEmployee[$employee])) {
                $person = $personsByEmployee[$employee];
            }

            if ($person === null) {
                // The cache may lag behind UniFi (e.g. a person created by an
                // earlier run whose response got lost). Checking the live
                // controller before POSTing prevents duplicate persons.
                try {
                    $person = $this->findLive($connectionId, $email, $employee, $live);
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Abgleich mit UniFi: ' . $e->getMessage()];
                    continue;
                }
                if ($person !== null) {
                    $index($person);
                }
            }

            if ($person === null) {
                try {
                    $created = $this->persons->create($connectionId, [
                        'first_name' => $ad['first_name'] ?? null,
                        'last_name' => $ad['last_name'] ?? null,
                        'full_name' => $this->fullName($ad),
                        'user_email' => $email,
                        'employee_number' => $employee,
                        'status' => 'ACTIVE',
                    ], $userId, $username);
                    $unifiId = (string) $created['id'];
                    $currentPolicies = [];
                    $personCards[$unifiId] = [];
                    $summary['created']++;
                    $index([
                        'unifi_id' => $unifiId,
                        'ad_identifier' => $identifier,
                        'email' => $email,
                        'employee_number' => $employee,
                        'status' => 'ACTIVE',
                        'access_policy_ids_json' => '[]',
                    ]);
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Person anlegen: ' . $e->getMessage()];
                    continue;
                }
            } else {
                $unifiId = (string) $person['unifi_id'];
                $currentPolicies = json_decode((string) ($person['access_policy_ids_json'] ?? '[]'), true) ?: [];
                $summary['matched']++;

                // Re-activate persons that the AD sync itself deactivated.
                if (!empty($person['ad_deactivated_at'])) {
                    try {
                        if (strtoupper((string) ($person['status'] ?? '')) === 'DEACTIVATED') {
                            $this->persons->update($connectionId, $unifiId, ['status' => 'ACTIVE'], $userId, $username);
                            $summary['reactivated']++;
                        }
                        $this->cache->setAdDeactivated($connectionId, $unifiId, false);
                    } catch (\Throwable $e) {
                        $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Reaktivieren: ' . $e->getMessage()];
                    }
                }
            }
            $seen[$unifiId] = true;

            // 2. Assign the RFID card from AD, if any and not yet assigned.
            if ($cardNumber !== null && $cardNumber !== '') {
                $hasCard = false;
                foreach ($personCards[$unifiId] ?? [] as $cr) {
                    if ((string) ($cr['unifi_token'] ?? '') === $cardNumber || (string) ($cr['display_id'] ?? '') === $cardNumber) {
                        $hasCard = true;
                        break;
                    }
                }
                if (!$hasCard) {
                    $free = $freeByToken[$cardNumber] ?? $freeByDisplay[$cardNumber] ?? null;
                    if ($free !== null) {
                        try {
                            $this->persons->assignCard($connectionId, $unifiId, (string) $free['unifi_token'], $userId, $username);
                            $summary['cards_assigned']++;
                            unset($freeByToken[(string) $free['unifi_token']]);
                            if (!empty($free['display_id'])) {
                                unset($freeByDisplay[(string) $free['display_id']]);
                            }
                            $personCards[$unifiId][] = $free;
                        } catch (\Throwable $e) {
                            $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Karte ' . $cardNumber . ': ' . $e->getMessage()];
                        }
                    } else {
                        $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Keine freie Karte „' . $cardNumber . '“ gefunden.'];
                    }
                }
            }

            // 3. Enforce AD-group -> access-group mappings.
            $desired = [];
            foreach ($memberOf as $dn) {
                if (isset($mapping[$dn])) {
                    $desired[] = $mapping[$dn];
                }
            }
            $desired = array_values(array_unique($desired));
            $newPolicies = $this->withoutManaged($currentPolicies, $managed);
            $newPolicies = array_values(array_unique(array_merge($newPolicies, $desired)));

            if ($this->policiesDiffer($currentPolicies, $newPolicies)) {
                try {
                    $this->persons->setGroups($connectionId, $unifiId, $newPolicies, $userId, $username);
                    $summary['groups_changed']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Zutrittsgruppen: ' . $e->getMessage()];
                }
            }

            // 4. Persist AD identity + memberships.
            $this->cache->setUserAdMetadata($connectionId, $unifiId, $identifier, $memberOf);
        }

        $this->revokeMissing($connectionId, $connectionName, $persons, $seen, $activeIdentifiers, $managed, count($adUsers), $summary, $userId, $username);
    }

    /**
     * Offboarding: persons linked to an AD account that is no longer present
     * (deleted, disabled or outside the filter) lose their mapped access
     * groups and are deactivated in UniFi.
     *
     * @param array<int,array<string,mixed>> $persons
     * @param array<string,true> $seen
     * @param array<string,true> $activeIdentifiers
     * @param array<int,string> $managed
     * @param array<string,mixed> $summary
     */
    private function revokeMissing(
        int $connectionId,
        string $connectionName,
        array $persons,
        array $seen,
        array $activeIdentifiers,
        array $managed,
        int $adUserCount,
        array &$summary,
        ?int $userId,
        ?string $username,
    ): void {
        $candidates = [];
        foreach ($persons as $p) {
            $unifiId = (string) $p['unifi_id'];
            $adId = (string) ($p['ad_identifier'] ?? '');
            if ($adId === '' || isset($seen[$unifiId]) || isset($activeIdentifiers[$adId])) {
                continue;
            }
            $policies = json_decode((string) ($p['access_policy_ids_json'] ?? '[]'), true) ?: [];
            $remaining = $this->withoutManaged($policies, $managed);
            $hasManaged = $this->policiesDiffer($policies, $remaining);
            $isActive = strtoupper((string) ($p['status'] ?? '')) !== 'DEACTIVATED';
            if (!$hasManaged && !$isActive) {
                continue; // already revoked
            }
            $candidates[] = ['person' => $p, 'remaining' => $remaining, 'has_managed' => $hasManaged, 'active' => $isActive];
        }

        if ($candidates === []) {
            return;
        }

        // Safety nets against mass revocation caused by a broken LDAP query.
        if ($adUserCount === 0) {
            $summary['errors'][] = ['connection' => $connectionName, 'error' => 'LDAP lieferte keine aktiven Benutzer – Entzug von ' . count($candidates) . ' Zugängen übersprungen.'];
            return;
        }
        if ($this->revokeLimit > 0 && count($candidates) > $this->revokeLimit) {
            $summary['errors'][] = ['connection' => $connectionName, 'error' => 'Entzug von ' . count($candidates) . ' Zugängen übersprungen: mehr als AD_SYNC_REVOKE_LIMIT=' . $this->revokeLimit . '. Bitte LDAP-Konfiguration prüfen oder das Limit erhöhen.'];
            Logger::warning('ad_sync', "Revocation for {$connectionName} skipped: " . count($candidates) . " candidates > limit {$this->revokeLimit}");
            return;
        }

        foreach ($candidates as $c) {
            $p = $c['person'];
            $unifiId = (string) $p['unifi_id'];
            $adId = (string) $p['ad_identifier'];
            try {
                if ($c['has_managed']) {
                    $this->persons->setGroups($connectionId, $unifiId, $c['remaining'], $userId, $username);
                }
                if ($c['active']) {
                    $this->persons->update($connectionId, $unifiId, ['status' => 'DEACTIVATED'], $userId, $username);
                    $this->cache->setAdDeactivated($connectionId, $unifiId, true);
                }
                $this->cache->setUserAdMetadata($connectionId, $unifiId, $adId, []);
                $summary['revoked']++;
                $this->audit->log(
                    'ad.sync.revoke',
                    'person',
                    $unifiId,
                    (string) ($p['full_name'] ?? $unifiId),
                    ['ad_identifier' => $adId, 'deactivated' => $c['active'], 'removed_groups' => $c['has_managed']],
                    $connectionId,
                    $userId,
                    $username,
                );
            } catch (\Throwable $e) {
                $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $adId, 'error' => 'Zugang entziehen: ' . $e->getMessage()];
            }
        }
    }

    /**
     * Look up a person directly on the controller by e-mail / employee number.
     * The user list is fetched at most once per connection and run.
     *
     * @param array{email:array<string,array<string,mixed>>,employee:array<string,array<string,mixed>>}|null $live
     * @return array<string,mixed>|null cache row (see CacheRepository::personsForAdSync)
     */
    private function findLive(int $connectionId, ?string $email, ?string $employee, ?array &$live): ?array
    {
        if ($email === null && $employee === null) {
            return null;
        }

        if ($live === null) {
            $connection = $this->connections->find($connectionId);
            if ($connection === null) {
                throw new UniFiApiException('Standort wurde nicht gefunden.');
            }
            $client = ApiClientFactory::forConnection($connection);
            $users = SyncService::fetchAllPages(
                fn (int $page) => $client->getUsers(['page_num' => $page, 'page_size' => self::UNIFI_PAGE_SIZE]),
            );
            $live = ['email' => [], 'employee' => []];
            foreach ($users as $u) {
                $mail = (string) ($u['user_email'] ?? $u['email'] ?? '');
                if ($mail !== '') {
                    $live['email'][mb_strtolower($mail)] ??= $u;
                }
                $emp = (string) ($u['employee_number'] ?? '');
                if ($emp !== '') {
                    $live['employee'][$emp] ??= $u;
                }
            }
        }

        $found = null;
        if ($email !== null) {
            $found = $live['email'][mb_strtolower($email)] ?? null;
        }
        if ($found === null && $employee !== null) {
            $found = $live['employee'][$employee] ?? null;
        }
        if ($found === null || (string) ($found['id'] ?? '') === '') {
            return null;
        }

        $this->cache->upsertUser($connectionId, $found);
        return $this->cache->personForAdSync($connectionId, (string) $found['id']);
    }

    /**
     * @param array<int,mixed> $policies
     * @param array<int,string> $managed
     * @return array<int,string>
     */
    private function withoutManaged(array $policies, array $managed): array
    {
        return array_values(array_map('strval', array_filter(
            $policies,
            static fn ($id): bool => !in_array((string) $id, $managed, true),
        )));
    }

    /** @param array<int,mixed> $a @param array<int,mixed> $b */
    private function policiesDiffer(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        return count(array_diff($a, $b)) > 0 || count(array_diff($b, $a)) > 0;
    }

    /** @param array<string,mixed> $ad */
    private function fullName(array $ad): ?string
    {
        $display = $ad['display_name'] ?? null;
        if ($display !== null && $display !== '') {
            return (string) $display;
        }
        $first = $ad['first_name'] ?? null;
        $last = $ad['last_name'] ?? null;
        if ($first !== null || $last !== null) {
            return trim((string) $first . ' ' . (string) $last);
        }
        if (!empty($ad['samaccount_name'])) {
            return (string) $ad['samaccount_name'];
        }
        return !empty($ad['email']) ? (string) $ad['email'] : null;
    }

    /** @return array<string,mixed> */
    private function emptySummary(string $source): array
    {
        return [
            'status' => 'success',
            'source' => $source,
            'users_fetched' => 0,
            'groups_fetched' => 0,
            'matched' => 0,
            'created' => 0,
            'cards_assigned' => 0,
            'groups_changed' => 0,
            'revoked' => 0,
            'reactivated' => 0,
            'errors' => [],
        ];
    }
}
