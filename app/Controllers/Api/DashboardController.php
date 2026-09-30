<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Request;

final class DashboardController extends ApiController
{
    public function stats(Request $request): never
    {
        $this->authorize($request);
        $this->run(fn () => App::dashboard()->stats());
    }
}
