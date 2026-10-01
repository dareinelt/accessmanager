<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Config\Config;
use App\Core\Logger;

/**
 * Chooses the LDAP source. Never falls back to mock data silently:
 *
 *  - LDAP_MOCK=true is only honoured together with UNIFI_API_MOCK=true,
 *    because the AD sync would otherwise create persons and change access
 *    rights on real controllers based on demo data.
 *  - Without ext-ldap the AD integration is unavailable (no mock fallback).
 *
 * In both refusal cases an UnavailableLdapClient is returned, which makes
 * every AD read – and therefore the AD sync – fail before any write.
 */
final class LdapClientFactory
{
    public static function create(): LdapClientInterface
    {
        $reason = self::refusalReason(
            Config::bool('LDAP_MOCK', false),
            Config::isMock(),
            LdapService::isSupported(),
        );
        if ($reason !== null) {
            Logger::error('ldap', $reason);
            return new UnavailableLdapClient($reason);
        }

        if (Config::bool('LDAP_MOCK', false)) {
            return new MockLdapClient();
        }

        return new LdapService(Config::ldap());
    }

    /** Returns why no LDAP client may be used, or null when it is safe. */
    public static function refusalReason(bool $ldapMock, bool $unifiMock, bool $extLdapAvailable): ?string
    {
        if ($ldapMock && !$unifiMock) {
            return 'LDAP_MOCK=true ist nur zusammen mit UNIFI_API_MOCK=true zulässig: Mock-AD-Daten dürfen keine echten UniFi-Controller verändern. '
                . 'Setzen Sie LDAP_MOCK=false und konfigurieren Sie das Active Directory.';
        }
        if (!$ldapMock && !$extLdapAvailable) {
            return 'Die PHP-Erweiterung ext-ldap ist nicht verfügbar – die AD-Synchronisation ist deaktiviert.';
        }
        return null;
    }
}
