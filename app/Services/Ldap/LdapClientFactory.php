<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Config\Config;
use App\Core\Logger;

/**
 * Chooses the LDAP source: the real ext-ldap client when configured and
 * available, otherwise the mock client so the feature stays usable in
 * development and in containers without ext-ldap.
 */
final class LdapClientFactory
{
    public static function create(): LdapClientInterface
    {
        if (Config::bool('LDAP_MOCK', false)) {
            return new MockLdapClient();
        }

        if (!LdapService::isSupported()) {
            Logger::warning('ldap', 'ext-ldap ist nicht verfügbar – verwende Mock-Daten.');
            return new MockLdapClient();
        }

        return new LdapService(Config::ldap());
    }
}
