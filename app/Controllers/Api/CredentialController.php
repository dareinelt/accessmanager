<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;

final class CredentialController extends ApiController
{
    public function list(Request $request): never
    {
        $this->authorize($request);
        // FIX: page_size was unbounded (DoS via ?page_size=10000000).
        $filters = $this->credentialFilters($request);
        $this->run(fn () => App::credentials()->list($filters));
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request);
        $this->run(fn () => App::credentials()->get((int) $params['connection_id'], (string) $params['token']));
    }
}
