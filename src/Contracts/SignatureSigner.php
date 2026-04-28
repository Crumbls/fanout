<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Contracts;

use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;

interface SignatureSigner
{
    /**
     * Produce headers to attach to an outbound delivery so the destination
     * can verify the request originated from this fan-out instance.
     *
     * @param  string          $rawBody  Final, post-transform payload bytes.
     * @param  EndpointConfig  $endpoint
     * @param  FanoutEvent|null $event   Null in `persist: 'none'` mode.
     * @return array<string, string>
     */
    public function sign(string $rawBody, EndpointConfig $endpoint, ?FanoutEvent $event): array;
}
