<?php

declare(strict_types=1);

namespace App\Services\Ldap;

/**
 * Contract for an LDAP/Active Directory source. Implementations return
 * normalised arrays so the sync logic never has to deal with the raw
 * ext-ldap API (or a mock) directly.
 */
interface LdapClientInterface
{
    /**
     * List all relevant AD users, normalised.
     *
     * Each entry:
     *   identifier           string  objectSid (hex) or sAMAccountName fallback
     *   samaccount_name      string
     *   user_principal_name  ?string
     *   email                ?string
     *   first_name           ?string
     *   last_name            ?string
     *   display_name         ?string
     *   employee_number      ?string
     *   card_number          ?string  value of the configured RFID attribute
     *   enabled              bool
     *   member_of            string[] DN list of group memberships
     *   raw                  array    original attribute set
     *
     * @return array<int,array<string,mixed>>
     */
    public function getUsers(): array;

    /**
     * List all AD groups, normalised to {dn, name}.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getGroups(): array;

    /** Human-readable label of the active source (for the UI/CLI). */
    public function label(): string;
}
