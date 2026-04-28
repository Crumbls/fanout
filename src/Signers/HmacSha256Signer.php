<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Signers;

use Crumbls\Fanout\Contracts\SignatureSigner;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;

class HmacSha256Signer implements SignatureSigner
{
    public function sign(string $rawBody, EndpointConfig $endpoint, ?FanoutEvent $event): array
    {
        $secret = $endpoint->signerSecret;

        if ($secret === null || $secret === '') {
            return [];
        }

        $header = $endpoint->signatureHeader ?? 'X-Fanout-Signature';
        $hash   = hash_hmac('sha256', $rawBody, $secret);

        return [$header => "sha256={$hash}"];
    }
}
