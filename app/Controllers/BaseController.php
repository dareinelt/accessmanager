<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Security\Auth;
use App\Security\Csrf;

/**
 * Base for server-rendered page controllers.
 */
abstract class BaseController
{
    /** Maximum accepted size for uploaded files (backup JSON, certificates). */
    protected const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

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
        Session::flash($type, $message);
    }

    /** @return array{type:string,message:string}|null */
    protected function flash(): ?array
    {
        return Session::pullFlash();
    }

    protected function csrfValid(Request $request): bool
    {
        $token = $request->header('x-csrf-token') ?: $request->input('_csrf');

        return Csrf::validate(is_string($token) ? $token : null);
    }

    /**
     * Central CSRF guard for HTML form posts (previously duplicated in
     * several controllers): on mismatch the user is sent back with a message.
     */
    protected function requireFormCsrf(Request $request, string $redirectTo): void
    {
        if (!$this->csrfValid($request)) {
            $this->setFlash('error', 'Ihre Sitzung ist abgelaufen. Bitte erneut versuchen.');
            Response::redirect($redirectTo);
        }
    }

    /**
     * Read an uploaded file safely. Returns null if no file was sent.
     *
     * SECURITY FIX: only genuine HTTP uploads are accepted (the previous
     * `is_file($tmp)` fallback allowed reading arbitrary server files when
     * the tmp_name could be influenced) and the size is bounded.
     *
     * @throws \InvalidArgumentException with a user-facing message
     */
    protected function readUpload(string $field, int $maxBytes = self::MAX_UPLOAD_BYTES): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \InvalidArgumentException('Die Datei ist zu groß.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Die Datei konnte nicht hochgeladen werden.');
        }
        $size = (int) filesize($tmp);
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('Die Datei ist zu groß (maximal ' . (int) ceil($maxBytes / 1024 / 1024) . ' MB).');
        }

        return (string) file_get_contents($tmp);
    }

    protected function uploadName(string $field): string
    {
        return strtolower((string) ($_FILES[$field]['name'] ?? ''));
    }

    /**
     * Normalised list filters for persons (shared by page + API controller).
     * FIX: values are type-checked (arrays/garbage no longer cause warnings)
     * and the page size is bounded to 1..100.
     *
     * @return array<string,mixed>
     */
    protected function personFilters(Request $request): array
    {
        return [
            'connection_id' => $request->queryInt('connection_id'),
            'page' => $request->queryInt('page') ?? 1,
            'page_size' => min(100, $request->queryInt('page_size') ?? 25),
            'search' => $request->queryString('search'),
            'status' => $request->queryString('status'),
            'group_id' => $request->queryString('group_id'),
            'card_filter' => $request->queryString('card_filter'),
            'sort' => $request->queryString('sort'),
        ];
    }

    /** @return array<string,mixed> */
    protected function credentialFilters(Request $request): array
    {
        return [
            'connection_id' => $request->queryInt('connection_id'),
            'page' => $request->queryInt('page') ?? 1,
            'page_size' => min(100, $request->queryInt('page_size') ?? 25),
            'search' => $request->queryString('search'),
            'status' => $request->queryString('status'),
            'card_filter' => $request->queryString('card_filter'),
        ];
    }
}
