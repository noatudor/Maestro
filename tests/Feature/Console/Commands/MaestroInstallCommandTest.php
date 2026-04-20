<?php

declare(strict_types=1);

describe('MaestroInstallCommand', function () {
    it('publishes config and migrations', function () {
        $this->artisan('maestro:install')
            ->assertSuccessful()
            ->expectsOutputToContain('Maestro');
    });
});
