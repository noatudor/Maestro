<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;

/**
 * Artisan command to recover stale polling steps.
 *
 * Finds polling steps that have been waiting for dispatch longer than expected
 * (next_poll_at is far in the past) and reports or resets them.
 */
final class RecoverPollsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:recover-polls
                            {--stale-threshold=3600 : Seconds past next_poll_at to consider a poll stale}
                            {--dry-run : Show which polls would be recovered without recovering}';

    /**
     * @var string
     */
    protected $description = 'Recover stale polling steps that may have been missed';

    public function handle(): int
    {
        $staleThreshold = (int) $this->option('stale-threshold');
        $dryRun = (bool) $this->option('dry-run');

        $now = CarbonImmutable::now();
        $staleThresholdTime = $now->subSeconds($staleThreshold);

        if ($dryRun) {
            $this->info('Dry run mode - no polls will be recovered.');
        }

        /** @phpstan-ignore staticMethod.dynamicCall */
        $stalePolls = StepRunModel::query()
            ->where('status', StepState::Polling->value)
            ->where('next_poll_at', '<', $staleThresholdTime)
            ->orderBy('next_poll_at')
            ->get();

        if ($stalePolls->isEmpty()) {
            $this->info('No stale polls found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d stale poll(s).', $stalePolls->count()));

        $this->table(
            ['Step Run ID', 'Workflow ID', 'Step Key', 'Next Poll At', 'Delay'],
            $stalePolls->map(static function (StepRunModel $stepRunModel) use ($now): array {
                $nextPollAt = $stepRunModel->next_poll_at;
                $delay = $nextPollAt instanceof CarbonImmutable
                    ? $now->diffInSeconds($nextPollAt).' seconds'
                    : 'N/A';

                return [
                    $stepRunModel->id,
                    $stepRunModel->workflow_id,
                    $stepRunModel->step_key,
                    $nextPollAt?->toIso8601String() ?? 'N/A',
                    $delay,
                ];
            })->toArray(),
        );

        if ($dryRun) {
            $this->info('Run without --dry-run to reset next_poll_at for these polls.');

            return self::SUCCESS;
        }

        $recoveredCount = 0;

        foreach ($stalePolls as $stalePoll) {
            $stalePoll->next_poll_at = $now;
            $stalePoll->save();

            $this->info(sprintf('  Reset next_poll_at for step run %s', $stalePoll->id));
            $recoveredCount++;
        }

        $this->info(sprintf('Recovered %d stale poll(s).', $recoveredCount));
        $this->info('Run maestro:dispatch-polls to dispatch the recovered polls.');

        return self::SUCCESS;
    }
}
