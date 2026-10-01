<?php

declare(strict_types=1);

namespace App\Services\Ldap;

/**
 * Development / test LDAP source. Returns a small, deterministic Active
 * Directory dataset so the AD sync can be exercised without a real AD.
 * Activated only when LDAP_MOCK=true and UNIFI_API_MOCK=true.
 */
final class MockLdapClient implements LdapClientInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $users;

    /** @var array<int,array<string,mixed>> */
    private array $groups;

    /**
     * @param array<int,array<string,mixed>> $users  Normalised user rows.
     * @param array<int,array<string,mixed>> $groups Normalised group rows {dn,name}.
     */
    public function __construct(array $users = [], array $groups = [])
    {
        $this->users = $users !== [] ? $users : self::demoUsers();
        $this->groups = $groups !== [] ? $groups : self::demoGroups();
    }

    public function label(): string
    {
        return 'Active Directory (Mock)';
    }

    public function getUsers(): array
    {
        return $this->users;
    }

    public function getGroups(): array
    {
        return $this->groups;
    }

    /** @return array<int,array<string,mixed>> */
    private static function demoGroups(): array
    {
        return [
            ['dn' => 'CN=IT,OU=Groups,DC=example,DC=com', 'name' => 'IT'],
            ['dn' => 'CN=Verwaltung,OU=Groups,DC=example,DC=com', 'name' => 'Verwaltung'],
            ['dn' => 'CN=Serverraum,OU=Groups,DC=example,DC=com', 'name' => 'Serverraum'],
            ['dn' => 'CN=Produktion,OU=Groups,DC=example,DC=com', 'name' => 'Produktion'],
        ];
    }

    /**
     * Demo users that overlap with the mock UniFi users of the seeded demo
     * connection (connection id 1 => mock variant 1), plus one newcomer that
     * has no UniFi counterpart yet. The e-mail addresses and employee numbers
     * match the MockUniFiApiClient seed, so the AD sync demonstrates both the
     * "match existing person" and the "create person" path.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function demoUsers(): array
    {
        $g = fn (string $name): string => 'CN=' . $name . ',OU=Groups,DC=example,DC=com';

        return [
            [
                'identifier' => 'S-1-5-21-1000-1001-1002-1000',
                'samaccount_name' => 'lena.weber',
                'user_principal_name' => 'lena.weber@example.com',
                'email' => 'lena.weber@example.com',
                'first_name' => 'Lena',
                'last_name' => 'Weber',
                'display_name' => 'Lena Weber',
                'employee_number' => '2001',
                'card_number' => 'AA:BB:CC:DD',
                'enabled' => true,
                'member_of' => [$g('IT')],
                'raw' => [],
            ],
            [
                'identifier' => 'S-1-5-21-1000-1001-1002-1001',
                'samaccount_name' => 'jonas.becker',
                'user_principal_name' => 'jonas.becker@example.com',
                'email' => 'jonas.becker@example.com',
                'first_name' => 'Jonas',
                'last_name' => 'Becker',
                'display_name' => 'Jonas Becker',
                'employee_number' => '2002',
                'card_number' => 'AA:BB:CC:DE',
                'enabled' => true,
                'member_of' => [$g('Verwaltung')],
                'raw' => [],
            ],
            [
                'identifier' => 'S-1-5-21-1000-1001-1002-1002',
                'samaccount_name' => 'sophie.koch',
                'user_principal_name' => 'sophie.koch@example.com',
                'email' => 'sophie.koch@example.com',
                'first_name' => 'Sophie',
                'last_name' => 'Koch',
                'display_name' => 'Sophie Koch',
                'employee_number' => '2003',
                'card_number' => null,
                'enabled' => true,
                'member_of' => [$g('Serverraum')],
                'raw' => [],
            ],
            [
                'identifier' => 'S-1-5-21-1000-1001-1002-1003',
                'samaccount_name' => 'lukas.wolf',
                'user_principal_name' => 'lukas.wolf@example.com',
                'email' => 'lukas.wolf@example.com',
                'first_name' => 'Lukas',
                'last_name' => 'Wolf',
                'display_name' => 'Lukas Wolf',
                'employee_number' => '2004',
                'card_number' => null,
                'enabled' => true,
                'member_of' => [$g('IT'), $g('Serverraum')],
                'raw' => [],
            ],
            [
                'identifier' => 'S-1-5-21-1000-1001-1002-1004',
                'samaccount_name' => 'ad.newcomer',
                'user_principal_name' => 'ad.newcomer@example.com',
                'email' => 'ad.newcomer@example.com',
                'first_name' => 'AD',
                'last_name' => 'Newcomer',
                'display_name' => 'AD Newcomer',
                'employee_number' => '5000',
                'card_number' => null,
                'enabled' => true,
                'member_of' => [$g('IT')],
                'raw' => [],
            ],
        ];
    }
}
