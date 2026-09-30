<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use App\Core\App;
use App\Core\Session;
use App\Repositories\BackupRepository;
use InvalidArgumentException;

/**
 * Backup and restore of all configurable data plus the cached UniFi data.
 *
 * The archive is an unencrypted, human-readable JSON document (see README).
 * Encrypted database values (API tokens, system secrets, private keys) are
 * exported verbatim as ciphertext and therefore remain bound to the APP_SECRET
 * of the installation that produced the archive.
 */
final class BackupService
{
    public const FORMAT = 'accessmanager-backup';
    public const VERSION = 1;

    private const DEFAULT_RETENTION = 10;

    public function __construct(private readonly BackupRepository $repository)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(?string $actor = null): array
    {
        $snapshot = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => date('c'),
            'app_name' => Config::appName(),
            'tables' => $this->repository->exportTables(),
        ];
        if ($actor !== null && $actor !== '') {
            $snapshot['created_by'] = $actor;
        }
        return $snapshot;
    }

    public function toJson(?string $actor = null): string
    {
        return json_encode(
            $this->snapshot($actor),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Decode and validate an uploaded archive.
     *
     * @return array<string,mixed>
     */
    public function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Die Datei ist kein gültiges JSON-Archiv.');
        }
        return $this->validate($data);
    }

    /** @return array<string,mixed> */
    public function validate(array $data): array
    {
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Ungültiges Archivformat (erwartet: ' . self::FORMAT . ').');
        }
        $version = (int) ($data['version'] ?? 0);
        if ($version < 1 || $version > self::VERSION) {
            throw new InvalidArgumentException('Diese Archivversion wird nicht unterstützt.');
        }
        if (!is_array($data['tables'] ?? null)) {
            throw new InvalidArgumentException('Das Archiv enthält keine Tabellendaten.');
        }
        foreach (array_keys($data['tables']) as $table) {
            if (!is_string($table) || !in_array($table, BackupRepository::TABLES, true)) {
                throw new InvalidArgumentException('Unbekannte Tabelle im Archiv: ' . (string) $table);
            }
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function preview(array $snapshot): array
    {
        $counts = [];
        $total = 0;
        foreach (BackupRepository::TABLES as $table) {
            $count = is_array($snapshot['tables'][$table] ?? null) ? count($snapshot['tables'][$table]) : 0;
            $counts[$table] = $count;
            $total += $count;
        }
        return [
            'created_at' => is_string($snapshot['created_at'] ?? null) ? $snapshot['created_at'] : null,
            'app_name' => is_string($snapshot['app_name'] ?? null) ? $snapshot['app_name'] : null,
            'created_by' => is_string($snapshot['created_by'] ?? null) ? $snapshot['created_by'] : null,
            'counts' => $counts,
            'total' => $total,
        ];
    }

    /**
     * Replace the current data with the snapshot. The currently authenticated
     * user is preserved so an admin cannot lock themselves out.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function restore(array $snapshot, int $actorId, string $actorUsername): array
    {
        $captured = $this->repository->findUserRow($actorId);

        $this->repository->restoreTables($snapshot['tables']);
        $this->repository->ensureRoles();

        if ($captured !== null) {
            $current = $this->repository->findUserByUsername((string) $captured['username']);
            if ($current === null) {
                $id = $this->repository->insertUserRow($captured);
                $current = ['id' => $id] + $captured;
            }
            // Re-anchor the session to the (possibly changed) user row.
            Session::put('user_id', (int) $current['id']);
            Session::put('username', (string) $current['username']);
            Session::put('role', (string) $current['role']);
        }

        App::audit()->log(
            'backup.restore',
            'backup',
            null,
            'Einstellungen wiederhergestellt',
            ['tables' => count($snapshot['tables']), 'rows' => $this->countRows($snapshot['tables'])],
            null,
            $actorId,
            $actorUsername,
        );

        return ['restored_tables' => count($snapshot['tables'])];
    }

    // ------------------------------------------------------------------
    // Scheduled / stored archives
    // ------------------------------------------------------------------

    public function storageDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array<string,mixed> */
    public function createArchiveFile(string $actor): array
    {
        $dir = $this->storageDir();
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $actor));
        if ($slug === '' || $slug === '-') {
            $slug = 'manual';
        }
        $filename = 'backup_' . date('Ymd_His') . '_' . $slug . '.json';
        $snapshot = $this->snapshot($actor);
        $json = json_encode(
            $snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        file_put_contents($dir . '/' . $filename, $json);
        return [
            'filename' => $filename,
            'size' => strlen($json),
            'created_at' => $snapshot['created_at'],
            'created_by' => $actor,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listArchives(): array
    {
        $files = glob($this->storageDir() . '/backup_*.json') ?: [];
        $out = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match('/^backup_(\d{8}_\d{6})_([A-Za-z0-9_-]+)\.json$/', $name, $m) !== 1) {
                continue;
            }
            $createdAt = \DateTime::createFromFormat('Ymd_His', $m[1]);
            $out[] = [
                'filename' => $name,
                'created_at' => $createdAt !== false ? $createdAt->format('Y-m-d H:i:s') : '–',
                'created_by' => $m[2],
                'size' => (int) (filesize($file) ?: 0),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return $out;
    }

    public function filePath(string $filename): ?string
    {
        if (preg_match('/^backup_\d{8}_\d{6}_[A-Za-z0-9_-]+\.json$/', $filename) !== 1) {
            return null;
        }
        $path = $this->storageDir() . '/' . $filename;
        return is_file($path) ? $path : null;
    }

    public function deleteFile(string $filename): void
    {
        $path = $this->filePath($filename);
        if ($path !== null) {
            unlink($path);
        }
    }

    /** Remove archives beyond the configured retention window. */
    public function prune(int $retention): int
    {
        $retention = max(0, $retention);
        if ($retention === 0) {
            return 0;
        }
        $archives = $this->listArchives();
        $removed = 0;
        foreach (array_slice($archives, $retention) as $archive) {
            $this->deleteFile((string) $archive['filename']);
            $removed++;
        }
        return $removed;
    }

    public function isDue(): bool
    {
        $settings = App::settings();
        if ($settings->get('backup_enabled', '0') !== '1') {
            return false;
        }
        $interval = max(1, (int) ($settings->get('backup_interval_minutes', '1440') ?? 1440));
        $last = $settings->get('backup_last_run');
        if ($last === null || $last === '') {
            return true;
        }
        $lastTs = strtotime($last);
        if ($lastTs === false) {
            return true;
        }
        return (time() - $lastTs) >= ($interval * 60);
    }

    /**
     * Create a scheduled archive when due. Returns the archive metadata, or
     * null when no backup was necessary.
     *
     * @return array<string,mixed>|null
     */
    public function runScheduled(): ?array
    {
        if (!$this->isDue()) {
            return null;
        }
        $metadata = $this->createArchiveFile('auto');
        $settings = App::settings();
        $settings->set('backup_last_run', date('c'));
        $retention = max(0, (int) ($settings->get('backup_retention', (string) self::DEFAULT_RETENTION) ?? self::DEFAULT_RETENTION));
        $metadata['pruned'] = $this->prune($retention);
        App::audit()->log('backup.create', 'backup', $metadata['filename'], 'Automatisches Backup erstellt', null, null, null, 'auto');
        return $metadata;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $tables
     */
    private function countRows(array $tables): int
    {
        $total = 0;
        foreach ($tables as $rows) {
            if (is_array($rows)) {
                $total += count($rows);
            }
        }
        return $total;
    }
}
