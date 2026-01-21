<?php

declare(strict_types=1);

use Maestro\Workflow\Application\Job\ZombieJobDetectionResult;
use Maestro\Workflow\Application\Job\ZombieJobDetector;
use Maestro\Workflow\Console\Commands\DetectZombieJobsCommand;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Tests\Fixtures\Jobs\TestJob;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

describe('DetectZombieJobsCommand', static function (): void {
    it('reports no zombies when none detected', function (): void {
        $mock = Mockery::mock(ZombieJobDetector::class);
        $mock->shouldReceive('detect')
            ->with(30)
            ->andReturn(ZombieJobDetectionResult::noZombies());

        $command = new DetectZombieJobsCommand();
        $command->setLaravel(app());

        $exitCode = $command->handle($mock);

        expect($exitCode)->toBe(0);
    });

    it('reports zombies when detected', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();

        $jobRecord = JobRecord::create(
            $workflowId,
            $stepRunId,
            'zombie-job-uuid',
            TestJob::class,
            'default',
        );
        $jobRecord->start('worker-1');

        $mock = Mockery::mock(ZombieJobDetector::class);
        $mock->shouldReceive('detect')
            ->with(30)
            ->andReturn(ZombieJobDetectionResult::withZombies(
                [$jobRecord],
                1,
                [$workflowId],
            ));

        $command = new DetectZombieJobsCommand();
        $command->setLaravel(app());

        $exitCode = $command->handle($mock);

        expect($exitCode)->toBe(0);
    });

    it('detects stale dispatched jobs when option enabled', function (): void {
        $workflowId = WorkflowId::generate();
        $stepRunId = StepRunId::generate();

        $jobRecord = JobRecord::create(
            $workflowId,
            $stepRunId,
            'stale-job-uuid',
            TestJob::class,
            'default',
        );

        $mock = Mockery::mock(ZombieJobDetector::class);
        $mock->shouldReceive('detect')
            ->with(30)
            ->andReturn(ZombieJobDetectionResult::noZombies());
        $mock->shouldReceive('detectStaleDispatched')
            ->with(60)
            ->andReturn(ZombieJobDetectionResult::withZombies(
                [$jobRecord],
                1,
                [$workflowId],
            ));

        $legacyMock = Mockery::mock(DetectZombieJobsCommand::class)->makePartial();
        $legacyMock->shouldReceive('option')
            ->with('timeout')
            ->andReturn('30');
        $legacyMock->shouldReceive('option')
            ->with('stale-dispatched')
            ->andReturn(true);
        $legacyMock->shouldReceive('option')
            ->with('stale-timeout')
            ->andReturn('60');
        $legacyMock->shouldReceive('info')->andReturnNull();
        $legacyMock->shouldReceive('warn')->andReturnNull();
        $legacyMock->shouldReceive('table')->andReturnNull();
        $legacyMock->shouldReceive('newLine')->andReturnNull();
        $legacyMock->setLaravel(app());

        $exitCode = $legacyMock->handle($mock);

        expect($exitCode)->toBe(0);
    });

    it('skips stale detection when option disabled', function (): void {
        $mock = Mockery::mock(ZombieJobDetector::class);
        $mock->shouldReceive('detect')
            ->with(30)
            ->andReturn(ZombieJobDetectionResult::noZombies());
        $mock->shouldNotReceive('detectStaleDispatched');

        $command = new DetectZombieJobsCommand();
        $command->setLaravel(app());

        $exitCode = $command->handle($mock);

        expect($exitCode)->toBe(0);
    });

    it('reports no stale jobs when none detected', function (): void {
        $mock = Mockery::mock(ZombieJobDetector::class);
        $mock->shouldReceive('detect')
            ->with(30)
            ->andReturn(ZombieJobDetectionResult::noZombies());
        $mock->shouldReceive('detectStaleDispatched')
            ->with(60)
            ->andReturn(ZombieJobDetectionResult::noZombies());

        $legacyMock = Mockery::mock(DetectZombieJobsCommand::class)->makePartial();
        $legacyMock->shouldReceive('option')
            ->with('timeout')
            ->andReturn('30');
        $legacyMock->shouldReceive('option')
            ->with('stale-dispatched')
            ->andReturn(true);
        $legacyMock->shouldReceive('option')
            ->with('stale-timeout')
            ->andReturn('60');
        $legacyMock->shouldReceive('info')->andReturnNull();
        $legacyMock->shouldReceive('newLine')->andReturnNull();
        $legacyMock->setLaravel(app());

        $exitCode = $legacyMock->handle($mock);

        expect($exitCode)->toBe(0);
    });
});
