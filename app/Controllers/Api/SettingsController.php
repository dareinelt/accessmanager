<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

final class SettingsController extends ApiController
{
    /** Editable keys: type + allowed range (ints) */
    private const EDITABLE = [
        'sync_interval_minutes' => ['int', 1, 10080],
        'sync_enabled' => ['bool'],
        'backup_enabled' => ['bool'],
        'backup_interval_minutes' => ['int', 5, 525600],
        'backup_retention' => ['int', 0, 1000],
    ];

    private const LABELS = [
        'sync_interval_minutes' => 'Sync-Intervall (Minuten)',
        'backup_interval_minutes' => 'Backup-Intervall (Minuten)',
        'backup_retention' => 'Anzahl aufzubewahrender Backups',
    ];

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
            // SECURITY/FUNCTION FIX: values were stored unvalidated (e.g. negative
            // or non-numeric intervals, arbitrary strings for booleans).
            $clean = [];
            foreach (self::EDITABLE as $key => $rule) {
                if (array_key_exists($key, $data)) {
                    $clean[$key] = $this->normalize($key, $data[$key], $rule);
                }
            }
            $settings = App::settings();
            foreach ($clean as $key => $value) {
                $settings->set($key, $value);
            }
            App::audit()->log('settings.update', 'settings', null, null, $clean, null, $userId, $username);
            return ['settings' => App::settings()->all()];
        });
    }

    /** @param array{0:string,1?:int,2?:int} $rule */
    private function normalize(string $key, mixed $value, array $rule): string
    {
        if ($rule[0] === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $rule[1], 'max_range' => $rule[2]]]);
        if ($int === false) {
            throw new \InvalidArgumentException(sprintf('%s: bitte eine ganze Zahl zwischen %d und %d angeben.', self::LABELS[$key] ?? $key, $rule[1], $rule[2]));
        }
        return (string) $int;
    }
}
