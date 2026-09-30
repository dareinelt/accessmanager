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
    public function persons(Request $request): void
    {
        Auth::requireLogin();
        $filters = [
            'connection_id' => $request->query('connection_id'),
            'page' => 1,
            'page_size' => 100000,
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'group_id' => $request->query('group_id'),
            'card_filter' => $request->query('card_filter'),
            'sort' => $request->query('sort'),
        ];
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
        $filters = [
            'connection_id' => $request->query('connection_id'),
            'page' => 1,
            'page_size' => 100000,
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'card_filter' => $request->query('card_filter'),
        ];
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
        Auth::requireRole(Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR);
        $connectionId = $request->query('connection_id');
        $rows = App::adMappings()->findNonCompliant($connectionId !== null && $connectionId !== '' ? (int) $connectionId : null);

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
        $search = (string) $request->query('search', '');
        $result = App::auditRepository()->list(1, 100000, $search !== '' ? $search : null);

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
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
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
