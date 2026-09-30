<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Repositories\AdGroupMappingRepository;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Services\Ldap\LdapClientInterface;

/**
 * Active Directory -> UniFi Access synchronisation.
 *
 * For every connection and every AD user this service:
 *   1. matches (or creates) the corresponding UniFi person,
 *   2. assigns the RFID card read from the configured AD attribute,
 *   3. enforces the AD-group <-> access-group mappings (grant/revoke),
 *   4. persists the AD identity + memberships for later reconciliation.
 *
 * All mutations go through PersonService (audited + cache-refreshing).
 */
final class AdSyncService
{
    private const PAGE_SIZE = 100000;

    public function __construct(
        private readonly LdapClientInterface $ldap,
        private readonly AdGroupMappingRepository $mappings,
        private readonly CatalogRepository $catalog,
        private readonly CacheRepository $cache,
        private readonly ConnectionRepository $connections,
        private readonly PersonService $persons,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $connectionId = null, ?int $userId = null, ?string $username = null): array
    {
        $summary = $this->emptySummary($this->ldap->label());

        try {
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

        $summary['users_fetched'] = count($adUsers);
        $summary['groups_fetched'] = count($adGroups);

        $adByIdentifier = [];
        $adByEmail = [];
        foreach ($adUsers as $u) {
            if (($u['identifier'] ?? '') !== '') {
                $adByIdentifier[(string) $u['identifier']] = $u;
            }
            if (!empty($u['email'])) {
                $adByEmail[mb_strtolower((string) $u['email'])] = $u;
            }
        }

        $connections = $this->connections->all();
        if ($connectionId !== null) {
            $connections = array_values(array_filter(
                $connections,
                static fn (array $c): bool => (int) $c['id'] === $connectionId,
            ));
        }

        foreach ($connections as $connection) {
            $cid = (int) $connection['id'];
            try {
                $this->syncConnection($cid, (string) $connection['name'], $adUsers, $adByIdentifier, $adByEmail, $summary, $userId, $username);
            } catch (\Throwable $e) {
                Logger::error('ad_sync', "AD-Sync fehlgeschlagen für {$connection['name']}: {$e->getMessage()}");
                $summary['errors'][] = ['connection' => (string) $connection['name'], 'error' => $e->getMessage()];
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
                'errors' => count($summary['errors']),
            ],
            userId: $userId,
            username: $username,
            result: $summary['errors'] === [] ? 'success' : 'failed',
        );

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $adUsers
     * @param array<string,array<string,mixed>> $adByIdentifier
     * @param array<string,array<string,mixed>> $adByEmail
     * @param array<string,mixed> $summary
     */
    private function syncConnection(
        int $connectionId,
        string $connectionName,
        array $adUsers,
        array $adByIdentifier,
        array $adByEmail,
        array &$summary,
        ?int $userId,
        ?string $username,
    ): void {
        $mapping = $this->mappings->byConnection($connectionId);
        $managed = $this->mappings->mappedAccessGroupIds($connectionId);

        $persons = $this->catalog->listPersons(['connection_id' => $connectionId, 'page' => 1, 'page_size' => self::PAGE_SIZE])['items'];
        $credentials = $this->catalog->listCredentials(['connection_id' => $connectionId, 'page' => 1, 'page_size' => self::PAGE_SIZE])['items'];

        $personsByAdId = [];
        $personsByEmail = [];
        $personsByEmployee = [];
        foreach ($persons as $p) {
            if (!empty($p['ad_identifier'])) {
                $personsByAdId[(string) $p['ad_identifier']] = $p;
            }
            if (!empty($p['email'])) {
                $personsByEmail[mb_strtolower((string) $p['email'])] = $p;
            }
            if (!empty($p['employee_number'])) {
                $personsByEmployee[(string) $p['employee_number']] = $p;
            }
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

        foreach ($adUsers as $ad) {
            $identifier = (string) ($ad['identifier'] ?? '');
            $email = $ad['email'] ?? null;
            $employee = $ad['employee_number'] ?? null;
            $cardNumber = $ad['card_number'] ?? null;
            $memberOf = array_values(array_map('strval', $ad['member_of'] ?? []));

            // 1. Match existing person.
            $person = null;
            if ($identifier !== '' && isset($personsByAdId[$identifier])) {
                $person = $personsByAdId[$identifier];
            } elseif ($email !== null && isset($personsByEmail[mb_strtolower($email)])) {
                $person = $personsByEmail[mb_strtolower($email)];
            } elseif ($employee !== null && isset($personsByEmployee[$employee])) {
                $person = $personsByEmployee[$employee];
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
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Person anlegen: ' . $e->getMessage()];
                    continue;
                }
            } else {
                $unifiId = (string) $person['unifi_id'];
                $currentPolicies = json_decode((string) ($person['access_policy_ids_json'] ?? '[]'), true) ?: [];
                $summary['matched']++;
            }

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
            $newPolicies = array_values(array_filter(
                $currentPolicies,
                static fn ($id): bool => !in_array((string) $id, $managed, true),
            ));
            $newPolicies = array_values(array_unique(array_merge($newPolicies, $desired)));

            $changed = count(array_diff($newPolicies, $currentPolicies)) > 0
                || count(array_diff($currentPolicies, $newPolicies)) > 0;

            if ($changed) {
                try {
                    $this->persons->setGroups($connectionId, $unifiId, $newPolicies, $userId, $username);
                    $summary['groups_changed']++;
                    $currentPolicies = $newPolicies;
                } catch (\Throwable $e) {
                    $summary['errors'][] = ['connection' => $connectionName, 'identifier' => $identifier, 'error' => 'Zutrittsgruppen: ' . $e->getMessage()];
                }
            }

            // 4. Persist AD identity + memberships.
            $this->cache->setUserAdMetadata($connectionId, $unifiId, $identifier, $memberOf);
        }
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
            'errors' => [],
        ];
    }
}
