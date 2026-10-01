<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiClientInterface;
use App\Api\UniFiApiException;
use App\Core\Database;
use App\Core\Logger;
use App\Repositories\CacheRepository;
use App\Repositories\ConnectionRepository;
use App\Repositories\SyncLogRepository;

/**
 * Orchestrates a full pull from one (or all) UniFi controller(s) into the
 * local cache tables and records the outcome in sync_logs.
 */
final class SyncService
{
    private const PAGE_SIZE = 100;
    public const LOCK_TIMEOUT = 120;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly CacheRepository $cache,
        private readonly SyncLogRepository $syncLogs,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{results:array<int,array<string,mixed>>,ok:int,failed:int} */
    public function syncAll(?int $userId = null, ?string $username = null): array
    {
        $results = [];
        $ok = 0;
        $failed = 0;

        foreach ($this->connections->all() as $connection) {
            try {
                $results[] = $this->syncConnection($connection);
                $ok++;
            } catch (UniFiApiException $e) {
                $failed++;
                $results[] = [
                    'connection_id' => (int) $connection['id'],
                    'name' => $connection['name'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                Logger::error('sync', "Sync failed for {$connection['name']}: {$e->getMessage()}");
            }
        }

        $this->audit->log(
            action: 'sync.run',
            entityType: 'sync',
            details: ['connections' => $ok + $failed, 'ok' => $ok, 'failed' => $failed],
            userId: $userId,
            username: $username,
            result: $failed === 0 ? 'success' : 'failed',
        );

        return ['results' => $results, 'ok' => $ok, 'failed' => $failed];
    }

    /** @return array<string,mixed> */
    public function syncConnection(array $connection): array
    {
        $connectionId = (int) $connection['id'];

        $result = Database::withLock(
            self::lockName($connectionId),
            self::LOCK_TIMEOUT,
            fn (): array => $this->syncConnectionLocked($connection),
        );
        if ($result === null) {
            $message = 'Für diesen Standort läuft bereits eine Synchronisation. Bitte später erneut versuchen.';
            Logger::warning('sync', "Sync skipped for {$connection['name']}: lock busy");
            throw new UniFiApiException($message);
        }

        return $result;
    }

    /**
     * Name of the per-connection advisory lock shared by the UniFi sync and
     * the AD sync, so both never write the same connection concurrently.
     */
    public static function lockName(int $connectionId): string
    {
        return 'uam_connection_' . $connectionId;
    }

    /** @return array<string,mixed> */
    private function syncConnectionLocked(array $connection): array
    {
        $connectionId = (int) $connection['id'];
        $client = ApiClientFactory::forConnection($connection);

        $logId = $this->syncLogs->start($connectionId);

        try {
            $users = self::fetchAllPages(fn (int $page) => $client->getUsers(['page_num' => $page, 'page_size' => self::PAGE_SIZE]));
            $credentials = self::fetchAllPages(fn (int $page) => $client->getCredentials(['page_num' => $page, 'page_size' => self::PAGE_SIZE]));
            $groups = self::fetchAllPages(fn (int $page) => $client->getAccessGroups(['page_num' => $page, 'page_size' => self::PAGE_SIZE]));
            $doors = $client->getDoors()['data'] ?? [];

            // Transactional upsert + removal of stale rows (keeps AD metadata).
            $this->cache->replaceConnection($connectionId, $users, $credentials, $groups, $doors);

            $stats = [
                'users' => count($users),
                'credentials' => count($credentials),
                'access_groups' => count($groups),
                'doors' => count($doors),
            ];

            $this->syncLogs->finish($logId, 'success', null, $stats);
            Logger::info('sync', "Sync OK for {$connection['name']}: " . json_encode($stats));

            return [
                'connection_id' => $connectionId,
                'name' => $connection['name'],
                'success' => true,
                'stats' => $stats,
            ];
        } catch (\Throwable $e) {
            $message = $e instanceof UniFiApiException ? $e->getMessage() : 'Unerwarteter Fehler: ' . $e->getMessage();
            $this->syncLogs->finish($logId, 'failed', $message);
            Logger::error('sync', "Sync failed for {$connection['name']}: {$message}");
            if ($e instanceof UniFiApiException) {
                throw $e;
            }
            throw new UniFiApiException($message);
        }
    }

    /**
     * Page through a list endpoint until the API reports it is exhausted.
     *
     * @param callable(int):array $fetcher
     * @return array<int,array<string,mixed>>
     */
    public static function fetchAllPages(callable $fetcher): array
    {
        $all = [];
        $page = 1;

        while (true) {
            $res = $fetcher($page);
            $data = $res['data'] ?? [];
            $all = array_merge($all, $data);

            $pagination = $res['pagination'] ?? null;
            if (!is_array($pagination)) {
                break;
            }

            $total = (int) ($pagination['total'] ?? 0);
            if ($total === 0 || count($all) >= $total || count($data) === 0) {
                break;
            }
            $page++;
        }

        return $all;
    }
}
