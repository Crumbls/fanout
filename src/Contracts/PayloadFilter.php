<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Contracts;

use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;

interface PayloadFilter
{
    /**
     * Decide whether a delivery should run for this endpoint. Return false
     * to mark the delivery as `skipped` without sending an HTTP request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function shouldDeliver(array $payload, EndpointConfig $endpoint, ?FanoutEvent $event): bool;
}
