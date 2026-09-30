<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Session;
use App\Core\View;
use App\Security\Auth;

/**
 * Base for server-rendered page controllers.
 */
abstract class BaseController
{
    protected function view(string $template, array $data = [], string $layout = 'app'): void
    {
        View::render($template, $data, $layout);
    }

    /** @return array<string,mixed>|null */
    protected function currentUser(): ?array
    {
        return Auth::user();
    }

    protected function userId(): int
    {
        return Auth::id();
    }

    protected function username(): string
    {
        return Auth::username();
    }

    protected function role(): string
    {
        return Auth::role();
    }

    protected function setFlash(string $type, string $message): void
    {
        Session::put('flash', ['type' => $type, 'message' => $message]);
    }

    /** @return array{type:string,message:string}|null */
    protected function flash(): ?array
    {
        $flash = Session::get('flash');
        Session::forget('flash');
        return is_array($flash) ? $flash : null;
    }
}
