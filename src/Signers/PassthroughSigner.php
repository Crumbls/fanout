<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Signers;

use Crumbls\Fanout\Contracts\SignatureSigner;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;

/**
 * Forwards the original signature header from the inbound webhook so that
 * downstream destinations can verify against the original sender's secret.
 *
 * Only useful when:
 *   - the destination shares the original sender's secret, AND
 *   - the payload is not transformed (any mutation would invalidate the
 *     signature).
 */
class PassthroughSigner implements SignatureSigner
{
    public function sign(string $rawBody, EndpointConfig $endpoint, ?FanoutEvent $event): array
    {
        if ($event === null || $event->signature === null) {
            return [];
        }

        $header = $endpoint->signatureHeader ?? 'X-Fanout-Signature';

        return [$header => $event->signature];
    }
}
