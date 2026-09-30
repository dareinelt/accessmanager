<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Core\Logger;
use RuntimeException;

/**
 * Real Active Directory / LDAP client built on PHP's native ext-ldap.
 *
 * Normalises raw LDAP entries into the shape expected by AdSyncService
 * (see LdapClientInterface). Supports StartTLS, paged result sets and
 * skips accounts that are disabled via the ACCOUNTDISABLE flag.
 */
final class LdapService implements LdapClientInterface
{
    private const PAGE_SIZE = 500;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public static function isSupported(): bool
    {
        return function_exists('ldap_connect');
    }

    public function label(): string
    {
        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            return 'LDAP (nicht konfiguriert)';
        }
        return 'LDAP ' . $host . ':' . (int) ($this->config['port'] ?? 389);
    }

    public function getUsers(): array
    {
        $baseDn = (string) ($this->config['base_dn'] ?? '');
        if ($baseDn === '') {
            throw new RuntimeException('LDAP_BASE_DN ist nicht konfiguriert.');
        }
        $filter = (string) ($this->config['user_filter'] ?? '(&(objectCategory=person)(objectClass=user))');
        $cardAttribute = strtolower((string) ($this->config['card_attribute'] ?? 'employeeid'));

        $attributes = [
            'objectsid', 'samaccountname', 'userprincipalname', 'mail',
            'givenname', 'sn', 'displayname', 'employeenumber',
            'useraccountcontrol', 'memberof', $cardAttribute,
        ];

        $entries = $this->search($baseDn, $filter, $attributes);

        $users = [];
        foreach ($entries as $entry) {
            $uac = (int) $this->first($entry, 'useraccountcontrol');
            if (($uac & 0x0002) !== 0) { // ACCOUNTDISABLE
                continue;
            }

            $sid = $this->sidToString((string) $this->first($entry, 'objectsid'));
            $sam = (string) $this->first($entry, 'samaccountname');

            $users[] = [
                'identifier' => $sid !== '' ? $sid : ($sam !== '' ? $sam : ''),
                'samaccount_name' => $sam,
                'user_principal_name' => $this->nullable($this->first($entry, 'userprincipalname')),
                'email' => $this->nullable($this->first($entry, 'mail')),
                'first_name' => $this->nullable($this->first($entry, 'givenname')),
                'last_name' => $this->nullable($this->first($entry, 'sn')),
                'display_name' => $this->nullable($this->first($entry, 'displayname')),
                'employee_number' => $this->nullable($this->first($entry, 'employeenumber')),
                'card_number' => $this->nullable($this->first($entry, $cardAttribute)),
                'enabled' => true,
                'member_of' => $this->stringArray($entry, 'memberof'),
                'raw' => $entry,
            ];
        }

        return $users;
    }

    public function getGroups(): array
    {
        $baseDn = (string) ($this->config['group_base_dn'] ?? '');
        if ($baseDn === '') {
            $baseDn = (string) ($this->config['base_dn'] ?? '');
        }
        if ($baseDn === '') {
            throw new RuntimeException('LDAP_BASE_DN ist nicht konfiguriert.');
        }
        $filter = (string) ($this->config['group_filter'] ?? '(objectClass=group)');

        $entries = $this->search($baseDn, $filter, ['cn', 'name', 'samaccountname', 'distinguishedname']);

        $groups = [];
        $seen = [];
        foreach ($entries as $entry) {
            $dn = (string) $this->first($entry, 'dn');
            $name = (string) ($this->first($entry, 'cn') ?: $this->first($entry, 'name') ?: $this->first($entry, 'samaccountname'));
            if ($dn === '' && $name === '') {
                continue;
            }
            $key = $dn !== '' ? $dn : $name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $groups[] = ['dn' => $dn, 'name' => $name];
        }

        return $groups;
    }

    // ------------------------------------------------------------- internals

    /**
     * @return resource
     */
    private function connect()
    {
        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('LDAP_HOST ist nicht konfiguriert.');
        }
        $port = (int) ($this->config['port'] ?? 389);

        $conn = @ldap_connect($host, $port);
        if ($conn === false) {
            throw new RuntimeException("LDAP-Verbindung zu {$host}:{$port} fehlgeschlagen.");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        $timeout = (int) ($this->config['timeout'] ?? 5);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);

        if ((bool) ($this->config['use_tls'] ?? false) && $port !== 636) {
            if (!@ldap_start_tls($conn)) {
                throw new RuntimeException('LDAP StartTLS fehlgeschlagen: ' . ldap_error($conn));
            }
        }

        $bindDn = (string) ($this->config['bind_dn'] ?? '');
        $bindPassword = (string) ($this->config['bind_password'] ?? '');
        if (!@ldap_bind($conn, $bindDn !== '' ? $bindDn : null, $bindPassword !== '' ? $bindPassword : null)) {
            throw new RuntimeException('LDAP-Bind fehlgeschlagen: ' . ldap_error($conn));
        }

        return $conn;
    }

    /**
     * @param list<string> $attributes
     * @return array<int,array<string,mixed>>
     */
    private function search(string $baseDn, string $filter, array $attributes): array
    {
        $conn = $this->connect();
        try {
            $all = [];
            $cookie = '';

            do {
                $controls = [
                    [
                        'oid' => LDAP_CONTROL_PAGEDRESULTS,
                        'iscritical' => false,
                        'value' => ['size' => self::PAGE_SIZE, 'cookie' => $cookie],
                    ],
                ];

                $result = @ldap_search($conn, $baseDn, $filter, $attributes, 0, 0, 0, LDAP_DEREF_NEVER, $controls);
                if ($result === false) {
                    throw new RuntimeException('LDAP-Suche fehlgeschlagen: ' . ldap_error($conn));
                }

                // Extract the paging cookie returned by the server.
                $errcode = 0;
                $matched = '';
                $errmsg = '';
                $referrals = [];
                $serverCtrls = [];
                if (!@ldap_parse_result($conn, $result, $errcode, $matched, $errmsg, $referrals, $serverCtrls)) {
                    throw new RuntimeException('LDAP-Ergebnis konnte nicht gelesen werden: ' . ldap_error($conn));
                }
                if ($errcode !== 0 && $errcode !== 10) { // 10 = referral
                    throw new RuntimeException("LDAP-Suche fehlgeschlagen: {$errmsg}");
                }

                $entries = @ldap_get_entries($conn, $result);
                if (is_array($entries)) {
                    // ldap_get_entries prepends a "count" key; drop it.
                    unset($entries['count']);
                    foreach ($entries as $entry) {
                        $all[] = $entry;
                    }
                }

                $cookie = $this->pagingCookie($serverCtrls);
            } while ($cookie !== '');

            return $all;
        } finally {
            @ldap_unbind($conn);
        }
    }

    /** @param array<int,array<string,mixed>> $serverCtrls */
    private function pagingCookie(array $serverCtrls): string
    {
        foreach ($serverCtrls as $ctrl) {
            if (isset($ctrl['oid']) && $ctrl['oid'] === LDAP_CONTROL_PAGEDRESULTS) {
                $value = $ctrl['value'] ?? [];
                if (is_array($value) && !empty($value['cookie'])) {
                    return (string) $value['cookie'];
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $entry */
    private function first(array $entry, string $key): mixed
    {
        $value = $entry[$key] ?? null;
        if (is_array($value)) {
            return $value[0] ?? null;
        }
        return $value;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /** @param array<string,mixed> $entry @return array<int,string> */
    private function stringArray(array $entry, string $key): array
    {
        $value = $entry[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        unset($value['count']);
        return array_values(array_map('strval', $value));
    }

    /** Convert a binary objectSid to its "S-1-5-..." string form. */
    private function sidToString(string $bin): string
    {
        if ($bin === '' || strlen($bin) < 8 || ord($bin[0]) !== 1) {
            return '';
        }
        $revision = ord($bin[0]);
        $count = ord($bin[1]);
        $authority = 0;
        for ($i = 0; $i < 6; $i++) {
            $authority = $authority * 256 + ord($bin[2 + $i]);
        }
        $sid = sprintf('S-%d-%d', $revision, $authority);
        $offset = 8;
        for ($i = 0; $i < $count; $i++) {
            $sub = 0;
            for ($j = 0; $j < 4; $j++) {
                $sub = $sub * 256 + ord($bin[$offset + 3 - $j]);
            }
            $offset += 4;
            $sid .= '-' . $sub;
        }
        return $sid;
    }
}
