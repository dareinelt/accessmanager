<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;

final class AuditController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request, \App\Security\Auth::ROLE_ADMIN);
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(100, max(1, (int) $request->query('page_size', 25)));
        $filter = (string) $request->query('search', '');
        $this->run(fn () => App::auditRepository()->list($page, $pageSize, $filter !== '' ? $filter : null));
    }
}
