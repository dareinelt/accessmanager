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
use App\Services\Tls\TlsCertificateService;
use InvalidArgumentException;

/**
 * Adminbereich „Zertifikate (HTTPS)“: CSR erstellen, Zertifikat importieren
 * (Vorschau + Bestaetigung), aktives Zertifikat waehlen. Solange kein
 * gueltiges Zertifikat aktiv ist, wird ein selbstsigniertes Notfall-Zertifikat
 * ausgeliefert – HTTPS ist damit per Default aktiv.
 */
final class CertificateController extends BaseController
{
    private const PENDING_KEY = 'tls_pending_import';

    public function index(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->render($request);
    }

    public function createRequest(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $fields = ['common_name', 'san', 'organization', 'organizational_unit', 'locality', 'state', 'country', 'email', 'key_type'];
        $input = [];
        foreach ($fields as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        try {
            $id = App::tls()->createRequest($input, Auth::username());
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', 'Der Request konnte nicht erstellt werden: ' . $exception->getMessage());
            $this->render($request, ['csrValues' => $input], 422);

            return;
        }

        App::audit()->log('certificate.request', 'certificate', (string) $id, $input['common_name'], username: Auth::username());
        $this->setFlash('success', 'Der Request wurde erstellt. Laden Sie den CSR herunter und lassen Sie ihn von Ihrer Zertifizierungsstelle signieren.');

        Response::redirect('/certificates');
    }

    public function downloadCsr(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $download = App::tls()->csrDownload((int) $request->query('id', 0));
        if ($download === null) {
            $this->setFlash('error', 'Der Request wurde nicht gefunden.');
            Response::redirect('/certificates');
        }

        header('Content-Type: application/pkcs10');
        header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
        header('Cache-Control: no-store');
        echo $download['content'];
        exit;
    }

    public function previewImport(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $raw = (string) ($request->input('certificate_text', ''));
        $file = $_FILES['certificate_file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            $name = strtolower((string) ($file['name'] ?? ''));
            if (preg_match('/\.(pem|crt|cer)$/', $name) !== 1) {
                $this->importError('Bitte eine Datei im Format PEM oder CRT auswählen (.pem, .crt).');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            $raw = is_uploaded_file($tmp) || is_file($tmp) ? (string) file_get_contents($tmp, false, null, 0, 262145) : '';
        } elseif ($error !== UPLOAD_ERR_NO_FILE) {
            $this->importError('Die Datei konnte nicht hochgeladen werden.');
        }

        try {
            $preview = App::tls()->previewImport($raw);
        } catch (InvalidArgumentException $exception) {
            $this->importError($exception->getMessage());
        }

        Session::put(self::PENDING_KEY, $preview['pending']);
        Response::redirect('/certificates?vorschau=1');
    }

    public function confirmImport(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        $pending = Session::get(self::PENDING_KEY);
        Session::forget(self::PENDING_KEY);
        if (!is_array($pending)) {
            $this->setFlash('error', 'Es liegt kein Zertifikat zur Bestätigung vor. Bitte erneut importieren.');
            Response::redirect('/certificates');
        }

        try {
            $result = App::tls()->confirmImport($pending, $request->input('activate') === '1', Auth::username());
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
            Response::redirect('/certificates');
        }

        App::audit()->log('certificate.import', 'certificate', (string) $result['id'], 'Import bestätigt', username: Auth::username());
        $this->setFlash('success', $result['activated']
            ? 'Das Zertifikat wurde importiert und ist aktiv. Der Webserver übernimmt es innerhalb weniger Sekunden.'
            : 'Das Zertifikat wurde importiert. Aktivieren Sie es in der Tabelle, sobald es verwendet werden soll.');

        Response::redirect('/certificates');
    }

    public function discardImport(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        Session::forget(self::PENDING_KEY);
        $this->setFlash('success', 'Der Import wurde abgebrochen. Es wurde nichts geändert.');
        Response::redirect('/certificates');
    }

    public function activate(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        $id = (int) $request->input('id', 0);

        try {
            App::tls()->activate($id);
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
            Response::redirect('/certificates');
        }

        App::audit()->log('certificate.activate', 'certificate', (string) $id, 'Zertifikat aktiviert', username: Auth::username());
        $this->setFlash('success', 'Das Zertifikat ist jetzt aktiv. Der Webserver übernimmt es innerhalb weniger Sekunden.');

        Response::redirect('/certificates');
    }

    public function deactivate(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);
        App::tls()->deactivate();

        App::audit()->log('certificate.deactivate', 'certificate', '', 'Zertifikat deaktiviert', username: Auth::username());
        $this->setFlash('success', 'Das Zertifikat wurde deaktiviert. Bis zur Aktivierung eines anderen Zertifikats wird das Notfall-Zertifikat verwendet.');

        Response::redirect('/certificates');
    }

    public function delete(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $this->requireCsrf($request);

        try {
            App::tls()->deleteRequest((int) $request->input('id', 0));
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
            Response::redirect('/certificates');
        }

        $this->setFlash('success', 'Der Request wurde gelöscht.');
        Response::redirect('/certificates');
    }

    private function importError(string $message): never
    {
        Session::forget(self::PENDING_KEY);
        $this->setFlash('error', $message);
        Response::redirect('/certificates');
    }

    private function requireCsrf(Request $request): void
    {
        $token = $request->input('_csrf');
        if (!Csrf::validate(is_string($token) ? $token : null)) {
            $this->setFlash('error', 'Ihre Sitzung ist abgelaufen. Bitte erneut versuchen.');
            Response::redirect('/certificates');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(Request $request, array $data = [], int $status = 200): void
    {
        $service = App::tls();

        $preview = null;
        $pending = Session::get(self::PENDING_KEY);
        if ($request->query('vorschau') === '1' && is_array($pending)) {
            try {
                $preview = $service->previewImport(implode("\n", array_filter((array) ($pending['certificates'] ?? []), 'is_string')));
            } catch (InvalidArgumentException $exception) {
                Session::forget(self::PENDING_KEY);
                $this->setFlash('error', $exception->getMessage());
            }
        }

        $values = array_merge($service->csrDefaults(), $data['csrValues'] ?? []);

        http_response_code($status);
        $this->view('pages/certificates/index', array_merge($data, [
            'appName' => Config::appName(),
            'currentUser' => $this->currentUser(),
            'section' => 'certificates',
            'flash' => $this->flash(),
            'state' => $service->state(),
            'rows' => $service->overview(),
            'keyTypes' => TlsCertificateService::keyTypes(),
            'csrValues' => $values,
            'preview' => $preview,
        ]));
    }
}
