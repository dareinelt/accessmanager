<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppUserRepository;
use App\Security\Auth;

/**
 * Management of local admin users (the operators of this application).
 */
final class AppUserService
{
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
    public function create(array $data, ?int $actorId = null, ?string $actorName = null): array
    {
        if (empty(trim((string) ($data['username'] ?? '')))) {
            throw new \InvalidArgumentException('Bitte einen Benutzernamen angeben.');
        }
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        if (strlen((string) ($data['password'] ?? '')) < 10) {
            throw new \InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        $role = (string) ($data['role'] ?? Auth::ROLE_READONLY);
        if (!in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR, Auth::ROLE_READONLY], true)) {
            throw new \InvalidArgumentException('Ungültige Rolle.');
        }

        $id = $this->users->create(
            username: trim((string) $data['username']),
            email: trim((string) $data['email']),
            password: (string) $data['password'],
            role: $role,
        );
        $this->audit->log('user.create', 'app_user', (string) $id, trim((string) $data['username']), userId: $actorId, username: $actorName);

        $user = $this->users->find($id);
        return $user ?? ['id' => $id];
    }

    public function update(int $id, array $data, ?int $actorId = null, ?string $actorName = null): void
    {
        if (array_key_exists('role', $data) && !in_array((string) $data['role'], [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR, Auth::ROLE_READONLY], true)) {
            throw new \InvalidArgumentException('Ungültige Rolle.');
        }
        if (array_key_exists('password', $data) && $data['password'] !== '' && strlen((string) $data['password']) < 10) {
            throw new \InvalidArgumentException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        }
        $this->users->update($id, $data);
        $this->audit->log('user.update', 'app_user', (string) $id, (string) $id, userId: $actorId, username: $actorName);
    }

    public function delete(int $id, ?int $actorId = null, ?string $actorName = null): void
    {
        if ($id === $actorId) {
            throw new \InvalidArgumentException('Sie können Ihr eigenes Konto nicht löschen.');
        }
        $this->users->delete($id);
        $this->audit->log('user.delete', 'app_user', (string) $id, (string) $id, userId: $actorId, username: $actorName);
    }
}
