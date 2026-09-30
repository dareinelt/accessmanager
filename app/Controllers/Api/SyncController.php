<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;
use App\Repositories\SyncLogRepository;

final class SyncController extends ApiController
{
    public function status(Request $request): never
    {
        $this->authorize($request);
        $repo = new SyncLogRepository();
        $this->run(fn () => [
            'recent' => $repo->recent(15),
            'last_success' => $repo->lastSuccessful(),
            'last_failed' => $repo->lastFailed(),
        ]);
    }

    public function run(Request $request): never
    {
        $this->authorize($request, \App\Security\Auth::ROLE_ADMIN, \App\Security\Auth::ROLE_OPERATOR);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $this->run(fn () => App::sync()->syncAll($userId, $username));
    }
}
