<?php

declare(strict_types=1);

namespace Crumbls\Fanout;

use Crumbls\Fanout\Console\InstallCommand;
use Crumbls\Fanout\Console\PurgeOldEventsCommand;
use Crumbls\Fanout\Console\ReplayEventCommand;
use Crumbls\Fanout\Console\ReplayFailedCommand;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FanoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fanout.php', 'fanout');

        $this->app->singleton(Fanout::class, fn ($app) => new Fanout($app));
    }

    public function boot(): void
    {
        $this->registerPublishes();
        $this->registerMigrations();
        $this->registerRoutes();
        $this->registerCommands();
    }

    protected function registerPublishes(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/fanout.php' => config_path('fanout.php'),
        ], 'fanout-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'fanout-migrations');
    }

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function registerRoutes(): void
    {
        if (! config('fanout.route.enabled', true)) {
            return;
        }

        $config = config('fanout.route', []);

        Route::group([
            'prefix'     => $config['prefix'] ?? 'fanout/in',
            'middleware' => $config['middleware'] ?? ['api'],
            'domain'     => $config['domain'] ?? null,
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/fanout.php');
        });
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            ReplayEventCommand::class,
            ReplayFailedCommand::class,
            PurgeOldEventsCommand::class,
        ]);
    }
}
