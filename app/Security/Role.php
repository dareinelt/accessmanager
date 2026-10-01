<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Application roles, ordered by privilege. A higher rank satisfies every
 * lower-rank role check.
 */
enum Role: string
{
    case Sysadmin = 'sysadmin';
    case Admin = 'admin';
    case Operator = 'operator';
    case Readonly = 'readonly';

    public function rank(): int
    {
        return match ($this) {
            self::Sysadmin => 4,
            self::Admin => 3,
            self::Operator => 2,
            self::Readonly => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sysadmin => 'Systemadministrator',
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Readonly => 'Nur Lesen',
        };
    }

    public function satisfies(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /** @return array<string,string> slug => label, highest privilege first */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $role) {
            $out[$role->value] = $role->label();
        }
        return $out;
    }
}
