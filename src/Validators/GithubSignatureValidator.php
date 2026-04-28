<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Validators;

/**
 * GitHub webhooks: X-Hub-Signature-256: sha256=<hex>
 * https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries
 *
 * Thin wrapper around HmacSha256SignatureValidator with the right header /
 * prefix locked in.
 */
class GithubSignatureValidator extends HmacSha256SignatureValidator
{
    public function verify(string $rawBody, array $headers, array $config): bool
    {
        $config['signature_header'] = 'X-Hub-Signature-256';
        $config['signature_prefix'] = 'sha256=';

        return parent::verify($rawBody, $headers, $config);
    }
}
