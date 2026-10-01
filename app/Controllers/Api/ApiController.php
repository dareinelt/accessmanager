<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Api\UniFiApiException;
use App\Controllers\BaseController;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;

/**
 * Base for JSON API controllers. Every response uses the {success,data}
 * envelope; every mutating route is CSRF-protected.
 */
abstract class ApiController extends BaseController
{
    protected function success(mixed $data = [], int $status = 200): never
    {
        Response::success($data, $status);
    }

    protected function error(string $message, string $code = 'ERROR', int $status = 400): never
    {
        Response::error($message, $code, $status);
    }

    /** Enforce authentication and an optional role for API requests. */
    protected function authorize(Request $request, string ...$roles): void
    {
        if (!Auth::check()) {
            $this->error('Nicht authentifiziert', 'UNAUTHENTICATED', 401);
        }
        if ($roles && !Auth::hasRole(...$roles)) {
            $this->error('Keine Berechtigung für diese Aktion', 'FORBIDDEN', 403);
        }
    }

    protected function requireCsrf(Request $request): void
    {
        if (!$this->csrfValid($request)) {
            $this->error('Ungültiges oder fehlendes CSRF-Token', 'CSRF_MISMATCH', 419);
        }
    }

    /** @return array{0:int,1:string} [userId, username] */
    protected function actor(): array
    {
        return [Auth::id(), Auth::username()];
    }

    /**
     * Run a closure and emit a success response; translate expected domain
     * errors into the correct JSON envelope.
     */
    protected function run(callable $fn): never
    {
        try {
            $this->success($fn());
        } catch (UniFiApiException $e) {
            $this->error($e->getMessage(), 'UNIFI_ERROR', 400);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 'VALIDATION', 422);
        } catch (\Throwable $e) {
            Logger::error('api', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Unerwarteter Serverfehler', 'SERVER_ERROR', 500);
        }
    }
}
