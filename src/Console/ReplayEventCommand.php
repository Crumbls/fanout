<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Console;

use Crumbls\Fanout\Fanout;
use Illuminate\Console\Command;

class ReplayEventCommand extends Command
{
    protected $signature = 'fanout:replay
        {event : The fanout event id to replay}
        {--endpoint= : Optional endpoint name to scope the replay to}';

    protected $description = 'Re-dispatch deliveries for a previously received fanout event';

    public function handle(Fanout $fanout): int
    {
        $eventId  = (string) $this->argument('event');
        $endpoint = $this->option('endpoint');

        $this->info("Replaying event [{$eventId}]" . ($endpoint ? " to endpoint [{$endpoint}]" : ' to all endpoints'));

        $eventClass = $fanout->eventModel();
        $event      = $eventClass::query()->find($eventId);

        if ($event === null) {
            $this->error("Event [{$eventId}] not found.");

            return self::FAILURE;
        }

        $fanout->replay($event, $endpoint);

        $this->comment('Replay queued.');

        return self::SUCCESS;
    }
}
