<?php

declare(strict_types=1);

namespace Plugins\Commands;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Commands\Migrate\CliCommandFactory as MigrateFactory;
use Plugins\Commands\Configuration\EnvironmentConfigurationLoader;
use Plugins\Commands\Exceptions\ConfigurationException;
use Plugins\Commands\Configuration\ConfigurationValidator;
use Plugins\Commands\Infrastructure\Http\Commands\RouteListCommand;
use Plugins\Commands\Application\Services\MigrationService;
use Plugins\Commands\API\Contracts\MigrationServiceContract;
use Plugins\Commands\Infrastructure\Persistence\{
    MigrationRepository,
    DeploymentLockRepository,
    CommandAuditLogRepository,
    BackupRepository,
    ApprovalRepository,
};
use Plugins\Commands\Infrastructure\Gateways\LetMigrateGateway;
use Plugins\Commands\Application\Services\CommandsInfrastructureService;
use Plugins\Commands\Logging\CommandExecutionLogger;
use Plugins\Commands\Logging\FallbackFileLogger;
use Plugins\Commands\Deployment\DeploymentLockManager;
use Plugins\Commands\Backup\BackupManager;
use Plugins\Commands\Approval\MigrationApprovalManager;
use Plugins\Commands\Validation\PreFlightValidator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * CommandsProvider — registers framework infrastructure commands with enterprise safeguards.
 *
 * Solves: system.commands (framework-level commands for infrastructure)
 *
 * Commands registered:
 *   • migrate:* (25+) — database migrations via LetMigrate
 *   • make:* — scaffold new migrations, seeders, factories
 *   • seed:run — execute database seeders
 *   • tenant:* — multi-tenant migration variants
 *
 * Enterprise Features:
 *   ✅ Configuration validation — catches errors at boot time
 *   ✅ Environment-specific configs — dev/staging/prod isolation
 *   ✅ Deployment locks — prevents concurrent migrations
 *   ✅ Command logging — audit trail for compliance
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'system.commands';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
        // Get project root for repositories
        $projectRoot = dirname(__DIR__, 2);

        // Register data access repositories (use DatabasePort)
        $container->singleton(DeploymentLockRepository::class, fn($c) =>
            new DeploymentLockRepository(
                $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class)
            )
        );
        $container->singleton(CommandAuditLogRepository::class, fn($c) =>
            new CommandAuditLogRepository(
                $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class)
            )
        );
        $container->singleton(BackupRepository::class, fn($c) =>
            new BackupRepository(
                $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class)
            )
        );
        $container->singleton(ApprovalRepository::class, fn($c) =>
            new ApprovalRepository(
                $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class)
            )
        );
        $container->singleton(MigrationRepository::class, fn($c) =>
            new MigrationRepository(
                $c->make(LetMigrateGateway::class),
                $projectRoot
            )
        );

        // Register single infrastructure service that aggregates all repositories
        $container->singleton(CommandsInfrastructureService::class, fn($c) =>
            new CommandsInfrastructureService(
                $c->make(DeploymentLockRepository::class),
                $c->make(CommandAuditLogRepository::class),
                $c->make(BackupRepository::class),
                $c->make(ApprovalRepository::class),
                $c->make(MigrationRepository::class),
            )
        );

        // Register enterprise feature classes (all use the single infrastructure service!)
        //
        // NOTE: this used to bind Psr\Log\LoggerInterface to a NullLogger — the
        // ONLY logger binding in the codebase — so every command-audit line, and
        // every line the Database/Tenancy/EventBus components wrote, was silently
        // discarded. Resolve the real LoggerPort instead, and fall back to a
        // file-backed logger of our own rather than to nothing — see logger().
        $container->singleton(CommandExecutionLogger::class, fn($c) =>
            new CommandExecutionLogger(self::logger($c))
        );
        $container->singleton(DeploymentLockManager::class, fn($c) =>
            new DeploymentLockManager($c->make(CommandsInfrastructureService::class))
        );
        $container->singleton(BackupManager::class, fn($c) =>
            new BackupManager()
        );
        $container->singleton(MigrationApprovalManager::class, fn($c) =>
            new MigrationApprovalManager($c->make(CommandsInfrastructureService::class))
        );
        $container->singleton(PreFlightValidator::class, fn($c) =>
            new PreFlightValidator($c->make(CommandsInfrastructureService::class))
        );

        // Register gateways
        $container->singleton(LetMigrateGateway::class, fn($c) =>
            new LetMigrateGateway()
        );

        // Register public service contracts
        $container->bind(MigrationServiceContract::class, fn($c) =>
            new MigrationService(
                $c->make(MigrationRepository::class),
                $c->make(CommandExecutionLogger::class),
                $c->make(DeploymentLockManager::class),
                $c->make(BackupManager::class),
                $c->make(MigrationApprovalManager::class),
                $c->make(PreFlightValidator::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // All registration below reads the migration configuration and builds
        // 25+ factory-injected command instances. That work is pointless (and
        // expensive) on the HTTP/worker path, where boot() still runs but the
        // CLI is never invoked. Defer it so it executes ONLY when the CLI
        // actually materializes its commands.
        $cli->defer(function (CliPipeline $cli): void {
            // Read-only introspection — no DB deps, resolves straight from the manifest.
            $cli->command(new RouteListCommand());

            // ── Migration Commands with Enterprise Safeguards ──────────────
            try {
                $migrateConfig = $this->loadConfiguration();
            } catch (ConfigurationException $e) {
                error_log("Configuration Error: {$e->getMessage()}");

                // Fail hard in production
                if ($this->isProduction()) {
                    throw new \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException(
                        "Cannot boot: {$e->getMessage()}",
                        previous: $e
                    );
                }

                // In development, use minimal fallback config
                $migrateConfig = $this->getMinimalConfig();
            }

            $migrationFactory = MigrateFactory::fromConfig($migrateConfig);

            // Register all 25+ migration commands. Pass the built instances
            // directly so their factory-injected dependencies are preserved
            // (re-instantiating via class-string would drop them).
            //
            // Yield to any command a plugin already claimed (queued at boot,
            // before this deferred callback runs). This is why the kernel's
            // generic LetMigrate `tenant:*` commands do NOT shadow the Tenancy
            // plugin's registry-based equivalents when Tenancy is enabled — and
            // they still register normally when it is not.
            foreach ($migrationFactory->all() as $commandInstance) {
                if ($cli->hasQueued($commandInstance->getName())) {
                    continue;
                }
                $cli->command($commandInstance);
            }
        });
    }

    /**
     * The LoggerPort to hand this plugin's own components.
     *
     * Resolved from the container when SOMETHING bound it — a project wiring
     * `->withPorts([LoggerPort::class => ...])` reaches us through the
     * CoreContainer — and satisfied by our own file-backed logger when nothing
     * did.
     *
     * The fallback is not belt-and-braces. Provider::boot() registers commands
     * from a container this plugin builds by hand, which is NOT the one
     * OnDemandLoader assembles from the dependency graph; nothing there runs
     * another module's register(), so the Logger plugin's binding is invisible
     * to us even when that plugin is installed and enabled. Without a fallback
     * the port is simply absent and `make()` throws EntryNotFoundException
     * before the CLI can list a single command — logging a migration is not
     * worth being unable to run one.
     */
    private static function logger(ModuleContainer $container): LoggerPort
    {
        if ($container->has(LoggerPort::class)) {
            $resolved = $container->make(LoggerPort::class);

            if ($resolved instanceof LoggerPort) {
                return $resolved;
            }
        }

        return new FallbackFileLogger();
    }

    private function loadConfiguration(): array
    {
        // Use per-project config via Paths (resolves under project root)
        try {
            return $this->withPluginMigrationPaths(EnvironmentConfigurationLoader::load());
        } catch (ConfigurationException) {
            // Fall back to base config if environment-specific doesn't exist
            $baseConfigPath = Paths::config('let-migrate.php');

            if (!is_file($baseConfigPath)) {
                throw ConfigurationException::fileNotFound($baseConfigPath);
            }

            try {
                $config = require $baseConfigPath;
                return $this->withPluginMigrationPaths(ConfigurationValidator::validate($config));
            } catch (\Throwable $e) {
                throw ConfigurationException::loadFailed($baseConfigPath, $e);
            }
        }
    }

    /**
     * Append every plugins/{Name}/database/migrations directory to the config
     * "paths" so plugin-owned migrations (e.g. Auth, Authorization) run
     * alongside the project's own. Idempotent — duplicates are removed.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function withPluginMigrationPaths(array $config): array
    {
        // $pluginPaths = glob(Paths::base('plugins/*/database/migrations'), GLOB_ONLYDIR) ?: [];
        // if ($pluginPaths === []) {
        //     return $config;
        // }
        // We don't use plugin migration paths for now.
        // Plugin database migrations are populated into the project that uses the plugin.
        $pluginPaths = [];

        $existing = $config['paths'] ?? (isset($config['path']) ? [(string) $config['path']] : []);
        $config['paths'] = array_values(array_unique([...$existing, ...$pluginPaths]));
        unset($config['path']); // normalise to the plural form

        return $config;
    }

    private function getMinimalConfig(): array
    {
        // Fallback in-memory SQLite config for development
        return [
            'connections' => [
                'default' => [
                    'driver'   => 'sqlite',
                    'host'     => 'localhost',
                    'database' => ':memory:',
                    'username' => '',
                    'password' => '',
                ],
            ],
            'paths' => array_values(array_unique([
                Paths::project('database/migrations'),
                ...(glob(Paths::base('plugins/*/database/migrations'), GLOB_ONLYDIR) ?: []),
            ])),
            'tracking_table' => 'let_migrations',
            'pretend' => false,
            'transactional' => false,
        ];
    }

    private function isProduction(): bool
    {
        $env = (string) (env('APP_ENV') ?: 'local');
        return $env === 'production';
    }
}
