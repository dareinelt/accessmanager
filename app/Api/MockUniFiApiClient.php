<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Development / demo API client. Simulates the official UniFi Access API
 * against an in-memory dataset that is persisted to a JSON file in storage,
 * so changes survive across requests. Activated via UNIFI_API_MOCK=true.
 */
final class MockUniFiApiClient implements UniFiApiClientInterface
{
    private const FIRST_NAMES = [
        'Max', 'Erika', 'Lena', 'Jonas', 'Sophie', 'Lukas', 'Marie', 'Paul',
        'Anna', 'David', 'Laura', 'Felix', 'Julia', 'Tim', 'Nina', 'Moritz',
        'Clara', 'Jan', 'Emma', 'Tom', 'Lea', 'Noah', 'Mia', 'Ben', 'Sarah',
        'Leon', 'Hannah', 'Finn', 'Lisa', 'Niklas', 'Mara', 'Elias', 'Ida',
        'Tobias', 'Nora', 'Simon', 'Alina', 'Florian', 'Katrin', 'Robert',
        'Sandra', 'Daniel', 'Petra', 'Stefan', 'Melanie', 'Andreas', 'Sabine',
        'Markus',
    ];

    private const LAST_NAMES = [
        'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner',
        'Becker', 'Hoffmann', 'Schulz', 'Koch', 'Richter', 'Klein', 'Wolf',
        'Schröder', 'Neumann', 'Braun', 'Zimmermann', 'Krüger', 'Hofmann',
        'Hartmann', 'Lange', 'Schmitt', 'Werner', 'Krause', 'Maier', 'Lehmann',
        'Köhler', 'Hermann', 'König', 'Walter', 'Fuchs', 'Peters', 'Lang',
        'Scholz', 'Möller', 'Weiß', 'Jung', 'Hahn', 'Schubert',
    ];

    private const GROUPS = [
        'Mitarbeiter', 'Verwaltung', 'Büro EG', 'Büro OG', 'Serverraum',
        'Produktion', 'Lager', 'Geschäftsleitung', 'IT', 'Reinigung',
    ];

    private const DOORS = [
        'Haupteingang', 'Nebeneingang', 'Büro EG', 'Büro OG', 'Serverraum',
        'Produktionshalle', 'Lager Nord', 'Lager Süd', 'Kantine', 'Tiefgarage',
        'Aufzug', 'Konferenzraum', 'Labor', 'Werkstatt', 'Empfang', 'Archiv',
    ];

    private array $state;

    public function __construct(
        private readonly string $stateFile,
        private readonly int $variant = 0,
    ) {
        $this->state = $this->load();
    }

    // ---------------------------------------------------------------- Users

    public function getUsers(array $params = []): array
    {
        $users = $this->state['users'];

        if (!empty($params['keyword'])) {
            $kw = mb_strtolower((string) $params['keyword']);
            $users = array_values(array_filter($users, function (array $u) use ($kw): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $u['first_name'] ?? '', $u['last_name'] ?? '', $u['full_name'] ?? '',
                    $u['user_email'] ?? '', $u['employee_number'] ?? '',
                    implode(' ', array_column($u['nfc_cards'] ?? [], 'token')),
                ]));
                return str_contains($haystack, $kw);
            }));
        }

        if (!empty($params['status'])) {
            $status = strtoupper((string) $params['status']);
            $users = array_values(array_filter($users, fn (array $u): bool => ($u['status'] ?? '') === $status));
        }

        if (!empty($params['group_id'])) {
            $users = array_values(array_filter($users, fn (array $u): bool => in_array($params['group_id'], $u['access_policy_ids'] ?? [], true)));
        }

        $total = count($users);
        $pageSize = max(1, (int) ($params['page_size'] ?? 25));
        $pageNum = max(1, (int) ($params['page_num'] ?? 1));
        $offset = ($pageNum - 1) * $pageSize;
        $page = array_slice($users, $offset, $pageSize);

        return [
            'data' => $this->hydrateUsers($page),
            'pagination' => ['page_num' => $pageNum, 'page_size' => $pageSize, 'total' => $total],
        ];
    }

    public function getUser(string $id): ?array
    {
        foreach ($this->state['users'] as $u) {
            if ($u['id'] === $id) {
                return $this->hydrateUser($u);
            }
        }
        return null;
    }

    public function createUser(array $data): array
    {
        $user = [
            'id' => $this->uuid(),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'full_name' => '',
            'user_email' => (string) ($data['user_email'] ?? ''),
            'employee_number' => (string) ($data['employee_number'] ?? ''),
            'status' => 'ACTIVE',
            'onboard_time' => time(),
            'nfc_cards' => [],
            'access_policy_ids' => [],
            'pin_code' => null,
        ];
        $user['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
        $this->state['users'][] = $user;
        $this->save();

        return $this->hydrateUser($user);
    }

    public function updateUser(string $id, array $data): array
    {
        $idx = $this->findUserIndex($id);
        if ($idx === null) {
            throw new UniFiApiException('Benutzer nicht gefunden', 404);
        }
        foreach (['first_name', 'last_name', 'user_email', 'employee_number', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->state['users'][$idx][$field] = $data[$field];
            }
        }
        $this->state['users'][$idx]['full_name'] = trim(
            ($this->state['users'][$idx]['first_name'] ?? '') . ' ' . ($this->state['users'][$idx]['last_name'] ?? ''),
        );
        $this->save();

        return $this->hydrateUser($this->state['users'][$idx]);
    }

    public function deleteUser(string $id): void
    {
        $idx = $this->findUserIndex($id);
        if ($idx === null) {
            throw new UniFiApiException('Benutzer nicht gefunden', 404);
        }
        // Release any cards assigned to this user.
        foreach ($this->state['credentials'] as &$cred) {
            if (($cred['user_id'] ?? null) === $id) {
                $cred['user_id'] = null;
                $cred['status'] = 'pending';
            }
        }
        unset($cred);
        array_splice($this->state['users'], $idx, 1);
        $this->save();
    }

    public function getUserAccessPolicies(string $userId): array
    {
        $user = $this->getUser($userId);
        if (!$user) {
            return [];
        }
        $ids = $user['access_policy_ids'] ?? [];
        return array_values(array_filter($this->state['access_groups'], fn (array $g): bool => in_array($g['id'], $ids, true)));
    }

    public function setUserAccessPolicies(string $userId, array $policyIds): void
    {
        $idx = $this->findUserIndex($userId);
        if ($idx === null) {
            throw new UniFiApiException('Benutzer nicht gefunden', 404);
        }
        $this->state['users'][$idx]['access_policy_ids'] = array_values($policyIds);
        $this->save();
    }

    // ---------------------------------------------------------- Credentials

    public function getCredentials(array $params = []): array
    {
        $cards = $this->state['credentials'];

        if (!empty($params['keyword'])) {
            $kw = mb_strtolower((string) $params['keyword']);
            $cards = array_values(array_filter($cards, function (array $c) use ($kw): bool {
                return str_contains(mb_strtolower($c['display_id'] ?? '' . $c['token'] ?? ''), $kw)
                    || str_contains(mb_strtolower($c['alias'] ?? ''), $kw);
            }));
        }

        if (!empty($params['status'])) {
            $cards = array_values(array_filter($cards, fn (array $c): bool => ($c['status'] ?? '') === $params['status']));
        }

        if (array_key_exists('free', $params) && $params['free'] === '1') {
            $cards = array_values(array_filter($cards, fn (array $c): bool => empty($c['user_id'])));
        }

        $total = count($cards);
        $pageSize = max(1, (int) ($params['page_size'] ?? 25));
        $pageNum = max(1, (int) ($params['page_num'] ?? 1));
        $offset = ($pageNum - 1) * $pageSize;
        $page = array_slice($cards, $offset, $pageSize);

        return [
            'data' => $this->hydrateCredentials($page),
            'pagination' => ['page_num' => $pageNum, 'page_size' => $pageSize, 'total' => $total],
        ];
    }

    public function getCredential(string $token): ?array
    {
        foreach ($this->state['credentials'] as $c) {
            if ($c['token'] === $token) {
                return $this->hydrateCredential($c);
            }
        }
        return null;
    }

    public function assignCredential(string $userId, string $token): void
    {
        $userIdx = $this->findUserIndex($userId);
        if ($userIdx === null) {
            throw new UniFiApiException('Benutzer nicht gefunden', 404);
        }
        $card = $this->findCredential($token);
        if ($card === null) {
            throw new UniFiApiException('RFID-Karte nicht gefunden', 404);
        }
        if (!empty($card['user_id'])) {
            throw new UniFiApiException('Diese Karte ist bereits einer Person zugeordnet.', 400);
        }
        $card['user_id'] = $userId;
        $card['status'] = 'assigned';
        $this->replaceCredential($card);

        $user = $this->state['users'][$userIdx];
        $user['nfc_cards'][] = ['id' => $card['display_id'], 'token' => $card['token'], 'type' => $card['card_type'] ?? 'ua_card'];
        $this->state['users'][$userIdx] = $user;
        $this->save();
    }

    public function unassignCredential(string $userId, string $token): void
    {
        $userIdx = $this->findUserIndex($userId);
        $card = $this->findCredential($token);
        if ($card === null) {
            throw new UniFiApiException('RFID-Karte nicht gefunden', 404);
        }
        if (($card['user_id'] ?? null) === $userId) {
            $card['user_id'] = null;
            $card['status'] = 'pending';
            $this->replaceCredential($card);
        }
        if ($userIdx !== null) {
            $this->state['users'][$userIdx]['nfc_cards'] = array_values(array_filter(
                $this->state['users'][$userIdx]['nfc_cards'] ?? [],
                fn (array $n) => $n['token'] !== $token,
            ));
        }
        $this->save();
    }

    // ------------------------------------------------------- Access policies

    public function getAccessGroups(array $params = []): array
    {
        return [
            'data' => $this->state['access_groups'],
            'pagination' => null,
        ];
    }

    public function getAccessGroup(string $id): ?array
    {
        foreach ($this->state['access_groups'] as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        return null;
    }

    public function createAccessGroup(array $data): array
    {
        $group = [
            'id' => $this->uuid(),
            'name' => (string) ($data['name'] ?? ''),
            'resources' => $data['resources'] ?? [],
            'schedule_id' => $data['schedule_id'] ?? null,
        ];
        $this->state['access_groups'][] = $group;
        $this->save();
        return $group;
    }

    public function updateAccessGroup(string $id, array $data): array
    {
        foreach ($this->state['access_groups'] as &$g) {
            if ($g['id'] === $id) {
                foreach (['name', 'resources', 'schedule_id'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $g[$field] = $data[$field];
                    }
                }
                $this->save();
                return $g;
            }
        }
        throw new UniFiApiException('Zutrittsgruppe nicht gefunden', 404);
    }

    public function deleteAccessGroup(string $id): void
    {
        $this->state['access_groups'] = array_values(array_filter($this->state['access_groups'], fn (array $g): bool => $g['id'] !== $id));
        foreach ($this->state['users'] as &$u) {
            $u['access_policy_ids'] = array_values(array_filter($u['access_policy_ids'] ?? [], fn ($gid) => $gid !== $id));
        }
        unset($u);
        $this->save();
    }

    // ----------------------------------------------------------------- Doors

    public function getDoors(): array
    {
        return [
            'data' => $this->state['doors'],
            'pagination' => null,
        ];
    }

    public function getDoor(string $id): ?array
    {
        foreach ($this->state['doors'] as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }
        return null;
    }

    public function unlockDoor(string $id): void
    {
        foreach ($this->state['doors'] as $d) {
            if ($d['id'] === $id) {
                return;
            }
        }
        throw new UniFiApiException('Tür nicht gefunden', 404);
    }

    // ------------------------------------------------------------- Persistence

    private function load(): array
    {
        if (is_file($this->stateFile)) {
            $raw = file_get_contents($this->stateFile);
            $data = json_decode((string) $raw, true);
            if (is_array($data) && isset($data['users'])) {
                return $data;
            }
        }
        $state = $this->seed();
        $this->state = $state;
        $this->save();
        return $state;
    }

    private function save(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($this->stateFile, json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function seed(): array
    {
        $v = $this->variant;

        // Access groups
        $groups = [];
        foreach (self::GROUPS as $i => $name) {
            $groups[] = [
                'id' => sprintf('g-%d-%02d', $v, $i),
                'name' => $name,
                'resources' => [],
                'schedule_id' => null,
            ];
        }

        // Doors
        $doors = [];
        foreach (self::DOORS as $i => $name) {
            $doors[] = [
                'id' => sprintf('d-%d-%02d', $v, $i),
                'name' => $name,
                'full_name' => $name,
                'floor_id' => 'floor-' . (int) ($i / 4),
                'type' => 'door',
                'door_lock_relay_status' => ($i % 7 === 3) ? 'unlock' : 'lock',
                'door_position_status' => null,
            ];
        }

        // Assign a subset of doors to each group as resources.
        foreach ($groups as $gi => &$g) {
            $count = 2 + ($gi % 4);
            $start = ($gi * 3) % count($doors);
            for ($k = 0; $k < $count; $k++) {
                $door = $doors[($start + $k) % count($doors)];
                $g['resources'][] = ['id' => $door['id'], 'type' => 'door'];
            }
        }
        unset($g);

        // Users
        $users = [];
        $numUsers = 48;
        for ($i = 0; $i < $numUsers; $i++) {
            $first = self::FIRST_NAMES[($i + $v) % count(self::FIRST_NAMES)];
            $last = self::LAST_NAMES[($i * 3 + $v) % count(self::LAST_NAMES)];
            $full = $first . ' ' . $last;
            $status = 'ACTIVE';
            if ($i % 19 === 0) {
                $status = 'DEACTIVATED';
            } elseif ($i % 23 === 0) {
                $status = 'PENDING';
            }
            $policyCount = 1 + ($i % 3);
            $policyIds = [];
            for ($k = 0; $k < $policyCount; $k++) {
                $policyIds[] = $groups[($i + $k) % count($groups)]['id'];
            }
            $users[] = [
                'id' => sprintf('u-%d-%03d', $v, $i),
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => $full,
                'user_email' => strtolower(self::ascii($first)) . '.' . strtolower(self::ascii($last)) . '@example.com',
                'employee_number' => (string) (1000 + $i + $v * 1000),
                'status' => $status,
                'onboard_time' => time() - ($i * 86400),
                'nfc_cards' => [],
                'access_policy_ids' => $policyIds,
                'pin_code' => null,
            ];
        }

        // Credentials (cards): one per user plus a few free/extra ones.
        $credentials = [];
        $cardIndex = 0;
        foreach ($users as $i => $u) {
            $credentials[] = $this->makeCard($v, $cardIndex++, $u['id'], 'assigned', 'ua_card');
            if ($i % 6 === 0) {
                $credentials[] = $this->makeCard($v, $cardIndex++, $u['id'], 'assigned', 'ua_card');
            }
            $u['nfc_cards'] = [];
        }

        // Attach cards to users (rebuild user.nfc_cards).
        foreach ($credentials as $c) {
            if ($c['user_id'] !== null) {
                $this->attachCardToUser($users, $c);
            }
        }

        // Free cards.
        for ($i = 0; $i < 12; $i++) {
            $credentials[] = $this->makeCard($v, $cardIndex++, null, 'pending', 'ua_card');
        }
        // A couple of disabled / lost cards.
        $credentials[] = $this->makeCard($v, $cardIndex++, null, 'disable', 'ua_card');
        $credentials[] = $this->makeCard($v, $cardIndex++, null, 'loss', 'ua_card');

        return [
            'users' => $users,
            'credentials' => $credentials,
            'access_groups' => $groups,
            'doors' => $doors,
        ];
    }

    private function makeCard(int $variant, int $index, ?string $userId, string $status, string $type): array
    {
        return [
            'token' => sprintf('nfc-%d-%04d', $variant, $index),
            'display_id' => sprintf('%02X:%02X:%02X:%02X', ($variant + 4) % 256, ($index * 7 + 1) % 256, ($index * 3 + 9) % 256, ($index + 42) % 256),
            'status' => $status,
            'alias' => null,
            'card_type' => $type,
            'user_id' => $userId,
            'user_type' => $userId ? 'USER' : null,
        ];
    }

    private function attachCardToUser(array &$users, array $card): void
    {
        foreach ($users as &$u) {
            if ($u['id'] === $card['user_id']) {
                $u['nfc_cards'][] = ['id' => $card['display_id'], 'token' => $card['token'], 'type' => $card['card_type']];
                return;
            }
        }
        unset($u);
    }

    private function hydrateUsers(array $users): array
    {
        return array_map(fn (array $u): array => $this->hydrateUser($u), $users);
    }

    private function hydrateUser(array $u): array
    {
        $policies = array_values(array_filter($this->state['access_groups'], fn (array $g): bool => in_array($g['id'], $u['access_policy_ids'] ?? [], true)));
        $u['access_policies'] = array_map(fn (array $g): array => ['id' => $g['id'], 'name' => $g['name']], $policies);
        return $u;
    }

    private function hydrateCredentials(array $cards): array
    {
        return array_map(fn (array $c): array => $this->hydrateCredential($c), $cards);
    }

    private function hydrateCredential(array $c): array
    {
        if (!empty($c['user_id'])) {
            $u = $this->getUser($c['user_id']);
            if ($u) {
                $c['user'] = [
                    'id' => $u['id'],
                    'first_name' => $u['first_name'],
                    'last_name' => $u['last_name'],
                    'name' => $u['full_name'],
                ];
            }
        }
        return $c;
    }

    private function findUserIndex(string $id): ?int
    {
        foreach ($this->state['users'] as $i => $u) {
            if ($u['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    private function findCredential(string $token): ?array
    {
        foreach ($this->state['credentials'] as $c) {
            if ($c['token'] === $token) {
                return $c;
            }
        }
        return null;
    }

    private function replaceCredential(array $card): void
    {
        foreach ($this->state['credentials'] as $i => $c) {
            if ($c['token'] === $card['token']) {
                $this->state['credentials'][$i] = $card;
                return;
            }
        }
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }

    private static function ascii(string $value): string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'];
        return strtr($value, $map);
    }
}
