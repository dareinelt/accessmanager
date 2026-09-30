<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\RateLimiter;
use App\Core\Session;
use App\Security\Auth;

/**
 * Login/logout orchestration with brute-force protection and audit trail.
 */
final class AuthService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /** @return array{success:bool,locked?:bool,error?:string} */
    public function login(string $username, string $password, string $ip): array
    {
        $identifier = mb_strtolower(trim($username));
        if ($identifier === '') {
            return ['success' => false, 'error' => 'Bitte Benutzernamen und Passwort eingeben.'];
        }

        if (RateLimiter::tooManyAttempts($identifier)) {
            return ['success' => false, 'locked' => true, 'error' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'];
        }

        $ok = Auth::attempt($identifier, $password);
        RateLimiter::record($identifier, $ip, $ok);

        if (!$ok) {
            $this->audit->log('auth.login_failed', 'auth', null, $identifier, ['ip' => $ip], null, null, $identifier, $ip, 'failed');
            return ['success' => false, 'error' => 'Benutzername oder Passwort ist falsch.'];
        }

        RateLimiter::clear($identifier);
        $this->audit->log('auth.login', 'auth', null, $identifier, ['ip' => $ip], null, Auth::id(), Auth::username(), $ip);

        return ['success' => true];
    }

    public function logout(): void
    {
        if (Auth::check()) {
            $this->audit->log('auth.logout', 'auth', null, Auth::username(), null, null, Auth::id(), Auth::username());
        }
        Session::destroy();
    }
}
