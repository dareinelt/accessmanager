<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\AdGroupMappingRepository;
use App\Repositories\AppUserRepository;
use App\Repositories\AuditRepository;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\TlsCertificateRepository;
use App\Services\AccessGroupService;
use App\Services\AdSyncService;
use App\Services\AppUserService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CredentialService;
use App\Services\DashboardService;
use App\Services\DoorService;
use App\Services\Ldap\LdapClientFactory;
use App\Services\Ldap\LdapClientInterface;
use App\Services\PersonService;
use App\Services\SiteService;
use App\Services\SyncService;
use App\Services\Tls\TlsCertificateService;

/**
 * Minimal service locator for the application. No framework, no magic —
 * every service is lazily constructed once and its dependencies are wired
 * explicitly here.
 */
final class App
{
    /** @var array<class-string,object> */
    private static array $services = [];

    public static function audit(): AuditService
    {
        return self::$services['audit'] ??= new AuditService(new AuditRepository());
    }

    public static function auth(): AuthService
    {
        return self::$services['auth'] ??= new AuthService(self::audit());
    }

    public static function persons(): PersonService
    {
        return self::$services['persons'] ??= new PersonService(
            new CatalogRepository(),
            new CacheRepository(),
            new ConnectionRepository(),
            self::audit(),
        );
    }

    public static function credentials(): CredentialService
    {
        return self::$services['credentials'] ??= new CredentialService(new CatalogRepository());
    }

    public static function groups(): AccessGroupService
    {
        return self::$services['groups'] ??= new AccessGroupService(
            new CatalogRepository(),
            new CacheRepository(),
            new ConnectionRepository(),
            self::audit(),
        );
    }

    public static function doors(): DoorService
    {
        return self::$services['doors'] ??= new DoorService(
            new CatalogRepository(),
            new ConnectionRepository(),
            self::audit(),
        );
    }

    public static function sites(): SiteService
    {
        return self::$services['sites'] ??= new SiteService(
            new ConnectionRepository(),
            new CatalogRepository(),
            self::audit(),
        );
    }

    public static function sync(): SyncService
    {
        return self::$services['sync'] ??= new SyncService(
            new ConnectionRepository(),
            new CacheRepository(),
            new SyncLogRepository(),
            self::audit(),
        );
    }

    public static function dashboard(): DashboardService
    {
        return self::$services['dashboard'] ??= new DashboardService(
            new CacheRepository(),
            new CatalogRepository(),
            new ConnectionRepository(),
            new SyncLogRepository(),
            new AppUserRepository(),
        );
    }

    public static function appUsers(): AppUserService
    {
        return self::$services['app_users'] ??= new AppUserService(new AppUserRepository(), self::audit());
    }

    public static function settings(): SettingsRepository
    {
        return self::$services['settings'] ??= new SettingsRepository();
    }

    public static function auditRepository(): AuditRepository
    {
        return self::$services['audit_repo'] ??= new AuditRepository();
    }

    public static function tls(): TlsCertificateService
    {
        return self::$services['tls'] ??= new TlsCertificateService(
            new TlsCertificateRepository(),
            (string) \App\Config\Config::get('APP_URL', 'https://localhost:8443'),
        );
    }

    public static function ldap(): LdapClientInterface
    {
        return self::$services['ldap'] ??= LdapClientFactory::create();
    }

    public static function adMappings(): AdGroupMappingRepository
    {
        return self::$services['ad_mappings'] ??= new AdGroupMappingRepository();
    }

    public static function ad(): AdSyncService
    {
        return self::$services['ad'] ??= new AdSyncService(
            self::ldap(),
            self::adMappings(),
            new CatalogRepository(),
            new CacheRepository(),
            new ConnectionRepository(),
            self::persons(),
            self::audit(),
        );
    }
}
