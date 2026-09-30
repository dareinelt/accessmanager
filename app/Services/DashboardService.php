<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppUserRepository;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Repositories\SyncLogRepository;

/**
 * Aggregates the counters shown on the dashboard.
 */
final class DashboardService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly CatalogRepository $catalog,
        private readonly ConnectionRepository $connections,
        private readonly SyncLogRepository $syncLogs,
        private readonly AppUserRepository $appUsers,
    ) {
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        return [
            'locations' => $this->connections->count(),
            'persons' => $this->cache->countUsers(),
            'credentials' => $this->cache->countCredentials(),
            'free_credentials' => $this->cache->countFreeCredentials(),
            'access_groups' => $this->cache->countAccessGroups(),
            'doors' => $this->cache->countDoors(),
            'app_users' => $this->appUsers->count(),
            'locations_detail' => $this->catalog->locationStats(),
            'last_sync' => $this->syncLogs->lastSuccessful(),
            'last_sync_failed' => $this->syncLogs->lastFailed(),
            'recent_syncs' => $this->syncLogs->recent(5),
        ];
    }
}
