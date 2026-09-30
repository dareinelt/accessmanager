<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Security\Csrf;

final class AuthController extends BaseController
{
    public function loginForm(Request $request): void
    {
        if (Auth::check()) {
            Response::redirect('/dashboard');
        }
        $this->view('auth/login', [
            'appName' => Config::appName(),
            'flash' => $this->flash(),
            'csrf' => Csrf::token(),
        ], 'auth');
    }

    public function login(Request $request): void
    {
        $token = $request->input('_csrf');
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            $this->setFlash('error', 'Ihre Sitzung ist abgelaufen. Bitte erneut versuchen.');
            Response::redirect('/login');
        }

        $result = App::auth()->login(
            (string) $request->input('username', ''),
            (string) $request->input('password', ''),
            $request->ip(),
        );

        if (!$result['success']) {
            $this->setFlash('error', (string) ($result['error'] ?? 'Anmeldung fehlgeschlagen.'));
            Response::redirect('/login');
        }

        Response::redirect('/dashboard');
    }

    public function logout(Request $request): void
    {
        App::auth()->logout();
        Response::redirect('/login');
    }
}
