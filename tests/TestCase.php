<?php

declare(strict_types=1);

namespace Maestro\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Maestro\Workflow\MaestroServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            MaestroServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        match ($driver) {
            'mysql' => $this->configureMysql(),
            'pgsql' => $this->configurePostgresql(),
            default => $this->configureSqlite(),
        };

        $this->configureRedis();
        config()->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function configureSqlite(): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    private function configureMysql(): void
    {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'mysql'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'sail'),
            'password' => env('DB_PASSWORD', 'password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);
    }

    private function configurePostgresql(): void
    {
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'pgsql'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'sail'),
            'password' => env('DB_PASSWORD', 'password'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    private function configureRedis(): void
    {
        $queueConnection = env('QUEUE_CONNECTION', 'sync');

        if ($queueConnection === 'redis') {
            config()->set('queue.default', 'redis');
            config()->set('database.redis.client', 'phpredis');
            config()->set('database.redis.default', [
                'host' => env('REDIS_HOST', 'redis'),
                'port' => env('REDIS_PORT', '6379'),
                'database' => 0,
            ]);
            config()->set('queue.connections.redis', [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
                'retry_after' => 90,
                'block_for' => null,
            ]);
        } else {
            config()->set('queue.default', 'sync');
        }
    }
}
