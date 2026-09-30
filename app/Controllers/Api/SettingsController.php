<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class SettingsController extends ApiController
{
    private const EDITABLE = ['sync_interval_minutes', 'sync_enabled'];

    public function show(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->run(fn () => [
            'app_name' => Config::appName(),
            'debug' => Config::isDebug(),
            'mock' => Config::isMock(),
            'timezone' => date_default_timezone_get(),
            'php_version' => PHP_VERSION,
            'settings' => App::settings()->all(),
        ]);
    }

    public function update(Request $request): never
    {
        $this->authorize($request, Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        [$userId, $username] = $this->actor();
        $data = $request->all();
        $this->run(function () use ($data, $userId, $username) {
            $settings = App::settings();
            foreach ($data as $key => $value) {
                if (!in_array($key, self::EDITABLE, true)) {
                    continue;
                }
                $settings->set($key, is_scalar($value) ? (string) $value : null);
            }
            App::audit()->log('settings.update', 'settings', null, null, $data, null, $userId, $username);
            return ['settings' => App::settings()->all()];
        });
    }
}
