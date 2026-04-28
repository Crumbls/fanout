<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Contracts;

use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;

interface PayloadTransformer
{
    /**
     * Mutate the payload before it is dispatched to the endpoint. Use this
     * to strip sensitive fields, remap shapes, or downgrade schemas for
     * older destinations.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function transform(array $payload, EndpointConfig $endpoint, ?FanoutEvent $event): array;
}
