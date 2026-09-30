<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditRepository;

/**
 * Central audit-logging facade. Every mutating action in the app flows
 * through here so that the who/what/where/when is recorded consistently.
 */
final class AuditService
{
    public function __construct(private AuditRepository $audit)
    {
    }

    public function log(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $entityLabel = null,
        ?array $details = null,
        ?int $connectionId = null,
        ?int $userId = null,
        ?string $username = null,
        ?string $ip = null,
        string $result = 'success'
    ): void {
        if ($connectionId !== null) {
            $details = array_merge($details ?? [], ['connection_id' => $connectionId]);
        }
        $this->audit->log(
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            entityLabel: $entityLabel,
            result: $result,
            details: $details,
            userId: $userId,
            username: $username,
            ip: $ip,
        );
    }
}
