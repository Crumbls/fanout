<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Console;

use Crumbls\Fanout\Fanout;
use Illuminate\Console\Command;

class ReplayFailedCommand extends Command
{
    protected $signature = 'fanout:replay-failed
        {--profile= : Limit replay to a single profile}
        {--endpoint= : Limit replay to a single endpoint}';

    protected $description = 'Re-dispatch every failed fanout delivery, optionally scoped by profile or endpoint';

    public function handle(Fanout $fanout): int
    {
        $profile  = $this->option('profile');
        $endpoint = $this->option('endpoint');

        $scope = array_filter([
            $profile  ? "profile=[{$profile}]"   : null,
            $endpoint ? "endpoint=[{$endpoint}]" : null,
        ]);

        $this->info('Replaying failed deliveries' . ($scope ? ' (' . implode(', ', $scope) . ')' : ''));

        $count = $fanout->replayFailed($profile, $endpoint);

        $this->comment("Re-queued {$count} delivery " . ($count === 1 ? 'job' : 'jobs') . '.');

        return self::SUCCESS;
    }
}
