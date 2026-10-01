<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Security\Auth;

/**
 * CSV export endpoints. Output is streamed directly to the browser as a
 * downloadable, UTF-8 (with BOM for Excel) text file.
 */
final class ExportController extends BaseController
{
    private const MAX_ROWS = 100000;

    public function persons(Request $request): void
    {
        Auth::requireLogin();
        $filters = array_merge($this->personFilters($request), ['page' => 1, 'page_size' => self::MAX_ROWS]);
        $result = App::persons()->list($filters);
        $groupNames = $this->groupNameMap();

        $rows = [];
        foreach ($result['items'] as $p) {
            $ids = json_decode($p['access_policy_ids_json'] ?? '[]', true) ?: [];
            $groupList = [];
            foreach ($ids as $id) {
                $groupList[] = $groupNames[$id] ?? $id;
            }
            $rows[] = [
                $p['full_name'] ?? '',
                $p['first_name'] ?? '',
                $p['last_name'] ?? '',
                $p['email'] ?? '',
                $p['employee_number'] ?? '',
                $p['status'] ?? '',
                $p['connection_name'] ?? '',
                $p['cards'] ?? '',
                implode('; ', $groupList),
            ];
        }

        $this->download(
            'personen.csv',
            ['Name', 'Vorname', 'Nachname', 'E-Mail', 'Personalnummer', 'Status', 'Standort', 'Karten', 'Zutrittsgruppen'],
            $rows,
        );
    }

    public function credentials(Request $request): void
    {
        Auth::requireLogin();
        $filters = array_merge($this->credentialFilters($request), ['page' => 1, 'page_size' => self::MAX_ROWS]);
        $result = App::credentials()->list($filters);

        $rows = [];
        foreach ($result['items'] as $c) {
            $rows[] = [
                $c['display_id'] ?? '',
                $c['unifi_token'] ?? '',
                $c['status'] ?? '',
                $c['alias'] ?? '',
                $c['card_type'] ?? '',
                $c['person_name'] ?? '',
                $c['connection_name'] ?? '',
            ];
        }

        $this->download(
            'karten.csv',
            ['Karte', 'Token', 'Status', 'Alias', 'Typ', 'Person', 'Standort'],
            $rows,
        );
    }

    /**
     * Reconciliation / drift export: Personen, die in Access eine gemappte
     * Zutrittsgruppe besitzen, aber nicht (mehr) Mitglied der zugeordneten
     * AD-Gruppe sind.
     */
    public function adNonCompliance(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_OPERATOR);
        $rows = App::adMappings()->findNonCompliant($request->queryInt('connection_id'));

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $r['connection_name'] ?? '',
                $r['full_name'] ?? '',
                $r['email'] ?? '',
                $r['access_group_name'] ?? '',
                $r['ad_group_name'] ?? '',
                $r['ad_group_dn'] ?? '',
            ];
        }

        $this->download(
            'ad-datenabweichung.csv',
            ['Standort', 'Person', 'E-Mail', 'Zutrittsgruppe', 'AD-Gruppe', 'AD-Gruppen-DN'],
            $data,
        );
    }

    public function audit(Request $request): void
    {
        Auth::requireRole(Auth::ROLE_ADMIN);
        $search = $request->queryString('search');
        $result = App::auditRepository()->list(1, self::MAX_ROWS, $search);

        $rows = [];
        foreach ($result['items'] as $a) {
            $rows[] = [
                $a['created_at'] ?? '',
                $a['username'] ?? '',
                $a['action'] ?? '',
                $a['entity_type'] ?? '',
                $a['entity_label'] ?? '',
                $a['result'] ?? '',
                $a['ip_address'] ?? '',
            ];
        }

        $this->download(
            'auditlog.csv',
            ['Zeitpunkt', 'Benutzer', 'Aktion', 'Entitätstyp', 'Entität', 'Ergebnis', 'IP-Adresse'],
            $rows,
        );
    }

    /**
     * @param array<int,string> $header
     * @param array<int,array<int,string>> $rows
     */
    private function download(string $filename, array $header, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($out, $header, ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvCell'], $row), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /**
     * SECURITY FIX (CSV/formula injection): cells starting with = + - @ TAB CR
     * are executed as formulas by Excel/LibreOffice. Data originates from
     * UniFi/AD/users, so such cells are prefixed with an apostrophe.
     */
    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /** @return array<string,string> */
    private function groupNameMap(): array
    {
        $map = [];
        foreach (App::groups()->list() as $group) {
            $map[$group['unifi_id']] = $group['name'];
        }
        return $map;
    }
}
