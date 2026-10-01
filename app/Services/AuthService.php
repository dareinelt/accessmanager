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
        if ($identifier === '' || $password === '') {
            return ['success' => false, 'error' => 'Bitte Benutzernamen und Passwort eingeben.'];
        }
        // FIX: over-long input used to overflow the audit/rate-limit columns
        // (VARCHAR) and caused a 500 error instead of a normal failed login.
        $identifier = mb_substr($identifier, 0, 255);

        // SECURITY FIX: additional per-IP limit against password spraying.
        if (RateLimiter::tooManyAttempts($identifier) || RateLimiter::tooManyAttemptsFromIp($ip)) {
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

    public function logout(?string $ip = null): void
    {
        if (Auth::check()) {
            $this->audit->log('auth.logout', 'auth', null, Auth::username(), null, null, Auth::id(), Auth::username(), $ip);
        }
        Session::restart();
        Auth::forget();
    }
}
