<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;
use App\Security\Csrf;
use InvalidArgumentException;

/**
 * „Backup & Wiederherstellung“: On-Demand-Download, geplante/automatisch
 * gespeicherte Archive sowie die Wiederherstellung aus einer JSON-Datei
 * (Vorschau + Bestätigung). Zugriff nur für Administratoren.
 */
final class BackupController extends BaseController
{
    private const PENDING_PATH = 'backup_pending_path';
    private const PENDING_META = 'backup_pending_meta';

    public function index(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->render($request);
    }

    public function download(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        App::audit()->log('backup.download', 'backup', null, 'Backup heruntergeladen', username: Auth::username());
        $filename = 'backup_' . date('Ymd_His') . '_' . $this->slug(Auth::username()) . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo App::backup()->toJson(Auth::username());
        exit;
    }

    public function downloadFile(Request $request, array $params): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $path = App::backup()->filePath((string) ($params['filename'] ?? ''));
        if ($path === null) {
            $this->setFlash('error', 'Das Archiv wurde nicht gefunden.');
            Response::redirect('/backup');
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    public function create(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $metadata = App::backup()->createArchiveFile(Auth::username());
        App::audit()->log('backup.create', 'backup', $metadata['filename'], 'Backup-Archiv erstellt', username: Auth::username());
        $this->setFlash('success', 'Das Backup wurde erstellt und in der Archivliste gespeichert.');
        Response::redirect('/backup');
    }

    public function delete(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $filename = (string) $request->input('filename', '');
        App::backup()->deleteFile($filename);
        App::audit()->log('backup.delete', 'backup', $filename, 'Backup-Archiv gelöscht', username: Auth::username());
        $this->setFlash('success', 'Das Archiv wurde gelöscht.');
        Response::redirect('/backup');
    }

    public function previewRestore(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $file = $_FILES['backup_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $this->restoreError($error === UPLOAD_ERR_NO_FILE
                ? 'Bitte wählen Sie eine Backup-Datei (.json) aus.'
                : 'Die Datei konnte nicht hochgeladen werden.');
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($name, '.json')) {
            $this->restoreError('Bitte wählen Sie eine Backup-Datei im JSON-Format (.json) aus.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $raw = is_uploaded_file($tmp) || is_file($tmp) ? (string) file_get_contents($tmp) : '';
        if ($raw === '') {
            $this->restoreError('Die hochgeladene Datei ist leer.');
        }

        try {
            $snapshot = App::backup()->decode($raw);
        } catch (InvalidArgumentException $exception) {
            $this->restoreError($exception->getMessage());
        }

        $pendingPath = App::backup()->storageDir() . '/.pending_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($pendingPath, $raw);
        Session::put(self::PENDING_PATH, $pendingPath);
        Session::put(self::PENDING_META, [
            'filename' => (string) ($file['name'] ?? ''),
            'size' => strlen($raw),
        ]);

        Response::redirect('/backup?vorschau=1');
    }

    public function confirmRestore(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $pendingPath = Session::get(self::PENDING_PATH);
        $raw = is_string($pendingPath) && is_file($pendingPath) ? (string) file_get_contents($pendingPath) : '';
        $this->discardPending();

        if ($raw === '') {
            $this->setFlash('error', 'Es liegt keine Wiederherstellung zur Bestätigung vor. Bitte erneut hochladen.');
            Response::redirect('/backup');
        }

        try {
            $snapshot = App::backup()->decode($raw);
            $result = App::backup()->restore($snapshot, Auth::id(), Auth::username());
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', 'Die Wiederherstellung konnte nicht ausgeführt werden: ' . $exception->getMessage());
            Response::redirect('/backup');
        }

        $this->setFlash('success', 'Die Einstellungen wurden aus dem Backup wiederhergestellt (' . $result['restored_tables'] . ' Tabellen).');
        Response::redirect('/backup');
    }

    public function discardRestore(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        $this->discardPending();
        $this->setFlash('success', 'Die Wiederherstellung wurde abgebrochen. Es wurde nichts geändert.');
        Response::redirect('/backup');
    }

    private function discardPending(): void
    {
        $path = Session::get(self::PENDING_PATH);
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
        Session::forget(self::PENDING_PATH);
        Session::forget(self::PENDING_META);
    }

    private function restoreError(string $message): never
    {
        $this->discardPending();
        $this->setFlash('error', $message);
        Response::redirect('/backup');
    }

    private function requireCsrf(Request $request): void
    {
        $token = $request->input('_csrf');
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            $this->setFlash('error', 'Ihre Sitzung ist abgelaufen. Bitte erneut versuchen.');
            Response::redirect('/backup');
        }
    }

    /** @param array<string,mixed> $data */
    private function render(Request $request, array $data = []): void
    {
        $service = App::backup();

        $preview = null;
        $pendingMeta = null;
        if ($request->query('vorschau') === '1') {
            $pendingPath = Session::get(self::PENDING_PATH);
            $pendingMeta = Session::get(self::PENDING_META);
            if (is_string($pendingPath) && is_file($pendingPath)) {
                try {
                    $preview = $service->preview($service->decode((string) file_get_contents($pendingPath)));
                } catch (InvalidArgumentException $exception) {
                    $this->discardPending();
                    $this->setFlash('error', $exception->getMessage());
                }
            }
        }

        $settings = App::settings()->all();
        $this->view('pages/backup/index', array_merge($data, [
            'appName' => Config::appName(),
            'currentUser' => $this->currentUser(),
            'section' => 'backup',
            'flash' => $this->flash(),
            'archives' => $service->listArchives(),
            'settings' => $settings,
            'scheduleDue' => $service->isDue(),
            'preview' => $preview,
            'pendingMeta' => is_array($pendingMeta) ? $pendingMeta : null,
        ]));
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        return $slug !== '' && $slug !== '-' ? $slug : 'manual';
    }
}
