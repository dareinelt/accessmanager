<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use App\Repositories\AppUserRepository;
use App\Security\Role;
use InvalidArgumentException;
use PDOException;

/**
 * Management of local application users (the operators of this application).
 *
 * SECURITY FIX (privilege escalation): previously every "admin" could create
 * sysadmin accounts, promote anyone (incl. themselves via a second account)
 * to sysadmin and reset passwords of / delete sysadmin accounts. Now:
 *   - only a sysadmin may assign the sysadmin role or touch sysadmin accounts,
 *   - nobody can change their own role, deactivate or delete themselves,
 *   - the default admin account can be neither demoted, disabled nor deleted.
 *
 * `$actorRole === null` means a trusted system context (CLI/seeding).
 */
final class AppUserService
{
    private const MIN_PASSWORD_LENGTH = 10;
    private const MAX_PASSWORD_LENGTH = 4096;

    public function __construct(
        private readonly AppUserRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        return $this->users->all();
    }

    /** @return array<string,mixed> */
    public function create(array $data, ?int $actorId = null, ?string $actorName = null, ?Role $actorRole = null): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            throw new InvalidArgumentException('Bitte einen Benutzernamen angeben.');
        }
        if (mb_strlen($username) > 64 || preg_match('/^[\p{L}\p{N}._@-]+$/u', $username) !== 1) {
            throw new InvalidArgumentException('Der Benutzername darf nur Buchstaben, Ziffern sowie . _ - @ enthalten (max. 64 Zeichen).');
        }
        $email = $this->validEmail($data['email'] ?? '');
        $password = $this->validPassword($data['password'] ?? '', true);
        $role = $this->validRole($data['role'] ?? Role::Readonly->value);
        $this->assertMayAssign($role, $actorRole);

        try {
            $id = $this->users->create(username: $username, email: $email, password: (string) $password, role: $role->value);
        } catch (PDOException $exception) {
            $this->rethrowDuplicate($exception);
        }
        $this->audit->log('user.create', 'app_user', (string) $id, $username, ['role' => $role->value], userId: $actorId, username: $actorName);

        return $this->users->find($id) ?? ['id' => $id];
    }

    public function update(int $id, array $data, ?int $actorId = null, ?string $actorName = null, ?Role $actorRole = null): void
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Der Benutzer wurde nicht gefunden.');
        }
        $this->assertMayManage($user, $actorRole);

        $changes = [];
        if (array_key_exists('email', $data)) {
            // FIX: e-mail was stored unvalidated on update.
            $changes['email'] = $this->validEmail($data['email']);
        }
        if (array_key_exists('role', $data)) {
            $role = $this->validRole($data['role']);
            if ($role->value !== $user['role']) {
                if ($actorId !== null && $id === $actorId) {
                    throw new InvalidArgumentException('Sie können Ihre eigene Rolle nicht ändern.');
                }
                if ($this->isDefaultAdmin($user) && $role !== Role::Sysadmin) {
                    throw new InvalidArgumentException('Der Standard-Administrator muss immer Systemadministrator sein.');
                }
                $this->assertMayAssign($role, $actorRole);
            }
            $changes['role'] = $role->value;
        }
        $password = $this->validPassword($data['password'] ?? null, false);
        if ($password !== null) {
            $changes['password'] = $password;
        }
        if (array_key_exists('is_active', $data)) {
            $active = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            if (!$active && $actorId !== null && $id === $actorId) {
                throw new InvalidArgumentException('Sie können Ihr eigenes Konto nicht deaktivieren.');
            }
            if (!$active && $this->isDefaultAdmin($user)) {
                throw new InvalidArgumentException('Der Standard-Administrator kann nicht deaktiviert werden.');
            }
            $changes['is_active'] = $active;
        }

        try {
            $this->users->update($id, $changes);
        } catch (PDOException $exception) {
            $this->rethrowDuplicate($exception);
        }
        $details = array_keys($changes);
        $this->audit->log('user.update', 'app_user', (string) $id, (string) $user['username'], ['fields' => $details], userId: $actorId, username: $actorName);
    }

    public function delete(int $id, ?int $actorId = null, ?string $actorName = null, ?Role $actorRole = null): void
    {
        if ($actorId !== null && $id === $actorId) {
            throw new InvalidArgumentException('Sie können Ihr eigenes Konto nicht löschen.');
        }
        $user = $this->users->find($id);
        if ($user === null) {
            throw new InvalidArgumentException('Der Benutzer wurde nicht gefunden.');
        }
        $this->assertMayManage($user, $actorRole);
        if ($this->isDefaultAdmin($user)) {
            throw new InvalidArgumentException('Der Standard-Administrator kann nicht gelöscht werden.');
        }
        $this->users->delete($id);
        $this->audit->log('user.delete', 'app_user', (string) $id, (string) $user['username'], userId: $actorId, username: $actorName);
    }

    /** Roles the given actor may assign (used by the UI). @return array<string,string> */
    public static function assignableRoles(?Role $actorRole): array
    {
        $labels = Role::labels();
        if ($actorRole !== null && $actorRole !== Role::Sysadmin) {
            unset($labels[Role::Sysadmin->value]);
        }
        return $labels;
    }

    private function assertMayAssign(Role $role, ?Role $actorRole): void
    {
        if ($actorRole === null) {
            return;
        }
        if (!$actorRole->satisfies(Role::Admin)) {
            throw new InvalidArgumentException('Keine Berechtigung für die Benutzerverwaltung.');
        }
        if ($role === Role::Sysadmin && $actorRole !== Role::Sysadmin) {
            throw new InvalidArgumentException('Nur Systemadministratoren dürfen die Rolle „Systemadministrator“ vergeben.');
        }
    }

    /** @param array<string,mixed> $user */
    private function assertMayManage(array $user, ?Role $actorRole): void
    {
        if ($actorRole === null) {
            return;
        }
        if (!$actorRole->satisfies(Role::Admin)) {
            throw new InvalidArgumentException('Keine Berechtigung für die Benutzerverwaltung.');
        }
        if (($user['role'] ?? '') === Role::Sysadmin->value && $actorRole !== Role::Sysadmin) {
            throw new InvalidArgumentException('Nur Systemadministratoren dürfen Systemadministrator-Konten bearbeiten oder löschen.');
        }
    }

    private function validRole(mixed $value): Role
    {
        $role = is_string($value) ? Role::tryFrom($value) : null;
        if ($role === null) {
            throw new InvalidArgumentException('Ungültige Rolle.');
        }
        return $role;
    }

    private function validEmail(mixed $value): string
    {
        $email = is_string($value) ? trim($value) : '';
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        return $email;
    }

    /** Returns null when the password is optional and was left empty. */
    private function validPassword(mixed $value, bool $required): ?string
    {
        $password = is_string($value) ? $value : '';
        if ($password === '' && !$required) {
            return null;
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Das Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen lang sein.');
        }
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Das Passwort ist zu lang.');
        }
        return $password;
    }

    /** @param array<string,mixed> $user */
    private function isDefaultAdmin(array $user): bool
    {
        return ($user['username'] ?? null) === (string) Config::get('ADMIN_USERNAME', 'admin');
    }

    /** FIX: duplicate username/e-mail used to surface as HTTP 500. */
    private function rethrowDuplicate(PDOException $exception): never
    {
        if ($exception->getCode() === '23000') {
            throw new InvalidArgumentException('Benutzername oder E-Mail-Adresse ist bereits vergeben.');
        }
        throw $exception;
    }
}
