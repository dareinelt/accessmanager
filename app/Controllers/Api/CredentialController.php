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
        $filters = [
            'connection_id' => $request->query('connection_id'),
            'page' => (int) $request->query('page', 1),
            'page_size' => (int) $request->query('page_size', 25),
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'card_filter' => $request->query('card_filter'),
        ];
        $this->run(fn () => App::credentials()->list($filters));
    }

    public function detail(Request $request, array $params): never
    {
        $this->authorize($request);
        $this->run(fn () => App::credentials()->get((int) $params['connection_id'], (string) $params['token']));
    }
}
