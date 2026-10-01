<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use RuntimeException;

/**
 * Placeholder LDAP source used when no safe directory source is available
 * (ext-ldap missing, or mock data would touch a real controller). Every
 * read fails with the reason, so the AD sync aborts before changing anything.
 */
final class UnavailableLdapClient implements LdapClientInterface
{
    public function __construct(private readonly string $reason)
    {
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function label(): string
    {
        return 'Active Directory (nicht verfügbar: ' . $this->reason . ')';
    }

    public function getUsers(): array
    {
        throw new RuntimeException($this->reason);
    }

    public function getGroups(): array
    {
        throw new RuntimeException($this->reason);
    }
}
