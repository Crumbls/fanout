<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'fanout:install {--force : Overwrite existing files}';

    protected $description = 'Publish fanout config and migration files into the host app';

    public function handle(): int
    {
        $this->info('Publishing fanout config...');

        $this->callSilent('vendor:publish', [
            '--tag'   => 'fanout-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->info('Publishing fanout migrations...');

        $this->callSilent('vendor:publish', [
            '--tag'   => 'fanout-migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->comment('Fanout installed. Next steps:');
        $this->line('  1. Configure profiles in config/fanout.php');
        $this->line('  2. Run "php artisan migrate"');
        $this->line('  3. Start a worker on the fanout queue: "php artisan queue:work --queue=fanout"');

        return self::SUCCESS;
    }
}
