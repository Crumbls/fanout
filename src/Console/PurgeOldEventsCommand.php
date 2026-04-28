<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Console;

use Crumbls\Fanout\Fanout;
use Illuminate\Console\Command;

class PurgeOldEventsCommand extends Command
{
    protected $signature = 'fanout:purge {--dry-run : Report what would be deleted without removing rows}';

    protected $description = 'Remove fanout events and deliveries whose retention window has elapsed';

    public function handle(Fanout $fanout): int
    {
        if (! (bool) config('fanout.pruning.enabled', true)) {
            $this->comment('Pruning disabled in config — nothing to do.');

            return self::SUCCESS;
        }

        $eventClass    = $fanout->eventModel();
        $deliveryClass = $fanout->deliveryModel();
        $now           = now();

        $expiredDeliveries = $deliveryClass::query()
            ->whereNotNull('purgeable_at')
            ->where('purgeable_at', '<=', $now);

        $expiredEvents = $eventClass::query()
            ->whereNotNull('purgeable_at')
            ->where('purgeable_at', '<=', $now);

        $deliveryCount = (clone $expiredDeliveries)->count();
        $eventCount    = (clone $expiredEvents)->count();

        $this->info("Found {$eventCount} expired event(s) and {$deliveryCount} expired delivery row(s).");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no rows removed.');

            return self::SUCCESS;
        }

        $expiredDeliveries->delete();
        $expiredEvents->delete();

        $this->comment("Purged {$eventCount} event(s) and {$deliveryCount} delivery row(s).");

        return self::SUCCESS;
    }
}
