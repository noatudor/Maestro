<?php

declare(strict_types=1);

namespace Maestro\Workflow\Commands;

use Illuminate\Console\Command;

final class MaestroInstallCommand extends Command
{
    protected $signature = 'maestro:install';

    protected $description = 'Install the Maestro workflow package';

    public function handle(): int
    {
        $this->info('Installing Maestro Workflow...');

        $this->call('vendor:publish', [
            '--tag' => 'maestro-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'maestro-migrations',
        ]);

        $this->info('Maestro Workflow installed successfully.');

        return self::SUCCESS;
    }
}
